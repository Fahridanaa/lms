#!/bin/bash

# ============================================================
# Complete Benchmark Suite — Semua Strategi × Semua Skenario
#
# Usage  : ./scripts/run-all-benchmarks.sh [base_url]
# Contoh : ./scripts/run-all-benchmarks.sh http://localhost
#
# Total run:
#   4 strategi × 2 skenario × 7 VU levels = 56 individual k6 runs
#   Estimasi waktu: ~10–12 jam (termasuk jeda antar run)
#
# Urutan eksekusi (no-cache duluan supaya ada baseline lebih awal):
#   no-cache → cache-aside → read-through → write-through
# ============================================================

BASE_URL=${1:-http://localhost}
SKIP_PREPARE=${2:-no}   # set "yes" untuk skip prepare (debug/resume)

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RESULTS_DIR="${SCRIPT_DIR}/../benchmark-results"

STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy")

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

TOTAL_RUNS=$(( ${#STRATEGIES[@]} * ${#SCENARIOS[@]} ))
COMPLETED=0
FAILED=0
START_TIME=$(date +%s)

# ─────────────────────────────────────────────
# Log file
# ─────────────────────────────────────────────
mkdir -p "${RESULTS_DIR}"
LOG_FILE="${RESULTS_DIR}/benchmark-suite-$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo ""
echo "=================================================="
echo "  LMS Cache Strategy — Complete Benchmark Suite"
echo "=================================================="
echo -e "  Base URL    : ${BLUE}${BASE_URL}${NC}"
echo -e "  Strategi    : ${BLUE}${STRATEGIES[*]}${NC}"
echo -e "  Skenario    : ${BLUE}${SCENARIOS[*]}${NC}"
echo -e "  Total Runs  : ${BLUE}${TOTAL_RUNS} kombinasi (masing-masing 7 VU levels)${NC}"
echo -e "  Est. Waktu  : ${BLUE}~10–12 jam (+ ~20 menit prepare)${NC}"
echo -e "  Log         : ${BLUE}${LOG_FILE}${NC}"
echo "=================================================="
echo ""
echo -e "${YELLOW}⚠  Pastikan:${NC}"
echo "   1. App sudah berjalan (docker compose ps)"
echo "   2. k6 sudah terinstall"
echo "   3. Cukup disk space di /var/www/lms/benchmark-results"
echo ""
if [ "${SKIP_PREPARE}" = "yes" ]; then
  echo -e "${YELLOW}⚠  SKIP_PREPARE=yes — prepare-benchmark.sh TIDAK akan dijalankan.${NC}"
  echo -e "${YELLOW}   Pastikan database sudah dalam kondisi clean secara manual.${NC}"
  echo ""
fi
read -rp "Tekan Enter untuk mulai, atau Ctrl+C untuk batal..."
echo ""

# ─────────────────────────────────────────────
# Reset kondisi awal (sesuai proposal: migrate:fresh + seed
# + cache flush dilakukan sebelum setiap iterasi)
# ─────────────────────────────────────────────
if [ "${SKIP_PREPARE}" != "yes" ]; then
  echo -e "${CYAN}══════════════════════════════════════════════${NC}"
  echo -e "${CYAN}  PREPARE — Reset Kondisi Awal${NC}"
  echo -e "${CYAN}  (migrate:fresh, seed, cache flush)${NC}"
  echo -e "${CYAN}  Mulai: $(date)${NC}"
  echo -e "${CYAN}══════════════════════════════════════════════${NC}"

  "${SCRIPT_DIR}/prepare-benchmark.sh" "${BASE_URL}"

  if [ $? -ne 0 ]; then
    echo -e "${RED}✗ prepare-benchmark.sh gagal! Benchmark dibatalkan.${NC}"
    exit 1
  fi

  echo -e "${GREEN}✓ Prepare selesai. Sistem siap untuk benchmark.${NC}"
  echo ""
  echo -e "${YELLOW}Menunggu 30 detik agar sistem stabil setelah seeding...${NC}"
  sleep 30
fi

echo "Mulai: $(date)"
echo ""

# ─────────────────────────────────────────────
# Loop: strategi × skenario
# ─────────────────────────────────────────────
for strategy in "${STRATEGIES[@]}"; do
  for scenario in "${SCENARIOS[@]}"; do
    COMPLETED=$((COMPLETED + 1))

    echo ""
    echo -e "${CYAN}══════════════════════════════════════════════${NC}"
    echo -e "${CYAN}  Run ${COMPLETED} / ${TOTAL_RUNS}${NC}"
    echo -e "${CYAN}  Strategi : ${strategy}${NC}"
    echo -e "${CYAN}  Skenario : ${scenario}${NC}"
    echo -e "${CYAN}  Mulai    : $(date)${NC}"
    echo -e "${CYAN}══════════════════════════════════════════════${NC}"

    "${SCRIPT_DIR}/run-benchmark.sh" "${strategy}" "${scenario}" "${BASE_URL}"

    if [ $? -eq 0 ]; then
      echo -e "${GREEN}✓ Run ${COMPLETED}/${TOTAL_RUNS} selesai (${strategy} × ${scenario})${NC}"
    else
      echo -e "${RED}✗ Run ${COMPLETED}/${TOTAL_RUNS} gagal (${strategy} × ${scenario})${NC}"
      FAILED=$((FAILED + 1))
    fi

    # Jeda antar kombinasi (kecuali yang terakhir)
    if [ $COMPLETED -lt $TOTAL_RUNS ]; then
      echo ""
      echo -e "${YELLOW}Menunggu 60 detik sebelum run berikutnya...${NC}"
      sleep 60
    fi
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
echo -e "  Berhasil  : ${GREEN}$((TOTAL_RUNS - FAILED)) / ${TOTAL_RUNS}${NC}"
echo -e "  Gagal     : ${RED}${FAILED} / ${TOTAL_RUNS}${NC}"
echo -e "  Durasi    : ${HOURS}j ${MINUTES}m"
echo -e "  Selesai   : $(date)"
echo -e "  Log       : ${LOG_FILE}"
echo "=================================================="
echo ""
echo "Langkah berikutnya:"
echo "  Download hasil: scp -r root@<vps-ip>:/var/www/lms/benchmark-results ./benchmark-results"
echo "  Atau jalankan analyze-results.sh untuk ekstrak metrik."
echo ""
