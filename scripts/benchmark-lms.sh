#!/bin/bash
# ============================================================
# LMS-Side Benchmark Operations
#
# Usage  : ./scripts/benchmark-lms.sh <subcommand> [options]
#
# Subcommands:
#   preflight                  Validate LMS host readiness
#   prepare                    Full benchmark reset (migrate:fresh --seed → cache clear → restart)
#   switch-strategy <strategy> Switch cache strategy, clear caches, restart app
#   redis-stats <label> <file> Capture Redis INFO stats to file (mode-aware)
#   redis-counters             Print JSON with current Redis hits/misses (mode-aware)
#   start-resource-monitor <csv>  Start background resource monitor
#   stop-resource-monitor <pid>   Stop resource monitor by PID
#   container-stats <file>     Snapshot Docker container stats
#
# Designed to be invoked over SSH from a k6 VPS.
# All subcommands return non-zero on failure.
# ============================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
REDIS_LIB="${SCRIPT_DIR}/lib/redis-mode-check.sh"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

# ─────────────────────────────────────────────
# Guard: require librarise
# ─────────────────────────────────────────────
if [ ! -f "$REDIS_LIB" ]; then
  echo "Error: Redis mode verification library not found: ${REDIS_LIB}" >&2
  exit 1
fi
source "$REDIS_LIB"

# ─────────────────────────────────────────────
# Help
# ─────────────────────────────────────────────
usage() {
  echo "Usage: $(basename "$0") <subcommand> [options]"
  echo ""
  echo "Subcommands:"
  echo "  preflight                         Validate LMS host readiness"
  echo "  prepare [--base-url URL] [--cluster]  Full benchmark reset"
  echo "  switch-strategy <strategy>        Switch cache strategy"
  echo "  redis-stats <label> <output-file> Capture Redis INFO stats"
  echo "  redis-counters                    Print JSON hits/misses"
  echo "  start-resource-monitor <csv>      Start background resource monitor"
  echo "  stop-resource-monitor <pid>       Stop resource monitor"
  echo "  container-stats <output-file>     Snapshot Docker container stats"
  echo "  cluster-stats <output-file>       Capture Redis Cluster topology and keyspace"
  echo ""
  echo "Available strategies: cache-aside | read-through | write-through | no-cache"
  echo ""
  echo "Environment variables:"
  echo "  CLUSTER_MODE=true        Enable Redis Cluster mode (default: false)"
  echo "  SKIP_PAGE_CACHE=true     Skip OS page cache clear (default: clear and fail on error)"
  echo "  SKIP_MYSQL_RESTART=true  Skip MySQL restart (default: restart)"
}

# ─────────────────────────────────────────────
# Subcommand: preflight
# ─────────────────────────────────────────────
cmd_preflight() {
  local exit_code=0

  echo "[preflight] Checking LMS host readiness..."
  echo ""

  # 1. Project directory exists
  echo -n "[preflight] Project directory: "
  if [ -d "${PROJECT_DIR}" ] && [ -f "${PROJECT_DIR}/artisan" ]; then
    echo -e "${GREEN}OK${NC} (${PROJECT_DIR})"
  else
    echo -e "${RED}FAIL${NC} — not a Laravel project"
    exit_code=1
  fi

  # 2. Docker Compose available
  echo -n "[preflight] Docker Compose: "
  if docker compose version &>/dev/null; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  # 3. Docker services running
  echo -n "[preflight] App container: "
  if docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "app"; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  # 4. App reachable via health endpoint or artisan
  echo -n "[preflight] App responds: "
  if docker compose exec -T app php artisan --version &>/dev/null; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  # 5. Database reachable
  echo -n "[preflight] Database: "
  if docker compose exec -T app php artisan db:show --quiet 2>/dev/null; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  # 6. Cache strategy binding resolves
  echo -n "[preflight] CacheStrategyInterface: "
  local strategy_class
  strategy_class=$(docker compose exec -T app php artisan tinker \
    --execute="echo app(\App\Contracts\CacheStrategyInterface::class)::class;" 2>/dev/null | tr -d '\r\n ')
  if [ -n "${strategy_class}" ]; then
    echo -e "${GREEN}OK${NC} (${strategy_class})"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  # 7. Fixture generator command exists
  echo -n "[preflight] Fixture generator: "
  if docker compose exec -T app php artisan list --format=json 2>/dev/null \
    | grep -q "benchmark:generate-k6-fixtures"; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  # 8. Redis mode
  echo -n "[preflight] Redis mode: "
  if [ "${CLUSTER_MODE:-false}" = "true" ]; then
    echo -n "cluster → "
    if require_redis_cluster "${PROJECT_DIR}" &>/dev/null; then
      echo -e "${GREEN}OK${NC}"
    else
      echo -e "${RED}FAIL${NC}"
      exit_code=1
    fi
  else
    echo -n "single → "
    if require_redis_single "${PROJECT_DIR}" &>/dev/null; then
      echo -e "${GREEN}OK${NC}"
    else
      echo -e "${RED}FAIL${NC}"
      exit_code=1
    fi
  fi

  # 9. Page cache policy
  # Uses narrow sudo commands matching the sudoers contract:
  #   fahri ALL=(root) NOPASSWD: /usr/bin/sync, /usr/bin/tee
  # Does NOT require full passwordless sudo (sudo -n true would fail with restricted sudoers).
  echo -n "[preflight] OS page cache: "
  if [ "${SKIP_PAGE_CACHE:-false}" = "true" ]; then
    echo -e "${YELLOW}SKIPPED (explicit)${NC}"
    echo "PAGE_CACHE_RESULT=skipped-explicit"
  else
    if sudo -n sync && echo 3 | sudo -n tee /proc/sys/vm/drop_caches >/dev/null 2>&1; then
      echo -e "${GREEN}CLEARED${NC}"
      echo "PAGE_CACHE_RESULT=pass"
    else
      echo -e "${RED}FAILED — sync or tee not available via sudo -n; check sudoers for /usr/bin/sync and /usr/bin/tee${NC}"
      echo "PAGE_CACHE_RESULT=fail"
      exit_code=1
    fi
  fi

  # 10. Result/artifact directory writable
  local remote_artifact_dir="${REMOTE_ARTIFACT_DIR:-/tmp/lms-benchmark-artifacts}"
  mkdir -p "${remote_artifact_dir}"
  echo -n "[preflight] Artifact dir (${remote_artifact_dir}): "
  if [ -w "${remote_artifact_dir}" ]; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  echo ""
  if [ $exit_code -eq 0 ]; then
    echo -e "${GREEN}[preflight] ✓ All LMS checks passed${NC}"
  else
    echo -e "${RED}[preflight] ✗ Some checks failed${NC}"
  fi

  return $exit_code
}

# ─────────────────────────────────────────────
# Subcommand: prepare
# ─────────────────────────────────────────────
cmd_prepare() {
  local base_url=""
  local cluster_flag=""

  while [ $# -gt 0 ]; do
    case "$1" in
      --base-url) base_url="$2"; shift 2 ;;
      --base-url=*) base_url="${1#*=}"; shift ;;
      --cluster) cluster_flag="true"; shift ;;
      *) echo "Unknown option: $1"; return 1 ;;
    esac
  done

  base_url="${base_url:-http://localhost}"

  echo "[prepare] Full benchmark reset starting..."
  echo "[prepare] Base URL: ${base_url}"
  echo "[prepare] Cluster mode: ${cluster_flag:-false}"

  cd "${PROJECT_DIR}"

  # Load environment variables (for DB_PASSWORD, etc.)
  if [ -f ".env" ]; then
    set -a
    source ".env"
    set +a
  fi

  # 1. Verify Docker containers
  echo "[prepare] Checking Docker containers..."
  if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "app"; then
    echo -e "${RED}[prepare] Error: 'app' container not running${NC}" >&2
    return 1
  fi

  # 2. Verify database
  echo "[prepare] Verifying database..."
  if ! docker compose exec -T app php artisan db:show --quiet 2>/dev/null; then
    echo -e "${RED}[prepare] Error: database not accessible${NC}" >&2
    return 1
  fi

  # 3. Run migrate:fresh --seed
  echo "[prepare] Running migrate:fresh --seed..."
  if ! docker compose exec -T app php artisan migrate:fresh --seed --force; then
    echo -e "${RED}[prepare] Error: migrate:fresh --seed failed${NC}" >&2
    return 1
  fi
  echo -e "${GREEN}[prepare] Database reset and seeded${NC}"

  # 4. Clear Laravel caches
  echo "[prepare] Clearing Laravel caches..."
  docker compose exec -T app php artisan cache:clear  --quiet 2>/dev/null || true
  docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true
  docker compose exec -T app php artisan route:clear  --quiet 2>/dev/null || true
  docker compose exec -T app php artisan view:clear   --quiet 2>/dev/null || true

  # 5. Flush Redis (mode-aware)
  echo "[prepare] Flushing Redis..."
  if [ "${cluster_flag:-false}" = "true" ]; then
    for node in redis-c1 redis-c2 redis-c3 redis-c4 redis-c5 redis-c6; do
      if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
        docker compose exec -T "$node" redis-cli FLUSHALL >/dev/null 2>&1 || {
          echo -e "${RED}[prepare] FLUSHALL failed on ${node}${NC}" >&2
          return 1
        }
      else
        echo -e "${RED}[prepare] Cluster node ${node} not running${NC}" >&2
        return 1
      fi
    done
  else
    docker compose exec -T redis redis-cli PING >/dev/null 2>&1 || {
      echo -e "${RED}[prepare] Redis not reachable${NC}" >&2
      return 1
    }
    docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || {
      echo -e "${RED}[prepare] FLUSHALL failed${NC}" >&2
      return 1
    }
  fi
  echo -e "${GREEN}[prepare] Redis flushed${NC}"

  # 6. Restart MySQL (reset InnoDB buffer pool)
  if [ "${SKIP_MYSQL_RESTART:-false}" != "true" ]; then
    echo "[prepare] Restarting MySQL..."
    docker compose restart mysql
    echo "[prepare] Waiting for MySQL..."
    local mysql_ready=0
    for i in $(seq 1 30); do
      if docker compose exec -T mysql mysqladmin ping -h localhost -p"${DB_PASSWORD:-password}" --silent --connect-timeout=3 2>/dev/null; then
        mysql_ready=1
        break
      fi
      sleep 1
    done
    if [ $mysql_ready -eq 0 ]; then
      echo -e "${RED}[prepare] MySQL did not start after restart${NC}" >&2
      return 1
    fi
    echo -e "${GREEN}[prepare] MySQL ready${NC}"
  fi

  # 7. Clear OS page cache
  # Policy: default requires clear; SKIP_PAGE_CACHE=true is the explicit escape hatch.
  # Uses narrow sudo commands compatible with passwordless sudo: sync + tee
  if [ "${SKIP_PAGE_CACHE:-false}" = "true" ]; then
    echo -e "${YELLOW}[prepare] OS page cache: skipped (SKIP_PAGE_CACHE=true)${NC}"
  else
    echo "[prepare] Clearing OS page cache..."
    if sudo -n sync && echo 3 | sudo -n tee /proc/sys/vm/drop_caches >/dev/null 2>&1; then
      echo -e "${GREEN}[prepare] OS page cache cleared${NC}"
    else
      echo -e "${RED}[prepare] Failed to clear OS page cache — check sudoers for /usr/bin/sync and /usr/bin/tee${NC}"
      return 1
    fi
  fi

  # 8. Restart app and nginx
  echo "[prepare] Restarting app container..."
  docker compose restart app
  echo "[prepare] Restarting nginx..."
  docker compose restart nginx

  # Wait for app
  echo "[prepare] Waiting 30s for app to be ready..."
  sleep 30

  # 9. Verify seed counts
  echo "[prepare] Verifying seed data..."
  local user_count course_count
  user_count=$(docker compose exec -T app php artisan tinker \
    --execute="echo \App\Models\User::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1 || echo "0")
  course_count=$(docker compose exec -T app php artisan tinker \
    --execute="echo \App\Models\Course::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1 || echo "0")
  echo "  Users:   ${user_count:-0}"
  echo "  Courses: ${course_count:-0}"

  if [ "${user_count:-0}" -lt 4000 ] 2>/dev/null; then
    echo -e "${RED}[prepare] Seed verification failed: low user count${NC}" >&2
    return 1
  fi

  # 10. Generate k6 fixtures
  echo "[prepare] Generating k6 fixtures..."
  if ! docker compose exec -T app php artisan benchmark:generate-k6-fixtures --quiet; then
    echo -e "${RED}[prepare] Fixture generation failed${NC}" >&2
    return 1
  fi
  echo -e "${GREEN}[prepare] k6 fixtures generated${NC}"

  # 11. Verify all cache strategies resolve
  echo "[prepare] Verifying cache strategies..."
  local strategies=("no-cache" "cache-aside" "read-through" "write-through")
  local original_strategy
  original_strategy=$(grep "^CACHE_STRATEGY=" "${PROJECT_DIR}/.env" | cut -d'=' -f2 | tr -d '\r ' || echo "no-cache")
  local verify_failed=0

  for strategy in "${strategies[@]}"; do
    sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${strategy}/" "${PROJECT_DIR}/.env"
    docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true
    local strategy_class
    strategy_class=$(docker compose exec -T app php artisan tinker \
      --execute="echo app(\App\Contracts\CacheStrategyInterface::class)::class;" 2>/dev/null | tr -d '\r\n ')
    if [ -n "${strategy_class}" ]; then
      echo -e "  ${GREEN}✓ ${strategy}${NC} (${strategy_class})"
    else
      echo -e "  ${RED}✗ ${strategy}${NC}"
      verify_failed=1
    fi
  done

  # Restore
  sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${original_strategy}/" "${PROJECT_DIR}/.env"
  docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true

  if [ "${verify_failed}" -ne 0 ]; then
    echo -e "${RED}[prepare] Some cache strategies failed to resolve${NC}" >&2
    return 1
  fi

  echo -e "${GREEN}[prepare] ✓ Benchmark preparation complete${NC}"
  return 0
}

# ─────────────────────────────────────────────
# Subcommand: switch-strategy
# ─────────────────────────────────────────────
cmd_switch_strategy() {
  local strategy="${1:-}"

  if [ -z "${strategy}" ]; then
    echo "Error: strategy required (cache-aside | read-through | write-through | no-cache)" >&2
    return 1
  fi

  case "${strategy}" in
    cache-aside|read-through|write-through|no-cache) ;;
    *) echo "Error: invalid strategy '${strategy}'" >&2; return 1 ;;
  esac

  echo "[switch-strategy] Switching to: ${strategy}"

  cd "${PROJECT_DIR}"

  # Update .env
  if [ ! -f ".env" ]; then
    echo "Error: .env not found" >&2
    return 1
  fi

  cp ".env" ".env.backup"
  if grep -q "^CACHE_STRATEGY=" ".env"; then
    sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${strategy}/" ".env"
  else
    echo "CACHE_STRATEGY=${strategy}" >> ".env"
  fi

  # Clear Laravel caches
  docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true
  docker compose exec -T app php artisan cache:clear  --quiet 2>/dev/null || true

  # Restart app (CacheStrategyInterface is a singleton — config:clear alone is not enough)
  docker compose restart app
  sleep 15

  # Verify strategy resolves (check class name is returned)
  local strategy_class
  strategy_class=$(docker compose exec -T app php artisan tinker \
    --execute="echo app(\App\Contracts\CacheStrategyInterface::class)::class;" 2>/dev/null | tr -d '\r\n ')
  if [ -z "${strategy_class}" ]; then
    echo -e "${RED}[switch-strategy] Strategy '${strategy}' did not resolve${NC}" >&2
    return 1
  fi

  echo -e "${GREEN}[switch-strategy] ✓ Switched to ${strategy}${NC}"
  return 0
}

# ─────────────────────────────────────────────
# Subcommand: redis-stats
# ─────────────────────────────────────────────
cmd_redis_stats() {
  local label="${1:?Error: label required (e.g. 'before' or 'after')}"
  local output_file="${2:?Error: output file path required}"

  mkdir -p "$(dirname "${output_file}")"

  echo "=== Redis INFO stats (${label}) - $(date -Iseconds)" > "${output_file}"

  cd "${PROJECT_DIR}"

  if [ "${CLUSTER_MODE:-false}" = "true" ]; then
    for node in redis-c1 redis-c2 redis-c3 redis-c4 redis-c5 redis-c6; do
      echo "--- Node: ${node} ---" >> "${output_file}"
      docker compose exec -T "$node" redis-cli INFO stats >> "${output_file}" || return 1
      docker compose exec -T "$node" redis-cli INFO memory >> "${output_file}" || return 1
      docker compose exec -T "$node" redis-cli CLUSTER INFO >> "${output_file}" || return 1
      echo "" >> "${output_file}"
    done
  else
    docker compose exec -T redis redis-cli INFO stats >> "${output_file}" || return 1
    docker compose exec -T redis redis-cli INFO memory >> "${output_file}" || return 1
  fi

  echo "" >> "${output_file}"
  echo -e "${GREEN}[redis-stats] Stats written to ${output_file}${NC}"
}

# ─────────────────────────────────────────────
# Subcommand: redis-counters
#
# Returns JSON with current hits and misses.
# Cluster mode sums across all nodes.
# ─────────────────────────────────────────────
cmd_redis_counters() {
  local hits=0
  local misses=0
  local unreachable=0

  cd "${PROJECT_DIR}"

  if [ "${CLUSTER_MODE:-false}" = "true" ]; then
    for node in redis-c1 redis-c2 redis-c3 redis-c4 redis-c5 redis-c6; do
      local node_stats
      node_stats=$(docker compose exec -T "$node" redis-cli INFO stats 2>/dev/null) || {
        echo "[redis-counters] ERROR: Node ${node} unreachable" >&2
        unreachable=1
        continue
      }
      local node_hits node_misses
      node_hits=$(echo "${node_stats}" | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r ')
      node_misses=$(echo "${node_stats}" | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ')
      hits=$(( hits + node_hits + 0 ))
      misses=$(( misses + node_misses + 0 ))
    done
  else
    local stats
    stats=$(docker compose exec -T redis redis-cli INFO stats 2>/dev/null) || {
      echo "[redis-counters] ERROR: Redis unreachable" >&2
      echo '{"hits":0,"misses":0}'
      return 1
    }
    hits=$(echo "${stats}" | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r ')
    misses=$(echo "${stats}" | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ')
  fi

  if [ "${unreachable}" -ne 0 ]; then
    echo "[redis-counters] ERROR: One or more cluster nodes unreachable" >&2
    echo '{"hits":0,"misses":0}'
    return 1
  fi

  hits=${hits:-0}
  misses=${misses:-0}

  echo "{\"hits\":${hits},\"misses\":${misses}}"
}

# ─────────────────────────────────────────────
# Subcommand: start-resource-monitor
#
# Background process that writes CSV with CPU%, Memory, Disk I/O every 5s.
# Output PID to stdout for later use with stop-resource-monitor.
# ─────────────────────────────────────────────
cmd_start_resource_monitor() {
  local output_csv="${1:?Error: output CSV path required}"
  local pid_file="/tmp/.lms-resource-monitor-pid-$(basename "${output_csv}" .csv)"

  mkdir -p "$(dirname "${output_csv}")"

  cat > /tmp/lms-resource-monitor.sh << 'MONEOF'
#!/bin/bash
# Resource monitor script — runs until killed
OUTPUT_CSV="$1"
shift

echo "timestamp,cpu_pct,mem_used_mb,mem_total_mb,mem_used_pct,disk_read_mb_s,disk_write_mb_s" > "${OUTPUT_CSV}"

while true; do
  ts=$(date +%s)

  # CPU% from /proc/stat delta (primary path, avoids top formatting fragility)
  # idle_all = idle + iowait
  # non_idle = user + nice + system + irq + softirq + steal
  # total = idle_all + non_idle
  # cpu_pct = (delta_total - delta_idle) * 100 / delta_total
  read -r _ user1 nice1 system1 idle1 iowait1 irq1 softirq1 steal1 _ < /proc/stat
  sleep 1
  read -r _ user2 nice2 system2 idle2 iowait2 irq2 softirq2 steal2 _ < /proc/stat
  cpu_pct=$(awk -v u1="$user1" -v n1="$nice1" -v s1="$system1" -v id1="$idle1" -v io1="$iowait1" -v ir1="$irq1" -v sr1="$softirq1" -v st1="$steal1" \
                -v u2="$user2" -v n2="$nice2" -v s2="$system2" -v id2="$idle2" -v io2="$iowait2" -v ir2="$irq2" -v sr2="$softirq2" -v st2="$steal2" \
                'BEGIN {
                  idle1 = id1 + io1; idle2 = id2 + io2
                  total1 = id1 + io1 + u1 + n1 + s1 + ir1 + sr1 + st1
                  total2 = id2 + io2 + u2 + n2 + s2 + ir2 + sr2 + st2
                  dtotal = total2 - total1
                  didle = idle2 - idle1
                  if (dtotal > 0) printf "%.1f", (dtotal - didle) * 100 / dtotal
                  else printf "0"
                }' 2>/dev/null || echo "0")

  # Memory from free -m
  read -r _ mem_total mem_used _ < <(free -m | grep "^Mem:" 2>/dev/null)
  mem_pct=$(echo "scale=1; ${mem_used} * 100 / ${mem_total}" | bc 2>/dev/null || echo "0")

  # Disk I/O
  disk_read="0" disk_write="0"
  if command -v iostat &>/dev/null; then
    # Use iostat -m 1 2: first sample is boot average, second is real interval
    iostat_line=$(iostat -d -m 1 2 2>/dev/null | grep -E "^(sda|vda|nvme|xvda|sdb|vdb|dm-)" | tail -1)
    disk_read=$(echo  "${iostat_line}" | awk '{print $3}' | tr -d ',' || echo "0")
    disk_write=$(echo "${iostat_line}" | awk '{print $4}' | tr -d ',' || echo "0")
    disk_read=${disk_read:-0}
    disk_write=${disk_write:-0}
  else
    # Fallback: /proc/diskstats delta
    stats_line1=$(grep -E "\s+(sda|vda|nvme0n1|xvda|dm-0)\s" /proc/diskstats 2>/dev/null | head -1)
    if [ -n "${stats_line1}" ]; then
      sec_r1=$(echo "${stats_line1}" | awk '{print $6}'); sec_w1=$(echo "${stats_line1}" | awk '{print $10}')
      sleep 1
      stats_line2=$(grep -E "\s+(sda|vda|nvme0n1|xvda|dm-0)\s" /proc/diskstats 2>/dev/null | head -1)
      sec_r2=$(echo "${stats_line2}" | awk '{print $6}'); sec_w2=$(echo "${stats_line2}" | awk '{print $10}')
      d_r=$(( sec_r2 - sec_r1 )) d_w=$(( sec_w2 - sec_w1 ))
      disk_read=$(echo "scale=2; ${d_r} * 512 / 1048576" | bc 2>/dev/null || echo "0")
      disk_write=$(echo "scale=2; ${d_w} * 512 / 1048576" | bc 2>/dev/null || echo "0")
    fi
  fi

  echo "${ts},${cpu_pct},${mem_used},${mem_total},${mem_pct},${disk_read},${disk_write}" >> "${OUTPUT_CSV}"
  sleep 5
done
MONEOF

  chmod +x /tmp/lms-resource-monitor.sh

  # Double-fork + full detach: SSH cannot block on this child
  (
    /tmp/lms-resource-monitor.sh "${output_csv}" </dev/null >/dev/null 2>&1 &
    local bg_pid=$!
    echo "${bg_pid}" > "${pid_file}"
  ) &
  wait $! 2>/dev/null || true

  local pid
  pid=$(cat "${pid_file}" 2>/dev/null || echo "")
  echo "${pid}"
  echo -e "${GREEN}[resource-monitor] Started (PID ${pid}), writing to ${output_csv}${NC}" >&2
}

# ─────────────────────────────────────────────
# Subcommand: stop-resource-monitor
# ─────────────────────────────────────────────
cmd_stop_resource_monitor() {
  local pid="${1:-}"
  local pid_file="${2:-}"

  # If PID not provided, try reading from pid_file
  if [ -z "${pid}" ] && [ -n "${pid_file}" ]; then
    pid=$(cat "${pid_file}" 2>/dev/null || echo "")
  fi

  if [ -z "${pid}" ]; then
    # Try to find any lms-resource-monitor process
    pid=$(pgrep -f "lms-resource-monitor" 2>/dev/null | head -1 || echo "")
  fi

  if [ -z "${pid}" ]; then
    echo -e "${YELLOW}[resource-monitor] No running resource monitor found${NC}"
    return 0
  fi

  if kill -0 "${pid}" 2>/dev/null; then
    kill "${pid}" 2>/dev/null
    wait "${pid}" 2>/dev/null || true
    echo -e "${GREEN}[resource-monitor] Stopped PID ${pid}${NC}"
  else
    echo -e "${YELLOW}[resource-monitor] PID ${pid} not running${NC}"
  fi
}

# ─────────────────────────────────────────────
# Subcommand: container-stats
# ─────────────────────────────────────────────
cmd_container_stats() {
  local output_file="${1:?Error: output file path required}"

  mkdir -p "$(dirname "${output_file}")"

  docker stats --no-stream 2>/dev/null > "${output_file}" || \
    echo "(docker stats not available)" > "${output_file}"

  echo -e "${GREEN}[container-stats] Written to ${output_file}${NC}"
}

# ─────────────────────────────────────────────
# Subcommand: cluster-stats
#
# Capture Redis Cluster topology and keyspace info.
# Matches the local run-benchmark.sh cluster-stats.txt format.
# Only meaningful when CLUSTER_MODE=true.
# ─────────────────────────────────────────────
cmd_cluster_stats() {
  local output_file="${1:?Error: output file path required}"
  local cluster_nodes=("redis-c1" "redis-c2" "redis-c3" "redis-c4" "redis-c5" "redis-c6")

  mkdir -p "$(dirname "${output_file}")"

  {
    echo "=== Cluster Stats - $(date -Iseconds) ==="
    echo ""
    for node in "${cluster_nodes[@]}"; do
      if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
        echo "--- ${node} ---"
        docker compose exec -T "$node" redis-cli CLUSTER INFO 2>/dev/null || echo "(CLUSTER INFO unavailable)"
        echo ""
        docker compose exec -T "$node" redis-cli CLUSTER NODES 2>/dev/null || echo "(CLUSTER NODES unavailable)"
        echo ""
        docker compose exec -T "$node" redis-cli INFO keyspace 2>/dev/null || echo "(INFO keyspace unavailable)"
        echo ""
      else
        echo "--- ${node} ---"
        echo "(not running)"
        echo ""
      fi
    done
  } > "${output_file}"

  echo -e "${GREEN}[cluster-stats] Written to ${output_file}${NC}"
}

# ─────────────────────────────────────────────
# Main dispatcher
# ─────────────────────────────────────────────
main() {
  if [ $# -eq 0 ]; then
    usage
    exit 0
  fi

  local subcommand="$1"
  shift

  case "${subcommand}" in
    preflight)
      cmd_preflight "$@"
      ;;
    prepare)
      cmd_prepare "$@"
      ;;
    switch-strategy)
      cmd_switch_strategy "$@"
      ;;
    redis-stats)
      cmd_redis_stats "$@"
      ;;
    redis-counters)
      cmd_redis_counters "$@"
      ;;
    start-resource-monitor)
      cmd_start_resource_monitor "$@"
      ;;
    stop-resource-monitor)
      cmd_stop_resource_monitor "$@"
      ;;
    container-stats)
      cmd_container_stats "$@"
      ;;
    cluster-stats)
      cmd_cluster_stats "$@"
      ;;
    -h|--help)
      usage
      ;;
    *)
      echo "Error: unknown subcommand '${subcommand}'" >&2
      usage
      exit 1
      ;;
  esac
}

main "$@"
