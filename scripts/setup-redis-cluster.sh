#!/bin/bash

# ============================================================
# Redis Cluster Setup for Benchmark
#
# Usage  : ./scripts/setup-redis-cluster.sh [up|down|status]
# Contoh : ./scripts/setup-redis-cluster.sh up
#
# Script ini mengelola lifecycle Redis Cluster untuk benchmark:
#   up      — Start cluster nodes, form cluster, update .env
#   down    — Stop cluster, restore single-node .env
#   status  — Periksa status cluster
#
# Cluster terdiri dari 6 node (3 masters + 3 replicas)
# menggunakan Docker Compose profile "redis-cluster".
# ============================================================

ACTION=${1:-status}
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${PROJECT_DIR}/.env"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

CLUSTER_NODES=("redis-c1" "redis-c2" "redis-c3" "redis-c4" "redis-c5" "redis-c6")
CLUSTER_HOSTS="redis-c1,redis-c2,redis-c3"

# ─────────────────────────────────────────────
# Help
# ─────────────────────────────────────────────
usage() {
    echo "Usage: ./scripts/setup-redis-cluster.sh [up|down|status]"
    echo ""
    echo "Actions:"
    echo "  up      — Start cluster nodes, form cluster, update .env"
    echo "  down    — Stop cluster, restore single-node .env"
    echo "  status  — Periksa status cluster (default)"
    exit 0
}

# ─────────────────────────────────────────────
# Check prerequisites
# ─────────────────────────────────────────────
check_prereqs() {
    if ! command -v docker &>/dev/null; then
        echo -e "${RED}Error: docker tidak ditemukan.${NC}"
        exit 1
    fi

    if ! docker compose version &>/dev/null; then
        echo -e "${RED}Error: docker compose tidak ditemukan.${NC}"
        exit 1
    fi
}

# ─────────────────────────────────────────────
# Bawa cluster nodes naik via profile
# ─────────────────────────────────────────────
cluster_up() {
    echo ""
    echo "=============================================="
    echo "  Redis Cluster — Start Nodes"
    echo "=============================================="
    echo ""

    cd "${PROJECT_DIR}" || exit 1

    # Cek apakah cluster sudah jalan
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "redis-c1"; then
        echo -e "${YELLOW}Cluster nodes sudah berjalan. Me-skip start.${NC}"
    else
        echo -e "${YELLOW}[1/4] Menjalankan cluster nodes (profile: redis-cluster)...${NC}"
        docker compose --profile redis-cluster up -d redis-c1 redis-c2 redis-c3 redis-c4 redis-c5 redis-c6

        if [ $? -ne 0 ]; then
            echo -e "${RED}Error: Gagal menjalankan cluster nodes.${NC}"
            exit 1
        fi
        echo -e "${GREEN}✓ Cluster nodes dimulai.${NC}"
    fi

    # ─────────────────────────────────────────
    # 2. Tunggu semua nodes healthy
    # ─────────────────────────────────────────
    echo ""
    echo -e "${YELLOW}[2/4] Menunggu semua nodes siap (healthcheck)...${NC}"

    for node in "${CLUSTER_NODES[@]}"; do
        echo -n "  ${node}: menunggu"
        for i in $(seq 1 30); do
            if docker compose exec -T "$node" redis-cli ping 2>/dev/null | grep -q "PONG"; then
                echo -e " ${GREEN}✓${NC}"
                break
            fi
            echo -n "."
            sleep 1
            if [ "$i" -eq 30 ]; then
                echo -e " ${RED}✗ TIMEOUT${NC}"
                echo -e "${RED}Error: ${node} tidak merespon setelah 30 detik.${NC}"
                exit 1
            fi
        done
    done

    # ─────────────────────────────────────────
    # 3. Form cluster (redis-cli --cluster create)
    # ─────────────────────────────────────────
    echo ""
    echo -e "${YELLOW}[3/4] Membentuk Redis cluster (3 masters, 3 replicas)...${NC}"

    # Cek apakah cluster sudah terbentuk (cari cluster_state)
    EXISTING_CLUSTER=$(docker compose exec -T redis-c1 redis-cli CLUSTER INFO 2>/dev/null | grep "cluster_state" | tr -d '\r ')
    if echo "$EXISTING_CLUSTER" | grep -q "ok"; then
        echo -e "${GREEN}  Cluster sudah terbentuk sebelumnya (cluster_state: ok).${NC}"
    else
        echo "  Menjalankan redis-cli --cluster create..."
        docker compose run --rm redis-cluster-init
        INIT_EXIT=$?

        if [ $INIT_EXIT -ne 0 ]; then
            echo -e "${YELLOW}  redis-cluster-init selesai dengan kode ${INIT_EXIT} (mungkin cluster sudah ada).${NC}"
        fi

        # Verifikasi
        sleep 2
        CLUSTER_STATE=$(docker compose exec -T redis-c1 redis-cli CLUSTER INFO 2>/dev/null | grep "cluster_state" | tr -d '\r ')
        if echo "$CLUSTER_STATE" | grep -q "ok"; then
            echo -e "${GREEN}  ✓ Cluster state: ok${NC}"
        else
            echo -e "${RED}  ✗ Cluster state: ${CLUSTER_STATE:-unknown}${NC}"
            echo -e "${YELLOW}  Mencoba form cluster manual...${NC}"
            docker compose exec -T redis-c1 redis-cli --cluster create \
                redis-c1:6379 redis-c2:6379 redis-c3:6379 \
                redis-c4:6379 redis-c5:6379 redis-c6:6379 \
                --cluster-replicas 1 --cluster-yes
            sleep 2
        fi
    fi

    # ─────────────────────────────────────────
    # 4. Update .env untuk cluster mode
    # ─────────────────────────────────────────
    echo ""
    echo -e "${YELLOW}[4/4] Mengupdate .env untuk cluster mode...${NC}"

    # Backup .env
    cp "${ENV_FILE}" "${ENV_FILE}.cluster-backup"

    # Set cluster mode vars
    if grep -q "^REDIS_CLUSTER_MODE=" "${ENV_FILE}"; then
        sed -i "s/^REDIS_CLUSTER_MODE=.*/REDIS_CLUSTER_MODE=true/" "${ENV_FILE}"
    else
        echo "REDIS_CLUSTER_MODE=true" >> "${ENV_FILE}"
    fi

    if grep -q "^REDIS_CLUSTER_HOSTS=" "${ENV_FILE}"; then
        sed -i "s/^REDIS_CLUSTER_HOSTS=.*/REDIS_CLUSTER_HOSTS=${CLUSTER_HOSTS}/" "${ENV_FILE}"
    else
        echo "REDIS_CLUSTER_HOSTS=${CLUSTER_HOSTS}" >> "${ENV_FILE}"
    fi

    echo -e "${GREEN}  ✓ .env diupdate: REDIS_CLUSTER_MODE=true${NC}"
    echo -e "${GREEN}  ✓ .env diupdate: REDIS_CLUSTER_HOSTS=${CLUSTER_HOSTS}${NC}"
    echo -e "${YELLOW}  Backup .env: ${ENV_FILE}.cluster-backup${NC}"

    # ─────────────────────────────────────────
    # 5. Tampilkan info cluster
    # ─────────────────────────────────────────
    echo ""
    echo "=============================================="
    echo "  Cluster Info"
    echo "=============================================="
    docker compose exec -T redis-c1 redis-cli CLUSTER INFO 2>/dev/null
    echo ""
    docker compose exec -T redis-c1 redis-cli CLUSTER NODES 2>/dev/null | head -10
    echo "=============================================="
    echo ""

    echo -e "${GREEN}✓ Redis Cluster siap digunakan.${NC}"
    echo ""
    echo "Langkah selanjutnya:"
    echo "  Restart app container: docker compose restart app"
    echo "  Jalankan benchmark:    ./scripts/run-benchmark.sh --cluster ..."
    echo ""
}

# ─────────────────────────────────────────────
# Hentikan cluster, restore .env
# ─────────────────────────────────────────────
cluster_down() {
    echo ""
    echo "=============================================="
    echo "  Redis Cluster — Stop & Cleanup"
    echo "=============================================="
    echo ""

    cd "${PROJECT_DIR}" || exit 1

    # ─────────────────────────────────────────
    # 1. Hentikan cluster nodes
    # ─────────────────────────────────────────
    echo -e "${YELLOW}[1/2] Menghentikan cluster nodes...${NC}"
    docker compose --profile redis-cluster down

    if [ $? -ne 0 ]; then
        echo -e "${RED}Error: Gagal menghentikan cluster.${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ Cluster nodes dihentikan.${NC}"

    # ─────────────────────────────────────────
    # 2. Restore .env ke single-node mode
    # ─────────────────────────────────────────
    echo ""
    echo -e "${YELLOW}[2/2] Merestore .env ke single-node mode...${NC}"

    if [ -f "${ENV_FILE}.cluster-backup" ]; then
        # Restore dari backup yang diambil saat cluster_up
        cp "${ENV_FILE}.cluster-backup" "${ENV_FILE}"
        echo -e "${GREEN}  ✓ .env di-restore dari backup.${NC}"
    else
        # Manual restore
        if grep -q "^REDIS_CLUSTER_MODE=" "${ENV_FILE}"; then
            sed -i "s/^REDIS_CLUSTER_MODE=.*/REDIS_CLUSTER_MODE=false/" "${ENV_FILE}"
        fi
        echo -e "${GREEN}  ✓ .env di-set ke REDIS_CLUSTER_MODE=false${NC}"
    fi

    echo ""
    echo -e "${GREEN}✓ Redis Cluster dihentikan.${NC}"
    echo ""
    echo "Langkah selanjutnya:"
    echo "  Restart app container: docker compose restart app"
    echo "  Jalankan benchmark:    ./scripts/run-benchmark.sh ..."
    echo ""
}

# ─────────────────────────────────────────────
# Status cluster
# ─────────────────────────────────────────────
cluster_status() {
    echo ""
    echo "=============================================="
    echo "  Redis Cluster — Status"
    echo "=============================================="
    echo ""

    cd "${PROJECT_DIR}" 2>/dev/null || true

    # Cek apakah cluster nodes berjalan
    if ! docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "redis-c1"; then
        echo -e "${RED}Cluster nodes TIDAK berjalan.${NC}"
        echo ""
        echo "Untuk memulai: ./scripts/setup-redis-cluster.sh up"
        exit 0
    fi

    echo -e "${GREEN}Cluster nodes running.${NC}"
    echo ""

    # Tampilkan info setiap node
    for node in "${CLUSTER_NODES[@]}"; do
        local role
        role=$(docker compose exec -T "$node" redis-cli CLUSTER NODES 2>/dev/null \
            | grep "\b$(docker compose exec -T "$node" redis-cli CLUSTER MYID 2>/dev/null | tr -d '\r ')\b" \
            | awk '{print $3}')
        local ping
        ping=$(docker compose exec -T "$node" redis-cli ping 2>/dev/null | tr -d '\r ')

        if [ "$ping" = "PONG" ]; then
            echo -e "  ${GREEN}✓${NC} ${node} — role: ${role:-unknown}"
        else
            echo -e "  ${RED}✗${NC} ${node} — no response"
        fi
    done

    echo ""
    echo "--- Cluster Info ---"
    docker compose exec -T redis-c1 redis-cli CLUSTER INFO 2>/dev/null || echo -e "${RED}Gagal获取 cluster info${NC}"

    echo ""
    echo "--- Cluster Nodes ---"
    docker compose exec -T redis-c1 redis-cli CLUSTER NODES 2>/dev/null | head -10 || true

    echo ""
    echo "--- .env setting ---"
    grep "^REDIS_CLUSTER_" "${ENV_FILE}" 2>/dev/null || echo "(env vars not set)"
    echo "=============================================="
    echo ""
}

# ─────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────
main() {
    check_prereqs

    case "${ACTION}" in
        up)     cluster_up ;;
        down)   cluster_down ;;
        status) cluster_status ;;
        *)
            echo -e "${RED}Error: Aksi tidak valid.${NC}"
            usage ;;
    esac
}

main
