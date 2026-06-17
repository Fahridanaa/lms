# 01 — PHP-FPM Tuning (Aggressive)

## Goal

Use the LMS VPS's 4-core CPU and 8 GB RAM to its full potential during benchmarks.
Current `pm.max_children = 64` limits concurrency to 64, causing request queuing
(10s avg response at 1500 VUs) while CPU idles at ~12%.

## Resource Budget

| Consumer | Current Usage | Budget |
|----------|--------------|--------|
| OS / system | ~1 GB | 1.5 GB |
| MySQL (mysql-server:8.0) | ~400 MB | 1 GB |
| Redis (7-alpine) | ~10 MB | 50 MB |
| nginx | ~5 MB | 10 MB |
| **PHP-FPM workers** | ~800 MB (64 × ~12MB) | **~5.4 GB** |

At ~35 MB per worker (Laravel typical RSS): **5.4 GB ÷ 35 MB ≈ 154 workers**

MySQL `max_connections=300` can support 150 PHP workers + overhead.

## Changes

### File: `Dockerfile`

Replace the `zz-benchmark.conf` block:

```diff
 RUN { \
     echo '[www]'; \
     echo 'pm = dynamic'; \
-    echo 'pm.max_children = 64'; \
-    echo 'pm.start_servers = 16'; \
-    echo 'pm.min_spare_servers = 12'; \
-    echo 'pm.max_spare_servers = 24'; \
-    echo 'pm.max_requests = 500'; \
+    echo 'pm.max_children = 150'; \
+    echo 'pm.start_servers = 40'; \
+    echo 'pm.min_spare_servers = 20'; \
+    echo 'pm.max_spare_servers = 60'; \
+    echo 'pm.max_requests = 2000'; \
 } > /usr/local/etc/php-fpm.d/zz-benchmark.conf
```

### Rationale

| Setting | Old | New | Why |
|---------|-----|-----|-----|
| `pm.max_children` | 64 | **150** | Utilise ~5.4 GB RAM; 150 workers × 35 MB = 5.25 GB |
| `pm.start_servers` | 16 | **40** | Pre-warm enough workers for 1500 VU ramp-up |
| `pm.min_spare_servers` | 12 | **20** | Keep a healthy pool floor under load |
| `pm.max_spare_servers` | 24 | **60** | Allow pool to grow quickly during spikes |
| `pm.max_requests` | 500 | **2000** | Reduce worker recycling during benchmarks |

### Not Changed

| Setting | Value | Reason |
|---------|-------|--------|
| `pm` | `dynamic` | Keeps baseline memory low when idle |
| nginx `fastcgi_read_timeout` | 300s | Already adequate |
| MySQL `max_connections` | 300 | Can handle 150 + connections |
| OPCache | already enabled | 128 MB, fine for current codebase |

### Risk

- 150 workers × 35 MB = 5.25 GB RSS under full load. If each worker spikes to
  50 MB (e.g. during PDF generation or heavy serialization), total = 7.5 GB.
  **Monitor RSS during the first run.** If OOM-killer triggers, reduce to 120.
- MySQL `innodb-buffer-pool-size=64M` is very small. May become the next
  bottleneck if PHP-FPM is no longer the limiter. Not part of this plan.

## Verification

1. Rebuild and restart containers on LMS VPS:
   ```bash
   docker compose build app
   docker compose up -d
   ```

2. Run 1 VU smoke test:
   ```bash
   bash scripts/run-remote-benchmark.sh cache-aside read-heavy "$BASE_URL" 1
   ```

3. Check `resources.csv` — CPU should now show **higher values** (>5% avg)
   compared to previous 0.6% avg. The exact figure depends on how much the
   single VU stresses the app, but 150 workers means zero queuing at 1 VU.

4. Check k6 summary — response time should remain ~100ms (unchanged from
   previous 94ms avg at 1 VU, which was already good).

5. **If stable**, proceed to 1500 VU run after Plan 03 is applied.
