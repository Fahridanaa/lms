#!/bin/bash

# LMS Caching Strategy Benchmark Runner
# Usage: ./run-benchmark.sh [strategy] [scenario]
# Example: ./run-benchmark.sh cache-aside read-heavy

STRATEGY=${1:-cache-aside}
SCENARIO=${2:-read-heavy}
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RESULTS_DIR="../benchmark-results"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

# Create results directory
mkdir -p ${RESULTS_DIR}

echo ""
echo "=========================================="
echo "LMS Caching Strategy Benchmark"
echo "=========================================="
echo -e "${BLUE}Strategy:${NC} ${STRATEGY}"
echo -e "${BLUE}Scenario:${NC} ${SCENARIO}"
echo -e "${BLUE}Timestamp:${NC} ${TIMESTAMP}"
echo "=========================================="
echo ""

# Validate strategy
case $STRATEGY in
    cache-aside|read-through|write-through|no-cache)
        ;;
    *)
        echo -e "${RED}Error: Invalid strategy '${STRATEGY}'${NC}"
        echo "Valid strategies: cache-aside, read-through, write-through, no-cache"
        exit 1
        ;;
esac

# Validate scenario
case $SCENARIO in
    read-heavy|write-heavy|stress)
        ;;
    *)
        echo -e "${RED}Error: Invalid scenario '${SCENARIO}'${NC}"
        echo "Valid scenarios: read-heavy, write-heavy, stress"
        exit 1
        ;;
esac

# Switch to the target strategy
echo -e "${YELLOW}Step 1: Switching to ${STRATEGY} strategy...${NC}"
$(dirname "$0")/switch-strategy.sh ${STRATEGY}

# Prepare database (fresh migration and seed)
# echo ""
# echo -e "${YELLOW}Step 2: Preparing database (fresh migration + seed)...${NC}"
# ./vendor/bin/sail artisan migrate:fresh --seed --force

# Clear all caches
echo ""
echo -e "${YELLOW}Step 3: Clearing all caches...${NC}"
../vendor/bin/sail artisan cache:clear
../vendor/bin/sail artisan config:clear
../vendor/bin/sail exec redis redis-cli FLUSHALL

# Wait for system to stabilize
echo ""
echo -e "${YELLOW}Step 4: Waiting for system to stabilize (10 seconds)...${NC}"
sleep 10

# Record system state before benchmark
echo ""
echo -e "${YELLOW}Step 5: Recording pre-benchmark system state...${NC}"
echo "Redis Stats (Before):" > ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-redis-before.txt
../vendor/bin/sail exec redis redis-cli INFO stats >> ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-redis-before.txt

# Run k6 benchmark
echo ""
echo -e "${YELLOW}Step 6: Running k6 benchmark...${NC}"
echo -e "${GREEN}This will take approximately 15 minutes${NC}"
echo ""

RESULT_FILE="${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}.json"

k6 run \
    --out json=${RESULT_FILE} \
    --summary-export=${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-summary.json \
    ../tests/Benchmark/k6/${SCENARIO}-scenario.js

# Check if k6 is installed
# if [ $? -ne 0 ]; then
#     echo -e "${RED}Error: k6 is not installed or benchmark failed${NC}"
#     echo "Install k6: https://k6.io/docs/getting-started/installation/"
#     exit 1
# fi

# Record system state after benchmark
echo ""
echo -e "${YELLOW}Step 7: Recording post-benchmark system state...${NC}"
echo "Redis Stats (After):" > ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-redis-after.txt
../vendor/bin/sail exec redis redis-cli INFO stats >> ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-redis-after.txt

# Calculate cache hit ratio
echo ""
echo -e "${YELLOW}Step 8: Calculating cache statistics...${NC}"
$(dirname "$0")/check-redis-stats.sh > ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-cache-stats.txt

# Display summary
echo ""
echo "=========================================="
echo "Benchmark Complete!"
echo "=========================================="
echo -e "${GREEN}Results saved to:${NC}"
echo "  - ${RESULT_FILE}"
echo "  - ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-summary.json"
echo "  - ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-redis-*.txt"
echo "  - ${RESULTS_DIR}/${STRATEGY}-${SCENARIO}-${TIMESTAMP}-cache-stats.txt"
echo "=========================================="
echo ""
echo -e "${BLUE}Next steps:${NC}"
echo "1. Review the summary JSON file for key metrics"
echo "2. Compare cache hit ratios between strategies"
echo "3. Run the same scenario with other strategies"
echo ""
