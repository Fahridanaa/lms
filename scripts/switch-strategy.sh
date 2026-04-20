#!/bin/bash

# Caching Strategy Switcher
# Usage: ./scripts/switch-strategy.sh [cache-aside|read-through|write-through|no-cache]

STRATEGY=$1
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${PROJECT_DIR}/.env"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

usage() {
    echo "Usage: ./scripts/switch-strategy.sh [cache-aside|read-through|write-through|no-cache]"
    echo ""
    echo "Available strategies:"
    echo "  cache-aside    - Lazy loading, app manages cache"
    echo "  read-through   - Cache layer handles DB reads transparently"
    echo "  write-through  - Synchronous write to cache and DB"
    echo "  no-cache       - No caching, direct database access (baseline)"
    exit 1
}

validate_strategy() {
    case $1 in
        cache-aside|read-through|write-through|no-cache) return 0 ;;
        *)
            echo -e "${RED}Error: Strategi '$1' tidak valid.${NC}"
            usage ;;
    esac
}

update_env() {
    local strategy=$1
    if [ ! -f "$ENV_FILE" ]; then
        echo -e "${RED}Error: .env tidak ditemukan di ${ENV_FILE}${NC}"
        exit 1
    fi
    cp "$ENV_FILE" "${ENV_FILE}.backup"
    if grep -q "^CACHE_STRATEGY=" "$ENV_FILE"; then
        sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${strategy}/" "$ENV_FILE"
    else
        echo "CACHE_STRATEGY=${strategy}" >> "$ENV_FILE"
    fi
    echo -e "${GREEN}✓ .env diupdate: CACHE_STRATEGY=${strategy}${NC}"
}

clear_caches() {
    echo -e "${YELLOW}Membersihkan cache Laravel...${NC}"
    cd "${PROJECT_DIR}"
    docker compose exec -T app php artisan config:clear --quiet 2>/dev/null || true
    docker compose exec -T app php artisan cache:clear  --quiet 2>/dev/null || true
    echo -e "${GREEN}✓ Cache bersih.${NC}"
}

show_config() {
    echo ""
    echo "====================================="
    echo "Konfigurasi Aktif"
    echo "====================================="
    grep "^CACHE_STRATEGY=" "$ENV_FILE" || echo "CACHE_STRATEGY=(tidak ditemukan)"
    grep "^CACHE_TTL="      "$ENV_FILE" || true
    grep "^CACHE_DRIVER="   "$ENV_FILE" || true
    grep "^REDIS_HOST="     "$ENV_FILE" || true
    echo "====================================="
    echo ""
}

main() {
    if [ -z "$STRATEGY" ]; then
        echo -e "${RED}Error: Strategi tidak ditentukan.${NC}"
        usage
    fi
    validate_strategy "$STRATEGY"

    echo ""
    echo "====================================="
    echo "Switching Caching Strategy"
    echo "====================================="
    echo "Target: ${STRATEGY}"
    echo ""

    update_env "$STRATEGY"
    clear_caches
    show_config

    echo -e "${GREEN}✓ Strategi berhasil diganti ke: ${STRATEGY}${NC}"
    echo ""
}

main
