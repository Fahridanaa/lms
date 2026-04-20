#!/bin/bash

# Redis Cache Statistics Checker
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "=========================================="
echo "Redis Cache Statistics"
echo "=========================================="
echo ""

cd "${PROJECT_DIR}"

STATS=$(docker compose exec -T redis redis-cli INFO stats 2>/dev/null)
if [ -z "$STATS" ]; then
    echo "Redis tidak tersedia atau container belum jalan."
    exit 1
fi

KEYSPACE_HITS=$(echo "$STATS"   | grep "keyspace_hits:"   | cut -d':' -f2 | tr -d '\r ')
KEYSPACE_MISSES=$(echo "$STATS" | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ')
EXPIRED_KEYS=$(echo "$STATS"    | grep "expired_keys:"    | cut -d':' -f2 | tr -d '\r ')
EVICTED_KEYS=$(echo "$STATS"    | grep "evicted_keys:"    | cut -d':' -f2 | tr -d '\r ')

KEYSPACE_HITS=${KEYSPACE_HITS:-0}
KEYSPACE_MISSES=${KEYSPACE_MISSES:-0}

if [ "$KEYSPACE_HITS" -gt 0 ] || [ "$KEYSPACE_MISSES" -gt 0 ]; then
    TOTAL_REQUESTS=$((KEYSPACE_HITS + KEYSPACE_MISSES))
    HIT_RATIO=$(echo "scale=2; ($KEYSPACE_HITS / $TOTAL_REQUESTS) * 100" | bc)
else
    TOTAL_REQUESTS=0
    HIT_RATIO="0.00"
fi

echo -e "${BLUE}Cache Requests:${NC}"
echo "  Total   : ${TOTAL_REQUESTS}"
echo "  Hits    : ${KEYSPACE_HITS}"
echo "  Misses  : ${KEYSPACE_MISSES}"
echo ""
echo -e "${BLUE}Cache Performance:${NC}"
echo -e "  Hit Ratio : ${GREEN}${HIT_RATIO}%${NC}"
echo ""
echo -e "${BLUE}Cache Management:${NC}"
echo "  Expired Keys : ${EXPIRED_KEYS:-0}"
echo "  Evicted Keys : ${EVICTED_KEYS:-0}"
echo ""

KEYSPACE=$(docker compose exec -T redis redis-cli INFO keyspace 2>/dev/null)
echo -e "${BLUE}Keyspace:${NC}"
echo "$KEYSPACE" | grep "^db" || echo "  (kosong)"
echo ""

MEMORY=$(docker compose exec -T redis redis-cli INFO memory 2>/dev/null | grep "used_memory_human:")
echo -e "${BLUE}Memory Usage:${NC}"
echo "  ${MEMORY}" | cut -d':' -f2
echo ""
echo "=========================================="
