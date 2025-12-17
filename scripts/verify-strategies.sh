#!/bin/bash

# Verify all caching strategies can be loaded correctly
# This script tests each strategy by temporarily switching to it

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

STRATEGIES=("cache-aside" "read-through" "write-through")
RESULTS=()

echo ""
echo "====================================="
echo "Verifying Caching Strategies"
echo "====================================="
echo ""

# Store original strategy
ORIGINAL_STRATEGY=$(grep "^CACHE_STRATEGY=" ../.env | cut -d'=' -f2)
echo "Original strategy: ${ORIGINAL_STRATEGY}"
echo ""

# Test each strategy
for strategy in "${STRATEGIES[@]}"; do
    echo -e "${YELLOW}Testing: ${strategy}${NC}"

    # Update .env
    sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${strategy}/" ../.env

    # Clear config
    ../vendor/bin/sail artisan config:clear > /dev/null 2>&1

    # Test if strategy loads without error
    OUTPUT=$(../vendor/bin/sail artisan tinker --execute="app(App\Contracts\CacheStrategyInterface::class); echo 'SUCCESS';" 2>&1)

    if echo "$OUTPUT" | grep -q "SUCCESS"; then
        echo -e "${GREEN}✓ ${strategy} - OK${NC}"
        RESULTS+=("${strategy}:OK")
    else
        echo -e "${RED}✗ ${strategy} - FAILED${NC}"
        echo "Error: $OUTPUT"
        RESULTS+=("${strategy}:FAILED")
    fi
    echo ""
done

# Restore original strategy
if [ ! -z "$ORIGINAL_STRATEGY" ]; then
    sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${ORIGINAL_STRATEGY}/" ../.env
    ../vendor/bin/sail artisan config:clear > /dev/null 2>&1
    echo -e "${YELLOW}Restored original strategy: ${ORIGINAL_STRATEGY}${NC}"
fi

echo ""
echo "====================================="
echo "Results Summary"
echo "====================================="

for result in "${RESULTS[@]}"; do
    strategy=$(echo $result | cut -d':' -f1)
    status=$(echo $result | cut -d':' -f2)

    if [ "$status" == "OK" ]; then
        echo -e "${GREEN}✓ ${strategy}${NC}"
    else
        echo -e "${RED}✗ ${strategy}${NC}"
    fi
done

echo "====================================="
echo ""
