#!/bin/bash
# ============================================================
# Redis Cache Statistics Checker — Mode-Aware
#
# Usage  : ./scripts/check-redis-stats.sh [--cluster]
# Contoh :
#   ./scripts/check-redis-stats.sh           # Single-node Redis
#   ./scripts/check-redis-stats.sh --cluster  # Redis Cluster
#
# Single mode  : reads from single 'redis' container
# Cluster mode : sums across redis-c1..redis-c6, shows per-node breakdown
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

CLUSTER_NODES=("redis-c1" "redis-c2" "redis-c3" "redis-c4" "redis-c5" "redis-c6")

# ─────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────
main() {
  local cluster_mode=false

  while [ $# -gt 0 ]; do
    case "$1" in
      --cluster) cluster_mode=true; shift ;;
      *) echo "Usage: $0 [--cluster]"; exit 1 ;;
    esac
  done

  cd "${PROJECT_DIR}" || exit 1

  echo "=========================================="
  echo "Redis Cache Statistics"
  echo "=========================================="
  if [ "$cluster_mode" = "true" ]; then
    echo "Mode: Cluster (${#CLUSTER_NODES[@]} nodes)"
  else
    echo "Mode: Single-node"
  fi
  echo ""

  # Collect stats per node
  local nodes=()
  if [ "$cluster_mode" = "true" ]; then
    nodes=("${CLUSTER_NODES[@]}")
  else
    nodes=("redis")
  fi

  local total_hits=0
  local total_misses=0
  local total_expired=0
  local total_evicted=0

  for node in "${nodes[@]}"; do
    local ping
    ping=$(docker compose exec -T "$node" redis-cli PING 2>/dev/null | tr -d '\r ')
    if [ "$ping" != "PONG" ]; then
      echo -e "${RED}  ${node}: no response (PING failed)${NC}"
      continue
    fi

    local stats
    stats=$(docker compose exec -T "$node" redis-cli INFO stats 2>/dev/null)

    local hits misses expired evicted
    hits=$(echo "$stats"   | grep "keyspace_hits:"   | cut -d':' -f2 | tr -d '\r ')
    misses=$(echo "$stats" | grep "keyspace_misses:" | cut -d':' -f2 | tr -d '\r ')
    expired=$(echo "$stats"| grep "expired_keys:"    | cut -d':' -f2 | tr -d '\r ')
    evicted=$(echo "$stats"| grep "evicted_keys:"    | cut -d':' -f2 | tr -d '\r ')

    hits=${hits:-0}
    misses=${misses:-0}
    expired=${expired:-0}
    evicted=${evicted:-0}

    total_hits=$((total_hits + hits))
    total_misses=$((total_misses + misses))
    total_expired=$((total_expired + expired))
    total_evicted=$((total_evicted + evicted))

    # Per-node breakdown (cluster mode)
    if [ "$cluster_mode" = "true" ]; then
      local role
      role=$(docker compose exec -T "$node" redis-cli CLUSTER NODES 2>/dev/null \
        | grep "\b$(docker compose exec -T "$node" redis-cli CLUSTER MYID 2>/dev/null | tr -d '\r ')\b" \
        | awk '{print $3}')
      role=${role:-unknown}

      local node_total=$((hits + misses))
      local node_ratio="0.00"
      if [ "$node_total" -gt 0 ]; then
        node_ratio=$(echo "scale=2; ($hits / $node_total) * 100" | bc 2>/dev/null || echo "0.00")
      fi

      local used_mem
      used_mem=$(docker compose exec -T "$node" redis-cli INFO memory 2>/dev/null | grep "used_memory_human:" | cut -d':' -f2 | tr -d '\r ')

      echo -e "${CYAN}--- ${node} (role: ${role}) ---${NC}"
      echo -e "  ${BLUE}Memory:${NC} ${used_mem:-unknown}"
      echo -e "  ${BLUE}Cache Requests:${NC} Hits=${hits} Misses=${misses} Total=${node_total}"
      echo -e "  ${BLUE}Hit Ratio:${NC} ${node_ratio}%"
      echo -e "  ${BLUE}Cache Management:${NC} Expired=${expired} Evicted=${evicted}"

      local keyspace
      keyspace=$(docker compose exec -T "$node" redis-cli INFO keyspace 2>/dev/null | grep "^db" || echo "")
      if [ -n "$keyspace" ]; then
        echo -e "  ${BLUE}Keyspace:${NC}"
        echo "$keyspace" | sed 's/^/    /'
      fi
      echo ""
    fi
  done

  # Summary
  echo "--- Summary ---"
  local total_requests=$((total_hits + total_misses))
  local hit_ratio="0.00"
  if [ "$total_requests" -gt 0 ]; then
    hit_ratio=$(echo "scale=2; ($total_hits / $total_requests) * 100" | bc 2>/dev/null || echo "0.00")
  fi

  echo -e "${BLUE}Total Cache Requests:${NC}"
  echo "  Total   : ${total_requests}"
  echo "  Hits    : ${total_hits}"
  echo "  Misses  : ${total_misses}"
  echo ""
  echo -e "${BLUE}Cache Performance:${NC}"
  echo -e "  Hit Ratio : ${GREEN}${hit_ratio}%${NC}"
  echo ""
  echo -e "${BLUE}Cache Management:${NC}"
  echo "  Expired Keys : ${total_expired}"
  echo "  Evicted Keys : ${total_evicted}"

  # Keyspace summary (single mode only)
  if [ "$cluster_mode" != "true" ]; then
    echo ""
    local keyspace
    keyspace=$(docker compose exec -T redis redis-cli INFO keyspace 2>/dev/null)
    echo -e "${BLUE}Keyspace:${NC}"
    echo "$keyspace" | grep "^db" || echo "  (kosong)"
    echo ""

    local memory_line
    memory_line=$(docker compose exec -T redis redis-cli INFO memory 2>/dev/null | grep "used_memory_human:")
    echo -e "${BLUE}Memory Usage:${NC}"
    echo "  ${memory_line}" | cut -d':' -f2
  fi

  echo ""
  echo "=========================================="
}

main "$@"
