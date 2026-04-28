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

# ─────────────────────────────────────────────
# 1. Verifikasi container Docker
# ─────────────────────────────────────────────
echo -e "${YELLOW}[1/6] Memeriksa container Docker...${NC}"

if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "app"; then
  echo -e "${RED}Error: Container 'app' tidak berjalan.${NC}"
  echo "Jalankan: docker compose up -d"
  exit 1
fi

if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "redis"; then
  echo -e "${RED}Error: Container 'redis' tidak berjalan.${NC}"
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

if ! docker compose exec -T app php artisan migrate:status --quiet > /dev/null 2>&1; then
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
echo -e "${YELLOW}[4/6] Mengosongkan cache...${NC}"

docker compose exec -T app php artisan cache:clear  --quiet 2>/dev/null || true
docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true
docker compose exec -T app php artisan route:clear  --quiet 2>/dev/null || true
docker compose exec -T app php artisan view:clear   --quiet 2>/dev/null || true

if docker compose exec -T redis redis-cli FLUSHALL > /dev/null 2>&1; then
  echo -e "${GREEN}  Redis FLUSHALL: OK${NC}"
else
  echo -e "${YELLOW}  Warning: Redis FLUSHALL gagal (lanjut)${NC}"
fi

echo -e "${GREEN}✓ Semua cache bersih.${NC}"

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

echo "  Users       : ${USER_COUNT:-?} (expected: 5000)"
echo "  Courses     : ${COURSE_COUNT:-?} (expected: 50)"
echo "  Quizzes     : ${QUIZ_COUNT:-?} (expected: 250)"
echo "  Materials   : ${MATERIAL_COUNT:-?} (expected: 500)"
echo "  Submissions : ${SUBMISSION_COUNT:-?} (expected: ~12500)"

if [ -n "${USER_COUNT}" ] && [ "${USER_COUNT}" -ge 5000 ] 2>/dev/null; then
  echo -e "${GREEN}✓ Data seeder valid.${NC}"
else
  echo -e "${RED}Error: Jumlah user tidak sesuai (${USER_COUNT:-unknown} vs 5000). Seeder mungkin gagal.${NC}"
  exit 1
fi

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
