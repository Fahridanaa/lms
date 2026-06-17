#!/bin/bash
# ============================================================
# Remote Complete Benchmark Suite — All Strategies × All Scenarios × N Iterations
#
# Usage  : ./scripts/run-all-remote-benchmarks.sh [--cluster] [--yes]
#
# Orchestrates the full benchmark matrix from the k6 VPS.
# Each child combination delegates to run-remote-benchmark.sh.
#
# Default: 3 iterations (BENCHMARK_ITERATIONS=env for override)
#   4 strategies × 2 scenarios × 3 iterations × 7 VU levels = 168 individual k6 runs
#
# Strategy order (baseline first):
#   no-cache → cache-aside → read-through → write-through
#
# Environment (see benchmark-remote-config.sh):
#   BENCHMARK_MODE=remote         Required
#   LMS_SSH_HOST=user@lms-vps     Required
#   LMS_PROJECT_DIR=/path         Required
#   BASE_URL=https://...          Required
#   VU_LEVELS="100 250 500..."    Optional
#   BENCHMARK_ITERATIONS=3        Optional
#   CLUSTER_MODE=true             Optional
# ============================================================

# Note: no set -euo pipefail here because exec > >(tee ...) process substitution
# can behave unexpectedly with pipefail. Matches local run-all-benchmarks.sh.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# ─────────────────────────────────────────────
# Source remote config FIRST (provides default env vars)
# CLI flags parsed afterwards override these defaults.
# ─────────────────────────────────────────────
REMOTE_CONFIG="${SCRIPT_DIR}/benchmark-remote-config.sh"
if [ -f "${REMOTE_CONFIG}" ]; then
  source "${REMOTE_CONFIG}"
fi

# ─────────────────────────────────────────────
# Defaults
# ─────────────────────────────────────────────
CLUSTER_MODE="${CLUSTER_MODE:-false}"
ASSUME_YES="${ASSUME_YES:-false}"

# Parse flags
while [ $# -gt 0 ]; do
  case "$1" in
    --cluster) CLUSTER_MODE=true; shift ;;
    --yes) ASSUME_YES=true; shift ;;
    -h|--help)
      echo "Usage: $(basename "$0") [--cluster] [--yes]"
      echo ""
      echo "Flags:"
      echo "  --cluster    Redis Cluster mode"
      echo "  --yes        Skip interactive confirmation"
      echo ""
      echo "Environment (see benchmark-remote-config.sh):"
      echo "  BENCHMARK_MODE=remote       Required"
      echo "  LMS_SSH_HOST=user@host       Required"
      echo "  LMS_PROJECT_DIR=/path        Required"
      echo "  BASE_URL=https://...         Required"
      echo "  VU_LEVELS=\"100 250 500...\"   Optional"
      echo "  BENCHMARK_ITERATIONS=3       Optional"
      echo "  CLUSTER_MODE=true            Optional"
      exit 0
      ;;
    -*)
      echo -e "${RED}Error: Unknown option $1${NC}"
      exit 1
      ;;
    *) break ;;
  esac
done

# ─────────────────────────────────────────────
# Colors
# ─────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# ─────────────────────────────────────────────
# Validate required env vars
# ─────────────────────────────────────────────
validate_env() {
  local missing=0

  if [ -z "${LMS_SSH_HOST:-}" ]; then
    echo -e "${RED}Error: LMS_SSH_HOST is not set.${NC}" >&2
    echo "  Set it in environment or source benchmark-remote-config.sh" >&2
    missing=1
  fi

  if [ -z "${LMS_PROJECT_DIR:-}" ]; then
    echo -e "${RED}Error: LMS_PROJECT_DIR is not set.${NC}" >&2
    missing=1
  fi

  if [ -z "${BASE_URL:-}" ]; then
    echo -e "${RED}Error: BASE_URL is not set.${NC}" >&2
    missing=1
  fi

  return $missing
}

# ─────────────────────────────────────────────
# Suite constants
# ─────────────────────────────────────────────
STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy")
ITERATIONS=${BENCHMARK_ITERATIONS:-3}

if [ -n "${VU_LEVELS+x}" ]; then
  IFS=' ' read -ra CONCURRENT_USERS_LEVELS <<< "${VU_LEVELS}"
else
  CONCURRENT_USERS_LEVELS=(100 250 500 750 1000 1500 2000)
fi

# Validate VU values
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

K6_PROJECT_DIR="${K6_PROJECT_DIR:-${LMS_PROJECT_DIR:-}}"
RESULTS_DIR="${RESULTS_DIR:-${K6_PROJECT_DIR}/benchmark-results}"

TOTAL_ATTEMPTS=$(( ${#STRATEGIES[@]} * ${#SCENARIOS[@]} * ITERATIONS ))
TOTAL_VU_RUNS=$(( TOTAL_ATTEMPTS * ${#CONCURRENT_USERS_LEVELS[@]} ))
COMPLETED=0
FAILED=0
START_TIME=$(date +%s)

# ─────────────────────────────────────────────
# Create results directory and suite log
# ─────────────────────────────────────────────
mkdir -p "${RESULTS_DIR}"

LOG_FILE="${RESULTS_DIR}/benchmark-suite-remote-$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo ""
echo "=================================================="
echo "  Remote LMS Cache Strategy — Complete Suite"
echo "=================================================="
echo -e "  Base URL      : ${BLUE}${BASE_URL}${NC}"
echo -e "  LMS Host      : ${BLUE}${LMS_SSH_HOST}${NC}"
echo -e "  Strategi      : ${BLUE}${STRATEGIES[*]}${NC}"
echo -e "  Skenario      : ${BLUE}${SCENARIOS[*]}${NC}"
echo -e "  Iterasi       : ${BLUE}${ITERATIONS}x per kombinasi${NC}"

if [ "${CLUSTER_MODE}" = "true" ]; then
  echo -e "  Redis         : ${BLUE}Cluster${NC}"
else
  echo -e "  Redis         : ${BLUE}Single${NC}"
fi
echo -e "  Combination attempts  : ${BLUE}${TOTAL_ATTEMPTS} (strategi × skenario × iterasi)${NC}"
echo -e "  Expected k6 runs      : ${BLUE}${TOTAL_VU_RUNS} (attempts × VU levels)${NC}"
echo -e "  VU levels     : ${BLUE}${CONCURRENT_USERS_LEVELS[*]}${NC}"
echo -e "  Log           : ${BLUE}${LOG_FILE}${NC}"
echo "=================================================="
echo ""

# ─────────────────────────────────────────────
# Interactive confirmation (skip with --yes)
# ─────────────────────────────────────────────
if [ "${ASSUME_YES}" != "true" ]; then
  echo -e "${YELLOW}⚠  Pastikan:${NC}"
  echo "   1. LMS VPS is running and reachable"
  echo "   2. k6 is installed on this host"
  echo "   3. Sufficient disk space for results"
  echo "   4. benchmark-remote-config.sh is configured correctly"
  echo ""
  read -rp "Tekan Enter untuk mulai, atau Ctrl+C untuk batal..."
  echo ""
fi

# ─────────────────────────────────────────────
# Preflight: validate env + run remote preflight
# ─────────────────────────────────────────────
echo -e "${YELLOW}[preflight] Validating environment...${NC}"
if ! validate_env; then
  echo -e "${RED}[preflight] ✗ Environment validation failed${NC}"
  exit 1
fi
echo -e "${GREEN}[preflight] ✓ Environment OK${NC}"
echo ""

echo -e "${YELLOW}[preflight] Running remote preflight...${NC}"
# Build preflight command array — pass --cluster only when CLUSTER_MODE is explicitly true
preflight_cmd=("${SCRIPT_DIR}/run-remote-benchmark.sh")
if [ "${CLUSTER_MODE}" = "true" ]; then
  preflight_cmd+=(--cluster)
fi
preflight_cmd+=(--preflight)

CLUSTER_MODE="${CLUSTER_MODE}" "${preflight_cmd[@]}" || {
  echo -e "${RED}[preflight] ✗ Remote preflight failed — aborting${NC}"
  exit 1
}
echo ""

# ─────────────────────────────────────────────
# Write metadata after preflight passes
# ─────────────────────────────────────────────
if [ "${CLUSTER_MODE}" = "true" ]; then
  echo "cluster" > "${RESULTS_DIR}/.redis-mode"
else
  echo "single" > "${RESULTS_DIR}/.redis-mode"
fi
printf '%s\n' "${CONCURRENT_USERS_LEVELS[@]}" > "${RESULTS_DIR}/.vu-levels"
echo -e "${GREEN}[preflight] ✓ Suite metadata written (.redis-mode, .vu-levels)${NC}"
echo ""

echo "Mulai: $(date)"
echo ""

# ─────────────────────────────────────────────
# Loop utama: iteration → strategy → scenario
# Child run-remote-benchmark.sh handles VU levels internally
# ─────────────────────────────────────────────
for iteration in $(seq 1 ${ITERATIONS}); do
  echo ""
  echo -e "${CYAN}╔══════════════════════════════════════════════╗${NC}"
  echo -e "${CYAN}  ITERASI ${iteration} / ${ITERATIONS}${NC}"
  echo -e "${CYAN}  Mulai: $(date)${NC}"
  echo -e "${CYAN}╚══════════════════════════════════════════════╝${NC}"

  for strategy in "${STRATEGIES[@]}"; do
    for scenario in "${SCENARIOS[@]}"; do
      COMPLETED=$((COMPLETED + 1))

      echo ""
      echo -e "${CYAN}══════════════════════════════════════════════${NC}"
      echo -e "${CYAN}  Attempt ${COMPLETED} / ${TOTAL_ATTEMPTS}${NC}"
      echo -e "${CYAN}  Expected k6 runs: ${TOTAL_VU_RUNS} (${#CONCURRENT_USERS_LEVELS[@]} VU levels per attempt)${NC}"
      echo -e "${CYAN}  Iterasi  : ${iteration} / ${ITERATIONS}${NC}"
      echo -e "${CYAN}  Strategi : ${strategy}${NC}"
      echo -e "${CYAN}  Skenario : ${scenario}${NC}"
      echo -e "${CYAN}  Mulai    : $(date)${NC}"
      echo -e "${CYAN}══════════════════════════════════════════════${NC}"

      # Export effective VU levels so child runner uses the same list
      export VU_LEVELS="${CONCURRENT_USERS_LEVELS[*]}"

      # Build child command
      run_cmd=("${SCRIPT_DIR}/run-remote-benchmark.sh")
      if [ "${CLUSTER_MODE}" = "true" ]; then
        run_cmd+=(--cluster)
      fi
      run_cmd+=("${strategy}" "${scenario}" "${BASE_URL}" "${iteration}")

      if "${run_cmd[@]}"; then
        echo -e "${GREEN}✓ Selesai (iterasi ${iteration}, ${strategy} × ${scenario})${NC}"
      else
        echo -e "${RED}✗ Gagal (iterasi ${iteration}, ${strategy} × ${scenario})${NC}"
        FAILED=$((FAILED + 1))
      fi

      # Jeda antar kombinasi (kecuali yang terakhir)
      if [ $COMPLETED -lt $TOTAL_ATTEMPTS ]; then
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
echo "  Remote Benchmark Suite Selesai!"
echo "=================================================="
echo -e "  Attempts succeeded : $((COMPLETED - FAILED)) / ${TOTAL_ATTEMPTS}"
echo -e "  Attempts failed    : ${RED}${FAILED} / ${TOTAL_ATTEMPTS}${NC}"
echo -e "  Expected k6 runs   : ${TOTAL_VU_RUNS} total (k6 per-VU success not tracked at suite level)"
echo -e "  Durasi       : ${HOURS}j ${MINUTES}m"
echo -e "  VU levels    : ${CONCURRENT_USERS_LEVELS[*]}"
echo -e "  Selesai      : $(date)"
echo -e "  Log          : ${LOG_FILE}"
echo "=================================================="
echo ""
echo "Langkah berikutnya:"
echo "  Jalankan analyze-results.sh untuk ekstrak & rata-rata metrik k6."
echo "  Jalankan analyze-resources.sh untuk ekstrak & rata-rata resource usage (CPU/mem/disk)."
echo ""
