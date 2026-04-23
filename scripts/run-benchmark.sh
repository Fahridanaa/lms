#!/bin/bash

# ============================================================
# LMS Caching Strategy Benchmark Runner
#
# Usage  : ./scripts/run-benchmark.sh [strategy] [scenario] [base_url]
# Contoh : ./scripts/run-benchmark.sh cache-aside read-heavy http://localhost
#
# Script ini menjalankan k6 untuk satu strategi + satu skenario,
# berulang untuk 7 level concurrent users sesuai proposal:
#   100, 250, 500, 750, 1000, 1500, 2000
#
# Metrik yang dikumpulkan per run:
#   - k6 summary JSON  : response time (avg/min/max/p90/p95/p99), throughput, error rate
#   - resources CSV    : CPU%, Memory (MB), Disk I/O (MB) per container setiap 10 detik
#   - redis stats      : keyspace hits/misses sebelum & sesudah → cache hit ratio
# ============================================================

STRATEGY=${1:-cache-aside}
SCENARIO=${2:-read-heavy}
BASE_URL=${3:-http://localhost}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
RESULTS_DIR="${PROJECT_DIR}/benchmark-results"
K6_SCRIPT="${PROJECT_DIR}/tests/Benchmark/k6/${SCENARIO}-scenario.js"

# Level concurrent users sesuai Tabel 3.1 proposal
CONCURRENT_USERS_LEVELS=(100 250 500 750 1000 1500 2000)

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# ─────────────────────────────────────────────
# Validasi input
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

  if ! command -v k6 &>/dev/null; then
    echo -e "${RED}Error: k6 tidak terinstall.${NC}"
    echo "Install: https://k6.io/docs/get-started/installation/"
    exit 1
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
  docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || true
  echo -e "${GREEN}[setup] Cache bersih.${NC}"
}

# ─────────────────────────────────────────────
# Ambil Redis stats (hits/misses) sebagai angka
# ─────────────────────────────────────────────
get_redis_hits()   {
  cd "${PROJECT_DIR}"
  docker compose exec -T redis redis-cli INFO stats 2>/dev/null \
    | grep "keyspace_hits:"   | cut -d':' -f2 | tr -d '\r ' || echo "0"
}
get_redis_misses() {
  cd "${PROJECT_DIR}"
  docker compose exec -T redis redis-cli INFO stats 2>/dev/null \
    | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ' || echo "0"
}

# ─────────────────────────────────────────────
# Simpan Redis stats lengkap ke file
# ─────────────────────────────────────────────
save_redis_stats() {
  local label=$1
  local output_file=$2
  cd "${PROJECT_DIR}"
  echo "=== Redis INFO stats (${label}) - $(date) ===" > "${output_file}"
  docker compose exec -T redis redis-cli INFO stats  >> "${output_file}" 2>/dev/null || echo "Redis tidak tersedia" >> "${output_file}"
  docker compose exec -T redis redis-cli INFO memory >> "${output_file}" 2>/dev/null || true
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
    echo "${ratio}" # return value
  else
    echo "Cache Hit Ratio        : N/A (no cache ops)" >> "${output_file}"
    echo "0"
  fi
}

# ─────────────────────────────────────────────
# Monitor resource usage (background process)
# Capture CPU%, Memory MB, Disk I/O tiap 10 detik
# ─────────────────────────────────────────────
start_resource_monitor() {
  local output_csv=$1

  # Header CSV
  echo "timestamp,container,cpu_pct,mem_usage_mb,mem_limit_mb,block_read_mb,block_write_mb" > "${output_csv}"

  # Jalankan loop di background
  (
    cd "${PROJECT_DIR}"
    while true; do
      local ts
      ts=$(date +%s)
      # docker stats --no-stream: satu snapshot semua container dalam project
      docker compose stats --no-stream --format \
        "${ts},{{.Name}},{{.CPUPerc}},{{.MemUsage}},{{.BlockIO}}" \
        2>/dev/null | while IFS=',' read -r timestamp name cpu mem_raw block_raw; do
          # Parse mem: "123.4MiB / 1.953GiB" → angka MB
          local mem_used mem_limit
          mem_used=$(echo "${mem_raw}" | awk '{print $1}' | sed 's/[^0-9.]//g')
          mem_unit=$(echo "${mem_raw}" | awk '{print $1}' | sed 's/[0-9.]//g')
          mem_limit_raw=$(echo "${mem_raw}" | awk '{print $3}')
          mem_limit=$(echo "${mem_limit_raw}" | sed 's/[^0-9.]//g')
          mem_limit_unit=$(echo "${mem_limit_raw}" | sed 's/[0-9.]//g')

          # Konversi ke MB
          case "${mem_unit}" in
            GiB|GB) mem_used=$(echo "${mem_used} * 1024" | bc) ;;
            KiB|KB) mem_used=$(echo "${mem_used} / 1024" | bc) ;;
          esac
          case "${mem_limit_unit}" in
            GiB|GB) mem_limit=$(echo "${mem_limit} * 1024" | bc) ;;
            KiB|KB) mem_limit=$(echo "${mem_limit} / 1024" | bc) ;;
          esac

          # Parse block I/O: "1.23MB / 456kB"
          local br bw
          br=$(echo "${block_raw}" | awk '{print $1}' | sed 's/[^0-9.]//g')
          br_unit=$(echo "${block_raw}" | awk '{print $1}' | sed 's/[0-9.]//g')
          bw=$(echo "${block_raw}" | awk '{print $3}' | sed 's/[^0-9.]//g')
          bw_unit=$(echo "${block_raw}" | awk '{print $3}' | sed 's/[0-9.]//g')

          case "${br_unit}" in
            GB|GiB) br=$(echo "${br} * 1024" | bc) ;;
            kB|KiB|KB) br=$(echo "scale=3; ${br} / 1024" | bc) ;;
          esac
          case "${bw_unit}" in
            GB|GiB) bw=$(echo "${bw} * 1024" | bc) ;;
            kB|KiB|KB) bw=$(echo "scale=3; ${bw} / 1024" | bc) ;;
          esac

          cpu_clean=$(echo "${cpu}" | tr -d '%')
          echo "${timestamp},${name},${cpu_clean},${mem_used},${mem_limit},${br},${bw}"
      done >> "${output_csv}"
      sleep 10
    done
  ) &

  echo $! # return PID monitor
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
  local result_prefix="${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/${vu_count}vu"
  local summary_output="${result_prefix}-${timestamp}-summary.json"
  local redis_file="${result_prefix}-${timestamp}-redis.txt"
  local resources_csv="${result_prefix}-${timestamp}-resources.csv"

  mkdir -p "${RESULTS_DIR}/${STRATEGY}/${SCENARIO}"

  echo ""
  echo -e "${CYAN}────────────────────────────────────────────${NC}"
  echo -e "${CYAN}  Concurrent Users : ${vu_count}${NC}"
  echo -e "${CYAN}  Strategi         : ${STRATEGY}${NC}"
  echo -e "${CYAN}  Skenario         : ${SCENARIO}${NC}"
  echo -e "${CYAN}  Durasi           : ~6.5 menit (1m ramp + 5m steady + 30s down)${NC}"
  echo -e "${CYAN}────────────────────────────────────────────${NC}"

  # Clear cache + tunggu sistem stabil
  clear_all_caches
  echo -e "${YELLOW}[${vu_count}vu] Menunggu 15 detik agar sistem stabil...${NC}"
  sleep 15

  # Snapshot Redis SEBELUM benchmark
  save_redis_stats "before" "${redis_file}"
  local hits_before misses_before
  hits_before=$(get_redis_hits)
  misses_before=$(get_redis_misses)

  # Mulai resource monitoring di background
  echo -e "${YELLOW}[${vu_count}vu] Memulai resource monitoring...${NC}"
  local monitor_pid
  monitor_pid=$(start_resource_monitor "${resources_csv}")

  # Jalankan k6
  echo -e "${YELLOW}[${vu_count}vu] Menjalankan k6...${NC}"
  k6 run \
    --env BASE_URL="${BASE_URL}" \
    --env CONCURRENT_USERS="${vu_count}" \
    --env CACHE_STRATEGY="${STRATEGY}" \
    --summary-export="${summary_output}" \
    "${K6_SCRIPT}"

  local k6_exit=$?

  # Stop resource monitoring
  stop_resource_monitor "${monitor_pid}"

  # Snapshot Redis SESUDAH benchmark
  local hits_after misses_after
  hits_after=$(get_redis_hits)
  misses_after=$(get_redis_misses)

  echo "" >> "${redis_file}"
  save_redis_stats "after" "${redis_file}"
  echo "" >> "${redis_file}"

  # Hitung cache hit ratio dari delta
  local hit_ratio
  hit_ratio=$(compute_cache_hit_ratio \
    "${hits_before}" "${misses_before}" \
    "${hits_after}"  "${misses_after}" \
    "${redis_file}")

  if [ $k6_exit -eq 0 ]; then
    echo -e "${GREEN}[${vu_count}vu] ✓ Selesai.${NC}"
    echo -e "${GREEN}  Summary : ${summary_output}${NC}"
    echo -e "${GREEN}  Cache HR: ${hit_ratio}%${NC}"
    echo -e "${GREEN}  Resources: ${resources_csv}${NC}"
  else
    echo -e "${RED}[${vu_count}vu] ✗ k6 selesai dengan exit code ${k6_exit}${NC}"
  fi

  return $k6_exit
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
  echo -e "  Base URL  : ${BLUE}${BASE_URL}${NC}"
  echo -e "  VU Levels : ${BLUE}${CONCURRENT_USERS_LEVELS[*]}${NC}"
  echo -e "  Hasil     : ${BLUE}${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/${NC}"
  echo "=============================================="
  echo ""

  validate_inputs
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

    # Jeda antar level (kecuali level terakhir)
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
  echo -e "  Hasil    : ${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/"
  echo "=============================================="
  echo ""
  echo "Langkah berikutnya:"
  echo "  Jalankan strategi lain dengan perintah yang sama, contoh:"
  echo "  ./scripts/run-benchmark.sh read-through ${SCENARIO} ${BASE_URL}"
  echo ""
}

main
