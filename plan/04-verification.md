# 04 — Verification

## Overview

After all three plans are applied, run the full benchmark sequence to confirm
everything works correctly.

## Prerequisites

- [ ] Plan 01 deployed: LMS VPS has rebuilt app container with new PHP-FPM config
- [ ] Plan 02 deployed: k6 VPS has updated threshold JS files
- [ ] Plan 03 deployed: Full `fixtures.js` on both LMS and k6 VPS, imports updated

## Smoke Test (1 VU)

```bash
bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
```

### Expected Results

| Check | Expected | Why |
|-------|----------|-----|
| Exit code | **0** | Thresholds pass |
| `http_req_failed` | **< 5%** | Lowered from 15% threshold |
| `[submit-assignment] status 201` | **all pass** | 4,819 targets, 1 VU uses ~30 |
| `[start-attempt] status 201` | **all pass** | 19,870 targets, 1 VU uses ~30 |
| `resources.csv` CPU avg | **> 5%** | 150 PHP-FPM workers, no queuing |
| `resources.csv` CPU max | **> 10%** | Brief spikes during warmup |
| HTTP response time (avg) | **< 200ms** | No queuing, single VU |

## Load Test (1500 VU)

```bash
VU_LEVELS="1500" bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
```

### Expected Results

| Check | Expected | Why |
|-------|----------|-----|
| Exit code | **0** | Thresholds pass |
| `http_req_duration` | **no threshold** | Removed — measurement only |
| `http_req_failed` | **< 5%** | Only controlled 403/404 failures |
| Write failures | **near 0** | Full fixtures, no exhaustion |
| LMS CPU (avg) | **40-70%** | 150 workers utilising all 4 cores |
| LMS CPU (max) | **> 80%** | Peak during steady state |
| Memory usage | **3-6 GB** | Workers + MySQL + OS |
| Throughput | **> 400 req/s** | 150 workers × ~3 req/s each |
| Response time (avg) | **< 2,000ms** | Reduced queuing from 64→150 workers |
| Redis cache hit ratio | **> 80%** | Cache warms up under load |

### Resource CSV Diagnostics

After the run, analyse:

```bash
# CPU distribution across 6.5 min
python3 -c "
import csv
with open('benchmark-results/cache-aside/read-heavy/iter1/1500vu-*resources.csv') as f:
    reader = csv.DictReader(f)
    cpus = [float(r['cpu_pct']) for r in reader]
    print(f'CPU min={min(cpus):.1f}% max={max(cpus):.1f}% avg={sum(cpus)/len(cpus):.1f}%')
    print(f'Memory min={min(int(r[\"mem_used_mb\"]) for r in csv.DictReader(open(f.name)))}MB '
          f'max={max(int(r[\"mem_used_mb\"]) for r in csv.DictReader(open(f.name)))}MB')
"
```

Expected: CPU 40-70% avg, not the previous 11.7%.

## Regression: 1 VU Still Works

Run the smoke test again after the load test. Verify nothing regressed:

```bash
bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
```

## Comparison Table

Fill this after the runs:

| Metric | Before (64 workers, sampled) | After (150 workers, full) | Delta |
|--------|---------------------------|---------------------------|-------|
| 1 VU CPU avg | 0.6% | | |
| 1 VU response time | 94ms | | |
| 1 VU throughput | 0.75/s | | |
| 1 VU http failures | 6.5% | | |
| 1500 VU CPU avg | 11.7% | | |
| 1500 VU response time | 10,286ms | | |
| 1500 VU throughput | 115.8/s | | |
| 1500 VU http failures | 25.0% | | |
| 1500 VU checks pass | 85.7% | | |
| Write success rate | ~11% | | |
| Exit code | 99 | | |

## What to Watch For

1. **OOM**: If RSS exceeds 7.5 GB, the kernel OOM-killer may kill MySQL or PHP.
   Reduce `pm.max_children` to 120 if this happens.
2. **MySQL bottleneck**: With `innodb-buffer-pool-size=64M`, MySQL may become
   the next limiter. If CPU is still low (< 30%) after PHP-FPM tuning, profile
   MySQL slow queries and consider increasing the buffer pool.
3. **Nginx connection pool**: Default `worker_connections` is 768. With 150 PHP
   workers and 1500 VUs, this may need tuning. Check nginx error logs for
   `worker_connections are not enough`.
