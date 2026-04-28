#!/bin/bash

# ============================================================
# Analyze Benchmark Results
#
# Usage  : ./scripts/analyze-results.sh
#
# Script ini membaca semua file *-summary.json di benchmark-results/
# dan mengekstrak metrik utama ke CSV yang siap dipakai di skripsi:
#   - response_time_avg, p90, p95, p99
#   - throughput (req/s)
#   - error_rate
#   - cache_hit_ratio (dari Redis stats)
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RESULTS_DIR="${SCRIPT_DIR}/../benchmark-results"
OUTPUT_CSV="${RESULTS_DIR}/metrics-summary.csv"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

# Cek apakah python3 tersedia (untuk parsing JSON)
if ! command -v python3 &>/dev/null; then
  echo -e "${RED}Error: python3 dibutuhkan untuk parsing JSON.${NC}"
  exit 1
fi

echo ""
echo "=============================================="
echo "  Benchmark Results Analyzer"
echo "=============================================="
echo -e "  Hasil dir : ${BLUE}${RESULTS_DIR}${NC}"
echo -e "  Output    : ${BLUE}${OUTPUT_CSV}${NC}"
echo "=============================================="
echo ""

# ─────────────────────────────────────────────
# Buat header CSV
# ─────────────────────────────────────────────
echo "strategy,scenario,concurrent_users,avg_ms,p90_ms,p95_ms,p99_ms,max_ms,throughput_rps,error_rate_pct,http_reqs_total,cache_hit_ratio_pct,iterations_averaged" > "${OUTPUT_CSV}"

# ─────────────────────────────────────────────
# Parse setiap summary JSON
# ─────────────────────────────────────────────
FOUND=0
STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy")
VU_LEVELS=(100 250 500 750 1000 1500 2000)

for strategy in "${STRATEGIES[@]}"; do
  for scenario in "${SCENARIOS[@]}"; do
    for vu in "${VU_LEVELS[@]}"; do
      # Kumpulkan semua file dari iter1..iterN untuk kombinasi ini
      # (sesuai proposal §3.4.4.4: 5 iterasi, hasil dirata-rata)
      SUMMARY_FILES=$(ls "${RESULTS_DIR}/${strategy}/${scenario}/iter*/${vu}vu-"*"-summary.json" 2>/dev/null \
        | sort | tr '\n' ';' | sed 's/;$//')
      HIT_RATIO_FILES=$(ls "${RESULTS_DIR}/${strategy}/${scenario}/iter*/${vu}vu-"*"-cache-hit-ratio.txt" 2>/dev/null \
        | sort | tr '\n' ';' | sed 's/;$//')

      if [ -z "${SUMMARY_FILES}" ]; then
        echo -e "${YELLOW}[skip] Tidak ada hasil untuk: ${strategy} / ${scenario} / ${vu}vu${NC}"
        continue
      fi

      FOUND=$((FOUND + 1))

      # Parse & rata-rata metrik dari semua iterasi menggunakan Python
      METRICS=$(python3 - "${SUMMARY_FILES}" "${HIT_RATIO_FILES}" <<'PYEOF'
import sys, json, os

summary_files    = [f for f in sys.argv[1].split(';') if f and os.path.exists(f)]
hit_ratio_files  = [f for f in sys.argv[2].split(';') if f and os.path.exists(f)] if len(sys.argv) > 2 else []

if not summary_files:
    print("N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,0")
    sys.exit(0)

def get_metric(data, *keys):
    try:
        d = data
        for k in keys:
            d = d[k]
        return float(d) if d is not None else None
    except:
        return None

collected = []
for sf in summary_files:
    try:
        with open(sf) as f:
            data = json.load(f)
        m = data.get("metrics", {})
        dur    = m.get("http_req_duration", {}).get("values", {})
        reqs   = m.get("http_reqs",         {}).get("values", {})
        failed = m.get("http_req_failed",   {}).get("values", {})
        collected.append({
            'avg_ms':     get_metric(dur, "avg"),
            'p90_ms':     get_metric(dur, "p(90)"),
            'p95_ms':     get_metric(dur, "p(95)"),
            'p99_ms':     get_metric(dur, "p(99)"),
            'max_ms':     get_metric(dur, "max"),
            'throughput': get_metric(reqs, "rate"),
            'total_reqs': get_metric(reqs, "count"),
            'error_pct':  (get_metric(failed, "rate") or 0) * 100,
        })
    except:
        pass

def avg_key(key):
    vals = [row[key] for row in collected if row.get(key) is not None]
    return round(sum(vals) / len(vals), 2) if vals else "N/A"

# Cache hit ratio: rata-rata pre-computed delta dari tiap iterasi
hit_ratios = []
for hf in hit_ratio_files:
    try:
        with open(hf) as f:
            hit_ratios.append(float(f.read().strip()))
    except:
        pass
avg_hr = round(sum(hit_ratios) / len(hit_ratios), 2) if hit_ratios else "N/A"

n = len(collected)
print(f"{avg_key('avg_ms')},{avg_key('p90_ms')},{avg_key('p95_ms')},{avg_key('p99_ms')},{avg_key('max_ms')},{avg_key('throughput')},{avg_key('error_pct')},{avg_key('total_reqs')},{avg_hr},{n}")
PYEOF
)

      echo "${strategy},${scenario},${vu},${METRICS}" >> "${OUTPUT_CSV}"
      echo -e "${GREEN}[ok] ${strategy} / ${scenario} / ${vu}vu${NC}"
    done
  done
done

echo ""

if [ $FOUND -eq 0 ]; then
  echo -e "${RED}Tidak ada hasil benchmark yang ditemukan di ${RESULTS_DIR}${NC}"
  echo "Pastikan sudah menjalankan run-benchmark.sh atau run-all-benchmarks.sh terlebih dahulu."
  exit 1
fi

echo "=============================================="
echo -e "  ${GREEN}Selesai! ${FOUND} hasil diproses.${NC}"
echo -e "  CSV tersimpan di: ${BLUE}${OUTPUT_CSV}${NC}"
echo "=============================================="
echo ""
echo "Buka CSV di Excel/Sheets untuk membuat tabel dan grafik skripsi."
echo ""

# Tampilkan preview CSV
echo "Preview (5 baris pertama):"
echo "─────────────────────────────────────────────────────────────────────────"
head -6 "${OUTPUT_CSV}" | column -t -s ','
echo "─────────────────────────────────────────────────────────────────────────"
echo ""
