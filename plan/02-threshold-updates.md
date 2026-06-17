# 02 — k6 Threshold Updates

## Goal

Fix thresholds to reflect realistic benchmark expectations. The current thresholds
either don't make sense after PHP-FPM tuning or are too loose for a meaningful
pass/fail signal.

## Changes

### File: `tests/Benchmark/k6/read-heavy-scenario.js`

Current thresholds (with previous fixes applied):

```javascript
thresholds: {
    http_req_duration:        ['p(95)<2000'],       // ← remove
    'http_req_failed{ef:1}': ['rate<1.01'],         // keep
    http_req_failed:          ['rate<0.15'],         // ← tighten to 0.05
},
```

Replace with:

```javascript
thresholds: {
    // http_req_failed{ef:1} is expected 403/404 — always succeeds at 100%.
    'http_req_failed{ef:1}': ['rate<1.01'],
    // http_req_failed includes both controlled failures (~7% ef:1) and
    // unexpected failures. After php-fpm tuning, unexpected failures should
    // be near 0, so total rate must be < 5%.
    http_req_failed:          ['rate<0.05'],
},
```

**Removed:** `http_req_duration: ['p(95)<2000']`

Why: Response time is a benchmark **measurement**, not a pass/fail gate.
`p(95) < 2000ms` is arbitrary — different cache strategies, VU levels, and
hardware produce vastly different latencies. The k6 summary already captures
p95, p99, avg, min, max for every endpoint. Report those in the analysis;
don't fail the run because latency is higher than an arbitrary number.

### File: `tests/Benchmark/k6/write-heavy-scenario.js`

Same changes:

```diff
 thresholds: {
-    http_req_duration:        ['p(95)<3000'],
-    'http_req_failed{ef:1}': ['rate<1.0'],
-    http_req_failed:          ['rate<0.15'],
+    'http_req_failed{ef:1}': ['rate<1.01'],
+    http_req_failed:          ['rate<0.05'],
 },
```

(Rationale: same as read-heavy. Controlled failures always pass at 100%.
Unexpected failures target < 5%.)

### File: `tests/Benchmark/k6/_archive/stress-test.js`

Check and apply same pattern if it uses the same thresholds (optional, not
in current scope).

## Resulting Threshold Logic

| Threshold | Reads 1 VU | Reads 1500 VU | Writes 1 VU | Writes 1500 VU |
|-----------|-----------|--------------|------------|---------------|
| `http_req_failed` < 5% | ✅ ~6.5% fail → ❌ | ✅ if failures < 5% | TBD | TBD |
| `http_req_failed{ef:1}` < 101% | ✅ 1.0 < 1.01 | ✅ 1.0 < 1.01 | ✅ | ✅ |

Note: the 1 VU read-heavy run had **6.5% HTTP failures** (from the previous
data). After php-fpm tuning + full fixtures, this should drop below 5%.
If it doesn't, investigate the 1.5% excess.

## Verification

```bash
# Run 1 VU smoke test to confirm thresholds pass
bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
# Exit code 0 = thresholds passed
```
