#!/bin/bash
# ============================================================
# Redis Mode Verification Library
#
# A shared shell library for verifying Redis mode matches
# the actual runtime state before benchmark execution.
#
# Usage:
#   source "$(dirname "$0")/lib/redis-mode-check.sh"
#   require_redis_single
#   require_redis_cluster
# ============================================================

# ─────────────────────────────────────────────
# Shared helpers
# ─────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

CLUSTER_NODE_NAMES=("redis-c1" "redis-c2" "redis-c3" "redis-c4" "redis-c5" "redis-c6")

# Resolve project root from this script's location
_redis_lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_redis_script_dir="$(dirname "$_redis_lib_dir")"
_redis_project_dir="$(dirname "$_redis_script_dir")"

# ─────────────────────────────────────────────
# Check a Redis node is reachable and responds PONG
# ─────────────────────────────────────────────
_redis_ping() {
  local node=$1
  docker compose exec -T "$node" redis-cli PING 2>/dev/null | tr -d '\r ' | grep -q "^PONG$"
}

# ─────────────────────────────────────────────
# Check whether any cluster-named services are running
# ─────────────────────────────────────────────
_any_cluster_services_running() {
  for node in "${CLUSTER_NODE_NAMES[@]}"; do
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
      return 0
    fi
  done
  return 1
}

# ─────────────────────────────────────────────
# Get the value of a Laravel config key from the running app
# ─────────────────────────────────────────────
_laravel_config() {
  local key=$1
  docker compose exec -T app php -r "\$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); \$value = config('${key}'); if (is_array(\$value)) { echo json_encode(\$value); } elseif (is_bool(\$value)) { echo \$value ? 'true' : 'false'; } elseif (\$value !== null) { echo \$value; }" 2>/dev/null | tr -d '\r\n'
}

# ─────────────────────────────────────────────
# Print diagnostic output when mode verification fails
# ─────────────────────────────────────────────
_diagnostic_output() {
  local requested_mode=$1

  echo ""
  echo -e "${RED}═══════════════════════════════════════════════${NC}"
  echo -e "${RED}  Redis Mode Mismatch${NC}"
  echo -e "${RED}═══════════════════════════════════════════════${NC}"
  echo -e "  ${YELLOW}Requested mode${NC}  : ${CYAN}${requested_mode}${NC}"
  echo ""

  # .env REDIS_CLUSTER_MODE
  local env_val
  env_val=$(grep "^REDIS_CLUSTER_MODE=" "${_redis_project_dir}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
  echo -e "  ${YELLOW}.env REDIS_CLUSTER_MODE${NC} : ${CYAN}${env_val:-unset}${NC}"

  # Running Redis services
  echo ""
  echo -e "  ${YELLOW}Running Redis containers:${NC}"
  local found=false
  for node in "${CLUSTER_NODE_NAMES[@]}"; do
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
      echo -e "    ${GREEN}✓${NC} $node"
      found=true
    fi
  done
  if docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "^redis$"; then
    echo -e "    ${GREEN}✓${NC} redis (single)"
    found=true
  fi
  if [ "$found" = false ]; then
    echo -e "    ${RED}(none)${NC}"
  fi

  # Laravel resolved config
  echo ""
  echo -e "  ${YELLOW}Laravel resolved config:${NC}"
  local default_host
  default_host=$(_laravel_config "database.redis.default.host" 2>/dev/null)
  echo -e "    database.redis.default.host : ${CYAN}${default_host:-unknown}${NC}"

  local cache_host
  cache_host=$(_laravel_config "database.redis.cache.host" 2>/dev/null)
  echo -e "    database.redis.cache.host   : ${CYAN}${cache_host:-unknown}${NC}"

  # Redis runtime config
  if [ "$requested_mode" = "single" ]; then
    echo ""
    echo -e "  ${YELLOW}Redis runtime config:${NC}"
    local redis_maxmemory
    redis_maxmemory=$(docker compose exec -T redis redis-cli CONFIG GET maxmemory 2>/dev/null | tail -1 | tr -d '\r\n ')
    local redis_maxmemory_policy
    redis_maxmemory_policy=$(docker compose exec -T redis redis-cli CONFIG GET maxmemory-policy 2>/dev/null | tail -1 | tr -d '\r\n ')
    echo -e "    maxmemory        : ${CYAN}${redis_maxmemory:-unknown}${NC} (expected 268435456 = 256MB)"
    echo -e "    maxmemory-policy : ${CYAN}${redis_maxmemory_policy:-unknown}${NC} (expected allkeys-lru)"
  fi

  # Corrective command
  echo ""
  echo -e "  ${YELLOW}Suggested fix:${NC}"
  if [ "$requested_mode" = "single" ]; then
    echo -e "    ${CYAN}1. Stop cluster services:   ./scripts/setup-redis-cluster.sh down${NC}"
    echo -e "    ${CYAN}2. Recreate Redis container: docker compose up -d redis${NC}"
    echo -e "    ${CYAN}3. Verify:                  docker compose exec redis redis-cli PING${NC}"
    echo -e "    ${CYAN}4. Check config:            docker compose exec redis redis-cli CONFIG GET maxmemory${NC}"
    echo -e "    ${CYAN}                             docker compose exec redis redis-cli CONFIG GET maxmemory-policy${NC}"
  else
    echo -e "    ${CYAN}./scripts/setup-redis-cluster.sh up${NC}"
    echo -e "    Then verify: docker compose exec redis-c1 redis-cli CLUSTER INFO"
  fi
  echo -e "${RED}═══════════════════════════════════════════════${NC}"
  echo ""
}

# ─────────────────────────────────────────────
# Verifikasi Single-Node Redis
#
# Memastikan:
#   1. .env: REDIS_CLUSTER_MODE=false atau tidak ada
#   2. Tidak ada redis-c1..redis-c6 running
#   3. Laravel config mengarah ke single redis
#   4. Container 'redis' reachable (PING PONG)
# ─────────────────────────────────────────────
require_redis_single() {
  local project_dir="${1:-$_redis_project_dir}"
  echo -e "${YELLOW}[mode-check] Verifying single-node Redis mode...${NC}"

  # 1. Check .env does not have REDIS_CLUSTER_MODE=true
  local env_val
  env_val=$(grep "^REDIS_CLUSTER_MODE=" "${project_dir}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
  if [ "$env_val" = "true" ]; then
    echo -e "  ${RED}✗ .env has REDIS_CLUSTER_MODE=true${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ .env REDIS_CLUSTER_MODE is not 'true'${NC}"

  # 2. Check no cluster services are running
  if _any_cluster_services_running; then
    echo -e "  ${RED}✗ Cluster Redis services (redis-c1..redis-c6) are running${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ No cluster Redis services running${NC}"

  # 3. Check Laravel config does not expose clusters
  cd "$project_dir"
  if docker compose exec -T app php artisan config:show database.redis.clusters 2>/dev/null | grep -qE "^\s+database.redis.clusters"; then
    echo -e "  ${RED}✗ Laravel config exposes database.redis.clusters${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ Laravel config does not expose clusters${NC}"

  # 4. Check default host points to 'redis'
  local default_host
  default_host=$(_laravel_config "database.redis.default.host")
  if [ "$default_host" != "redis" ]; then
    echo -e "  ${RED}✗ database.redis.default.host is '${default_host}' (expected 'redis')${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ database.redis.default.host = redis${NC}"

  # 5. Check cache host points to 'redis'
  local cache_host
  cache_host=$(_laravel_config "database.redis.cache.host")
  if [ "$cache_host" != "redis" ]; then
    echo -e "  ${RED}✗ database.redis.cache.host is '${cache_host}' (expected 'redis')${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ database.redis.cache.host = redis${NC}"

  # 6. Check single Redis container is reachable
  if _redis_ping "redis"; then
    echo -e "  ${GREEN}✓ Single Redis reachable (PONG)${NC}"
  else
    echo -e "  ${RED}✗ Single Redis not reachable (PING failed)${NC}"
    _diagnostic_output "single"
    return 1
  fi

  # 7. Verify single Redis config (maxmemory, maxmemory-policy)
  local redis_maxmemory
  redis_maxmemory=$(docker compose exec -T redis redis-cli CONFIG GET maxmemory 2>/dev/null | tail -1 | tr -d '
 ')
  local redis_maxmemory_policy
  redis_maxmemory_policy=$(docker compose exec -T redis redis-cli CONFIG GET maxmemory-policy 2>/dev/null | tail -1 | tr -d '
 ')

  if [ "$redis_maxmemory" != "268435456" ]; then
    echo -e "  ${RED}✗ Redis maxmemory is ${redis_maxmemory:-unknown} (expected 268435456 = 256MB)${NC}"
    echo -e "     ${YELLOW}Fix: recreate the Redis container or run:${NC}"
    echo -e "     ${CYAN}docker compose exec redis redis-cli CONFIG SET maxmemory 268435456${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ Redis maxmemory = 268435456 (256MB)${NC}"

  if [ "$redis_maxmemory_policy" != "allkeys-lru" ]; then
    echo -e "  ${RED}✗ Redis maxmemory-policy is '${redis_maxmemory_policy:-unknown}' (expected 'allkeys-lru')${NC}"
    echo -e "     ${YELLOW}Fix: run:${NC}"
    echo -e "     ${CYAN}docker compose exec redis redis-cli CONFIG SET maxmemory-policy allkeys-lru${NC}"
    _diagnostic_output "single"
    return 1
  fi
  echo -e "  ${GREEN}✓ Redis maxmemory-policy = allkeys-lru${NC}"

  echo -e "${GREEN}[mode-check] ✓ Single-node Redis verified${NC}"
  return 0
}

# ─────────────────────────────────────────────
# Verifikasi Redis Cluster Mode
#
# Memastikan:
#   1. .env: REDIS_CLUSTER_MODE=true
#   2. redis-c1..redis-c6 running
#   3. cluster_state: ok
#   4. Laravel config exposes clusters
# ─────────────────────────────────────────────
require_redis_cluster() {
  local project_dir="${1:-$_redis_project_dir}"
  echo -e "${YELLOW}[mode-check] Verifying Redis Cluster mode...${NC}"

  cd "$project_dir"

  # 1. Check .env has REDIS_CLUSTER_MODE=true
  local env_val
  env_val=$(grep "^REDIS_CLUSTER_MODE=" "${project_dir}/.env" 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
  if [ "$env_val" != "true" ]; then
    echo -e "  ${RED}✗ .env does not have REDIS_CLUSTER_MODE=true${NC}"
    _diagnostic_output "cluster"
    return 1
  fi
  echo -e "  ${GREEN}✓ .env REDIS_CLUSTER_MODE=true${NC}"

  # 2. Check all cluster services are running
  local all_running=true
  for node in "${CLUSTER_NODE_NAMES[@]}"; do
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -qF "$node"; then
      :
    else
      echo -e "  ${RED}✗ ${node} is not running${NC}"
      all_running=false
    fi
  done
  if [ "$all_running" = false ]; then
    _diagnostic_output "cluster"
    return 1
  fi
  echo -e "  ${GREEN}✓ All cluster nodes running${NC}"

  # 3. Check cluster_state: ok
  local cluster_state
  cluster_state=$(docker compose exec -T redis-c1 redis-cli CLUSTER INFO 2>/dev/null | grep "cluster_state" | cut -d':' -f2 | tr -d '\r ')
  if [ "$cluster_state" != "ok" ]; then
    echo -e "  ${RED}✗ Cluster state: ${cluster_state:-unknown} (expected: ok)${NC}"
    _diagnostic_output "cluster"
    return 1
  fi
  echo -e "  ${GREEN}✓ Cluster state: ok${NC}"

  # 4. Check Laravel config exposes clusters
  if ! docker compose exec -T app php artisan config:show database.redis.clusters 2>/dev/null | grep -qE "^\s+database.redis.clusters"; then
    echo -e "  ${RED}✗ Laravel config does not expose database.redis.clusters${NC}"
    _diagnostic_output "cluster"
    return 1
  fi
  echo -e "  ${GREEN}✓ Laravel config exposes clusters${NC}"

  echo -e "${GREEN}[mode-check] ✓ Redis Cluster verified${NC}"
  return 0
}
