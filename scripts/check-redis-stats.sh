#!/bin/bash

# Redis Cache Statistics Checker
# Displays cache hit ratio and other Redis statistics

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo "=========================================="
echo "Redis Cache Statistics"
echo "=========================================="
echo ""

# Get Redis stats
STATS=$(../vendor/bin/sail exec redis redis-cli INFO stats)

# Extract metrics
KEYSPACE_HITS=$(echo "$STATS" | grep "keyspace_hits:" | cut -d':' -f2 | tr -d '\r')
KEYSPACE_MISSES=$(echo "$STATS" | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r')
EXPIRED_KEYS=$(echo "$STATS" | grep "expired_keys:" | cut -d':' -f2 | tr -d '\r')
EVICTED_KEYS=$(echo "$STATS" | grep "evicted_keys:" | cut -d':' -f2 | tr -d '\r')

# Calculate hit ratio
if [ "$KEYSPACE_HITS" -gt 0 ] || [ "$KEYSPACE_MISSES" -gt 0 ]; then
    TOTAL_REQUESTS=$((KEYSPACE_HITS + KEYSPACE_MISSES))
    HIT_RATIO=$(echo "scale=2; ($KEYSPACE_HITS / $TOTAL_REQUESTS) * 100" | bc)
else
    TOTAL_REQUESTS=0
    HIT_RATIO=0
fi

echo -e "${BLUE}Cache Requests:${NC}"
echo "  Total Requests: ${TOTAL_REQUESTS}"
echo "  Cache Hits:     ${KEYSPACE_HITS}"
echo "  Cache Misses:   ${KEYSPACE_MISSES}"
echo ""

echo -e "${BLUE}Cache Performance:${NC}"
echo -e "  Hit Ratio:      ${GREEN}${HIT_RATIO}%${NC}"
echo ""

echo -e "${BLUE}Cache Management:${NC}"
echo "  Expired Keys:   ${EXPIRED_KEYS}"
echo "  Evicted Keys:   ${EVICTED_KEYS}"
echo ""

# Get keyspace info
KEYSPACE=$(../vendor/bin/sail exec redis redis-cli INFO keyspace)
echo -e "${BLUE}Keyspace Info:${NC}"
echo "$KEYSPACE" | grep "^db"
echo ""

# Get memory info
MEMORY=$(../vendor/bin/sail exec redis redis-cli INFO memory | grep "used_memory_human:")
echo -e "${BLUE}Memory Usage:${NC}"
echo "  $MEMORY" | cut -d':' -f2
echo ""

echo "=========================================="
