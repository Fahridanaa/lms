#!/bin/bash

# ============================================================
# Analyze Resource Usage from Benchmark Runs
#
# Usage  : ./scripts/analyze-resources.sh [results_dir]
# Contoh :
#   ./scripts/analyze-resources.sh                    # benchmark-results/
#   ./scripts/analyze-resources.sh benchmark-results-cluster
#
# Script ini membaca semua file *-resources.csv di direktori hasil
# benchmark dan mengekstrak metrik resource (CPU, memory, disk I/O) ke CSV:
#   - cpu_avg_pct, cpu_max_pct
#   - mem_avg_mb, mem_max_mb, mem_avg_pct, mem_max_pct
#   - disk_read_avg_mb_s, disk_read_max_mb_s
#   - disk_write_avg_mb_s, disk_write_max_mb_s
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RESULTS_DIR="${1:-${SCRIPT_DIR}/../benchmark-results}"
OUTPUT_CSV="${RESULTS_DIR}/resources-summary.csv"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

# Cek apakah python3 tersedia (untuk parsing CSV)
if ! command -v python3 &>/dev/null; then
  echo -e "${RED}Error: python3 dibutuhkan untuk parsing CSV.${NC}"
  exit 1
fi

echo ""
echo "=============================================="
echo "  Resource Usage Analyzer"
echo "=============================================="
echo -e "  Hasil dir : ${BLUE}${RESULTS_DIR}${NC}"
echo -e "  Output    : ${BLUE}${OUTPUT_CSV}${NC}"
echo "=============================================="
echo ""

# ─────────────────────────────────────────────
# Buat header CSV
# ─────────────────────────────────────────────
echo "strategy,scenario,concurrent_users,cpu_avg_pct,cpu_max_pct,mem_avg_mb,mem_max_mb,mem_avg_pct,mem_max_pct,disk_read_avg_mb_s,disk_read_max_mb_s,disk_write_avg_mb_s,disk_write_max_mb_s,iterations_averaged,redis_mode" > "${OUTPUT_CSV}"

# ─────────────────────────────────────────────
# Parse setiap resources CSV
# ─────────────────────────────────────────────
FOUND=0
STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy")
VU_LEVELS=(100 250 500 750 1000 1500 2000)

for strategy in "${STRATEGIES[@]}"; do
  for scenario in "${SCENARIOS[@]}"; do
    for vu in "${VU_LEVELS[@]}"; do
      # Kumpulkan semua file *-resources.csv dari iter1..iterN untuk kombinasi ini
      RESOURCE_FILES=$(ls "${RESULTS_DIR}/${strategy}/${scenario}"/iter*/${vu}vu-*-resources.csv 2>/dev/null \
        | sort | tr '\n' ';' | sed 's/;$//')

      if [ -z "${RESOURCE_FILES}" ]; then
        echo -e "${YELLOW}[skip] Tidak ada hasil resource untuk: ${strategy} / ${scenario} / ${vu}vu${NC}"
        continue
      fi

      FOUND=$((FOUND + 1))

      # Deteksi redis mode dari marker file
      redis_mode="single"
      first_file_dir=$(echo "${RESOURCE_FILES}" | cut -d';' -f1 | xargs dirname 2>/dev/null)
      if [ -f "${first_file_dir}/../.redis-mode" ]; then
        redis_mode=$(cat "${first_file_dir}/../.redis-mode")
      elif [ -f "${RESULTS_DIR}/${strategy}/${scenario}/.redis-mode" ]; then
        redis_mode=$(cat "${RESULTS_DIR}/${strategy}/${scenario}/.redis-mode")
      fi

      # Parse & rata-rata metrik dari semua iterasi menggunakan Python
      METRICS=$(python3 - "${RESOURCE_FILES}" <<'PYEOF'
import sys, os

# Files are semicolon-delimited in sys.argv[1]
files = [f for f in sys.argv[1].split(';') if f and os.path.exists(f)]

if not files:
    print("N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,0")
    sys.exit(0)

def parse_value(val):
    """Convert a CSV cell to float, returning None for N/A or empty."""
    if val is None:
        return None
    v = val.strip()
    if v == '' or v == 'N/A' or v.lower() == 'n/a':
        return None
    try:
        return float(v)
    except (ValueError, TypeError):
        return None

# Collect per-file aggregates
per_file = []
for fpath in files:
    rows = []
    try:
        with open(fpath, 'r') as f:
            lines = f.readlines()
    except Exception:
        continue

    if len(lines) < 2:
        # File has only header or is empty → skip
        continue

    # Parse CSV: skip header (line 0)
    for line in lines[1:]:
        line = line.strip()
        if not line:
            continue
        cols = line.split(',')
        if len(cols) < 7:
            continue
        # Columns: timestamp,cpu_pct,mem_used_mb,mem_total_mb,mem_used_pct,disk_read_mb_s,disk_write_mb_s
        row = {
            'cpu_pct':        parse_value(cols[1]),
            'mem_used_mb':    parse_value(cols[2]),
            'mem_used_pct':   parse_value(cols[4]),
            'disk_read_mb_s': parse_value(cols[5]),
            'disk_write_mb_s':parse_value(cols[6]),
        }
        rows.append(row)

    if not rows:
        continue

    # Compute per-column average and max for this file
    def avg_max(values):
        """Given a list of values (may contain None), return (avg, max)."""
        numeric = [v for v in values if v is not None]
        if not numeric:
            return (None, None)
        return (
            round(sum(numeric) / len(numeric), 2),
            round(max(numeric), 2),
        )

    cpu_avg, cpu_max = avg_max([r['cpu_pct'] for r in rows])
    mem_mb_avg, mem_mb_max = avg_max([r['mem_used_mb'] for r in rows])
    mem_pct_avg, mem_pct_max = avg_max([r['mem_used_pct'] for r in rows])
    dr_avg, dr_max = avg_max([r['disk_read_mb_s'] for r in rows])
    dw_avg, dw_max = avg_max([r['disk_write_mb_s'] for r in rows])

    per_file.append({
        'cpu_avg': cpu_avg,
        'cpu_max': cpu_max,
        'mem_mb_avg': mem_mb_avg,
        'mem_mb_max': mem_mb_max,
        'mem_pct_avg': mem_pct_avg,
        'mem_pct_max': mem_pct_max,
        'dr_avg': dr_avg,
        'dr_max': dr_max,
        'dw_avg': dw_avg,
        'dw_max': dw_max,
    })

if not per_file:
    print("N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,N/A,0")
    sys.exit(0)

# Average per-file aggregates across iterations
def avg_across_files(key):
    vals = [pf[key] for pf in per_file if pf.get(key) is not None]
    if not vals:
        return "N/A"
    return round(sum(vals) / len(vals), 2)

def max_across_files(key):
    vals = [pf[key] for pf in per_file if pf.get(key) is not None]
    if not vals:
        return "N/A"
    return round(max(vals), 2)

n = len(per_file)
result = (
    f"{avg_across_files('cpu_avg')},"
    f"{max_across_files('cpu_max')},"
    f"{avg_across_files('mem_mb_avg')},"
    f"{max_across_files('mem_mb_max')},"
    f"{avg_across_files('mem_pct_avg')},"
    f"{max_across_files('mem_pct_max')},"
    f"{avg_across_files('dr_avg')},"
    f"{max_across_files('dr_max')},"
    f"{avg_across_files('dw_avg')},"
    f"{max_across_files('dw_max')},"
    f"{n}"
)
print(result)
PYEOF
)

      echo "${strategy},${scenario},${vu},${METRICS},${redis_mode}" >> "${OUTPUT_CSV}"
      echo -e "${GREEN}[ok] ${strategy} / ${scenario} / ${vu}vu${NC}"
    done
  done
done

echo ""

if [ $FOUND -eq 0 ]; then
  echo -e "${RED}Tidak ada hasil resource benchmark yang ditemukan di ${RESULTS_DIR}${NC}"
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
