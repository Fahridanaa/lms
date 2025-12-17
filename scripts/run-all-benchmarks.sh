#!/bin/bash

# Complete Benchmark Suite Runner
# Runs all 4 strategies across all 3 scenarios (12 total tests)

STRATEGIES=("no-cache" "cache-aside" "read-through" "write-through")
SCENARIOS=("read-heavy" "write-heavy" "stress")

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo "=========================================="
echo "Complete Benchmark Suite"
echo "=========================================="
echo -e "${BLUE}Strategies:${NC} ${STRATEGIES[@]}"
echo -e "${BLUE}Scenarios:${NC} ${SCENARIOS[@]}"
echo -e "${BLUE}Total Tests:${NC} $((${#STRATEGIES[@]} * ${#SCENARIOS[@]}))"
echo -e "${BLUE}Estimated Time:${NC} ~3 hours"
echo "=========================================="
echo ""

# Confirm before running
read -p "Press Enter to start or Ctrl+C to cancel..."

# Track results
TOTAL_TESTS=$((${#STRATEGIES[@]} * ${#SCENARIOS[@]}))
COMPLETED=0
FAILED=0

START_TIME=$(date +%s)

for strategy in "${STRATEGIES[@]}"; do
  for scenario in "${SCENARIOS[@]}"; do
    COMPLETED=$((COMPLETED + 1))
    
    echo ""
    echo "=========================================="
    echo -e "${YELLOW}Test $COMPLETED of $TOTAL_TESTS${NC}"
    echo -e "${BLUE}Strategy:${NC} $strategy"
    echo -e "${BLUE}Scenario:${NC} $scenario"
    echo "=========================================="

    # Run benchmark
    $(dirname "$0")/run-benchmark.sh $strategy $scenario
    
    if [ $? -eq 0 ]; then
      echo -e "${GREEN}✓ Benchmark completed successfully${NC}"
    else
      echo -e "${RED}✗ Benchmark failed${NC}"
      FAILED=$((FAILED + 1))
    fi
    
    # Wait between tests (except after last test)
    if [ $COMPLETED -lt $TOTAL_TESTS ]; then
      echo ""
      echo -e "${YELLOW}Waiting 60 seconds before next test...${NC}"
      sleep 60
    fi
  done
done

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
HOURS=$((DURATION / 3600))
MINUTES=$(((DURATION % 3600) / 60))

echo ""
echo "=========================================="
echo "Benchmark Suite Complete!"
echo "=========================================="
echo -e "${GREEN}Completed:${NC} $((TOTAL_TESTS - FAILED)) / $TOTAL_TESTS"
echo -e "${RED}Failed:${NC} $FAILED / $TOTAL_TESTS"
echo -e "${BLUE}Duration:${NC} ${HOURS}h ${MINUTES}m"
echo ""
echo "Results saved in: benchmark-results/"
echo ""
echo "Next steps:"
echo "1. Analyze results: see BENCHMARK_COMPARISON.md"
echo "2. Extract metrics: grep -r 'http_req_duration' benchmark-results/*-summary.json"
echo "3. Compare strategies: cat benchmark-results/*-cache-stats.txt"
echo ""
