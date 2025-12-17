#!/bin/bash

# Caching Strategy Switcher
# Usage: ./switch-strategy.sh [cache-aside|read-through|write-through|no-cache]

STRATEGY=$1
ENV_FILE="../.env"

# Color codes for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Display usage
usage() {
    echo "Usage: ./scripts/switch-strategy.sh [cache-aside|read-through|write-through|no-cache]"
    echo ""
    echo "Available strategies:"
    echo "  cache-aside     - Lazy loading, app manages cache"
    echo "  read-through    - Cache layer handles DB reads"
    echo "  write-through   - Synchronous write to cache and DB"
    echo "  no-cache        - No caching, direct database access (baseline)"
    echo ""
    echo "Example: ./scripts/switch-strategy.sh read-through"
    exit 1
}

# Validate strategy
validate_strategy() {
    case $1 in
        cache-aside|read-through|write-through|no-cache)
            return 0
            ;;
        *)
            echo -e "${RED}Error: Invalid strategy '$1'${NC}"
            usage
            ;;
    esac
}

# Update .env file
update_env() {
    local strategy=$1

    # Check if .env exists
    if [ ! -f "$ENV_FILE" ]; then
        echo -e "${RED}Error: .env file not found${NC}"
        exit 1
    fi

    # Backup .env
    cp "$ENV_FILE" "${ENV_FILE}.backup"

    # Update CACHE_STRATEGY
    if grep -q "^CACHE_STRATEGY=" "$ENV_FILE"; then
        sed -i "s/^CACHE_STRATEGY=.*/CACHE_STRATEGY=${strategy}/" "$ENV_FILE"
    else
        echo "CACHE_STRATEGY=${strategy}" >> "$ENV_FILE"
    fi

    echo -e "${GREEN}✓ Updated .env file${NC}"
}

# Clear caches
clear_caches() {
    echo -e "${YELLOW}Clearing caches...${NC}"
    ../vendor/bin/sail artisan config:clear
    ../vendor/bin/sail artisan cache:clear
    echo -e "${GREEN}✓ Caches cleared${NC}"
}

# Display current configuration
show_config() {
    echo ""
    echo "====================================="
    echo "Current Configuration"
    echo "====================================="
    grep "^CACHE_STRATEGY=" "$ENV_FILE"
    grep "^CACHE_TTL=" "$ENV_FILE"
    grep "^CACHE_STORE=" "$ENV_FILE"
    grep "^REDIS_HOST=" "$ENV_FILE"
    echo "====================================="
    echo ""
}

# Main execution
main() {
    # Check if strategy is provided
    if [ -z "$STRATEGY" ]; then
        echo -e "${RED}Error: No strategy specified${NC}"
        usage
    fi

    # Validate strategy
    validate_strategy "$STRATEGY"

    echo ""
    echo "====================================="
    echo "Switching Caching Strategy"
    echo "====================================="
    echo "Target Strategy: ${STRATEGY}"
    echo ""

    # Update .env
    update_env "$STRATEGY"

    # Clear caches
    clear_caches

    # Show configuration
    show_config

    echo -e "${GREEN}✓ Strategy switched successfully to: ${STRATEGY}${NC}"
    echo ""
    echo "You can now run your benchmarks with the new strategy."
    echo ""
}

# Run main
main
