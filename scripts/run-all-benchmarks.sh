#!/bin/bash

# ============================================================
# Complete Benchmark Suite — Semua Strategi × Semua Skenario × N Iterasi
#
# Usage  : ./scripts/run-all-benchmarks.sh [base_url]
# Contoh : ./scripts/run-all-benchmarks.sh http://localhost
#
# Default: 3 iterasi (BENCHMARK_ITERATIONS=env untuk override)
#   4 strategi × 2 skenario × 3 iterasi × 7 VU levels = 168 individual k6 runs
#   Estimasi waktu: ~33 jam (termasuk jeda, warm-up, dan restart antar iterasi)
#
# Prosedur per iterasi (sesuai proposal §3.4.4.1):
#   1. Restart container 'app' untuk menghilangkan state di memory
#   2. Flush semua cache
#   3. Jalankan semua kombinasi strategi × skenario dengan 7 VU levels
#
# Urutan eksekusi (no-cache duluan supaya ada baseline lebih awal):
#   no-cache → cache-aside → read-through → write-through
# ============================================================

CLUSTER_MODE=false

# Parse --cluster flag
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

BASE_URL=${1:-http://localhost}
SKIP_PREPARE=${2:-no}   # set "yes" untuk skip prepare (debug/resume)

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

if [ "${CLUSTER_MODE}" = "true" ]; then
  RESULTS_DIR="${SCRIPT_DIR}/../benchmark-results-cluster"
else
  RESULTS_DIR="${SCRIPT_DIR}/../benchmark-results"
fi

STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy")
# Number of iterations per combination (configurable, default 3)
# For development: BENCHMARK_ITERATIONS=1 ./scripts/run-all-benchmarks.sh
ITERATIONS=${BENCHMARK_ITERATIONS:-3}

# VU levels — configurable via VU_LEVELS env var
if [ -n "${VU_LEVELS+x}" ]; then
  IFS=' ' read -ra CONCURRENT_USERS_LEVELS <<< "${VU_LEVELS}"
else
  CONCURRENT_USERS_LEVELS=(100 250 500 750 1000 1500 2000)
fi

# Validate VU values: must be positive integers
for v in "${CONCURRENT_USERS_LEVELS[@]}"; do
  if ! [[ "$v" =~ ^[1-9][0-9]*$ ]]; then
    echo -e "${RED}Error: Invalid VU level '${v}' — must be a positive integer.${NC}"
    exit 1
  fi
done

if [ ${#CONCURRENT_USERS_LEVELS[@]} -eq 0 ]; then
  echo -e "${RED}Error: VU_LEVELS is empty — at least one VU level required.${NC}"
  exit 1
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

TOTAL_COMBINATION_ATTEMPTS=$(( ${#STRATEGIES[@]} * ${#SCENARIOS[@]} * ITERATIONS ))
TOTAL_VU_RUNS=$(( TOTAL_COMBINATION_ATTEMPTS * ${#CONCURRENT_USERS_LEVELS[@]} ))
COMPLETED=0
FAILED=0
START_TIME=$(date +%s)

# ─────────────────────────────────────────────
# Log file — create results directory early for logging
# Metadata files (.redis-mode, .vu-levels) are written after preflight passes
# ─────────────────────────────────────────────
mkdir -p "${RESULTS_DIR}"

LOG_FILE="${RESULTS_DIR}/benchmark-suite-$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo ""
echo "=================================================="
echo "  LMS Cache Strategy — Complete Benchmark Suite"
echo "=================================================="
echo -e "  Base URL      : ${BLUE}${BASE_URL}${NC}"
echo -e "  Strategi      : ${BLUE}${STRATEGIES[*]}${NC}"
echo -e "  Skenario      : ${BLUE}${SCENARIOS[*]}${NC}"
echo -e "  Iterasi       : ${BLUE}${ITERATIONS}x per kombinasi (sesuai proposal §3.4.4.4)${NC}"

if [ "${CLUSTER_MODE}" = "true" ]; then
  echo -e "  Redis         : ${BLUE}Cluster${NC} (3 masters + 3 replicas)"
else
  echo -e "  Redis         : ${BLUE}Single${NC} (standalone)"
fi
echo -e "  Combination attempts  : ${BLUE}${TOTAL_COMBINATION_ATTEMPTS} (strategi × skenario × iterasi)${NC}"
echo -e "  Expected k6 runs      : ${BLUE}${TOTAL_VU_RUNS} (attempts × VU levels)${NC}"
echo -e "  VU levels     : ${BLUE}${CONCURRENT_USERS_LEVELS[*]}${NC}"
echo -e "  Log           : ${BLUE}${LOG_FILE}${NC}"
echo "=================================================="
echo ""
# ─────────────────────────────────────────────
# Preflight check: required tools
# ─────────────────────────────────────────────
echo -e "${YELLOW}[preflight] Checking required tools...${NC}"
missing_tools=()
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
  for tool in "${missing_tools[@]}"; do
    case "$tool" in
      "jq") echo -e "  ${YELLOW}Install jq: apt-get install jq${NC}" ;;
      "node"*) echo -e "  ${YELLOW}Install Node.js: https://nodejs.org/  or  apt-get install nodejs${NC}" ;;
      "k6") echo -e "  ${YELLOW}Install k6: https://k6.io/docs/get-started/installation/${NC}" ;;
      "bc") echo -e "  ${YELLOW}Install bc: apt-get install bc${NC}" ;;
      "curl") echo -e "  ${YELLOW}Install curl: apt-get install curl${NC}" ;;
      "docker"*) echo -e "  ${YELLOW}Install Docker: https://docs.docker.com/engine/install/${NC}" ;;
    esac
  done
  exit 1
fi
for tool in iostat python3; do
  if ! command -v "$tool" &>/dev/null; then
    echo -e "${YELLOW}  Warning: ${tool} not found${NC}"
  fi
done
echo -e "${GREEN}[preflight] ✓ Required tools OK${NC}"
echo ""

# ─────────────────────────────────────────────
# Verify Redis mode before allowing any run
# ─────────────────────────────────────────────
echo -e "${YELLOW}[preflight] Verifying Redis mode...${NC}"
if [ "${CLUSTER_MODE}" = "true" ]; then
  require_redis_cluster "${PROJECT_DIR}"
else
  require_redis_single "${PROJECT_DIR}"
fi

if [ $? -ne 0 ]; then
  echo -e "${RED}✗ Redis mode verification failed. Benchmark aborted.${NC}"
  exit 1
fi
echo -e "${GREEN}[preflight] ✓ Redis mode verified.${NC}"
echo ""

# ─────────────────────────────────────────────
# Write metadata after preflight validation passes
# ─────────────────────────────────────────────
if [ "${CLUSTER_MODE}" = "true" ]; then
  echo "cluster" > "${RESULTS_DIR}/.redis-mode"
else
  echo "single" > "${RESULTS_DIR}/.redis-mode"
fi

printf '%s\n' "${CONCURRENT_USERS_LEVELS[@]}" > "${RESULTS_DIR}/.vu-levels"
echo -e "${GREEN}[preflight] ✓ Suite metadata written (.redis-mode, .vu-levels)${NC}"
echo ""

# ─────────────────────────────────────────────
# Preflight: host capacity snapshot
# ─────────────────────────────────────────────
echo -e "${YELLOW}[preflight] Host capacity snapshot...${NC}"
snapshot_file="${RESULTS_DIR}/preflight-host-$(date +%Y%m%d_%H%M%S).txt"
mkdir -p "${RESULTS_DIR}"
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
  echo "--- k6 Location ---"
  if echo "${BASE_URL}" | grep -qE "localhost|127\.0\.0\.1"; then
    echo "k6 runs: LOCAL (same host as server) — resource metrics include k6 overhead."
  else
    echo "k6 runs: REMOTE — resource metrics represent server-side load only."
  fi
  echo ""
} > "${snapshot_file}"
echo -e "${GREEN}[preflight] ✓ Snapshot written to ${snapshot_file}${NC}"
echo ""

echo -e "${YELLOW}⚠  Pastikan:${NC}"
echo "   1. App sudah berjalan (docker compose ps)"
echo "   2. k6 sudah terinstall"
echo "   3. Cukup disk space di benchmark-results"
echo ""
if [ "${SKIP_PREPARE}" = "yes" ]; then
  echo -e "${YELLOW}⚠  SKIP_PREPARE=yes — prepare-benchmark.sh TIDAK akan dijalankan.${NC}"
  echo -e "${YELLOW}   Pastikan database sudah dalam kondisi clean secara manual.${NC}"
  echo ""
fi
read -rp "Tekan Enter untuk mulai, atau Ctrl+C untuk batal..."
echo ""

# ─────────────────────────────────────────────
# Prepare awal: migrate:fresh + seed + cache flush + container restart
# (sesuai proposal §3.4.4.1)
# ─────────────────────────────────────────────
if [ "${SKIP_PREPARE}" != "yes" ]; then
  echo -e "${CYAN}══════════════════════════════════════════════${NC}"
  echo -e "${CYAN}  PREPARE — Reset Kondisi Awal${NC}"
  echo -e "${CYAN}  (migrate:fresh, seed, cache flush, container restart)${NC}"
  echo -e "${CYAN}  Mulai: $(date)${NC}"
  echo -e "${CYAN}══════════════════════════════════════════════${NC}"

  "${SCRIPT_DIR}/prepare-benchmark.sh" "${BASE_URL}"

  if [ $? -ne 0 ]; then
    echo -e "${RED}✗ prepare-benchmark.sh gagal! Benchmark dibatalkan.${NC}"
    exit 1
  fi

  echo -e "${GREEN}✓ Prepare selesai. Sistem siap untuk benchmark.${NC}"
  echo ""
fi

echo "Mulai: $(date)"
echo ""

# ─────────────────────────────────────────────
# Restart container sebelum tiap iterasi
# (prepare-benchmark.sh sudah dipanggil per VU level di run-benchmark.sh)
# ─────────────────────────────────────────────
reset_and_verify() {
  local iteration=$1
  echo ""
  echo -e "${YELLOW}[iterasi ${iteration}] Restart container 'app' untuk membersihkan state memory...${NC}"
  cd "${PROJECT_DIR}"
  docker compose restart app
  if [ $? -ne 0 ]; then
    echo -e "${RED}[iterasi ${iteration}] ✗ Restart container 'app' gagal.${NC}"
    return 1
  else
    echo -e "${GREEN}[iterasi ${iteration}] ✓ Container 'app' di-restart.${NC}"
  fi

  echo -e "${YELLOW}[iterasi ${iteration}] Restart container 'nginx' agar upstream app IP ter-refresh...${NC}"
  docker compose restart nginx
  if [ $? -ne 0 ]; then
    echo -e "${RED}[iterasi ${iteration}] ✗ Restart container 'nginx' gagal.${NC}"
    return 1
  fi
  echo -e "${GREEN}[iterasi ${iteration}] ✓ Container 'nginx' di-restart.${NC}"

  echo -e "${YELLOW}[iterasi ${iteration}] Menunggu 30 detik agar app siap kembali...${NC}"
  sleep 30
}

# ─────────────────────────────────────────────
# Loop utama: 5 iterasi × strategi × skenario
# Sesuai proposal §3.4.4.4: 5 iterasi per kombinasi
# ─────────────────────────────────────────────
for iteration in $(seq 1 ${ITERATIONS}); do
  echo ""
  echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
  echo -e "${CYAN}  ITERASI ${iteration} / ${ITERATIONS}${NC}"
  echo -e "${CYAN}  Mulai: $(date)${NC}"
  echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"

  # Restart container sebelum setiap iterasi (sesuai proposal §3.4.4.1)
  if ! reset_and_verify "${iteration}"; then
    echo -e "${RED}✗ Reset container gagal. Benchmark dibatalkan.${NC}"
    exit 1
  fi

  for strategy in "${STRATEGIES[@]}"; do
    for scenario in "${SCENARIOS[@]}"; do
      COMPLETED=$((COMPLETED + 1))

      echo ""
      echo -e "${CYAN}══════════════════════════════════════════════${NC}"
      echo -e "${CYAN}  Attempt ${COMPLETED} / ${TOTAL_COMBINATION_ATTEMPTS}${NC}"
      echo -e "${CYAN}  Expected k6 runs: ${TOTAL_VU_RUNS} (${#CONCURRENT_USERS_LEVELS[@]} VU levels per attempt)${NC}"
      echo -e "${CYAN}  Iterasi  : ${iteration} / ${ITERATIONS}${NC}"
      echo -e "${CYAN}  Strategi : ${strategy}${NC}"
      echo -e "${CYAN}  Skenario : ${scenario}${NC}"
      echo -e "${CYAN}  Mulai    : $(date)${NC}"
      echo -e "${CYAN}══════════════════════════════════════════════${NC}"

      # Export effective VU levels so child run-benchmark.sh uses the same list
      export VU_LEVELS="${CONCURRENT_USERS_LEVELS[*]}"

      run_cmd=(
        "${SCRIPT_DIR}/run-benchmark.sh"
      )
      if [ "${CLUSTER_MODE}" = "true" ]; then
        run_cmd+=(--cluster)
      fi
      run_cmd+=("${strategy}" "${scenario}" "${BASE_URL}" "${iteration}")
      "${run_cmd[@]}"

      if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Selesai (iterasi ${iteration}, ${strategy} × ${scenario})${NC}"
      else
        echo -e "${RED}✗ Gagal (iterasi ${iteration}, ${strategy} × ${scenario})${NC}"
        FAILED=$((FAILED + 1))
      fi

      # Jeda antar kombinasi (kecuali yang terakhir)
      if [ $COMPLETED -lt $TOTAL_COMBINATION_ATTEMPTS ]; then
        echo ""
        echo -e "${YELLOW}Menunggu 60 detik sebelum run berikutnya...${NC}"
        sleep 60
      fi
    done
  done
done

# ─────────────────────────────────────────────
# Summary akhir
# ─────────────────────────────────────────────
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
HOURS=$((DURATION / 3600))
MINUTES=$(((DURATION % 3600) / 60))

echo ""
echo "=================================================="
echo "  Benchmark Suite Selesai!"
echo "=================================================="
echo -e "  Attempts succeeded : $((COMPLETED - FAILED)) / ${TOTAL_COMBINATION_ATTEMPTS}"
echo -e "  Attempts failed    : ${RED}${FAILED} / ${TOTAL_COMBINATION_ATTEMPTS}${NC}"
echo -e "  Expected k6 runs   : ${TOTAL_VU_RUNS} total (k6 per-VU success not tracked at suite level)"
echo -e "  Durasi       : ${HOURS}j ${MINUTES}m"
echo -e "  VU levels    : ${CONCURRENT_USERS_LEVELS[*]}"
echo -e "  Selesai      : $(date)"
echo -e "  Log          : ${LOG_FILE}"
echo "=================================================="
echo ""
echo "Langkah berikutnya:"
echo "  Jalankan analyze-results.sh untuk ekstrak & rata-rata metrik k6 dari 5 iterasi."
echo "  Jalankan analyze-resources.sh untuk ekstrak & rata-rata resource usage (CPU/mem/disk) dari 5 iterasi."
echo ""
