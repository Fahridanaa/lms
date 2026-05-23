#!/bin/bash

# ============================================================
# LMS Caching Strategy Benchmark Runner
#
# Usage  : ./scripts/run-benchmark.sh [--cluster] [strategy] [scenario] [base_url]
# Contoh :
#   ./scripts/run-benchmark.sh cache-aside read-heavy http://localhost
#   ./scripts/run-benchmark.sh --cluster cache-aside read-heavy http://localhost
#
# --cluster  : Jalankan benchmark terhadap Redis Cluster (3 masters + 3 replicas)
#              Pastikan cluster sudah up: ./scripts/setup-redis-cluster.sh up
#
# Script ini menjalankan k6 untuk satu strategi + satu skenario,
# berulang untuk 7 level concurrent users sesuai proposal:
#   100, 250, 500, 750, 1000, 1500, 2000
#
# Metrik yang dikumpulkan per run:
#   - k6 summary JSON  : response time (avg/min/max/p90/p95/p99), throughput, error rate
#   - resources CSV    : CPU%, Memory (MB), Disk I/O (MB/s) setiap 10 detik
#                        menggunakan top, free, iostat (sesuai proposal: htop & iostat)
#   - redis stats      : keyspace hits/misses sebelum & sesudah → cache hit ratio
#   - cluster stats    : (jika --cluster) per-node cluster info, keyspace, nodes
# ============================================================

CLUSTER_MODE=false

# Parse --cluster flag (before positional args)
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

STRATEGY=${1:-cache-aside}
SCENARIO=${2:-read-heavy}
BASE_URL=${3:-http://localhost}
ITERATION=${4:-1}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
RESULTS_DIR="${PROJECT_DIR}/benchmark-results"
K6_SCRIPT="${PROJECT_DIR}/tests/Benchmark/k6/${SCENARIO}-scenario.js"

CONCURRENT_USERS_LEVELS=(100 250 500 750 1000 1500 2000)
CLUSTER_NODES=("redis-c1" "redis-c2" "redis-c3" "redis-c4" "redis-c5" "redis-c6")

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
    exit 1
  fi

  # Cek iostat tersedia (dari package sysstat)
  if ! command -v iostat &>/dev/null; then
    echo -e "${YELLOW}Warning: iostat tidak ditemukan. Install dengan: apt-get install sysstat${NC}"
    echo -e "${YELLOW}Disk I/O tidak akan dimonitor.${NC}"
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

  if [ "${CLUSTER_MODE}" = "true" ]; then
    # FLUSHALL on all cluster nodes
    for node in "${CLUSTER_NODES[@]}"; do
      docker compose exec -T "$node" redis-cli FLUSHALL >/dev/null 2>&1 || true
    done
  else
    docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1 || true
  fi
  echo -e "${GREEN}[setup] Cache bersih.${NC}"
}

# ─────────────────────────────────────────────
# Warm-up cache (60 detik, sesuai proposal §3.3.1.2)
# Kirim request ringan ke semua endpoint utama secara
# bergantian selama 60 detik untuk inisialisasi
# connection pool DB dan mengisi cache dengan hot keys.
# Hasilnya tidak masuk metrik k6.
# ─────────────────────────────────────────────
warmup_cache() {
  local warmup_duration=60
  local end_time=$(( $(date +%s) + warmup_duration ))
  local req_count=0

  # Endpoint read-heavy yang paling sering diakses
  # (cycle melalui sample ID supaya lebih banyak key ter-cache)
  local quiz_ids=(1 5 10 25 50 100 150 200 250)
  local course_ids=(1 5 10 20 30 40 50)
  local material_ids=(1 10 50 100 200 300 400 500)
  local idx=0

  while [ $(date +%s) -lt ${end_time} ]; do
    local qi=${quiz_ids[$((idx % ${#quiz_ids[@]}))]}
    local ci=${course_ids[$((idx % ${#course_ids[@]}))]}
    local mi=${material_ids[$((idx % ${#material_ids[@]}))]}

    # Fire 4 parallel requests dan lanjut (tidak tunggu semua selesai)
    curl -sf --max-time 5 "${BASE_URL}/api/quizzes/${qi}"          -o /dev/null &
    curl -sf --max-time 5 "${BASE_URL}/api/courses/${ci}/materials" -o /dev/null &
    curl -sf --max-time 5 "${BASE_URL}/api/materials/${mi}"         -o /dev/null &
    curl -sf --max-time 5 "${BASE_URL}/api/courses/${ci}/gradebook" -o /dev/null &

    idx=$(( idx + 1 ))
    req_count=$(( req_count + 4 ))
    sleep 1
    # Wait for background curl processes periodically to avoid fork bomb
    if [ $(( idx % 5 )) -eq 0 ]; then
      wait
    fi
  done

  wait  # pastikan semua curl selesai
  echo -e "${GREEN}[warm-up] Selesai. ~${req_count} requests dikirim ke cache.${NC}"
}

# ─────────────────────────────────────────────
# Ambil Redis stats (hits/misses) sebagai angka
# ─────────────────────────────────────────────
get_redis_hits() {
  cd "${PROJECT_DIR}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    # Sum hits across all cluster nodes
    local total=0
    for node in "${CLUSTER_NODES[@]}"; do
      local hits
      hits=$(docker compose exec -T "$node" redis-cli INFO stats 2>/dev/null \
        | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r ' || echo "0")
      total=$(( total + hits ))
    done
    echo "${total}"
  else
    docker compose exec -T redis redis-cli INFO stats 2>/dev/null \
      | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r ' || echo "0"
  fi
}
get_redis_misses() {
  cd "${PROJECT_DIR}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    local total=0
    for node in "${CLUSTER_NODES[@]}"; do
      local misses
      misses=$(docker compose exec -T "$node" redis-cli INFO stats 2>/dev/null \
        | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ' || echo "0")
      total=$(( total + misses ))
    done
    echo "${total}"
  else
    docker compose exec -T redis redis-cli INFO stats 2>/dev/null \
      | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ' || echo "0"
  fi
}

# ─────────────────────────────────────────────
# Simpan Redis stats lengkap ke file
# ─────────────────────────────────────────────
save_redis_stats() {
  local label=$1
  local output_file=$2
  cd "${PROJECT_DIR}"
  echo "=== Redis INFO stats (${label}) - $(date) ===" >> "${output_file}"

  if [ "${CLUSTER_MODE}" = "true" ]; then
    # Collect stats from each cluster node
    for node in "${CLUSTER_NODES[@]}"; do
      echo "--- Node: ${node} ---" >> "${output_file}"
      docker compose exec -T "$node" redis-cli INFO stats >> "${output_file}" 2>/dev/null || true
      docker compose exec -T "$node" redis-cli INFO memory >> "${output_file}" 2>/dev/null || true
      docker compose exec -T "$node" redis-cli CLUSTER INFO >> "${output_file}" 2>/dev/null || true
      echo "" >> "${output_file}"
    done
  else
    docker compose exec -T redis redis-cli INFO stats  >> "${output_file}" 2>/dev/null || echo "Redis tidak tersedia" >> "${output_file}"
    docker compose exec -T redis redis-cli INFO memory >> "${output_file}" 2>/dev/null || true
  fi
  echo "" >> "${output_file}"
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
    echo "${ratio}"
  else
    echo "Cache Hit Ratio        : N/A (no cache ops)" >> "${output_file}"
    echo "0"
  fi
}

# ─────────────────────────────────────────────
# Monitor resource usage (background process)
# Menggunakan top (CPU), free (memory), iostat (disk I/O)
# sesuai proposal: htop & iostat
# Output: CSV dengan sample setiap 5 detik
# ─────────────────────────────────────────────
start_resource_monitor() {
  local output_csv=$1

  echo "timestamp,cpu_pct,mem_used_mb,mem_total_mb,mem_used_pct,disk_read_mb_s,disk_write_mb_s" > "${output_csv}"

  (
    while true; do
      local ts
      ts=$(date +%s)

      # CPU% — ambil dari top (1 iterasi, batch mode)
      # Format: "Cpu(s): 12.5 us, 3.2 sy, ..."  → ambil user+sys
      local cpu_pct
      cpu_pct=$(top -bn2 -d0.5 | grep "Cpu(s)" | tail -1 \
        | awk '{
            for(i=1;i<=NF;i++) {
              if($i ~ /[0-9]+\.[0-9]+/) {
                if($(i+1) ~ /us/ || $(i-1) ~ /us/) { us=$i }
                if($(i+1) ~ /sy/ || $(i-1) ~ /sy/) { sy=$i }
              }
            }
            gsub(/,/,"",us); gsub(/,/,"",sy);
            printf "%.1f", us+sy
          }' 2>/dev/null || echo "0")

      # Fallback CPU dari /proc/stat jika top gagal
      if [ "${cpu_pct}" = "0" ] || [ -z "${cpu_pct}" ]; then
        local idle1 total1 idle2 total2
        read -r _ user1 nice1 system1 idle1 iowait1 irq1 softirq1 _ < /proc/stat
        total1=$(( user1 + nice1 + system1 + idle1 + iowait1 + irq1 + softirq1 ))
        sleep 1
        read -r _ user2 nice2 system2 idle2 iowait2 irq2 softirq2 _ < /proc/stat
        total2=$(( user2 + nice2 + system2 + idle2 + iowait2 + irq2 + softirq2 ))
        local diff_total=$(( total2 - total1 ))
        local diff_idle=$(( idle2 - idle1 ))
        cpu_pct=$(echo "scale=1; (${diff_total} - ${diff_idle}) * 100 / ${diff_total}" | bc 2>/dev/null || echo "0")
      fi

      # Memory — dari free -m (sesuai htop)
      local mem_used mem_total mem_pct
      read -r _ mem_total mem_used _ < <(free -m | grep "^Mem:")
      mem_pct=$(echo "scale=1; ${mem_used} * 100 / ${mem_total}" | bc 2>/dev/null || echo "0")

      # Disk I/O — prioritas: iostat > /proc/diskstats
      local disk_read disk_write
      if command -v iostat &>/dev/null; then
        # iostat -d -m: MB/s, ambil baris disk utama (sda/vda/nvme)
        local iostat_line
        iostat_line=$(iostat -d -m 1 1 2>/dev/null \
          | grep -E "^(sda|vda|nvme|xvda|sdb|vdb|dm-)" | head -1)
        disk_read=$(echo  "${iostat_line}" | awk '{print $3}' | tr -d ',' || echo "0")
        disk_write=$(echo "${iostat_line}" | awk '{print $4}' | tr -d ',' || echo "0")
        # Pastikan tidak kosong
        disk_read=${disk_read:-0}
        disk_write=${disk_write:-0}
      else
        # Fallback: baca /proc/diskstats untuk hitung MB/s delta
        # Field 6 = sectors read, Field 10 = sectors written (1 sector = 512 bytes)
        local disk_stats_line1 disk_stats_line2
        local sectors_read1 sectors_written1 sectors_read2 sectors_written2
        disk_stats_line1=$(grep -E "\s+(sda|vda|nvme0n1|xvda|dm-0)\s" /proc/diskstats | head -1)
        sectors_read1=$(echo "${disk_stats_line1}" | awk '{print $6}')
        sectors_written1=$(echo "${disk_stats_line1}" | awk '{print $10}')
        sectors_read1=${sectors_read1:-0}
        sectors_written1=${sectors_written1:-0}
        sleep 1
        disk_stats_line2=$(grep -E "\s+(sda|vda|nvme0n1|xvda|dm-0)\s" /proc/diskstats | head -1)
        sectors_read2=$(echo "${disk_stats_line2}" | awk '{print $6}')
        sectors_written2=$(echo "${disk_stats_line2}" | awk '{print $10}')
        sectors_read2=${sectors_read2:-0}
        sectors_written2=${sectors_written2:-0}
        # Konversi delta sectors ke MB/s (1 sector = 512 bytes)
        local delta_read=$(( sectors_read2 - sectors_read1 ))
        local delta_write=$(( sectors_written2 - sectors_written1 ))
        disk_read=$(echo "scale=2; ${delta_read} * 512 / 1048576" | bc 2>/dev/null || echo "0")
        disk_write=$(echo "scale=2; ${delta_write} * 512 / 1048576" | bc 2>/dev/null || echo "0")
      fi

      echo "${ts},${cpu_pct},${mem_used},${mem_total},${mem_pct},${disk_read},${disk_write}"
      sleep 5
    done
  ) >> "${output_csv}" &

  echo $!
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
  local result_dir="${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}"
  local result_prefix="${result_dir}/${vu_count}vu"
  local summary_output="${result_prefix}-${timestamp}-summary.json"
  local redis_file="${result_prefix}-${timestamp}-redis.txt"
  local hit_ratio_file="${result_prefix}-${timestamp}-cache-hit-ratio.txt"
  local resources_csv="${result_prefix}-${timestamp}-resources.csv"

  mkdir -p "${result_dir}"

  # Marker file untuk redis mode (dibaca oleh analyze-results.sh)
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo "cluster" > "${result_dir}/.redis-mode"
  else
    echo "single" > "${result_dir}/.redis-mode"
  fi

  echo ""
  echo -e "${CYAN}────────────────────────────────────────────${NC}"
  echo -e "${CYAN}  Concurrent Users : ${vu_count}${NC}"
  echo -e "${CYAN}  Strategi         : ${STRATEGY}${NC}"
  echo -e "${CYAN}  Skenario         : ${SCENARIO}${NC}"
  echo -e "${CYAN}  Durasi           : ~22 menit (prepare ~15m + warm-up ~1m + benchmark ~6m)${NC}"
  echo -e "${CYAN}────────────────────────────────────────────${NC}"

  # Reset database + sistem untuk setiap VU level
  # (migrate:fresh --seed + cache clear + container restart + verifikasi)
  echo -e "${YELLOW}[${vu_count}vu] Reset database & sistem (prepare-benchmark.sh)...${NC}"
  "${SCRIPT_DIR}/prepare-benchmark.sh" "${BASE_URL}"
  if [ $? -ne 0 ]; then
    echo -e "${RED}[${vu_count}vu] ✗ prepare-benchmark.sh gagal!${NC}"
    return 1
  fi

  # ─────────────────────────────────────────────
  # WARM-UP PERIOD (60 detik, sesuai proposal §3.3.1.2)
  # Request ringan ke semua endpoint utama untuk:
  #   1. Inisialisasi connection pool database
  #   2. Mengisi cache dengan data yang umum diakses
  #   3. Menghindari cold-start bias pada k6
  # Request warm-up TIDAK masuk ke metrik k6 karena
  # dijalankan sebelum k6 start.
  # ─────────────────────────────────────────────
  echo -e "${YELLOW}[${vu_count}vu] Warm-up 60 detik (tidak masuk metrik)...${NC}"
  warmup_cache

  # Redis snapshot SEBELUM (diambil setelah warm-up selesai,
  # supaya metrik delta cache hit ratio mencerminkan benchmark saja)
  save_redis_stats "before" "${redis_file}"
  local hits_before misses_before
  hits_before=$(get_redis_hits)
  misses_before=$(get_redis_misses)
  hits_before=${hits_before:-0}
  misses_before=${misses_before:-0}

  # Mulai resource monitoring
  echo -e "${YELLOW}[${vu_count}vu] Memulai resource monitoring (CPU/Memory/Disk I/O)...${NC}"
  local monitor_pid
  monitor_pid=$(start_resource_monitor "${resources_csv}")

  # Jalankan k6 (dengan CLUSTER_MODE env jika cluster)
  echo -e "${YELLOW}[${vu_count}vu] Menjalankan k6...${NC}"

  local k6_envs=(
    --env BASE_URL="${BASE_URL}"
    --env CONCURRENT_USERS="${vu_count}"
    --env CACHE_STRATEGY="${STRATEGY}"
    --env SUMMARY_EXPORT="${summary_output}"
  )

  if [ "${CLUSTER_MODE}" = "true" ]; then
    k6_envs+=(--env CLUSTER_MODE=true)
  fi

  k6 run "${k6_envs[@]}" "${K6_SCRIPT}"

  local k6_exit=$?

  # Stop resource monitoring
  stop_resource_monitor "${monitor_pid}"

  # Redis snapshot SESUDAH + hitung cache hit ratio
  local hits_after misses_after
  hits_after=$(get_redis_hits)
  misses_after=$(get_redis_misses)
  hits_after=${hits_after:-0}
  misses_after=${misses_after:-0}

  save_redis_stats "after" "${redis_file}"

  # ── Cluster-specific stats (jika --cluster) ────────────
  if [ "${CLUSTER_MODE}" = "true" ]; then
    local cluster_file="${result_prefix}-${timestamp}-cluster-stats.txt"
    echo "=== Cluster Stats - $(date) ===" > "${cluster_file}"
    echo "" >> "${cluster_file}"
    for node in "${CLUSTER_NODES[@]}"; do
      echo "--- ${node} ---" >> "${cluster_file}"
      docker compose exec -T "$node" redis-cli CLUSTER INFO 2>/dev/null >> "${cluster_file}" || true
      echo "" >> "${cluster_file}"
      docker compose exec -T "$node" redis-cli CLUSTER NODES 2>/dev/null >> "${cluster_file}" || true
      echo "" >> "${cluster_file}"
      docker compose exec -T "$node" redis-cli INFO keyspace 2>/dev/null >> "${cluster_file}" || true
      echo "" >> "${cluster_file}"
    done
    echo -e "${GREEN}  Cluster stats : ${cluster_file}${NC}"
  fi

  local hit_ratio
  hit_ratio=$(compute_cache_hit_ratio \
    "${hits_before}" "${misses_before}" \
    "${hits_after}"  "${misses_after}" \
    "${redis_file}")

  echo "${hit_ratio}" > "${hit_ratio_file}"

  if [ $k6_exit -eq 0 ]; then
    echo -e "${GREEN}[${vu_count}vu] ✓ Selesai.${NC}"
    echo -e "${GREEN}  Summary   : ${summary_output}${NC}"
    echo -e "${GREEN}  Resources : ${resources_csv}${NC}"
    echo -e "${GREEN}  Cache HR  : ${hit_ratio}%${NC}"
  else
    echo -e "${RED}[${vu_count}vu] ✗ k6 exit code ${k6_exit}${NC}"
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
  echo -e "  Iterasi   : ${BLUE}${ITERATION}${NC}"
  echo -e "  Base URL  : ${BLUE}${BASE_URL}${NC}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo -e "  Redis     : ${BLUE}Cluster${NC} (3 masters + 3 replicas)"
  else
    echo -e "  Redis     : ${BLUE}Single${NC} (standalone)"
  fi
  echo -e "  VU Levels : ${BLUE}${CONCURRENT_USERS_LEVELS[*]}${NC}"
  echo -e "  Hasil     : ${BLUE}${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}/${NC}"
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
  echo -e "  Hasil    : ${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}/"
  echo "=============================================="
}

main
