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
echo "strategy,scenario,concurrent_users,avg_ms,p90_ms,p95_ms,p99_ms,max_ms,throughput_rps,error_rate_pct,http_reqs_total,cache_hit_ratio_pct" > "${OUTPUT_CSV}"

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
      # Cari file summary untuk kombinasi ini
      SUMMARY_FILE=$(ls "${RESULTS_DIR}/${strategy}/${scenario}/${vu}vu-"*"-summary.json" 2>/dev/null | sort | tail -1)
      REDIS_AFTER=$(ls  "${RESULTS_DIR}/${strategy}/${scenario}/${vu}vu-"*"-redis-after.txt" 2>/dev/null | sort | tail -1)

      if [ -z "$SUMMARY_FILE" ] || [ ! -f "$SUMMARY_FILE" ]; then
        echo -e "${YELLOW}[skip] Tidak ada hasil untuk: ${strategy} / ${scenario} / ${vu}vu${NC}"
        continue
      fi

      FOUND=$((FOUND + 1))

      # Parse metrik dari summary JSON menggunakan Python
      METRICS=$(python3 - "$SUMMARY_FILE" "$REDIS_AFTER" <<'PYEOF'
import sys, json

summary_file = sys.argv[1]
redis_file   = sys.argv[2] if len(sys.argv) > 2 else ""

with open(summary_file) as f:
    data = json.load(f)

def get_val(data, *keys, default="N/A"):
    try:
        d = data
        for k in keys:
            d = d[k]
        return round(float(d), 2) if d is not None else default
    except:
        return default

metrics = data.get("metrics", {})
dur     = metrics.get("http_req_duration", {}).get("values", {})
reqs    = metrics.get("http_reqs",        {}).get("values", {})
failed  = metrics.get("http_req_failed",  {}).get("values", {})

avg_ms       = get_val(dur, "avg")
p90_ms       = get_val(dur, "p(90)")
p95_ms       = get_val(dur, "p(95)")
p99_ms       = get_val(dur, "p(99)")
max_ms       = get_val(dur, "max")
throughput   = get_val(reqs,   "rate")
total_reqs   = get_val(reqs,   "count")
error_pct    = round(get_val(failed, "rate") * 100, 2) if get_val(failed, "rate") != "N/A" else "N/A"

# Cache hit ratio dari Redis stats file
cache_hit_ratio = "N/A"
if redis_file:
    try:
        with open(redis_file) as rf:
            content = rf.read()
        hits   = 0
        misses = 0
        for line in content.splitlines():
            if "keyspace_hits:" in line:
                hits   = int(line.split(":")[1].strip())
            if "keyspace_misses:" in line:
                misses = int(line.split(":")[1].strip())
        total = hits + misses
        if total > 0:
            cache_hit_ratio = round((hits / total) * 100, 2)
    except:
        pass

print(f"{avg_ms},{p90_ms},{p95_ms},{p99_ms},{max_ms},{throughput},{error_pct},{total_reqs},{cache_hit_ratio}")
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
