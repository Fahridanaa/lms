#!/bin/bash

# ============================================================
# LMS Caching Strategy Benchmark Runner
#
# Usage  : ./scripts/run-benchmark.sh [--cluster] [strategy] [scenario] [base_url]
# Contoh :
#   ./scripts/run-benchmark.sh cache-aside read-heavy http://localhost
#   ./scripts/run-benchmark.sh --cluster cache-aside read-heavy http://localhost
#
# --cluster  : Jalankan benchmark terhadap Redis Cluster (3 masters + 3 replicas)
#              Pastikan cluster sudah up: ./scripts/setup-redis-cluster.sh up
#
# Script ini menjalankan k6 untuk satu strategi + satu skenario,
# berulang untuk 7 level concurrent users sesuai proposal:
#   100, 250, 500, 750, 1000, 1500, 2000
#
# Metrik yang dikumpulkan per run:
#   - k6 summary JSON  : response time (avg/min/max/p90/p95/p99), throughput, error rate
#   - resources CSV    : CPU%, Memory (MB), Disk I/O (MB/s) setiap 10 detik
#                        menggunakan top, free, iostat (sesuai proposal: htop & iostat)
#   - redis stats      : keyspace hits/misses sebelum & sesudah → cache hit ratio
#   - cluster stats    : (jika --cluster) per-node cluster info, keyspace, nodes
# ============================================================

CLUSTER_MODE=false

# Parse --cluster flag (before positional args)
while [ $# -gt 0 ]; do
  case "$1" in
    --cluster)
      CLUSTER_MODE=true
      shift
      ;;
    -*)
      echo -e "${RED}Error: Unknown option $1${NC}"
      exit 1
      ;;
    *)
      break
      ;;
  esac
done

STRATEGY=${1:-cache-aside}
SCENARIO=${2:-read-heavy}
BASE_URL=${3:-http://localhost}
ITERATION=${4:-1}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
K6_SCRIPT="${PROJECT_DIR}/tests/Benchmark/k6/${SCENARIO}-scenario.js"

# VU levels — configurable via VU_LEVELS env var
if [ -n "${VU_LEVELS+x}" ]; then
  IFS=' ' read -ra CONCURRENT_USERS_LEVELS <<< "${VU_LEVELS}"
else
  CONCURRENT_USERS_LEVELS=(100 250 500 750 1000 1500 2000)
fi

# Validate VU values: must be numeric and positive
valid_vu_levels=()
for v in "${CONCURRENT_USERS_LEVELS[@]}"; do
  if ! [[ "$v" =~ ^[1-9][0-9]*$ ]]; then
    echo -e "${RED}Error: Invalid VU level '${v}' — must be a positive integer.${NC}"
    exit 1
  fi
  valid_vu_levels+=("$v")
done
CONCURRENT_USERS_LEVELS=("${valid_vu_levels[@]}")

if [ ${#CONCURRENT_USERS_LEVELS[@]} -eq 0 ]; then
  echo -e "${RED}Error: VU_LEVELS is empty — at least one VU level required.${NC}"
  exit 1
fi

CLUSTER_NODES=("redis-c1" "redis-c2" "redis-c3" "redis-c4" "redis-c5" "redis-c6")

if [ "${CLUSTER_MODE}" = "true" ]; then
  RESULTS_DIR="${PROJECT_DIR}/benchmark-results-cluster"
else
  RESULTS_DIR="${PROJECT_DIR}/benchmark-results"
fi

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Source shared Redis mode verification library
REDIS_LIB="${SCRIPT_DIR}/lib/redis-mode-check.sh"
if [ -f "$REDIS_LIB" ]; then
  source "$REDIS_LIB"
else
  echo -e "${RED}Error: Redis mode verification library not found: ${REDIS_LIB}${NC}"
  exit 1
fi

# ─────────────────────────────────────────────
# Validasi input + required tools
# ─────────────────────────────────────────────
validate_inputs() {
  case $STRATEGY in
    cache-aside|read-through|write-through|no-cache) ;;
    *)
      echo -e "${RED}Error: Strategi '${STRATEGY}' tidak valid.${NC}"
      echo "Pilihan: cache-aside | read-through | write-through | no-cache"
      exit 1 ;;
  esac

  case $SCENARIO in
    read-heavy|write-heavy) ;;
    *)
      echo -e "${RED}Error: Skenario '${SCENARIO}' tidak valid.${NC}"
      echo "Pilihan: read-heavy | write-heavy"
      exit 1 ;;
  esac

  if [ ! -f "$K6_SCRIPT" ]; then
    echo -e "${RED}Error: k6 script tidak ditemukan: ${K6_SCRIPT}${NC}"
    exit 1
  fi

  # Check required tools
  local missing_tools=()
  for tool in k6 bc curl docker jq node; do
    if ! command -v "$tool" &>/dev/null; then
      missing_tools+=("$tool")
    fi
  done

  if ! docker compose version &>/dev/null; then
    missing_tools+=("docker compose")
  fi

  if [ ${#missing_tools[@]} -gt 0 ]; then
    echo -e "${RED}Error: Missing required tools: ${missing_tools[*]}${NC}"
    # Print install guidance
    for tool in "${missing_tools[@]}"; do
      case "$tool" in
        "jq") echo -e "${YELLOW}  Install jq: apt-get install jq${NC}" ;;
        "node"*) echo -e "${YELLOW}  Install Node.js: https://nodejs.org/  or  apt-get install nodejs${NC}" ;;
        "k6") echo -e "${YELLOW}  Install k6: https://k6.io/docs/get-started/installation/${NC}" ;;
        "bc") echo -e "${YELLOW}  Install bc: apt-get install bc${NC}" ;;
        "curl") echo -e "${YELLOW}  Install curl: apt-get install curl${NC}" ;;
        "docker"*) echo -e "${YELLOW}  Install Docker: https://docs.docker.com/engine/install/${NC}" ;;
      esac
    done
    exit 1
  fi

  # Optional tools — warn but continue
  for tool in iostat python3; do
    if ! command -v "$tool" &>/dev/null; then
      echo -e "${YELLOW}Warning: ${tool} tidak ditemukan.${NC}"
      case "$tool" in
        iostat) echo -e "${YELLOW}  Install: apt-get install sysstat (disk I/O tidak akan dimonitor)${NC}" ;;
        python3) echo -e "${YELLOW}  Beberapa analisis mungkin tidak tersedia.${NC}" ;;
      esac
    fi
  done

  # ─────────────────────────────────────────────
  # Redis mode verification (shared library)
  # ─────────────────────────────────────────────
  if [ "${CLUSTER_MODE}" = "true" ]; then
    require_redis_cluster "${PROJECT_DIR}"
    local rc=$?
    if [ $rc -ne 0 ]; then
      exit $rc
    fi
  else
    require_redis_single "${PROJECT_DIR}"
    local rc=$?
    if [ $rc -ne 0 ]; then
      exit $rc
    fi
  fi
}

# ─────────────────────────────────────────────
# Switch strategi caching di .env
# ─────────────────────────────────────────────
switch_strategy() {
  echo -e "${YELLOW}[setup] Switching strategi ke: ${STRATEGY}${NC}"
  "${SCRIPT_DIR}/switch-strategy.sh" "${STRATEGY}"
  echo -e "${GREEN}[setup] Strategi berhasil di-switch.${NC}"
}

# ─────────────────────────────────────────────
# Bersihkan cache sebelum mulai
# ─────────────────────────────────────────────
clear_all_caches() {
  echo -e "${YELLOW}[setup] Membersihkan cache...${NC}"
  cd "${PROJECT_DIR}"
  docker compose exec -T app php artisan cache:clear  --quiet 2>/dev/null || true
  docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true

  if [ "${CLUSTER_MODE}" = "true" ]; then
    # FLUSHALL on all cluster nodes only
    for node in "${CLUSTER_NODES[@]}"; do
      if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
        docker compose exec -T "$node" redis-cli FLUSHALL >/dev/null 2>&1 || {
          echo -e "${RED}[setup] Failed to FLUSHALL ${node}${NC}"
          return 1
        }
      else
        echo -e "${RED}[setup] Cluster node ${node} is not running${NC}"
        return 1
      fi
    done
  else
    # FLUSHALL on single Redis only — fail if unreachable
    if ! docker compose exec -T redis redis-cli PING >/dev/null 2>&1; then
      echo -e "${RED}[setup] Single Redis not reachable (PING failed)${NC}"
      return 1
    fi
    docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || {
      echo -e "${RED}[setup] Single Redis FLUSHALL failed${NC}"
      return 1
    }
  fi
  echo -e "${GREEN}[setup] Cache bersih.${NC}"
}

# ─────────────────────────────────────────────
# Generate warm-up targets from k6 fixtures
# ─────────────────────────────────────────────
generate_warmup_targets() {
  local targets_file="${SCRIPT_DIR}/lib/warmup-targets.json"
  local tmp_file="${targets_file}.tmp.$$"

  >&2 echo -e "${YELLOW}[warm-up] Generating fresh warm-up targets from k6 fixtures...${NC}"
  node "${SCRIPT_DIR}/generate-warmup-targets.cjs" "${tmp_file}"
  local rc=$?

  if [ $rc -ne 0 ]; then
    >&2 echo -e "${RED}[warm-up] Error: target generation failed.${NC}"
    rm -f "${tmp_file}"
    return 1
  fi

  mv "${tmp_file}" "${targets_file}"
  >&2 echo -e "${GREEN}[warm-up] Targets refreshed: ${targets_file}${NC}"
  echo "${targets_file}"
}

# ─────────────────────────────────────────────
# Pick a random element from an array in the warmup JSON
# Uses jq (required, checked in preflight)
# ─────────────────────────────────────────────
_warmup_pick() {
  local json_file=$1
  local key=$2
  local count
  count=$(jq -r "${key} | length" "${json_file}" 2>/dev/null)
  if [ -z "$count" ] || [ "$count" -eq 0 ]; then
    echo "Error: ${key} is empty or not found"
    return 1
  fi
  local idx=$(( RANDOM % count ))
  jq -c "${key}[${idx}]" "${json_file}" 2>/dev/null
}

# ─────────────────────────────────────────────
# Warm-up cache (60 detik, sesuai proposal §3.3.1.2)
# Kirim request actor-aware ke endpoint utama untuk
# inisialisasi connection pool DB dan mengisi cache.
# Hasilnya tidak masuk metrik k6.
# ─────────────────────────────────────────────
warmup_cache() {
  local warmup_duration=60
  local end_time=$(( $(date +%s) + warmup_duration ))
  local req_count=0
  local fail_count=0
  local summary_file=$1  # optional: write warm-up summary here

  # Ensure warm-up targets exist
  local targets_file
  targets_file=$(generate_warmup_targets)
  if [ $? -ne 0 ]; then
    return 1
  fi

  # Validate captured path is a real file
  if [ ! -f "${targets_file}" ]; then
    >&2 echo -e "${RED}[warm-up] Error: generated target path is not a file: ${targets_file}${NC}"
    return 1
  fi

  # Log target file metadata
  local generated_at
  generated_at=$(jq -r '._generatedAt // "unknown"' "${targets_file}" 2>/dev/null)
  echo -e "${CYAN}[warm-up] Target file: ${targets_file} (generated: ${generated_at})${NC}"

  local enrolled_count
  enrolled_count=$(jq -r 'ENROLLED_PAIRS | length' "${targets_file}" 2>/dev/null || echo "0")
  if [ "$enrolled_count" -eq 0 ]; then
    if [ "${ALLOW_BASIC_WARMUP:-}" = "yes" ]; then
      echo -e "${YELLOW}[warm-up] Empty ENROLLED_PAIRS — falling back to basic warm-up (ALLOW_BASIC_WARMUP=yes)${NC}"
      _fallback_warmup "${warmup_duration}"
      return
    fi
    echo -e "${RED}[warm-up] ✗ ENROLLED_PAIRS is empty. Cannot perform actor-aware warm-up.${NC}"
    echo -e "${RED}         Regenerate targets: node scripts/generate-warmup-targets.cjs${NC}"
    echo -e "${RED}         Force basic warm-up: ALLOW_BASIC_WARMUP=yes${NC}"
    return 1
  fi

  # Build a temp file for tracking statuses
  local warmup_log
  warmup_log=$(mktemp /tmp/warmup-log-XXXXXX)

  echo -e "${YELLOW}[warm-up] Actor-aware warm-up, duration=${warmup_duration}s, targets=${enrolled_count} enrolled pairs...${NC}"

  # Declare endpoint categories with weights
  # Read-heavy warms: structure, materials, quizzes, assignments, gradebooks
  # Write-heavy warms: structure + gradebooks (non-mutating reads)
  local idx=0

  while [ $(date +%s) -lt ${end_time} ]; do
    local pair
    pair=$(jq -c "ENROLLED_PAIRS[$(( idx % enrolled_count ))]" "${targets_file}" 2>/dev/null)
    if [ -z "$pair" ] || [ "$pair" = "null" ]; then
      pair=$(jq -c "ENROLLED_PAIRS[0]" "${targets_file}" 2>/dev/null)
    fi
    local student_id course_id
    student_id=$(echo "$pair" | jq -r '.studentId' 2>/dev/null)
    course_id=$(echo "$pair" | jq -r '.courseId' 2>/dev/null)

    # Cycle through activity IDs from the pair
    local mat_id
    mat_id=$(jq -r "READABLE_MATERIAL_TARGETS | map(select(.courseId == ${course_id} and .studentId == ${student_id})) | .[$(( idx % 3 ))] // empty | .activityId" "${targets_file}" 2>/dev/null || echo "")
    local quiz_id
    quiz_id=$(jq -r "READABLE_QUIZ_TARGETS | map(select(.courseId == ${course_id} and .studentId == ${student_id})) | .[0] // empty | .activityId" "${targets_file}" 2>/dev/null || echo "")
    local assign_id
    assign_id=$(jq -r "READABLE_ASSIGNMENT_TARGETS | map(select(.courseId == ${course_id} and .studentId == ${student_id})) | .[0] // empty | .activityId" "${targets_file}" 2>/dev/null || echo "")

    # Course structure (always valid for enrolled student)
    if [ -n "$course_id" ] && [ "$course_id" != "null" ]; then
      _warmup_request "GET" "${BASE_URL}/api/courses/${course_id}/structure" "${student_id}" "structure" "${warmup_log}"
      req_count=$(( req_count + 1 ))
    fi

    # Material detail
    if [ -n "$mat_id" ] && [ "$mat_id" != "null" ]; then
      _warmup_request "GET" "${BASE_URL}/api/materials/${mat_id}" "${student_id}" "material" "${warmup_log}"
      req_count=$(( req_count + 1 ))
    fi

    # Quiz detail
    if [ -n "$quiz_id" ] && [ "$quiz_id" != "null" ]; then
      _warmup_request "GET" "${BASE_URL}/api/quizzes/${quiz_id}" "${student_id}" "quiz" "${warmup_log}"
      req_count=$(( req_count + 1 ))
    fi

    # Assignment detail
    if [ -n "$assign_id" ] && [ "$assign_id" != "null" ]; then
      _warmup_request "GET" "${BASE_URL}/api/assignments/${assign_id}" "${student_id}" "assignment" "${warmup_log}"
      req_count=$(( req_count + 1 ))
    fi

    # Gradebook (use instructor, not student)
    local instructor_pair
    local instructor_count
    instructor_count=$(jq -r 'INSTRUCTOR_COURSE_PAIRS | length' "${targets_file}" 2>/dev/null || echo "0")
    if [ "$instructor_count" -gt 0 ]; then
      local inst_idx=$(( idx % instructor_count ))
      instructor_pair=$(jq -c "INSTRUCTOR_COURSE_PAIRS[${inst_idx}]" "${targets_file}" 2>/dev/null)
      if [ -n "$instructor_pair" ] && [ "$instructor_pair" != "null" ]; then
        local instructor_id inst_course_id
        instructor_id=$(echo "$instructor_pair" | jq -r '.instructorId')
        inst_course_id=$(echo "$instructor_pair" | jq -r '.courseId')
        _warmup_request "GET" "${BASE_URL}/api/courses/${inst_course_id}/gradebook" "${instructor_id}" "gradebook" "${warmup_log}"
        req_count=$(( req_count + 1 ))
      fi
    fi

    # Course completion check
    local completion_target
    completion_target=$(jq -c "COURSE_COMPLETION_CHECK_TARGETS[$(( idx % $(jq -r 'COURSE_COMPLETION_CHECK_TARGETS | length' "${targets_file}" 2>/dev/null || echo 1) ))]" "${targets_file}" 2>/dev/null)
    if [ -n "$completion_target" ] && [ "$completion_target" != "null" ]; then
      local comp_student_id comp_course_id
      comp_student_id=$(echo "$completion_target" | jq -r '.studentId')
      comp_course_id=$(echo "$completion_target" | jq -r '.courseId')
      _warmup_request "GET" "${BASE_URL}/api/courses/${comp_course_id}/completion" "${comp_student_id}" "completion" "${warmup_log}"
      req_count=$(( req_count + 1 ))
    fi

    idx=$(( idx + 1 ))

    # Small delay to avoid flooding, collect background curls periodically
    if [ $(( idx % 3 )) -eq 0 ]; then
      wait 2>/dev/null || true
    fi
    sleep 1
  done

  wait 2>/dev/null || true

  # Compute summary
  local total_success total_fail
  if [ -f "${warmup_log}" ]; then
    total_success=$(grep -c " STATUS=" "${warmup_log}" 2>/dev/null || echo "0")
    total_fail=$(grep -c " FAIL=" "${warmup_log}" 2>/dev/null || echo "0")

    echo -e "${GREEN}[warm-up] Selesai. ${req_count} requests (${total_success} ok, ${total_fail} fail)${NC}"

    # Write warm-up summary if requested
    if [ -n "${summary_file}" ]; then
      {
        echo "=== Warm-up Summary ==="
        echo "Timestamp: $(date -Iseconds)"
        echo "Total requests: ${req_count}"
        echo "Successful: ${total_success}"
        echo "Failed: ${total_fail}"
        echo ""
        echo "Status distribution:"
        grep -oP 'STATUS=\d+' "${warmup_log}" | sort | uniq -c | sort -rn
        echo ""
        echo "Endpoint distribution:"
        grep -oP 'ENDPOINT=[^ ]+' "${warmup_log}" | sort | uniq -c | sort -rn
        echo ""
        echo "Failed requests:"
        grep "FAIL=" "${warmup_log}" || echo "(none)"
      } > "${summary_file}"
      echo -e "${GREEN}[warm-up] Summary written to ${summary_file}${NC}"
    fi

    # Fail benchmark if unexpected errors exceed threshold
    if [ "${total_fail}" -gt 0 ] && [ "${total_fail}" -gt $(( req_count / 10 )) ]; then
      echo -e "${RED}[warm-up] ✗ Too many warm-up failures (${total_fail}/${req_count}). Benchmark aborted.${NC}"
      cat "${warmup_log}" 2>/dev/null | head -20
      rm -f "${warmup_log}"
      return 1
    fi
  else
    echo -e "${GREEN}[warm-up] Selesai. ~${req_count} requests dikirim ke cache.${NC}"
  fi

  rm -f "${warmup_log}"
}

# ─────────────────────────────────────────────
# Single warm-up request with actor header and status check
# ─────────────────────────────────────────────
_warmup_request() {
  local method=$1
  local url=$2
  local actor_id=$3
  local label=$4
  local log_file=$5

  local status
  status=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 \
    -H "X-Benchmark-Actor-Id: ${actor_id}" \
    "${url}" 2>/dev/null)

  if [ -n "${log_file}" ]; then
    echo "${label} ${url} STATUS=${status} ACTOR=${actor_id}" >> "${log_file}"
  fi

  if [ "$status" = "000" ] || [ -z "$status" ]; then
    if [ -n "${log_file}" ]; then
      echo "${label} ${url} FAIL=timeout ACTOR=${actor_id}" >> "${log_file}"
    fi
    return 1
  fi

  # Unexpected errors (not 2xx and not 4xx controlled failures)
  if [ "$status" -ge 500 ]; then
    if [ -n "${log_file}" ]; then
      echo "${label} ${url} FAIL=5xx ACTOR=${actor_id}" >> "${log_file}"
    fi
    return 1
  fi

  return 0
}

# ─────────────────────────────────────────────
# Fallback warm-up when fixture data unavailable
# ─────────────────────────────────────────────
_fallback_warmup() {
  local duration=$1
  local end_time=$(( $(date +%s) + duration ))
  local req_count=0
  local course_ids=(1 5 10 20 30 40 50)
  local material_ids=(1 10 50 100 200 300 400 500)
  local quiz_ids=(1 5 10 25 50 100 150 200 250)
  local idx=0

  echo -e "${YELLOW}[warm-up] Fallback warm-up (no fixture targets)${NC}"

  while [ $(date +%s) -lt ${end_time} ]; do
    local ci=${course_ids[$((idx % ${#course_ids[@]}))]}
    local mi=${material_ids[$((idx % ${#material_ids[@]}))]}
    local qi=${quiz_ids[$((idx % ${#quiz_ids[@]}))]}

    # Include actor header even in fallback (use generic student ID)
    curl -sf --max-time 5 -H "X-Benchmark-Actor-Id: 6" "${BASE_URL}/api/courses/${ci}/structure" -o /dev/null &
    curl -sf --max-time 5 -H "X-Benchmark-Actor-Id: 6" "${BASE_URL}/api/materials/${mi}" -o /dev/null &
    curl -sf --max-time 5 -H "X-Benchmark-Actor-Id: 6" "${BASE_URL}/api/quizzes/${qi}" -o /dev/null &
    curl -sf --max-time 5 -H "X-Benchmark-Actor-Id: 1" "${BASE_URL}/api/courses/${ci}/gradebook" -o /dev/null &

    idx=$(( idx + 1 ))
    req_count=$(( req_count + 4 ))
    sleep 1
    if [ $(( idx % 5 )) -eq 0 ]; then
      wait 2>/dev/null || true
    fi
  done

  wait 2>/dev/null || true
  echo -e "${YELLOW}[warm-up] Fallback selesai. ~${req_count} requests.${NC}"
}

# ─────────────────────────────────────────────
# Ambil Redis stats (hits/misses) sebagai angka
# ─────────────────────────────────────────────
get_redis_hits() {
  cd "${PROJECT_DIR}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    # Sum hits across all cluster nodes
    local total=0
    for node in "${CLUSTER_NODES[@]}"; do
      local hits
      hits=$(docker compose exec -T "$node" redis-cli INFO stats 2>/dev/null \
        | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r ' || echo "0")
      total=$(( total + hits ))
    done
    echo "${total}"
  else
    docker compose exec -T redis redis-cli INFO stats 2>/dev/null \
      | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r ' || echo "0"
  fi
}
get_redis_misses() {
  cd "${PROJECT_DIR}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    local total=0
    for node in "${CLUSTER_NODES[@]}"; do
      local misses
      misses=$(docker compose exec -T "$node" redis-cli INFO stats 2>/dev/null \
        | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ' || echo "0")
      total=$(( total + misses ))
    done
    echo "${total}"
  else
    docker compose exec -T redis redis-cli INFO stats 2>/dev/null \
      | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ' || echo "0"
  fi
}

# ─────────────────────────────────────────────
# Simpan Redis stats lengkap ke file
# ─────────────────────────────────────────────
save_redis_stats() {
  local label=$1
  local output_file=$2
  cd "${PROJECT_DIR}"
  echo "=== Redis INFO stats (${label}) - $(date) ===" >> "${output_file}"

  if [ "${CLUSTER_MODE}" = "true" ]; then
    # Collect stats from each cluster node
    for node in "${CLUSTER_NODES[@]}"; do
      echo "--- Node: ${node} ---" >> "${output_file}"
      docker compose exec -T "$node" redis-cli INFO stats >> "${output_file}" 2>/dev/null || true
      docker compose exec -T "$node" redis-cli INFO memory >> "${output_file}" 2>/dev/null || true
      docker compose exec -T "$node" redis-cli CLUSTER INFO >> "${output_file}" 2>/dev/null || true
      echo "" >> "${output_file}"
    done
  else
    docker compose exec -T redis redis-cli INFO stats  >> "${output_file}" 2>/dev/null || echo "Redis tidak tersedia" >> "${output_file}"
    docker compose exec -T redis redis-cli INFO memory >> "${output_file}" 2>/dev/null || true
  fi
  echo "" >> "${output_file}"
}

# ─────────────────────────────────────────────
# Hitung cache hit ratio dari delta hits/misses
# ─────────────────────────────────────────────
compute_cache_hit_ratio() {
  local hits_before=$1
  local misses_before=$2
  local hits_after=$3
  local misses_after=$4
  local output_file=$5

  local hits_during=$(( hits_after - hits_before ))
  local misses_during=$(( misses_after - misses_before ))
  local total=$(( hits_during + misses_during ))

  echo "=== Cache Hit Ratio ===" >> "${output_file}"
  echo "Hits during benchmark  : ${hits_during}" >> "${output_file}"
  echo "Misses during benchmark: ${misses_during}" >> "${output_file}"
  echo "Total cache ops        : ${total}" >> "${output_file}"

  if [ "${total}" -gt 0 ]; then
    local ratio
    ratio=$(echo "scale=4; ${hits_during} / ${total} * 100" | bc)
    echo "Cache Hit Ratio        : ${ratio}%" >> "${output_file}"
    echo "${ratio}"
  else
    echo "Cache Hit Ratio        : N/A (no cache ops)" >> "${output_file}"
    echo "0"
  fi
}

# ─────────────────────────────────────────────
# Monitor resource usage (background process)
# Menggunakan top (CPU), free (memory), iostat (disk I/O)
# sesuai proposal: htop & iostat
# Output: CSV dengan sample setiap 5 detik
# ─────────────────────────────────────────────
start_resource_monitor() {
  local output_csv=$1

  echo "timestamp,cpu_pct,mem_used_mb,mem_total_mb,mem_used_pct,disk_read_mb_s,disk_write_mb_s" > "${output_csv}"

  (
    while true; do
      local ts
      ts=$(date +%s)

      # CPU% — ambil dari top (1 iterasi, batch mode)
      # Format: "Cpu(s): 12.5 us, 3.2 sy, ..."  → ambil user+sys
      local cpu_pct
      cpu_pct=$(top -bn2 -d0.5 | grep "Cpu(s)" | tail -1 \
        | awk '{
            for(i=1;i<=NF;i++) {
              if($i ~ /[0-9]+\.[0-9]+/) {
                if($(i+1) ~ /us/ || $(i-1) ~ /us/) { us=$i }
                if($(i+1) ~ /sy/ || $(i-1) ~ /sy/) { sy=$i }
              }
            }
            gsub(/,/,"",us); gsub(/,/,"",sy);
            printf "%.1f", us+sy
          }' 2>/dev/null || echo "0")

      # Fallback CPU dari /proc/stat jika top gagal
      if [ "${cpu_pct}" = "0" ] || [ -z "${cpu_pct}" ]; then
        local idle1 total1 idle2 total2
        read -r _ user1 nice1 system1 idle1 iowait1 irq1 softirq1 _ < /proc/stat
        total1=$(( user1 + nice1 + system1 + idle1 + iowait1 + irq1 + softirq1 ))
        sleep 1
        read -r _ user2 nice2 system2 idle2 iowait2 irq2 softirq2 _ < /proc/stat
        total2=$(( user2 + nice2 + system2 + idle2 + iowait2 + irq2 + softirq2 ))
        local diff_total=$(( total2 - total1 ))
        local diff_idle=$(( idle2 - idle1 ))
        cpu_pct=$(echo "scale=1; (${diff_total} - ${diff_idle}) * 100 / ${diff_total}" | bc 2>/dev/null || echo "0")
      fi

      # Memory — dari free -m (sesuai htop)
      local mem_used mem_total mem_pct
      read -r _ mem_total mem_used _ < <(free -m | grep "^Mem:")
      mem_pct=$(echo "scale=1; ${mem_used} * 100 / ${mem_total}" | bc 2>/dev/null || echo "0")

      # Disk I/O — prioritas: iostat > /proc/diskstats
      local disk_read disk_write
      if command -v iostat &>/dev/null; then
        # iostat -d -m: MB/s, ambil baris disk utama (sda/vda/nvme)
        local iostat_line
        iostat_line=$(iostat -d -m 1 1 2>/dev/null \
          | grep -E "^(sda|vda|nvme|xvda|sdb|vdb|dm-)" | head -1)
        disk_read=$(echo  "${iostat_line}" | awk '{print $3}' | tr -d ',' || echo "0")
        disk_write=$(echo "${iostat_line}" | awk '{print $4}' | tr -d ',' || echo "0")
        # Pastikan tidak kosong
        disk_read=${disk_read:-0}
        disk_write=${disk_write:-0}
      else
        # Fallback: baca /proc/diskstats untuk hitung MB/s delta
        # Field 6 = sectors read, Field 10 = sectors written (1 sector = 512 bytes)
        local disk_stats_line1 disk_stats_line2
        local sectors_read1 sectors_written1 sectors_read2 sectors_written2
        disk_stats_line1=$(grep -E "\s+(sda|vda|nvme0n1|xvda|dm-0)\s" /proc/diskstats | head -1)
        sectors_read1=$(echo "${disk_stats_line1}" | awk '{print $6}')
        sectors_written1=$(echo "${disk_stats_line1}" | awk '{print $10}')
        sectors_read1=${sectors_read1:-0}
        sectors_written1=${sectors_written1:-0}
        sleep 1
        disk_stats_line2=$(grep -E "\s+(sda|vda|nvme0n1|xvda|dm-0)\s" /proc/diskstats | head -1)
        sectors_read2=$(echo "${disk_stats_line2}" | awk '{print $6}')
        sectors_written2=$(echo "${disk_stats_line2}" | awk '{print $10}')
        sectors_read2=${sectors_read2:-0}
        sectors_written2=${sectors_written2:-0}
        # Konversi delta sectors ke MB/s (1 sector = 512 bytes)
        local delta_read=$(( sectors_read2 - sectors_read1 ))
        local delta_write=$(( sectors_written2 - sectors_written1 ))
        disk_read=$(echo "scale=2; ${delta_read} * 512 / 1048576" | bc 2>/dev/null || echo "0")
        disk_write=$(echo "scale=2; ${delta_write} * 512 / 1048576" | bc 2>/dev/null || echo "0")
      fi

      echo "${ts},${cpu_pct},${mem_used},${mem_total},${mem_pct},${disk_read},${disk_write}"
      sleep 5
    done
  ) >> "${output_csv}" &

  echo $!
}

stop_resource_monitor() {
  local pid=$1
  if [ -n "${pid}" ] && kill -0 "${pid}" 2>/dev/null; then
    kill "${pid}" 2>/dev/null
    wait "${pid}" 2>/dev/null || true
  fi
}

# ─────────────────────────────────────────────
# Jalankan k6 untuk satu level concurrent users
# ─────────────────────────────────────────────
run_k6_for_level() {
  local vu_count=$1
  local timestamp
  timestamp=$(date +%Y%m%d_%H%M%S)
  local result_dir="${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}"
  local result_prefix="${result_dir}/${vu_count}vu"
  local summary_output="${result_prefix}-${timestamp}-summary.json"
  local redis_file="${result_prefix}-${timestamp}-redis.txt"
  local hit_ratio_file="${result_prefix}-${timestamp}-cache-hit-ratio.txt"
  local resources_csv="${result_prefix}-${timestamp}-resources.csv"

  mkdir -p "${result_dir}"

  # Marker file untuk redis mode (dibaca oleh analyze-results.sh)
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo "cluster" > "${result_dir}/.redis-mode"
  else
    echo "single" > "${result_dir}/.redis-mode"
  fi

  echo ""
  echo -e "${CYAN}────────────────────────────────────────────${NC}"
  echo -e "${CYAN}  Concurrent Users : ${vu_count}${NC}"
  echo -e "${CYAN}  Strategi         : ${STRATEGY}${NC}"
  echo -e "${CYAN}  Skenario         : ${SCENARIO}${NC}"
  echo -e "${CYAN}  Durasi           : ~22 menit (prepare ~15m + warm-up ~1m + benchmark ~6m)${NC}"
  echo -e "${CYAN}────────────────────────────────────────────${NC}"

  # Reset database + sistem untuk setiap VU level
  # (migrate:fresh --seed + cache clear + container restart + verifikasi)
  echo -e "${YELLOW}[${vu_count}vu] Reset database & sistem (prepare-benchmark.sh)...${NC}"
  "${SCRIPT_DIR}/prepare-benchmark.sh" "${BASE_URL}"
  if [ $? -ne 0 ]; then
    echo -e "${RED}[${vu_count}vu] ✗ prepare-benchmark.sh gagal!${NC}"
    return 1
  fi

  # ─────────────────────────────────────────────
  # WARM-UP PERIOD (60 detik, sesuai proposal §3.3.1.2)
  # Request actor-aware ke semua endpoint utama untuk:
  #   1. Inisialisasi connection pool database
  #   2. Mengisi cache dengan data yang umum diakses (hot keys)
  #   3. Menghindari cold-start bias pada k6
  # Request warm-up TIDAK masuk ke metrik k6 karena
  # dijalankan sebelum k6 start.
  # ─────────────────────────────────────────────
  local warmup_summary_file="${result_prefix}-${timestamp}-warmup-summary.txt"
  echo -e "${YELLOW}[${vu_count}vu] Warm-up 60 detik (actor-aware, tidak masuk metrik)...${NC}"
  warmup_cache "${warmup_summary_file}"
  local warmup_rc=$?
  if [ $warmup_rc -ne 0 ]; then
    echo -e "${RED}[${vu_count}vu] ✗ Warm-up gagal! Benchmark dibatalkan.${NC}"
    return 1
  fi

  # Redis snapshot SEBELUM (diambil setelah warm-up selesai,
  # supaya metrik delta cache hit ratio mencerminkan benchmark saja)
  save_redis_stats "before" "${redis_file}"
  local hits_before misses_before
  hits_before=$(get_redis_hits)
  misses_before=$(get_redis_misses)
  hits_before=${hits_before:-0}
  misses_before=${misses_before:-0}

  # Mulai resource monitoring
  echo -e "${YELLOW}[${vu_count}vu] Memulai resource monitoring (CPU/Memory/Disk I/O)...${NC}"
  local monitor_pid
  monitor_pid=$(start_resource_monitor "${resources_csv}")

  # Container stats snapshot sebelum k6
  local container_stats_file="${result_prefix}-${timestamp}-container-stats.txt"
  snapshot_container_stats "${container_stats_file}"

  # Jalankan k6 (dengan CLUSTER_MODE env jika cluster)
  echo -e "${YELLOW}[${vu_count}vu] Menjalankan k6...${NC}"

  local k6_envs=(
    --env BASE_URL="${BASE_URL}"
    --env CONCURRENT_USERS="${vu_count}"
    --env CACHE_STRATEGY="${STRATEGY}"
    --env SUMMARY_EXPORT="${summary_output}"
  )

  if [ "${CLUSTER_MODE}" = "true" ]; then
    k6_envs+=(--env CLUSTER_MODE=true)
  fi

  k6 run "${k6_envs[@]}" "${K6_SCRIPT}"

  local k6_exit=$?

  # Stop resource monitoring
  stop_resource_monitor "${monitor_pid}"

  # Redis snapshot SESUDAH + hitung cache hit ratio
  local hits_after misses_after
  hits_after=$(get_redis_hits)
  misses_after=$(get_redis_misses)
  hits_after=${hits_after:-0}
  misses_after=${misses_after:-0}

  save_redis_stats "after" "${redis_file}"

  # ── Cluster-specific stats (jika --cluster) ────────────
  if [ "${CLUSTER_MODE}" = "true" ]; then
    local cluster_file="${result_prefix}-${timestamp}-cluster-stats.txt"
    echo "=== Cluster Stats - $(date) ===" > "${cluster_file}"
    echo "" >> "${cluster_file}"
    for node in "${CLUSTER_NODES[@]}"; do
      echo "--- ${node} ---" >> "${cluster_file}"
      docker compose exec -T "$node" redis-cli CLUSTER INFO 2>/dev/null >> "${cluster_file}" || true
      echo "" >> "${cluster_file}"
      docker compose exec -T "$node" redis-cli CLUSTER NODES 2>/dev/null >> "${cluster_file}" || true
      echo "" >> "${cluster_file}"
      docker compose exec -T "$node" redis-cli INFO keyspace 2>/dev/null >> "${cluster_file}" || true
      echo "" >> "${cluster_file}"
    done
    echo -e "${GREEN}  Cluster stats : ${cluster_file}${NC}"
  fi

  local hit_ratio
  hit_ratio=$(compute_cache_hit_ratio \
    "${hits_before}" "${misses_before}" \
    "${hits_after}"  "${misses_after}" \
    "${redis_file}")

  echo "${hit_ratio}" > "${hit_ratio_file}"

  if [ $k6_exit -eq 0 ]; then
    echo -e "${GREEN}[${vu_count}vu] ✓ Selesai.${NC}"
    echo -e "${GREEN}  Summary   : ${summary_output}${NC}"
    echo -e "${GREEN}  Resources : ${resources_csv}${NC}"
    echo -e "${GREEN}  Cache HR  : ${hit_ratio}%${NC}"
  else
    echo -e "${RED}[${vu_count}vu] ✗ k6 exit code ${k6_exit}${NC}"
  fi

  return $k6_exit
}

# ─────────────────────────────────────────────
# Preflight host capacity snapshot
# ─────────────────────────────────────────────
preflight_snapshot() {
  local output_dir=$1
  local snapshot_file="${output_dir}/preflight-$(date +%Y%m%d_%H%M%S).txt"

  mkdir -p "${output_dir}"

  {
    echo "============================================="
    echo " Preflight Host Capacity Snapshot"
    echo "============================================="
    echo "Timestamp: $(date -Iseconds)"
    echo ""

    echo "--- CPU ---"
    nproc 2>/dev/null || echo "nproc not available"
    echo ""

    echo "--- Memory ---"
    free -h 2>/dev/null || echo "free not available"
    echo ""

    echo "--- Disk ---"
    df -hT 2>/dev/null || echo "df not available"
    echo ""

    echo "--- Block Devices ---"
    lsblk -o NAME,ROTA,TYPE,SIZE,FSTYPE,MOUNTPOINT 2>/dev/null || echo "lsblk not available"
    echo ""

    echo "--- Docker Services ---"
    docker compose ps --services 2>/dev/null || echo "docker compose not available"
    echo ""

    echo "--- Redis Mode ---"
    if [ "${CLUSTER_MODE}" = "true" ]; then
      echo "cluster"
      for node in "${CLUSTER_NODES[@]}"; do
        local role
        role=$(docker compose exec -T "$node" redis-cli CLUSTER NODES 2>/dev/null \
          | grep "\b$(docker compose exec -T "$node" redis-cli CLUSTER MYID 2>/dev/null | tr -d '\r ')\b" \
          | awk '{print $3}' 2>/dev/null || echo "unknown")
        echo "  ${node}: ${role}"
      done
    else
      echo "single"
      echo "  host: redis"
    fi
    echo ""

    echo "--- Laravel Config ---"
    echo "  CACHE_DRIVER: $(grep '^CACHE_DRIVER=' "${PROJECT_DIR}/.env" 2>/dev/null | cut -d'=' -f2 || echo 'unknown')"
    echo "  SESSION_DRIVER: $(grep '^SESSION_DRIVER=' "${PROJECT_DIR}/.env" 2>/dev/null | cut -d'=' -f2 || echo 'unknown')"
    echo "  QUEUE_CONNECTION: $(grep '^QUEUE_CONNECTION=' "${PROJECT_DIR}/.env" 2>/dev/null | cut -d'=' -f2 || echo 'unknown')"
    echo "  CACHE_STRATEGY: $(grep '^CACHE_STRATEGY=' "${PROJECT_DIR}/.env" 2>/dev/null | cut -d'=' -f2 || echo 'unknown')"
    echo ""

    echo "--- k6 Location ---"
    if hostname -I 2>/dev/null | grep -q "127.0.0.1" 2>/dev/null \
      || echo "${BASE_URL}" | grep -qE "localhost|127\.0\.0\.1"; then
      echo "k6 runs: LOCAL (same host as server)"
      echo "  Resource metrics include k6 overhead."
    else
      echo "k6 runs: REMOTE"
      echo "  Resource metrics represent server-side load only."
    fi
    echo ""

  } > "${snapshot_file}"

  echo -e "${GREEN}[preflight] Host capacity snapshot: ${snapshot_file}${NC}"
}

# ─────────────────────────────────────────────
# Container resource snapshot
# ─────────────────────────────────────────────
snapshot_container_stats() {
  local output_file=$1
  mkdir -p "$(dirname "${output_file}")"
  docker stats --no-stream 2>/dev/null > "${output_file}" || echo "(docker stats not available)" > "${output_file}"
}

# ─────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────
main() {
  echo ""
  echo "=============================================="
  echo "  LMS Cache Strategy Benchmark"
  echo "=============================================="
  echo -e "  Strategi  : ${BLUE}${STRATEGY}${NC}"
  echo -e "  Skenario  : ${BLUE}${SCENARIO}${NC}"
  echo -e "  Iterasi   : ${BLUE}${ITERATION}${NC}"
  echo -e "  Base URL  : ${BLUE}${BASE_URL}${NC}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo -e "  Redis     : ${BLUE}Cluster${NC} (3 masters + 3 replicas)"
  else
    echo -e "  Redis     : ${BLUE}Single${NC} (standalone)"
  fi
  echo -e "  VU Levels : ${BLUE}${CONCURRENT_USERS_LEVELS[*]}${NC}"
  echo -e "  Hasil     : ${BLUE}${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}/${NC}"
  echo "=============================================="
  echo ""

  validate_inputs

  # Write top-level Redis mode marker after validation passes
  mkdir -p "${RESULTS_DIR}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo "cluster" > "${RESULTS_DIR}/.redis-mode"
  else
    echo "single" > "${RESULTS_DIR}/.redis-mode"
  fi

  # Persist effective VU levels for consistent analyzer reads
  printf '%s\n' "${CONCURRENT_USERS_LEVELS[@]}" > "${RESULTS_DIR}/.vu-levels"

  # Preflight snapshot
  preflight_snapshot "${RESULTS_DIR}"

  switch_strategy

  local total=${#CONCURRENT_USERS_LEVELS[@]}
  local completed=0
  local failed=0
  local start_time
  start_time=$(date +%s)

  for vu_count in "${CONCURRENT_USERS_LEVELS[@]}"; do
    completed=$((completed + 1))
    echo ""
    echo -e "${BLUE}[Progress: ${completed}/${total}]${NC}"

    run_k6_for_level "$vu_count"
    if [ $? -ne 0 ]; then
      failed=$((failed + 1))
    fi

    if [ $completed -lt $total ]; then
      echo -e "${YELLOW}Jeda 30 detik sebelum level berikutnya...${NC}"
      sleep 30
    fi
  done

  local end_time
  end_time=$(date +%s)
  local duration=$((end_time - start_time))
  local hours=$((duration / 3600))
  local minutes=$(((duration % 3600) / 60))

  echo ""
  echo "=============================================="
  echo "  Benchmark Selesai!"
  echo "=============================================="
  echo -e "  Berhasil : ${GREEN}$((total - failed)) / ${total}${NC}"
  echo -e "  Gagal    : ${RED}${failed} / ${total}${NC}"
  echo -e "  Durasi   : ${hours}j ${minutes}m"
  echo -e "  Hasil    : ${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}/"
  echo "=============================================="
}

main
