#!/bin/bash

# ============================================================
# Complete Benchmark Suite — Semua Strategi × Semua Skenario × 5 Iterasi
#
# Usage  : ./scripts/run-all-benchmarks.sh [base_url]
# Contoh : ./scripts/run-all-benchmarks.sh http://localhost
#
# Total run (sesuai proposal §3.4.4.4):
#   4 strategi × 2 skenario × 5 iterasi × 7 VU levels = 280 individual k6 runs
#   Estimasi waktu: ~50–60 jam (termasuk jeda, warm-up, dan restart antar iterasi)
#
# Prosedur per iterasi (sesuai proposal §3.4.4.1):
#   1. Restart container 'app' untuk menghilangkan state di memory
#   2. Flush semua cache
#   3. Jalankan semua kombinasi strategi × skenario dengan 7 VU levels
#
# Urutan eksekusi (no-cache duluan supaya ada baseline lebih awal):
#   no-cache → cache-aside → read-through → write-through
# ============================================================

BASE_URL=${1:-http://localhost}
SKIP_PREPARE=${2:-no}   # set "yes" untuk skip prepare (debug/resume)

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
RESULTS_DIR="${SCRIPT_DIR}/../benchmark-results"

STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy")
ITERATIONS=5

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

TOTAL_COMBINATIONS=$(( ${#STRATEGIES[@]} * ${#SCENARIOS[@]} ))
TOTAL_RUNS=$(( TOTAL_COMBINATIONS * ITERATIONS ))
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
echo -e "  Base URL      : ${BLUE}${BASE_URL}${NC}"
echo -e "  Strategi      : ${BLUE}${STRATEGIES[*]}${NC}"
echo -e "  Skenario      : ${BLUE}${SCENARIOS[*]}${NC}"
echo -e "  Iterasi       : ${BLUE}${ITERATIONS}x per kombinasi (sesuai proposal §3.4.4.4)${NC}"
echo -e "  Total Runs    : ${BLUE}${TOTAL_RUNS} kombinasi (masing-masing 7 VU levels)${NC}"
echo -e "  Est. Waktu    : ${BLUE}~50–60 jam (+ ~20 menit prepare)${NC}"
echo -e "  Log           : ${BLUE}${LOG_FILE}${NC}"
echo "=================================================="
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
# Restart container 'app' sebelum tiap iterasi
# Sesuai proposal §3.4.4.1: menghilangkan state di memory
# ─────────────────────────────────────────────
restart_app_container() {
  local iteration=$1
  echo ""
  echo -e "${YELLOW}[iterasi ${iteration}] Restart container 'app' untuk membersihkan state memory...${NC}"
  cd "${PROJECT_DIR}"
  docker compose restart app
  if [ $? -ne 0 ]; then
    echo -e "${RED}Warning: Restart container gagal. Melanjutkan...${NC}"
  else
    echo -e "${GREEN}[iterasi ${iteration}] ✓ Container 'app' di-restart.${NC}"
  fi
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
  restart_app_container "${iteration}"

  for strategy in "${STRATEGIES[@]}"; do
    for scenario in "${SCENARIOS[@]}"; do
      COMPLETED=$((COMPLETED + 1))

      echo ""
      echo -e "${CYAN}══════════════════════════════════════════════${NC}"
      echo -e "${CYAN}  Kombinasi ${COMPLETED} / ${TOTAL_RUNS}${NC}"
      echo -e "${CYAN}  Iterasi  : ${iteration} / ${ITERATIONS}${NC}"
      echo -e "${CYAN}  Strategi : ${strategy}${NC}"
      echo -e "${CYAN}  Skenario : ${scenario}${NC}"
      echo -e "${CYAN}  Mulai    : $(date)${NC}"
      echo -e "${CYAN}══════════════════════════════════════════════${NC}"

      "${SCRIPT_DIR}/run-benchmark.sh" "${strategy}" "${scenario}" "${BASE_URL}" "${iteration}"

      if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Selesai (iterasi ${iteration}, ${strategy} × ${scenario})${NC}"
      else
        echo -e "${RED}✗ Gagal (iterasi ${iteration}, ${strategy} × ${scenario})${NC}"
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
echo "  Jalankan analyze-results.sh untuk ekstrak & rata-rata metrik dari 5 iterasi."
echo ""
