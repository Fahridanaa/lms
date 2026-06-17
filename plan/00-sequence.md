# 00 — Execution Sequence

## Overview

Three independent changes to fix the 1500 VU benchmark. Each is self-contained and
can be verified before moving to the next.

## Order

```
01-php-fpm-tuning  ──────►  02-threshold-updates  ──────►  03-full-fixtures
     │                              │                              │
     ▼                              ▼                              ▼
  Deploy to LMS                Commit + push                 Generate on LMS
  Restart containers           (no deploy needed)            Update imports
     │                              │                              │
     └──────────────────────┬───────┘                              │
                            │                                      ▼
                     Re-run 1vu smoke test              Regenerate k6 fixtures
                     Verify resources.csv               Re-run 1500vu benchmark
                     shows higher CPU
```

## Dependencies

| Plan | Depends On | Why |
|------|-----------|-----|
| 01 — PHP-FPM | Nothing | Standalone config change |
| 02 — Thresholds | Nothing (or 01) | Can apply independently |
| 03 — Full fixtures | Nothing (ideally after 01) | Write exhaustion is fixture issue, not PHP-FPM |

02 can be done any time — it's just JS. 01 and 03 are independent but 03's
effect is best measured after PHP-FPM can actually handle the load.

## Deploy Flow

1. Apply Plan 01 → `git commit` → `git push` → `git pull` on LMS VPS
2. Apply Plan 02 → `git commit` → `git push` → `git pull` on k6 VPS (or sync)
3. Apply Plan 03 → need to run Artisan command on LMS VPS + sync JS file to k6 VPS

## Verify Each Step

Each plan has its own verification section. Run the 1 VU smoke test after Plan 01
to confirm resources.csv now shows higher CPU. Run the 1500 VU benchmark after
Plan 03 to confirm write failures disappear.
