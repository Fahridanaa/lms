# 03 — Full (Non-Sampled) Fixtures

## Goal

Eliminate write-operation fixture exhaustion. The sampled fixture caps writable
targets at 500 (submissions) and 1,000 (quiz attempts). With 1500 VUs and ~4,485
write ops per 6.5 min run, both pools are exhausted in the first ~30 seconds.
Full fixtures provide enough targets to last the entire run.

## Current Pool Sizes

| Pool | Sampled | Full | Needed (1500 VU, 6.5 min) |
|------|---------|------|---------------------------|
| WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS | 500 | **4,819** ✅ | ~4,485 |
| WRITABLE_QUIZ_ATTEMPT_TARGETS | 1,000 | **19,870** ✅ | ~4,485 |
| READABLE_MATERIAL_TARGETS | 1,000 | 13,176 | unlimited |
| ENROLLED_PAIRS | 1,000 | 3,830 | — |
| File size | 683 KB | 7.3 MB | — |

## Existing Fallback

`scripts/generate-warmup-targets.cjs` already prioritises `fixtures.sampled.js`
but falls back to `fixtures.js`:

```javascript
const fixtureFile = process.env.K6_FIXTURE_FILE
  ? path.resolve(projectDir, process.env.K6_FIXTURE_FILE)
  : (fs.existsSync(path.join(k6Dir, 'fixtures.sampled.js'))
      ? path.join(k6Dir, 'fixtures.sampled.js')
      : path.join(k6Dir, 'fixtures.js'));
```

## Steps

### Step 1 — Update k6 scenario imports

**Files:** `tests/Benchmark/k6/read-heavy-scenario.js`
`tests/Benchmark/k6/write-heavy-scenario.js`

```diff
- import { ... } from './fixtures.sampled.js';
+ import { ... } from './fixtures.js';
```

### Step 2 — Update benchmark scripts

**File:** `scripts/run-remote-benchmark.sh`

Three references to `fixtures.sampled.js` need changing:

```diff
- local lms_fixture_path="${LMS_PROJECT_DIR}/tests/Benchmark/k6/fixtures.sampled.js"
- local k6_fixture_path="${K6_PROJECT_DIR}/tests/Benchmark/k6/fixtures.sampled.js"
+ local lms_fixture_path="${LMS_PROJECT_DIR}/tests/Benchmark/k6/fixtures.js"
+ local k6_fixture_path="${K6_PROJECT_DIR}/tests/Benchmark/k6/fixtures.js"
```

(Lines 343-344 in fixture-check, and lines 499-500 in run_k6_for_level_remote.)

Also remove the `fixtures.sampled.js` fallback check in `generate-warmup-targets.cjs`
since we're standardising on `fixtures.js`:

```diff
- const fixtureFile = process.env.K6_FIXTURE_FILE
-   ? path.resolve(projectDir, process.env.K6_FIXTURE_FILE)
-   : (fs.existsSync(path.join(k6Dir, 'fixtures.sampled.js'))
-       ? path.join(k6Dir, 'fixtures.sampled.js')
-       : path.join(k6Dir, 'fixtures.js'));
+ const fixtureFile = process.env.K6_FIXTURE_FILE
+   ? path.resolve(projectDir, process.env.K6_FIXTURE_FILE)
+   : path.join(k6Dir, 'fixtures.js');
```

### Step 3 — Generate full fixtures on LMS VPS

```bash
ssh fahri@<LMS_IP>
cd /home/fahri/lms

# The db must be seeded first. If it's already seeded (from prepare),
# just generate:
vendor/bin/sail artisan benchmark:generate-k6-fixtures
```

Expected output:
```
Wrote tests/Benchmark/k6/fixtures.js (7347131 bytes)

+-------------------------------------------+-------+
| Pool                                      | Count |
+-------------------------------------------+-------+
| ENROLLED_PAIRS                            | 3830  |
| WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS    | 4819  |
| WRITABLE_QUIZ_ATTEMPT_TARGETS             | 19870 |
| READABLE_MATERIAL_TARGETS                 | 13176 |
| ...                                       |       |
+-------------------------------------------+-------+
```

### Step 4 — Sync to k6 VPS

```bash
# From k6 VPS (or after ssh into it):
rsync -az fahri@<LMS_IP>:/home/fahri/lms/tests/Benchmark/k6/fixtures.js \
  /home/fahri/lms/tests/Benchmark/k6/fixtures.js
```

### Step 5 — Commit and push code changes

```bash
git add -A
git commit -m "feat(benchmark): switch to full (non-sampled) k6 fixtures"
git push
```

(The script imports are changed; the generated fixture file itself is not
committed — it's generated from the database on each run.)

## Verification

### 1. Warmup generation works offline

On the k6 VPS, generate warmup targets using the new fixture:

```bash
K6_FIXTURE_FILE=/home/fahri/lms/tests/Benchmark/k6/fixtures.js \
  node scripts/generate-warmup-targets.cjs
```

Check that all pool counts are from the full fixture (not 500/1000 caps).

### 2. Run 1 VU smoke test

```bash
bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
```

Verify write operations now succeed:
- `[submit-assignment] status 201` — no failures (or very few)
- `[start-attempt] status 201` — no failures (or very few)
- `http_req_failed` rate < 5%

### 3. Run 1500 VU load test

```bash
# Single VU level (skipping the 1vu run already done):
VU_LEVELS="1500" \
bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
```

Verify:
- `WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS` passes match attempts (no exhaustion)
- `WRITABLE_QUIZ_ATTEMPT_TARGETS` passes match attempts (no exhaustion)
- `http_req_failed` rate < 5% (only controlled 403/404 failures)
- Total check pass rate > 95%

## Risk

- **7.3 MB JS file** — k6 SharedArray handles this fine, but warmup generation
  (`generate-warmup-targets.cjs`) reads the full file into memory. This may
  take slightly longer but should complete within a few seconds.
- **Full fixtures vary per seed** — the counts above assume the same seeder
  output. If the database is re-seeded with different data, pool sizes may
  change. Verify counts after generation.
