#!/bin/bash

# Prepare system for benchmarking
# This script ensures the system is in a clean state before running benchmarks

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo ""
echo "=========================================="
echo "Preparing System for Benchmark"
echo "=========================================="
echo ""

# Check if Sail is running
echo -e "${YELLOW}Checking Docker containers...${NC}"
if ! ../vendor/bin/sail ps | grep -q "Up"; then
    echo -e "${RED}Error: Sail is not running${NC}"
    echo "Starting Sail..."
    ../vendor/bin/sail up -d
    echo "Waiting for containers to be ready (30 seconds)..."
    sleep 30
else
    echo -e "${GREEN}✓ Docker containers are running${NC}"
fi

# Check database connection
echo ""
echo -e "${YELLOW}Checking database connection...${NC}"
if ../vendor/bin/sail artisan migrate:status > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Database is accessible${NC}"
else
    echo -e "${RED}Error: Cannot connect to database${NC}"
    exit 1
fi

# Check Redis connection
echo ""
echo -e "${YELLOW}Checking Redis connection...${NC}"
if ../vendor/bin/sail exec redis redis-cli PING | grep -q "PONG"; then
    echo -e "${GREEN}✓ Redis is accessible${NC}"
else
    echo -e "${RED}Error: Cannot connect to Redis${NC}"
    exit 1
fi

# Fresh migration and seed
echo ""
echo -e "${YELLOW}Running fresh migration and seed...${NC}"
../vendor/bin/sail artisan migrate:fresh --seed --force

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Database migrated and seeded${NC}"
else
    echo -e "${RED}Error: Migration/seeding failed${NC}"
    exit 1
fi

# Clear all caches
echo ""
echo -e "${YELLOW}Clearing all caches...${NC}"
../vendor/bin/sail artisan cache:clear
../vendor/bin/sail artisan config:clear
../vendor/bin/sail artisan route:clear
../vendor/bin/sail exec redis redis-cli FLUSHALL
echo -e "${GREEN}✓ All caches cleared${NC}"

# Verify current cache strategy
echo ""
echo -e "${YELLOW}Current configuration:${NC}"
STRATEGY=$(grep "^CACHE_STRATEGY=" ../.env | cut -d'=' -f2)
echo "  Cache Strategy: ${STRATEGY}"
echo "  Cache Store:    $(grep "^CACHE_STORE=" ../.env | cut -d'=' -f2)"
echo "  Cache TTL:      $(grep "^CACHE_TTL=" ../.env | cut -d'=' -f2) seconds"

# Check data counts
echo ""
echo -e "${YELLOW}Verifying data...${NC}"
USER_COUNT=$(../vendor/bin/sail artisan tinker --execute="echo App\Models\User::count();" 2>/dev/null | tail -1)
COURSE_COUNT=$(../vendor/bin/sail artisan tinker --execute="echo App\Models\Course::count();" 2>/dev/null | tail -1)
QUIZ_COUNT=$(../vendor/bin/sail artisan tinker --execute="echo App\Models\Quiz::count();" 2>/dev/null | tail -1)

echo "  Users:   ${USER_COUNT}"
echo "  Courses: ${COURSE_COUNT}"
echo "  Quizzes: ${QUIZ_COUNT}"

# Create results directory
echo ""
echo -e "${YELLOW}Creating results directory...${NC}"
mkdir -p ../benchmark-results
echo -e "${GREEN}✓ Results directory ready${NC}"

# Check k6 installation
echo ""
echo -e "${YELLOW}Checking k6 installation...${NC}"
if command -v k6 &> /dev/null; then
    K6_VERSION=$(k6 version | head -1)
    echo -e "${GREEN}✓ k6 is installed (${K6_VERSION})${NC}"
else
    echo -e "${RED}Warning: k6 is not installed${NC}"
    echo "Install k6 from: https://k6.io/docs/getting-started/installation/"
    echo ""
    echo "Ubuntu/Debian:"
    echo "  sudo gpg -k"
    echo "  sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69"
    echo "  echo \"deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main\" | sudo tee /etc/apt/sources.list.d/k6.list"
    echo "  sudo apt-get update"
    echo "  sudo apt-get install k6"
fi

# Display system info
echo ""
echo "=========================================="
echo "System Ready for Benchmarking!"
echo "=========================================="
echo ""
echo "Run a benchmark with:"
echo "  ./scripts/run-benchmark.sh [strategy] [scenario]"
echo ""
echo "Examples:"
echo "  ./scripts/run-benchmark.sh cache-aside read-heavy"
echo "  ./scripts/run-benchmark.sh read-through write-heavy"
echo "  ./scripts/run-benchmark.sh write-through stress"
echo ""
