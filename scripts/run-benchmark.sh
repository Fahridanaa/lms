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
# Setiap level menghasilkan file hasil tersendiri di benchmark-results/.
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
# Simpan Redis stats (sebelum/sesudah)
# ─────────────────────────────────────────────
save_redis_stats() {
  local label=$1
  local output_file=$2
  cd "${PROJECT_DIR}"
  echo "=== Redis INFO stats (${label}) - $(date) ===" > "${output_file}"
  cd "${PROJECT_DIR}"
  docker compose exec -T redis redis-cli INFO stats  >> "${output_file}" 2>/dev/null || echo "Redis tidak tersedia" >> "${output_file}"
  docker compose exec -T redis redis-cli INFO memory >> "${output_file}" 2>/dev/null || true
}

# ─────────────────────────────────────────────
# Jalankan k6 untuk satu level concurrent users
# ─────────────────────────────────────────────
run_k6_for_level() {
  local vu_count=$1
  local timestamp
  timestamp=$(date +%Y%m%d_%H%M%S)
  local result_prefix="${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/${vu_count}vu"
  local json_output="${result_prefix}-${timestamp}.json"
  local summary_output="${result_prefix}-${timestamp}-summary.json"
  local redis_before="${result_prefix}-${timestamp}-redis-before.txt"
  local redis_after="${result_prefix}-${timestamp}-redis-after.txt"

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

  # Catat state Redis sebelum benchmark
  save_redis_stats "before" "${redis_before}"

  # Jalankan k6
  echo -e "${YELLOW}[${vu_count}vu] Menjalankan k6...${NC}"
  k6 run \
    --env BASE_URL="${BASE_URL}" \
    --env CONCURRENT_USERS="${vu_count}" \
    --env CACHE_STRATEGY="${STRATEGY}" \
    --out "json=${json_output}" \
    --summary-export="${summary_output}" \
    "${K6_SCRIPT}"

  local k6_exit=$?

  # Catat state Redis sesudah benchmark
  save_redis_stats "after" "${redis_after}"

  # Hitung cache hit ratio dari Redis
  "${SCRIPT_DIR}/check-redis-stats.sh" >> "${redis_after}" 2>/dev/null || true

  if [ $k6_exit -eq 0 ]; then
    echo -e "${GREEN}[${vu_count}vu] ✓ Selesai. Hasil: ${summary_output}${NC}"
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
