#!/bin/bash

# ============================================================
# Prepare System for Benchmark — Reset ke Kondisi Awal
#
# Usage  : ./scripts/prepare-benchmark.sh [base_url]
# Contoh : ./scripts/prepare-benchmark.sh http://localhost
#
# Sesuai proposal §3.4.4.1: reset kondisi awal sebelum setiap iterasi,
# meliputi:
#   1. Verifikasi container Docker aktif
#   2. Verifikasi koneksi database
#   3. migrate:fresh --seed (data bersih, konsisten)
#   4. Kosongkan semua cache (artisan + Redis FLUSHALL)
#   4b. Clear OS page cache + restart MySQL (reset InnoDB buffer pool)
#   5. Restart container 'app' untuk menghilangkan state di memory
#   6. Verifikasi jumlah data seeder
#   7. Verifikasi semua strategi caching dapat di-load
#
# Catatan: Script ini menggunakan docker compose (bukan Sail).
# ============================================================

BASE_URL=${1:-http://localhost}

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo "=============================================="
echo "  LMS Benchmark — Prepare (Reset Awal)"
echo "=============================================="
echo -e "  Project Dir : ${BLUE}${PROJECT_DIR}${NC}"
echo -e "  Base URL    : ${BLUE}${BASE_URL}${NC}"
echo -e "  Waktu       : $(date)"
echo "=============================================="
echo ""

cd "${PROJECT_DIR}"

# Load environment variables for DB_PASSWORD
if [ -f "${PROJECT_DIR}/.env" ]; then
  set -a
  source "${PROJECT_DIR}/.env"
  set +a
fi

# ─────────────────────────────────────────────
# 1. Verifikasi container Docker
# ─────────────────────────────────────────────
echo -e "${YELLOW}[1/6] Memeriksa container Docker...${NC}"

if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "app"; then
  echo -e "${RED}Error: Container 'app' tidak berjalan.${NC}"
  echo "Jalankan: docker compose up -d"
  exit 1
fi

if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -Eq "redis(-c[1-6])?$"; then
  echo -e "${RED}Error: Container Redis (single atau cluster) tidak berjalan.${NC}"
  exit 1
fi

if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "db\|mysql"; then
  echo -e "${YELLOW}Warning: Container database tidak terdeteksi via filter, melanjutkan...${NC}"
fi

echo -e "${GREEN}✓ Container aktif.${NC}"

# ─────────────────────────────────────────────
# 2. Verifikasi koneksi database
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[2/6] Verifikasi koneksi database...${NC}"

if ! docker compose exec -T app php artisan db:show --quiet 2>/dev/null; then
  echo -e "${RED}Error: Database tidak bisa diakses. Periksa .env dan container DB.${NC}"
  exit 1
fi

echo -e "${GREEN}✓ Database accessible.${NC}"

# ─────────────────────────────────────────────
# 3. Fresh migration + seed
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[3/6] Menjalankan migrate:fresh --seed ...${NC}"
echo -e "${YELLOW}      (Ini akan menghapus semua data lama dan menanam data baru)${NC}"
echo -e "${YELLOW}      Estimasi: 15–20 menit tergantung resource VPS.${NC}"

docker compose exec -T app php artisan migrate:fresh --seed --force

if [ $? -ne 0 ]; then
  echo -e "${RED}Error: migrate:fresh --seed gagal!${NC}"
  exit 1
fi

echo -e "${GREEN}✓ Database di-reset dan di-seed ulang.${NC}"

# ─────────────────────────────────────────────
# 4. Kosongkan semua cache
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[4/7] Mengosongkan cache...${NC}"

docker compose exec -T app php artisan cache:clear  --quiet 2>/dev/null || true
docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true
docker compose exec -T app php artisan route:clear  --quiet 2>/dev/null || true
docker compose exec -T app php artisan view:clear   --quiet 2>/dev/null || true

# FLUSHALL — mode-aware, no silent fallback
# Check .env to determine which Redis to flush
REDIS_CLUSTER_ENABLED=false
if grep -q "^REDIS_CLUSTER_MODE=true" "${PROJECT_DIR}/.env" 2>/dev/null; then
  REDIS_CLUSTER_ENABLED=true
fi

if [ "$REDIS_CLUSTER_ENABLED" = "true" ]; then
  for node in redis-c1 redis-c2 redis-c3 redis-c4 redis-c5 redis-c6; do
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
      docker compose exec -T "$node" redis-cli FLUSHALL > /dev/null 2>&1 || {
        echo -e "${RED}  Error: FLUSHALL failed on ${node}${NC}"
        exit 1
      }
    else
      echo -e "${RED}  Error: Cluster node ${node} is not running${NC}"
      exit 1
    fi
  done
  echo -e "${GREEN}  Redis Cluster FLUSHALL: OK (6 nodes)${NC}"
else
  # Single node mode
  if ! docker compose exec -T redis redis-cli PING > /dev/null 2>&1; then
    echo -e "${RED}  Error: Single Redis not reachable (PING failed)${NC}"
    exit 1
  fi
  docker compose exec -T redis redis-cli FLUSHALL > /dev/null 2>&1 || {
    echo -e "${RED}  Error: Single Redis FLUSHALL failed${NC}"
    exit 1
  }
  echo -e "${GREEN}  Single Redis FLUSHALL: OK${NC}"
fi

echo -e "${GREEN}✓ Semua cache bersih.${NC}"

# ─────────────────────────────────────────────
# 4b. Clear OS page cache + restart MySQL (reset InnoDB buffer pool)
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[4b/7] Clearing OS page cache dan resetting InnoDB buffer pool...${NC}"

# Restart MySQL container agar InnoDB buffer pool kosong (64M limit)
# dan data pages harus dibaca ulang dari disk
docker compose restart mysql
if [ $? -ne 0 ]; then
  echo -e "${RED}Error: MySQL restart gagal!${NC}"
  exit 1
fi

# Tunggu MySQL siap kembali
echo -e "${YELLOW}  Menunggu MySQL siap...${NC}"
MYSQL_READY=0
for i in $(seq 1 30); do
  if docker compose exec -T mysql mysqladmin ping -h localhost -p"${DB_PASSWORD}" --silent --connect-timeout=3 2>/dev/null; then
    echo -e "${GREEN}  MySQL siap setelah ${i} detik.${NC}"
    MYSQL_READY=1
    break
  fi
  sleep 1
done

if [ "${MYSQL_READY}" -eq 0 ]; then
  echo -e "${RED}Error: MySQL tidak mau menyala setelah restart.${NC}"
  echo -e "${RED}Cek logs: docker compose logs mysql${NC}"
  exit 1
fi

# Clear Linux page cache agar data tidak tersimpan di OS filesystem cache
# Ini memaksa semua DB read operasi benchmark benar-benar dari disk
if [ -w /proc/sys/vm/drop_caches ]; then
  sync
  echo 3 > /proc/sys/vm/drop_caches
  echo -e "${GREEN}  OS page cache cleared.${NC}"
else
  echo -e "${YELLOW}  Warning: Tidak bisa clear page cache (butuh root/sudo). Mencoba dengan sudo...${NC}"
  sudo sh -c 'sync; echo 3 > /proc/sys/vm/drop_caches' 2>/dev/null
  if [ $? -eq 0 ]; then
    echo -e "${GREEN}  OS page cache cleared (via sudo).${NC}"
  else
    echo -e "${RED}  GAGAL clear OS page cache! Benchmark tanpa cache tidak akan benar-benar hit disk.${NC}"
    echo -e "${RED}  Jalankan: sudo sh -c 'echo 3 > /proc/sys/vm/drop_caches' sebelum benchmark.${NC}"
  fi
fi

echo -e "${GREEN}✓ InnoDB buffer pool dan OS page cache berhasil di-reset.${NC}"

# ─────────────────────────────────────────────
# 5. Restart container app (§3.4.4.1 — hilangkan state memory)
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[5/7] Restart container 'app' untuk menghilangkan state di memory...${NC}"

docker compose restart app

if [ $? -ne 0 ]; then
  echo -e "${RED}Error: Restart container 'app' gagal!${NC}"
  exit 1
fi

echo -e "${YELLOW}  Menunggu 30 detik agar app siap kembali...${NC}"
sleep 30
echo -e "${GREEN}✓ Container 'app' di-restart. State memory bersih.${NC}"

echo -e "${YELLOW}  Restart container 'nginx' agar upstream app IP ter-refresh...${NC}"
docker compose restart nginx

if [ $? -ne 0 ]; then
  echo -e "${RED}Error: Restart container 'nginx' gagal!${NC}"
  exit 1
fi

echo -e "${GREEN}✓ Container 'nginx' di-restart. Upstream app sudah di-refresh.${NC}"

# ─────────────────────────────────────────────
# 6. Verifikasi jumlah data seeder
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[6/7] Memverifikasi data seeder...${NC}"

USER_COUNT=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1)
COURSE_COUNT=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Course::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1)
QUIZ_COUNT=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Quiz::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1)
MATERIAL_COUNT=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Material::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1)
SUBMISSION_COUNT=$(docker compose exec -T app php artisan tinker --execute="echo \App\Models\Submission::count();" 2>/dev/null | grep -E "^[0-9]+$" | head -1)

echo "  Users       : ${USER_COUNT:-?} (expected: ~5000)"
echo "  Courses     : ${COURSE_COUNT:-?} (expected: ~50)"
echo "  Quizzes     : ${QUIZ_COUNT:-?} (expected: ~125)"
echo "  Materials   : ${MATERIAL_COUNT:-?} (expected: ~176)"
echo "  Submissions : ${SUBMISSION_COUNT:-?} (expected: ~6550)"

SEED_FAILED=0

if [ -z "${USER_COUNT}" ] || [ "${USER_COUNT}" -lt 4000 ] 2>/dev/null; then
  echo -e "  ${RED}✗ Users: got ${USER_COUNT:-?}, expected at least 4000${NC}"
  SEED_FAILED=1
fi

if [ -z "${COURSE_COUNT}" ] || [ "${COURSE_COUNT}" -lt 40 ] 2>/dev/null; then
  echo -e "  ${RED}✗ Courses: got ${COURSE_COUNT:-?}, expected at least 40${NC}"
  SEED_FAILED=1
fi

if [ -z "${MATERIAL_COUNT}" ] || [ "${MATERIAL_COUNT}" -lt 100 ] 2>/dev/null; then
  echo -e "  ${RED}✗ Materials: got ${MATERIAL_COUNT:-?}, expected at least 100${NC}"
  SEED_FAILED=1
fi

if [ "${SEED_FAILED}" -eq 0 ]; then
  echo -e "${GREEN}✓ Data seeder valid.${NC}"
else
  echo -e "${RED}Error: Seeder mungkin gagal. Periksa log di atas.${NC}"
  exit 1
fi

# ─────────────────────────────────────────────
# 6b. Generate k6 fixtures from seeded data
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[6b/8] Generating k6 fixtures from seeded data...${NC}"

# docker compose exec -T app php artisan benchmark:generate-k6-fixtures --quiet 2>/dev/null
# if [ $? -ne 0 ]; then
#   echo -e "${RED}Error: benchmark:generate-k6-fixtures gagal!${NC}"
#   exit 1
# fi

# echo -e "${GREEN}✓ k6 fixtures regenerated.${NC}"

docker compose exec -T app php artisan benchmark:generate-k6-fixtures --sampled --quiet 2>/dev/null
if [ $? -ne 0 ]; then
  echo -e "${RED}Error: benchmark:generate-k6-fixtures --sampled gagal!${NC}"
  exit 1
fi

echo -e "${GREEN}✓ k6 sampled fixtures regenerated.${NC}"

# ─────────────────────────────────────────────
# 7. Verifikasi semua strategi caching dapat di-load
# ─────────────────────────────────────────────
echo ""
echo -e "${YELLOW}[7/7] Memverifikasi semua strategi caching...${NC}"

STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
VERIFY_FAILED=0

# Simpan strategi aktif saat ini untuk di-restore setelah verifikasi
ORIGINAL_STRATEGY=$(grep "^CACHE_STRATEGY=" "${PROJECT_DIR}/.env" | cut -d'=' -f2 | tr -d '\r ')

for strategy in "${STRATEGIES[@]}"; do
  # Switch ke strategi ini
  sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${strategy}/" "${PROJECT_DIR}/.env"
  docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true

  # Resolve CacheStrategyInterface — kalau class tidak terdaftar, tinker akan throw
  OUTPUT=$(docker compose exec -T app php artisan tinker \
    --execute="app(\App\Contracts\CacheStrategyInterface::class); echo 'OK';" 2>&1)

  if echo "${OUTPUT}" | grep -q "^OK"; then
    echo -e "  ${GREEN}✓ ${strategy}${NC}"
  else
    echo -e "  ${RED}✗ ${strategy} — GAGAL${NC}"
    # Tampilkan baris error yang relevan (bukan stack trace penuh)
    echo "${OUTPUT}" | grep -v "^>" | grep -v "^=" | head -5 | sed 's/^/    /'
    VERIFY_FAILED=$((VERIFY_FAILED + 1))
  fi
done

# Restore ke strategi semula (atau no-cache sebagai default bersih)
RESTORE_TO="${ORIGINAL_STRATEGY:-no-cache}"
sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${RESTORE_TO}/" "${PROJECT_DIR}/.env"
docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true

if [ "${VERIFY_FAILED}" -gt 0 ]; then
  echo ""
  echo -e "${RED}Error: ${VERIFY_FAILED} strategi gagal di-load!${NC}"
  echo -e "${RED}Periksa CacheStrategyInterface binding di AppServiceProvider.${NC}"
  exit 1
fi

echo -e "${GREEN}✓ Semua strategi valid. Strategi di-restore ke: ${RESTORE_TO}${NC}"

# ─────────────────────────────────────────────
# Selesai
# ─────────────────────────────────────────────
echo ""
echo "=============================================="
echo "  System Siap untuk Benchmark!"
echo "=============================================="
echo -e "  Selesai : $(date)"
echo ""
echo "Langkah berikutnya:"
echo "  Jalankan satu iterasi:"
echo "    ./scripts/run-all-benchmarks.sh ${BASE_URL}"
echo ""
echo "  Atau langsung satu strategi:"
echo "    ./scripts/run-benchmark.sh cache-aside read-heavy ${BASE_URL}"
echo "=============================================="
echo ""
