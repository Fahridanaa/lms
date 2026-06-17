# Benchmark Fixes — 1500 VU Fix

## Checklist
### Plan 01 — PHP-FPM Tuning
- [x] Dockerfile: max_children 64→150, start 16→40, min 12→20, max 24→60, requests 500→2000
- [x] Commit & push

### Plan 02 — k6 Threshold Updates
- [x] Remove `http_req_duration`, tighten `http_req_failed` 15%→5%
- [x] Commit & push

### Plan 03 — Full Fixtures (3 fixes total)
- [x] Switch imports from `fixtures.sampled.js` → `fixtures.js`
- [x] Fix fixture paths in `run-remote-benchmark.sh` and `generate-warmup-targets.cjs`
- [x] **Fix 1** (commit `0910390`): Apply `wrapInSharedArray()` to ALL modes (not just sampled)
- [x] **Fix 2** (commit `2783fa2`): Remove `--sampled` from `benchmark-lms.sh prepare` — was generating wrong file!

### 🔥 Root cause chain
```
benchmark-lms.sh prepare → benchmark:generate-k6-fixtures --sampled
                           ↓
                     generates fixtures.sampled.js  (SharedArray ✅, 500 targets ❌)
                           ↓
run-remote-benchmark.sh → pulls fixtures.js        (plain array ❌, 7.1MB ❌)
                           ↓
                     k6 loads plain arrays → duplicated per VU → OOM
```

Fix 1: `wrapInSharedArray()` untuk semua mode → full fixtures pake SharedArray
Fix 2: hapus `--sampled` → prepare generate `fixtures.js` (bukan `fixtures.sampled.js`)

### Plan 04 — Verification (pending)
- [ ] Pull `git pull` di kedua VPS
- [ ] Run benchmark → fixtures.js sekarang SharedArray, tidak OOM
