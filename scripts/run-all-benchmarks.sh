#!/bin/bash

# ============================================================
# Complete Benchmark Suite — Semua Strategi × Semua Skenario
#
# Usage  : ./scripts/run-all-benchmarks.sh [base_url]
# Contoh : ./scripts/run-all-benchmarks.sh http://your-vps-ip
#
# Total run:
#   4 strategi × 2 skenario × 7 VU levels = 56 individual k6 runs
#   Estimasi waktu: ~10–12 jam (termasuk jeda antar run)
#
# Urutan eksekusi (no-cache duluan supaya ada baseline lebih awal):
#   no-cache → cache-aside → read-through → write-through
# ============================================================

BASE_URL=${1:-http://localhost}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Urutan strategi: no-cache dulu sebagai baseline
STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
# Skenario utama sesuai proposal (stress-test dijalankan terpisah)
SCENARIOS=("read-heavy" "write-heavy")

# Colors
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

echo ""
echo "=================================================="
echo "  LMS Cache Strategy — Complete Benchmark Suite"
echo "=================================================="
echo -e "  Base URL    : ${BLUE}${BASE_URL}${NC}"
echo -e "  Strategi    : ${BLUE}${STRATEGIES[*]}${NC}"
echo -e "  Skenario    : ${BLUE}${SCENARIOS[*]}${NC}"
echo -e "  Total Runs  : ${BLUE}${TOTAL_RUNS} (masing-masing 7 VU levels)${NC}"
echo -e "  Est. Waktu  : ${BLUE}~10–12 jam${NC}"
echo "=================================================="
echo ""
echo -e "${YELLOW}⚠  Pastikan:${NC}"
echo "   1. VPS sudah berjalan dan app sudah ter-deploy"
echo "   2. Database sudah di-seed (php artisan db:seed)"
echo "   3. Redis sudah aktif"
echo "   4. k6 sudah terinstall di mesin ini"
echo ""
read -rp "Tekan Enter untuk mulai, atau Ctrl+C untuk batal..."

# ─────────────────────────────────────────────
# Log file
# ─────────────────────────────────────────────
LOG_FILE="${SCRIPT_DIR}/../benchmark-results/benchmark-suite-$(date +%Y%m%d_%H%M%S).log"
mkdir -p "${SCRIPT_DIR}/../benchmark-results"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo ""
echo "Log disimpan di: ${LOG_FILE}"
echo "Mulai: $(date)"
echo ""

# ─────────────────────────────────────────────
# Loop: strategi → skenario
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
      echo -e "${GREEN}✓ Run ${COMPLETED}/${TOTAL_RUNS} berhasil${NC}"
    else
      echo -e "${RED}✗ Run ${COMPLETED}/${TOTAL_RUNS} gagal${NC}"
      FAILED=$((FAILED + 1))
    fi

    # Jeda antar run (kecuali run terakhir)
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
echo "=================================================="
echo ""
echo "Langkah berikutnya:"
echo "  Jalankan analyze-results.sh untuk ekstrak metrik:"
echo "  ./scripts/analyze-results.sh"
echo ""
