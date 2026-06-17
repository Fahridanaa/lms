# Benchmark Fixes — 1500 VU Fix

Execute the three independent plans to fix the 1500 VU benchmark, then verify.

Reference: `plan/` for full details on each plan.

## Goals
- Apply PHP-FPM tuning (Plan 01) to utilise LMS VPS 4-core/8GB RAM
- Update k6 thresholds (Plan 02) to realistic expectations
- Switch to full fixtures (Plan 03) to eliminate write exhaustion
- Verify with 1 VU smoke test and 1500 VU load test (Plan 04)

## Checklist
### Plan 01 — PHP-FPM Tuning
- [ ] Update `pm.max_children = 64` → `150` in Dockerfile
- [ ] Update `pm.start_servers = 16` → `40`
- [ ] Update `pm.min_spare_servers = 12` → `20`
- [ ] Update `pm.max_spare_servers = 24` → `60`
- [ ] Update `pm.max_requests = 500` → `2000`
- [ ] Commit and push to deploy
- [ ] Verify: run 1 VU smoke test, check resources.csv CPU > 5%

### Plan 02 — k6 Threshold Updates
- [ ] Update `read-heavy-scenario.js`: remove `http_req_duration` threshold
- [ ] Update `read-heavy-scenario.js`: tighten `http_req_failed` to `rate<0.05`
- [ ] Update `write-heavy-scenario.js`: same threshold changes
- [ ] Commit and push

### Plan 03 — Full Fixtures
- [ ] Update imports in both scenarios to use `fixtures.js` instead of `fixtures.sampled.js`
- [ ] Update `scripts/run-remote-benchmark.sh` fixture path references
- [ ] Update `scripts/generate-warmup-targets.cjs` to remove fallback
- [ ] SSH into LMS VPS, generate full fixtures via Artisan command
- [ ] Sync fixture to k6 VPS
- [ ] Commit and push code changes

### Plan 04 — Verification
- [ ] Run 1 VU smoke test — confirm exit code 0, CPU > 5%, failures < 5%
- [ ] Run 1500 VU load test — confirm exit code 0, failures < 5%, CPU 40-70%
- [ ] Fill comparison table with before/after metrics
- [ ] Run 1 VU regression check after load test

## Notes
(Update with progress, decisions, blockers)