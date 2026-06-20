#!/bin/bash
# ============================================================
# Remote Benchmark Runner — k6 VPS Controller
#
# Usage  : ./scripts/run-remote-benchmark.sh [--cluster] [--preflight] [--fixture-check] \\
#           <strategy> <scenario> <base_url> [iteration]
#
# Examples:
#   ./scripts/run-remote-benchmark.sh cache-aside read-heavy https://lms.example.com 1
#   ./scripts/run-remote-benchmark.sh --cluster no-cache write-heavy https://lms.example.com 1
#   ./scripts/run-remote-benchmark.sh --preflight
#   ./scripts/run-remote-benchmark.sh --fixture-check
#
# Environment variables (see benchmark-remote-config.sh):
#   BENCHMARK_MODE=remote         Required
#   LMS_SSH_HOST=user@lms-vps     Required
#   LMS_PROJECT_DIR=...           Required
#   K6_PROJECT_DIR=...            Required (defaults to LMS_PROJECT_DIR)
#   RESULTS_DIR=...               Required
#   SSH_OPTS=""                    Optional
#   RSYNC_OPTS=""                  Optional
#   REMOTE_ARTIFACT_DIR=...       Optional (default: /tmp/lms-benchmark-artifacts)
#   VU_LEVELS="100 250 500..."    Optional
#   BENCHMARK_ITERATIONS=3        Optional
#   CLUSTER_MODE=true             Optional
# ============================================================

set -euo pipefail

# ─────────────────────────────────────────────
# State & config
# ─────────────────────────────────────────────
CLUSTER_MODE="${CLUSTER_MODE:-false}"
MODE="run"  # run | preflight | fixture-check
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Parse flags before positional args
while [ $# -gt 0 ]; do
  case "$1" in
    --cluster) CLUSTER_MODE=true; shift ;;
    --preflight) MODE="preflight"; shift ;;
    --fixture-check) MODE="fixture-check"; shift ;;
    -h|--help)
      echo "Usage: $(basename "$0") [--cluster] [--preflight] [--fixture-check] <strategy> <scenario> <base_url> [iteration]"
      echo ""
      echo "Flags:"
      echo "  --cluster        Redis Cluster mode (overrides CLUSTER_MODE env)"
      echo "  --preflight      Run preflight checks on both hosts"
      echo "  --fixture-check  Verify fixture sync only"
      echo ""
      echo "Environment (see benchmark-remote-config.sh):"
      echo "  BENCHMARK_MODE=remote       Required when set"
      echo "  LMS_SSH_HOST=user@host       Required"
      echo "  LMS_PROJECT_DIR=/path        Required"
      echo "  BASE_URL=https://...         Required (or positional arg)"
      echo "  CLUSTER_MODE=true            Optional"
      echo "  K6_PROJECT_DIR=/path         Optional (defaults to LMS_PROJECT_DIR)"
      echo "  RESULTS_DIR=/path            Optional (default: \${K6_PROJECT_DIR}/benchmark-results)"
      echo "  REMOTE_ARTIFACT_DIR=/path    Optional (default: /tmp/lms-benchmark-artifacts)"
      exit 0
      ;;
    -*)
      echo "Error: Unknown option $1" >&2
      exit 1
      ;;
    *) break ;;
  esac
done

STRATEGY="${1:-cache-aside}"
SCENARIO="${2:-read-heavy}"
BASE_URL="${3:-${BASE_URL:-}}"
ITERATION="${4:-1}"
REMOTE_ARTIFACT_DIR="${REMOTE_ARTIFACT_DIR:-/tmp/lms-benchmark-artifacts}"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# ─────────────────────────────────────────────
# Config validation
# ─────────────────────────────────────────────
validate_config() {
  local missing=0

  if [ -n "${BENCHMARK_MODE:-}" ] && [ "${BENCHMARK_MODE}" != "remote" ]; then
    echo -e "${RED}Error: BENCHMARK_MODE must be 'remote' (got '${BENCHMARK_MODE}')${NC}" >&2
    return 1
  fi

  if [ -z "${LMS_SSH_HOST:-}" ]; then
    echo -e "${RED}Error: LMS_SSH_HOST is not set.${NC}" >&2
    echo "  Set it in environment or source benchmark-remote-config.sh" >&2
    missing=1
  fi

  if [ -z "${LMS_PROJECT_DIR:-}" ]; then
    echo -e "${RED}Error: LMS_PROJECT_DIR is not set.${NC}" >&2
    missing=1
  fi

  if [ -z "${BASE_URL}" ]; then
    echo -e "${RED}Error: BASE_URL is required as argument or env.${NC}" >&2
    missing=1
  fi

  K6_PROJECT_DIR="${K6_PROJECT_DIR:-${LMS_PROJECT_DIR:-}}"
  RESULTS_DIR="${RESULTS_DIR:-${K6_PROJECT_DIR}/benchmark-results}"

  return $missing
}

# ─────────────────────────────────────────────
# SSH helper — run command on LMS VPS
# ─────────────────────────────────────────────
lms_ssh() {
  ssh ${SSH_OPTS:-} "${LMS_SSH_HOST}" "cd ${LMS_PROJECT_DIR} && $*"
}

# ─────────────────────────────────────────────
# SSH helper — fire-and-forget (detached, no stdout capture)
# Uses -n -f to fork SSH to background immediately.
# ─────────────────────────────────────────────
lms_ssh_detach() {
  # -n:  prevent stdin reading
  # -f:  fork SSH to background before executing remote command
  # The remote command is backgrounded with output to /dev/null so
  # the SSH session can close immediately.
  ssh -n -f ${SSH_OPTS:-} "${LMS_SSH_HOST}" "cd ${LMS_PROJECT_DIR} && $* >/dev/null 2>&1 &"
}

# ─────────────────────────────────────────────
# Rsync helper — copy from LMS VPS to local
# ─────────────────────────────────────────────
lms_rsync_pull() {
  local remote_path="$1"
  local local_path="$2"
  rsync -az ${RSYNC_OPTS:-} "${LMS_SSH_HOST}:${remote_path}" "${local_path}"
} 

# ─────────────────────────────────────────────
# Preflight checks (both hosts)
# ─────────────────────────────────────────────
cmd_preflight() {
  echo ""
  echo "=============================================="
  echo "  Remote Preflight Checks"
  echo "=============================================="
  echo ""

  local exit_code=0

  # ── k6 VPS checks ──
  echo -e "${CYAN}--- k6 VPS ---${NC}"

  for tool in k6 curl jq node bc rsync; do
    echo -n "  ${tool}: "
    if command -v "${tool}" &>/dev/null; then
      local ver
      ver=$("${tool}" --version 2>/dev/null | head -1 || echo "available")
      echo -e "${GREEN}OK${NC} (${ver})"
    else
      echo -e "${RED}FAIL${NC}"
      exit_code=1
    fi
  done

  echo -n "  RESULTS_DIR (${RESULTS_DIR}): "
  mkdir -p "${RESULTS_DIR}"
  if [ -w "${RESULTS_DIR}" ]; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  echo -n "  BASE_URL reachable (${BASE_URL}): "
  local http_code
  http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "${BASE_URL}/api/courses" 2>/dev/null || true)
  http_code="${http_code: -3}"
  if [ "${http_code}" = "000" ]; then
    echo -e "${RED}FAIL${NC} (unreachable)"
    exit_code=1
  elif [[ "${http_code}" =~ ^5[0-9][0-9]$ ]]; then
    echo -e "${RED}FAIL${NC} (HTTP ${http_code})"
    exit_code=1
  elif [[ "${http_code}" =~ ^[0-9][0-9][0-9]$ ]]; then
    echo -e "${GREEN}OK${NC} (HTTP ${http_code})"
  else
    echo -e "${RED}FAIL${NC} (invalid HTTP code: ${http_code:-empty})"
    exit_code=1
  fi

  echo ""
  echo -e "${CYAN}--- SSH ---${NC}"

  echo -n "  SSH to ${LMS_SSH_HOST}: "
  if ssh ${SSH_OPTS:-} -o ConnectTimeout=10 -o BatchMode=yes "${LMS_SSH_HOST}" "echo OK" 2>/dev/null | grep -q "OK"; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  echo ""
  echo -e "${CYAN}--- LMS VPS (via SSH) ---${NC}"

  echo -n "  Project dir (${LMS_PROJECT_DIR}): "
  if lms_ssh "test -d '${LMS_PROJECT_DIR}' && test -f '${LMS_PROJECT_DIR}/artisan'" 2>/dev/null; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  echo -n "  benchmark-lms.sh: "
  if lms_ssh "test -f '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh'" 2>/dev/null; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    exit_code=1
  fi

  echo ""
  echo -e "${CYAN}--- LMS Host Readiness ---${NC}"

  # Propagate page cache policy to LMS-side preflight
  local page_cache_env=""
  if [ "${SKIP_PAGE_CACHE:-false}" = "true" ]; then
    page_cache_env="SKIP_PAGE_CACHE=true"
  elif [ "${CLEAR_PAGE_CACHE:-false}" = "true" ]; then
    page_cache_env="CLEAR_PAGE_CACHE=true"
  fi
  echo "  Running LMS preflight (CLUSTER_MODE=${CLUSTER_MODE}, page_cache=${page_cache_env:-default})..."

  # Capture LMS preflight output to extract machine-readable PAGE_CACHE_RESULT
  local lms_output
  lms_output=$(lms_ssh "${page_cache_env} CLUSTER_MODE=${CLUSTER_MODE} REMOTE_ARTIFACT_DIR=${REMOTE_ARTIFACT_DIR} bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' preflight" 2>&1) || {
    echo -e "${RED}  LMS preflight failed${NC}" >&2
    exit_code=1
  }
  echo "${lms_output}"

  # Extract machine-readable page-cache result emitted by benchmark-lms.sh
  local page_cache_result
  page_cache_result=$(echo "${lms_output}" | grep "^PAGE_CACHE_RESULT=" | tail -1 | cut -d= -f2 || true)

  # Write preflight evidence file
  local preflight_file="${RESULTS_DIR}/preflight-$(date +%Y%m%d_%H%M%S)-remote-preflight.txt"
  mkdir -p "${RESULTS_DIR}"

  # Determine page-cache policy for evidence
  local page_cache_policy=""
  local page_cache_status=""
  if [ "${SKIP_PAGE_CACHE:-false}" = "true" ]; then
    page_cache_policy="skipped-explicit"
    page_cache_status="${page_cache_result:-skip}"
  elif [ "${CLEAR_PAGE_CACHE:-false}" = "true" ]; then
    page_cache_policy="clear-required"
    page_cache_status="${page_cache_result:-attempted}"
  else
    page_cache_policy="skipped-default"
    page_cache_status="${page_cache_result:-skip}"
  fi

  {
    echo "=== Remote Preflight Evidence ==="
    echo "Timestamp        : $(date -Iseconds)"
    echo "LMS SSH host     : ${LMS_SSH_HOST}"
    echo "k6 hostname      : $(hostname 2>/dev/null || echo 'unknown')"
    echo "LMS project dir  : ${LMS_PROJECT_DIR}"
    echo "k6 project dir   : ${K6_PROJECT_DIR}"
    echo "Base URL         : ${BASE_URL}"
    echo "Redis mode       : $([ "${CLUSTER_MODE}" = "true" ] && echo 'cluster' || echo 'single')"
    echo "Result dir       : ${RESULTS_DIR}"
    echo "Remote artifact  : ${REMOTE_ARTIFACT_DIR}"
    echo "Page cache policy : ${page_cache_policy}"
    echo "Page cache status : ${page_cache_status}"
    echo "Exit code        : ${exit_code}"
    echo "Status           : $([ $exit_code -eq 0 ] && echo 'PASS' || echo 'FAIL')"
  } > "${preflight_file}"
  echo -e "${BLUE}[preflight] Evidence: ${preflight_file}${NC}"

  # ── Host capacity snapshot ──
  local snapshot_file="${RESULTS_DIR}/preflight-$(date +%Y%m%d_%H%M%S)-host-capacity.txt"
  mkdir -p "${RESULTS_DIR}"
  {
    echo "============================================="
    echo " Remote Preflight Host Capacity Snapshot"
    echo "============================================="
    echo "Timestamp: $(date -Iseconds)"
    echo ""
    echo "--- k6 VPS ---"
    echo ""
    echo "--- CPU ---"
    nproc 2>/dev/null || echo "nproc not available"
    echo ""
    echo "--- Memory ---"
    free -h 2>/dev/null || echo "free not available"
    echo ""
    echo "--- Disk ---"
    df -hT 2>/dev/null || echo "df not available"
    echo ""
    echo "--- k6 Version ---"
    k6 version 2>/dev/null || echo "k6 not available"
    echo ""
    echo "--- k6 Location ---"
    if echo "${BASE_URL}" | grep -qE "localhost|127\.0\.0\.1"; then
      echo "k6 runs: LOCAL (same host as server) — resource metrics include k6 overhead."
    else
      echo "k6 runs: REMOTE — resource metrics represent server-side load only."
    fi
    echo ""
    echo "--- LMS VPS (via SSH) ---"
    echo ""
  } > "${snapshot_file}"

  # Append LMS VPS capacity info via SSH
  lms_ssh "nproc 2>/dev/null || echo 'nproc not available'" >> "${snapshot_file}" 2>/dev/null || echo "LMS CPU: unreachable" >> "${snapshot_file}"
  lms_ssh "free -h 2>/dev/null || echo 'free not available'" >> "${snapshot_file}" 2>/dev/null || echo "LMS Memory: unreachable" >> "${snapshot_file}"
  lms_ssh "df -hT 2>/dev/null || echo 'df not available'" >> "${snapshot_file}" 2>/dev/null || echo "LMS Disk: unreachable" >> "${snapshot_file}"
  lms_ssh "docker compose ps --services 2>/dev/null || echo 'docker compose not available'" >> "${snapshot_file}" 2>/dev/null || echo "LMS Docker: unreachable" >> "${snapshot_file}"
  echo -e "${BLUE}[preflight] Host capacity: ${snapshot_file}${NC}"
  echo ""

  if [ $exit_code -eq 0 ]; then
    echo -e "${GREEN}[preflight] ✓ All checks passed${NC}"
  else
    echo -e "${RED}[preflight] ✗ Some checks failed${NC}"
  fi
  echo ""

  return $exit_code
}

# ─────────────────────────────────────────────
# Fixture sync verification
# ─────────────────────────────────────────────
cmd_fixture_check() {
  echo ""
  echo "=============================================="
  echo "  Fixture Sync Verification"
  echo "=============================================="
  echo ""

  local lms_fixture_path="${LMS_PROJECT_DIR}/tests/Benchmark/k6/fixtures.js"
  local k6_fixture_path="${K6_PROJECT_DIR}/tests/Benchmark/k6/fixtures.js"

  local exit_code=0

  # Step 1: Ensure fixture exists on LMS
  echo -n "Fixture on LMS: "
  if lms_ssh "test -f '${lms_fixture_path}'" 2>/dev/null; then
    echo -e "${GREEN}OK${NC}"
  else
    echo -e "${RED}MISSING — run prepare first${NC}"
    exit_code=1
  fi

  if [ $exit_code -ne 0 ]; then
    return $exit_code
  fi

  # Step 2: Copy fixture from LMS to k6
  mkdir -p "$(dirname "${k6_fixture_path}")"
  echo "Syncing fixture from LMS..."
  lms_rsync_pull "${lms_fixture_path}" "${k6_fixture_path}"
  if [ $? -ne 0 ] || [ ! -f "${k6_fixture_path}" ]; then
    echo -e "${RED}Fixture sync failed${NC}"
    return 1
  fi
  echo -e "${GREEN}Fixture synced${NC}"

  # Step 3: Record checksums
  local lms_sha256 k6_sha256
  lms_sha256=$(lms_ssh "sha256sum '${lms_fixture_path}' | awk '{print \$1}'" 2>/dev/null || echo "unknown")
  k6_sha256=$(sha256sum "${k6_fixture_path}" | awk '{print $1}')

  echo "  LMS SHA256: ${lms_sha256}"
  echo "  k6  SHA256: ${k6_sha256}"
  if [ "${lms_sha256}" = "${k6_sha256}" ]; then
    echo -e "${GREEN}  Checksums match${NC}"
  else
    echo -e "${RED}  Checksums MISMATCH${NC}"
    exit_code=1
  fi

  # Step 4: Generate warm-up targets
  echo "Generating warm-up targets..."
  if ! node "${SCRIPT_DIR}/generate-warmup-targets.cjs"; then
    echo -e "${RED}Warm-up target generation failed${NC}"
    exit_code=1
  fi
  echo -e "${GREEN}Warm-up targets generated${NC}"

  # Step 5: Validate required pools
  local targets_file="${SCRIPT_DIR}/lib/warmup-targets.json"
  if [ ! -f "${targets_file}" ]; then
    echo -e "${RED}Warm-up targets file not found at ${targets_file}${NC}"
    return 1
  fi

  local required_pools=(
    "ENROLLED_PAIRS"
    "INSTRUCTOR_COURSE_PAIRS"
    "READABLE_MATERIAL_TARGETS"
    "READABLE_QUIZ_TARGETS"
    "READABLE_ASSIGNMENT_TARGETS"
    "COURSE_COMPLETION_CHECK_TARGETS"
  )

  for pool in "${required_pools[@]}"; do
    local count
    count=$(node -e "
      var f=require('fs');
      var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));
      console.log(d['${pool}']?d['${pool}'].length:0);
    " 2>/dev/null || echo "0")
    if [ "${count}" -gt 0 ]; then
      echo -e "  ${GREEN}✓ ${pool}: ${count}${NC}"
    else
      echo -e "  ${RED}✗ ${pool}: EMPTY${NC}"
      exit_code=1
    fi
  done

  echo ""
  if [ $exit_code -eq 0 ]; then
    echo -e "${GREEN}[fixture-check] ✓ Fixture sync valid${NC}"
  else
    echo -e "${RED}[fixture-check] ✗ Fixture validation failed${NC}"
  fi
  echo ""

  return $exit_code
}

# ─────────────────────────────────────────────
# Run benchmark for one VU level (remote flow)
# ─────────────────────────────────────────────
run_k6_for_level_remote() {
  local vu_count=$1
  local timestamp
  timestamp=$(date +%Y%m%d_%H%M%S)
  local local_prefix="${vu_count}vu-${timestamp}"
  local remote_prefix="${STRATEGY}-${SCENARIO}-iter${ITERATION}-${vu_count}vu-${timestamp}"
  local result_dir="${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}"
  local result_prefix="${result_dir}/${local_prefix}"
  local summary_output="${result_prefix}-summary.json"
  local redis_file="${result_prefix}-redis.txt"
  local hit_ratio_file="${result_prefix}-cache-hit-ratio.txt"
  local resources_csv="${result_prefix}-resources.csv"
  local container_stats_file="${result_prefix}-container-stats.txt"
  local redis_before_file="${result_prefix}-redis-before-raw.txt"
  local redis_after_file="${result_prefix}-redis-after-raw.txt"
  local fixture_sha256_file="${result_prefix}-fixture-sha256.txt"
  local remote_artifact_dir="${REMOTE_ARTIFACT_DIR:-/tmp/lms-benchmark-artifacts}"
  local remote_resource_csv="${remote_artifact_dir}/${remote_prefix}-resources.csv"
  local remote_container_stats="${remote_artifact_dir}/${remote_prefix}-container-stats.txt"

  mkdir -p "${result_dir}"

  # Marker file for Redis mode (single or cluster, matching local runner format)
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo "cluster" > "${result_dir}/.redis-mode"
  else
    echo "single" > "${result_dir}/.redis-mode"
  fi

  echo ""
  echo -e "${CYAN}────────────────────────────────────────────${NC}"
  echo -e "${CYAN}  [REMOTE] Concurrent Users : ${vu_count}${NC}"
  echo -e "${CYAN}  [REMOTE] Strategy         : ${STRATEGY}${NC}"
  echo -e "${CYAN}  [REMOTE] Scenario         : ${SCENARIO}${NC}"
  echo -e "${CYAN}────────────────────────────────────────────${NC}"

  # ── 2. SSH LMS: switch strategy ────────────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Switching strategy to ${STRATEGY}...${NC}"
  local cluster_flag=""
  if [ "${CLUSTER_MODE}" = "true" ]; then cluster_flag="--cluster"; fi

  lms_ssh "bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' switch-strategy '${STRATEGY}'" || {
    echo -e "${RED}[${vu_count}vu] Strategy switch failed${NC}"
    return 1
  }

  # ── 3. SSH LMS: prepare benchmark ──────────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Preparing benchmark (LMS side)...${NC}"
  # Propagate page cache policy to LMS-side prepare
  local page_cache_env=""
  if [ "${SKIP_PAGE_CACHE:-false}" = "true" ]; then
    page_cache_env="SKIP_PAGE_CACHE=true"
  elif [ "${CLEAR_PAGE_CACHE:-false}" = "true" ]; then
    page_cache_env="CLEAR_PAGE_CACHE=true"
  fi
  lms_ssh "${page_cache_env} bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' prepare --base-url '${BASE_URL}' ${cluster_flag}" || {
    echo -e "${RED}[${vu_count}vu] Prepare failed${NC}"
    return 1
  }

  # ── 4. Pull fixture ────────────────────────────────
  local lms_fixture="${LMS_PROJECT_DIR}/tests/Benchmark/k6/fixtures.js"
  local k6_fixture="${K6_PROJECT_DIR}/tests/Benchmark/k6/fixtures.js"
  mkdir -p "$(dirname "${k6_fixture}")"
  echo -e "${YELLOW}[${vu_count}vu] [remote] Pulling fixture from LMS...${NC}"
  lms_rsync_pull "${lms_fixture}" "${k6_fixture}" || {
    echo -e "${RED}[${vu_count}vu] Fixture pull failed${NC}"
    return 1
  }

  # Record fixture checksums (LMS + k6, fail if mismatch)
  local lms_sha256 k6_sha256
  lms_sha256=$(lms_ssh "sha256sum '${lms_fixture}' | awk '{print \$1}'" 2>/dev/null || echo "unknown")
  k6_sha256=$(sha256sum "${k6_fixture}" 2>/dev/null | awk '{print $1}' || echo "unknown")
  echo "LMS SHA256: ${lms_sha256}"
  echo "k6  SHA256: ${k6_sha256}"

  {
    echo "LMS SHA256: ${lms_sha256}"
    echo "k6  SHA256: ${k6_sha256}"
  } > "${fixture_sha256_file}"

  if [ "${lms_sha256}" != "${k6_sha256}" ]; then
    echo -e "${RED}[${vu_count}vu] Fixture checksum MISMATCH — aborting${NC}"
    return 1
  fi
  echo -e "${GREEN}[${vu_count}vu] Fixture checksums match${NC}"

  # ── 5. Generate warm-up targets locally ────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Generating warm-up targets...${NC}"
  K6_FIXTURE_FILE="${k6_fixture}" node "${SCRIPT_DIR}/generate-warmup-targets.cjs" || {
    echo -e "${RED}[${vu_count}vu] Warm-up target generation failed${NC}"
    return 1
  }

  # Validate required fixture pools (scenario-aware)
  local targets_file="${SCRIPT_DIR}/lib/warmup-targets.json"
  _validate_fixture_pools "${targets_file}" "${SCENARIO}" || {
    echo -e "${RED}[${vu_count}vu] Fixture pool validation failed${NC}"
    return 1
  }

  # ── 6. Warm-up from k6 VPS ─────────────────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Warming up...${NC}"

  # Call existing warmup function from run-benchmark.sh logic
  _remote_warmup "${vu_count}" "${result_prefix}-warmup-summary.txt" || {
    echo -e "${RED}[${vu_count}vu] Warm-up failed${NC}"
    return 1
  }

  # ── 7. SSH LMS: capture Redis before ────────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Capturing Redis before stats...${NC}"
  local redis_before
  redis_before=$(lms_ssh "CLUSTER_MODE=${CLUSTER_MODE} bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' redis-counters") || return 1
  lms_ssh "CLUSTER_MODE=${CLUSTER_MODE} bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' redis-stats before '${remote_artifact_dir}/${remote_prefix}-redis-before.txt'" || return 1

  local hits_before misses_before
  hits_before=$(echo "${redis_before}" | node -e "process.stdin.on('data',d=>{try{const o=JSON.parse(d);if(typeof o.hits!=='number'||o.hits<0)process.exit(1);console.log(o.hits)}catch{process.exit(1)}})") || return 1
  misses_before=$(echo "${redis_before}" | node -e "process.stdin.on('data',d=>{try{const o=JSON.parse(d);if(typeof o.misses!=='number'||o.misses<0)process.exit(1);console.log(o.misses)}catch{process.exit(1)}})") || return 1

  # ── 8. SSH LMS: start resource monitor ─────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Starting resource monitor...${NC}"
  local monitor_pid=

  # Use detached SSH (forks to background) to avoid any hang from background processes
  lms_ssh_detach "bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' start-resource-monitor '${remote_resource_csv}'" || {
    echo -e "${RED}[${vu_count}vu] ✗ Failed to launch detached resource monitor${NC}"
    return 1
  }

  # Wait briefly then retrieve PID from remote PID file
  local pid_file_remote="/tmp/.lms-resource-monitor-pid-$(basename "${remote_resource_csv}" .csv)"
  local pid_attempt=0
  for pid_attempt in 1 2 3 4 5; do
    sleep 1
    monitor_pid=$(lms_ssh "cat '${pid_file_remote}'" 2>/dev/null | head -1)
    if [ -n "${monitor_pid}" ] && [[ "${monitor_pid}" =~ ^[0-9]+$ ]]; then
      break
    fi
    monitor_pid=
  done

  if [ -z "${monitor_pid}" ]; then
    echo -e "${RED}[${vu_count}vu] ✗ Resource monitor PID not found after 5s${NC}"
    return 1
  fi

  if ! [[ "${monitor_pid}" =~ ^[0-9]+$ ]]; then
    echo -e "${RED}[${vu_count}vu] ✗ Resource monitor returned non-numeric PID: '${monitor_pid}'${NC}"
    return 1
  fi

  echo -e "  Resource monitor PID: ${monitor_pid}"

  # ── 9. SSH LMS: container stats before ─────────────
  lms_ssh "bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' container-stats '${remote_container_stats}'" || true

  # ── 10. Run k6 locally ─────────────────────────────
  local k6_script="${K6_PROJECT_DIR}/tests/Benchmark/k6/${SCENARIO}-scenario.js"
  echo -e "${YELLOW}[${vu_count}vu] [remote] Running k6 locally...${NC}"

  local k6_envs=(
    --env BASE_URL="${BASE_URL}"
    --env CONCURRENT_USERS="${vu_count}"
    --env CACHE_STRATEGY="${STRATEGY}"
    --env SUMMARY_EXPORT="${summary_output}"
  )
  if [ "${CLUSTER_MODE}" = "true" ]; then
    k6_envs+=(--env CLUSTER_MODE=true)
  fi

  local k6_exit=0
  k6 run "${k6_envs[@]}" "${k6_script}" || k6_exit=$?

  # ── 11. SSH LMS: stop resource monitor ──────────────
  if [ -n "${monitor_pid:-}" ]; then
    echo -e "${YELLOW}[${vu_count}vu] [remote] Stopping resource monitor...${NC}"
    local stop_pid_file="/tmp/.lms-resource-monitor-pid-$(basename "${remote_resource_csv}" .csv)"
    lms_ssh "bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' stop-resource-monitor '${monitor_pid}' '${stop_pid_file}'" || true
  fi

  # ── 12. SSH LMS: capture Redis after + stats ───────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Capturing Redis after stats...${NC}"
  local redis_after
  redis_after=$(lms_ssh "CLUSTER_MODE=${CLUSTER_MODE} bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' redis-counters") || return 1
  lms_ssh "CLUSTER_MODE=${CLUSTER_MODE} bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' redis-stats after '${remote_artifact_dir}/${remote_prefix}-redis-after.txt'" || return 1

  local hits_after misses_after
  hits_after=$(echo "${redis_after}" | node -e "process.stdin.on('data',d=>{try{const o=JSON.parse(d);if(typeof o.hits!=='number'||o.hits<0)process.exit(1);console.log(o.hits)}catch{process.exit(1)}})") || return 1
  misses_after=$(echo "${redis_after}" | node -e "process.stdin.on('data',d=>{try{const o=JSON.parse(d);if(typeof o.misses!=='number'||o.misses<0)process.exit(1);console.log(o.misses)}catch{process.exit(1)}})") || return 1

  # ── 13. SSH LMS: container stats after ─────────────
  lms_ssh "bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' container-stats '${remote_container_stats}.after'" || true

  # ── 13b. SSH LMS: cluster stats (cluster mode only) ─
  local remote_cluster_stats="${remote_artifact_dir}/${remote_prefix}-cluster-stats.txt"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo -e "${YELLOW}[${vu_count}vu] [remote] Capturing cluster stats...${NC}"
    lms_ssh "bash '${LMS_PROJECT_DIR}/scripts/benchmark-lms.sh' cluster-stats '${remote_cluster_stats}'" || true
  fi

  # ── 14. Pull LMS artifacts ──────────────────────────
  echo -e "${YELLOW}[${vu_count}vu] [remote] Pulling LMS artifacts...${NC}"
  # Required artifacts (fail on pull error)
  lms_rsync_pull "${remote_artifact_dir}/${remote_prefix}-redis-before.txt" "${redis_before_file}" || {
    echo -e "${RED}[${vu_count}vu] Failed to pull Redis before stats${NC}"
    return 1
  }
  lms_rsync_pull "${remote_artifact_dir}/${remote_prefix}-redis-after.txt" "${redis_after_file}" || {
    echo -e "${RED}[${vu_count}vu] Failed to pull Redis after stats${NC}"
    return 1
  }
  lms_rsync_pull "${remote_resource_csv}" "${resources_csv}" || {
    echo -e "${RED}[${vu_count}vu] Failed to pull resource CSV${NC}"
    return 1
  }

  # Merge Redis before + after into one file
  {
    cat "${redis_before_file}"
    echo ""
    echo "=== After ==="
    cat "${redis_after_file}"
  } > "${redis_file}"
  rm -f "${redis_before_file}" "${redis_after_file}"

  # Cluster stats (cluster mode only, best effort)
  if [ "${CLUSTER_MODE}" = "true" ]; then
    lms_rsync_pull "${remote_cluster_stats}" "${result_prefix}-cluster-stats.txt" 2>/dev/null || true
  fi

  # Container stats: combine before + after into one local file with clear sections
  local container_before_tmp="${result_prefix}-container-before.tmp"
  local container_after_tmp="${result_prefix}-container-after.tmp"
  lms_rsync_pull "${remote_container_stats}" "${container_before_tmp}" 2>/dev/null || true
  lms_rsync_pull "${remote_container_stats}.after" "${container_after_tmp}" 2>/dev/null || true
  {
    echo "=== Before k6 ==="
    if [ -s "${container_before_tmp}" ]; then
      cat "${container_before_tmp}"
    else
      echo "(before docker stats not available)"
    fi
    echo ""
    echo "=== After k6 ==="
    if [ -s "${container_after_tmp}" ]; then
      cat "${container_after_tmp}"
    else
      echo "(after docker stats not available)"
    fi
  } > "${container_stats_file}"
  rm -f "${container_before_tmp}" "${container_after_tmp}"

  # ── 15. Compute cache hit ratio locally ─────────────
  local hits_during=$(( hits_after - hits_before ))
  local misses_during=$(( misses_after - misses_before ))
  local total_ops=$(( hits_during + misses_during ))
  local hit_ratio="0"

  {
    echo "=== Cache Hit Ratio ==="
    echo "Strategy                : ${STRATEGY}"
    echo "Hits during benchmark   : ${hits_during}"
    echo "Misses during benchmark : ${misses_during}"
    echo "Total cache ops         : ${total_ops}"
    if [ "${total_ops}" -gt 0 ]; then
      hit_ratio=$(echo "scale=4; ${hits_during} / ${total_ops} * 100" | bc 2>/dev/null || echo "0")
      echo "Cache Hit Ratio         : ${hit_ratio}%"
    else
      echo "Cache Hit Ratio         : N/A (no cache ops)"
    fi
  } > "${hit_ratio_file}"

  # ── Remote evidence files (analyzer-compatible) ──────
  # Write host evidence files
  echo "${LMS_SSH_HOST}" > "${result_prefix}-lms-host.txt"
  hostname > "${result_prefix}-k6-host.txt" 2>/dev/null || echo "unknown" > "${result_prefix}-k6-host.txt"

  # ════════════════════════════════════════════════════════
  # RESOURCES.CSV MEANING:
  #   resources.csv represents LMS-side resource usage only
  #   (CPU, memory, disk I/O from the LMS VPS resource monitor).
  #   This is intentional: in two-VPS execution, k6 overhead
  #   must not pollute the LMS resource metrics.
  #
  #   If k6-side resource monitoring is needed later, use:
  #     {vu}vu-{timestamp}-k6-resources.csv
  #   Do NOT mix k6 data into resources.csv.
  # ════════════════════════════════════════════════════════

  # ── 16. Verify required local artifacts ─────────────
  _verify_run_artifacts "${result_prefix}" || {
    echo -e "${RED}[${vu_count}vu] Artifact verification failed, marking run as failed${NC}"
    return 1
  }

  # Summary
  if [ $k6_exit -eq 0 ]; then
    echo -e "${GREEN}[${vu_count}vu] ✓ Remote run complete${NC}"
    echo -e "${GREEN}  Summary      : ${summary_output}${NC}"
    echo -e "${GREEN}  Resources    : ${resources_csv}${NC}"
    echo -e "${GREEN}  Cache HR     : ${hit_ratio}%${NC}"
  else
    echo -e "${RED}[${vu_count}vu] ✗ k6 exit code ${k6_exit}${NC}"
  fi

  return $k6_exit
}

# ─────────────────────────────────────────────
# Verify required local artifacts exist and are non-empty
# after a completed VU run.
# ─────────────────────────────────────────────
_verify_run_artifacts() {
  local result_prefix=$1

  local exit_code=0

  echo -e "${YELLOW}Verifying run artifacts...${NC}"

  # 1. k6 summary JSON exists and is non-empty
  local summary_file="${result_prefix}-summary.json"
  if [ -s "${summary_file}" ]; then
    echo -e "  ${GREEN}✓ k6 summary: $(basename "${summary_file}")${NC}"
  else
    echo -e "  ${RED}✗ k6 summary JSON missing or empty: ${summary_file}${NC}"
    exit_code=1
  fi

  # 2. Redis file exists with both before and after
  local redis_file="${result_prefix}-redis.txt"
  if [ -f "${redis_file}" ] && grep -q "=== After" "${redis_file}" 2>/dev/null; then
    echo -e "  ${GREEN}✓ Redis stats (before + after)${NC}"
  else
    echo -e "  ${RED}✗ Redis stats missing or incomplete: ${redis_file}${NC}"
    exit_code=1
  fi

  # 3. Cache hit ratio file exists
  local hr_file="${result_prefix}-cache-hit-ratio.txt"
  if [ -s "${hr_file}" ]; then
    echo -e "  ${GREEN}✓ Cache hit ratio${NC}"
  else
    echo -e "  ${RED}✗ Cache hit ratio file missing or empty: ${hr_file}${NC}"
    exit_code=1
  fi

  # 4. Resources CSV exists with more than one data row
  local csv_file="${result_prefix}-resources.csv"
  local csv_valid=true
  if [ -s "${csv_file}" ]; then
    local row_count
    row_count=$(wc -l < "${csv_file}" 2>/dev/null || echo 0)
    if [ "${row_count}" -gt 1 ]; then
      echo -e "  ${GREEN}✓ Resources CSV (${row_count} rows)${NC}"
      # Validate numeric fields: cpu_pct, mem_used_mb, mem_total_mb
      local numeric_errors
      numeric_errors=$(awk -F',' '
        NR>1 {
          if($1=="") next
          if($2!~/^[0-9]+\.?[0-9]*$/ && $2!~/^[0-9]*\.[0-9]+$/) { e++; print "cpu_pct="$2 }
          if($3!~/^[0-9]+\.?[0-9]*$/ && $3!~/^[0-9]*\.[0-9]+$/) { e++; print "mem_used_mb="$3 }
          if($4!~/^[0-9]+\.?[0-9]*$/ && $4!~/^[0-9]*\.[0-9]+$/) { e++; print "mem_total_mb="$4 }
        }
        END { if(e) print e+0 }' "${csv_file}" 2>/dev/null || echo "")
      local numeric_err_count
      numeric_err_count=$(echo "${numeric_errors}" | tail -1)
      if [ -n "${numeric_err_count}" ] && [ "${numeric_err_count}" -gt 0 ] 2>/dev/null; then
        echo -e "  ${RED}✗ Resources CSV has ${numeric_err_count} non-numeric values${NC}"
        echo "${numeric_errors}" | head -5 | while IFS= read -r line; do
          [ -n "$line" ] && echo "    ${RED}${line}${NC}"
        done
        exit_code=1
        csv_valid=false
      fi
      # Warning: check if all disk read/write samples are identical
      local disk_sig
      disk_sig=$(awk -F',' 'NR>1 && $1!="" {print $6,$7}' "${csv_file}" 2>/dev/null | sort -u | wc -l)
      if [ "${disk_sig}" -le 1 ] 2>/dev/null && [ "${row_count}" -gt 3 ]; then
        echo -e "  ${YELLOW}⚠ All disk I/O samples are identical — may indicate stale iostat interval${NC}"
      fi
    else
      echo -e "  ${RED}✗ Resources CSV has only header, no data: ${csv_file}${NC}"
      exit_code=1
      csv_valid=false
    fi
  else
    echo -e "  ${RED}✗ Resources CSV missing or empty: ${csv_file}${NC}"
    exit_code=1
    csv_valid=false
  fi

  # 4b. Container stats sections check (remote runs)
  local cs_file="${result_prefix}-container-stats.txt"
  if [ -f "${cs_file}" ] && [ -s "${cs_file}" ]; then
    if grep -q "=== Before k6 ===" "${cs_file}" 2>/dev/null && grep -q "=== After k6 ===" "${cs_file}" 2>/dev/null; then
      echo -e "  ${GREEN}✓ Container stats (before + after sections)${NC}"
    else
      echo -e "  ${YELLOW}⚠ Container stats missing before or after sections${NC}"
    fi
  fi

  # 4c. Heuristic warning: high http_reqs but extremely low CPU
  local summary_file="${result_prefix}-summary.json"
  if [ -s "${summary_file}" ] && [ "${csv_valid}" = true ]; then
    local http_reqs
    http_reqs=$(node -e "
      try {
        var d=require('fs').readFileSync('${summary_file}','utf8');
        var m=JSON.parse(d);
        var r=m.metrics?.http_reqs?.counts?.count || m.metrics?.http_reqs?.value || 0;
        console.log(r);
      } catch(e) { console.log('0'); }" 2>/dev/null || echo "0")
    local max_cpu
    max_cpu=$(awk -F',' 'NR>1 && $1!="" && $2~/^[0-9]/ {if($2+0>m)m=$2} END{printf "%.1f", m+0}' "${csv_file}" 2>/dev/null || echo "0")
    if [ "${http_reqs}" -gt 10000 ] 2>/dev/null && [ "${max_cpu%%.*}" -lt 15 ] 2>/dev/null; then
      echo -e "  ${YELLOW}⚠ High http_reqs (${http_reqs}) but max CPU (${max_cpu}%) is very low — resource data may be suspect${NC}"
    fi
  fi

  # 5. Fixture SHA256 file exists
  local sha_file="${result_prefix}-fixture-sha256.txt"
  if [ -s "${sha_file}" ]; then
    echo -e "  ${GREEN}✓ Fixture checksum${NC}"
  else
    echo -e "  ${RED}✗ Fixture checksum file missing or empty: ${sha_file}${NC}"
    exit_code=1
  fi

  # 6. Host evidence files exist
  local lms_host_file="${result_prefix}-lms-host.txt"
  local k6_host_file="${result_prefix}-k6-host.txt"
  if [ -f "${lms_host_file}" ] && [ -s "${lms_host_file}" ] && [ -f "${k6_host_file}" ] && [ -s "${k6_host_file}" ]; then
    echo -e "  ${GREEN}✓ Host evidence files${NC}"
  else
    echo -e "  ${RED}✗ Host evidence files missing or empty${NC}"
    exit_code=1
  fi

  if [ ${exit_code} -eq 0 ]; then
    echo -e "  ${GREEN}✓ All artifacts verified${NC}"
  else
    echo -e "  ${RED}✗ Some artifacts are missing or invalid${NC}"
  fi

  return $exit_code
}

# ─────────────────────────────────────────────
# Validate fixture pools required for benchmark
# Validates read pools for all scenarios; adds write pools for write-heavy.
# ─────────────────────────────────────────────
_validate_fixture_pools() {
  local targets_file="$1"
  local scenario="$2"

  local required_pools=(
    "ENROLLED_PAIRS"
    "INSTRUCTOR_COURSE_PAIRS"
    "READABLE_MATERIAL_TARGETS"
    "READABLE_QUIZ_TARGETS"
    "READABLE_ASSIGNMENT_TARGETS"
    "COURSE_COMPLETION_CHECK_TARGETS"
  )

  local write_pools=(
    "WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS"
    "WRITABLE_QUIZ_ATTEMPT_TARGETS"
    "GRADING_TARGETS"
    "GRADE_UPDATE_TARGETS"
  )

  if [ ! -f "${targets_file}" ]; then
    echo -e "${RED}  Warm-up targets file not found: ${targets_file}${NC}" >&2
    return 1
  fi

  local all_pools=("${required_pools[@]}")
  if [ "${scenario}" = "write-heavy" ]; then
    all_pools+=("${write_pools[@]}")
  fi

  local exit_code=0
  for pool in "${all_pools[@]}"; do
    local count
    count=$(node -e "
      var f=require('fs');
      var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));
      console.log(d['${pool}']?d['${pool}'].length:0);
    " 2>/dev/null || echo "0")
    if [ "${count}" -gt 0 ]; then
      echo -e "  ${GREEN}✓ ${pool}: ${count}${NC}"
    else
      echo -e "  ${RED}✗ ${pool}: EMPTY${NC}"
      exit_code=1
    fi
  done

  return $exit_code
}

# ─────────────────────────────────────────────
# Remote warm-up (adapted from run-benchmark.sh logic)
# ─────────────────────────────────────────────
_remote_warmup() {
  local vu_count=$1
  local summary_file=$2
  local warmup_duration=60
  local end_time=$(( $(date +%s) + warmup_duration ))
  local req_count=0
  local fail_count=0
  local targets_file="${SCRIPT_DIR}/lib/warmup-targets.json"

  if [ ! -f "${targets_file}" ]; then
    echo -e "${RED}[warm-up] Target file not found: ${targets_file}${NC}" >&2
    return 1
  fi

  local enrolled_count
  enrolled_count=$(node -e "var f=require('fs');var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));console.log(d.ENROLLED_PAIRS.length)" 2>/dev/null || echo "0")
  if [ "${enrolled_count}" -eq 0 ]; then
    echo -e "${RED}[warm-up] ENROLLED_PAIRS is empty${NC}" >&2
    return 1
  fi

  # Wait for app readiness
  local readiness_student_id readiness_course_id
  readiness_student_id=$(node -e "var f=require('fs');var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));console.log(d.ENROLLED_PAIRS[0].studentId)" 2>/dev/null)
  readiness_course_id=$(node -e "var f=require('fs');var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));console.log(d.ENROLLED_PAIRS[0].courseId)" 2>/dev/null)

  local app_ready=0
  for i in $(seq 1 30); do
    local status
    status=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 \
      -H "X-Benchmark-Actor-Id: ${readiness_student_id}" \
      "${BASE_URL}/api/courses/${readiness_course_id}/structure" 2>/dev/null || echo "000")
    if [ "${status}" = "200" ]; then
      echo -e "${GREEN}[warm-up] App ready after ${i}s${NC}"
      app_ready=1
      break
    fi
    sleep 1
  done

  if [ "${app_ready}" -eq 0 ]; then
    echo -e "${RED}[warm-up] App not ready after 30s${NC}" >&2
    return 1
  fi

  local idx=0
  while [ $(date +%s) -lt ${end_time} ]; do
    local pair
    pair=$(node -e "
      var f=require('fs');
      var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));
      var e=d.ENROLLED_PAIRS[${idx}%${enrolled_count}];
      if(e)console.log(JSON.stringify(e));
    " 2>/dev/null)
    local student_id course_id
    student_id=$(node -e "var p=${pair:-null};if(p)console.log(p.studentId);" 2>/dev/null)
    course_id=$(node -e "var p=${pair:-null};if(p)console.log(p.courseId);" 2>/dev/null)

    # Course structure
    if [ -n "${course_id}" ] && [ "${course_id}" != "null" ]; then
      curl -sf --max-time 5 \
        -H "X-Benchmark-Actor-Id: ${student_id}" \
        "${BASE_URL}/api/courses/${course_id}/structure" -o /dev/null 2>/dev/null &
      req_count=$((req_count + 1))
    fi

    # Material, quiz, assignment for this pair
    local activity_ids
    activity_ids=$(node -e "
      var f=require('fs');
      var d=JSON.parse(f.readFileSync('${targets_file}','utf8'));
      var mats=d.READABLE_MATERIAL_TARGETS.filter(function(x){return x.courseId==${course_id}&&x.studentId==${student_id}});
      var quizzes=d.READABLE_QUIZ_TARGETS.filter(function(x){return x.courseId==${course_id}&&x.studentId==${student_id}});
      var assigns=d.READABLE_ASSIGNMENT_TARGETS.filter(function(x){return x.courseId==${course_id}&&x.studentId==${student_id}});
      console.log(JSON.stringify({mat:mats[0]?mats[0].activityId:'',quiz:quizzes[0]?quizzes[0].activityId:'',assign:assigns[0]?assigns[0].activityId:''}));
    " 2>/dev/null)
    local mat_id quiz_id assign_id
    mat_id=$(echo "${activity_ids}" | node -e "process.stdin.on('data',d=>{try{console.log(JSON.parse(d).mat)}catch{console.log('')}})")
    quiz_id=$(echo "${activity_ids}" | node -e "process.stdin.on('data',d=>{try{console.log(JSON.parse(d).quiz)}catch{console.log('')}})")
    assign_id=$(echo "${activity_ids}" | node -e "process.stdin.on('data',d=>{try{console.log(JSON.parse(d).assign)}catch{console.log('')}})")

    [ -n "${mat_id}" ] && curl -sf --max-time 5 \
      -H "X-Benchmark-Actor-Id: ${student_id}" \
      "${BASE_URL}/api/materials/${mat_id}" -o /dev/null 2>/dev/null &
    [ -n "${quiz_id}" ] && curl -sf --max-time 5 \
      -H "X-Benchmark-Actor-Id: ${student_id}" \
      "${BASE_URL}/api/quizzes/${quiz_id}" -o /dev/null 2>/dev/null &
    [ -n "${assign_id}" ] && curl -sf --max-time 5 \
      -H "X-Benchmark-Actor-Id: ${student_id}" \
      "${BASE_URL}/api/assignments/${assign_id}" -o /dev/null 2>/dev/null &

    idx=$((idx + 1))
    sleep 0.5
    if [ $((idx % 8)) -eq 0 ]; then
      wait 2>/dev/null || true
    fi
  done
  wait 2>/dev/null || true

  echo -e "${GREEN}[warm-up] Complete. ~${req_count} requests sent${NC}"

  if [ -n "${summary_file}" ]; then
    echo "Warm-up complete: ~${req_count} requests" > "${summary_file}"
    echo "Timestamp: $(date -Iseconds)" >> "${summary_file}"
  fi
}

# ─────────────────────────────────────────────
# Main dispatch
# ─────────────────────────────────────────────
main() {
  validate_config

  case "${MODE}" in
    preflight)
      cmd_preflight
      exit $?
      ;;
    fixture-check)
      cmd_fixture_check
      exit $?
      ;;
  esac

  # ── Run mode ──
  echo ""
  echo "=============================================="
  echo "  Remote LMS Cache Strategy Benchmark"
  echo "=============================================="
  echo -e "  Mode       : ${BLUE}REMOTE${NC}"
  echo -e "  Strategy   : ${BLUE}${STRATEGY}${NC}"
  echo -e "  Scenario   : ${BLUE}${SCENARIO}${NC}"
  echo -e "  Iteration  : ${BLUE}${ITERATION}${NC}"
  echo -e "  Base URL   : ${BLUE}${BASE_URL}${NC}"
  echo -e "  LMS Host   : ${BLUE}${LMS_SSH_HOST}${NC}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo -e "  Redis      : ${BLUE}Cluster${NC}"
  else
    echo -e "  Redis      : ${BLUE}Single${NC}"
  fi
  echo "=============================================="
  echo ""

  # VU levels
  if [ -n "${VU_LEVELS+x}" ]; then
    IFS=' ' read -ra CONCURRENT_USERS_LEVELS <<< "${VU_LEVELS}"
  else
    CONCURRENT_USERS_LEVELS=(100 250 500 750 1000 1500 2000)
  fi

  # Validate VU levels
  for v in "${CONCURRENT_USERS_LEVELS[@]}"; do
    if ! [[ "$v" =~ ^[1-9][0-9]*$ ]]; then
      echo -e "${RED}Error: Invalid VU level '${v}'${NC}" >&2
      exit 1
    fi
  done

  # Preflight check
  echo -e "${YELLOW}[preflight] Running host preflight...${NC}"
  cmd_preflight || {
    echo -e "${RED}Preflight failed — aborting${NC}"
    exit 1
  }



  mkdir -p "${RESULTS_DIR}"
  if [ "${CLUSTER_MODE}" = "true" ]; then
    echo "cluster" > "${RESULTS_DIR}/.redis-mode"
  else
    echo "single" > "${RESULTS_DIR}/.redis-mode"
  fi
  printf '%s\n' "${CONCURRENT_USERS_LEVELS[@]}" > "${RESULTS_DIR}/.vu-levels"

  # Run each VU level
  local total=${#CONCURRENT_USERS_LEVELS[@]}
  local completed=0
  local failed=0
  local start_time
  start_time=$(date +%s)

  for vu_count in "${CONCURRENT_USERS_LEVELS[@]}"; do
    completed=$((completed + 1))
    echo ""
    echo -e "${BLUE}[Progress: ${completed}/${total}] VU=${vu_count}${NC}"

    run_k6_for_level_remote "$vu_count" || {
      failed=$((failed + 1))
      echo -e "${RED}VU level ${vu_count} failed${NC}"
    }

    if [ $completed -lt $total ]; then
      echo -e "${YELLOW}Pausing 30s before next VU level...${NC}"
      sleep 30
    fi
  done

  local end_time
  end_time=$(date +%s)
  local duration=$((end_time - start_time))
  local hours=$((duration / 3600))
  local minutes=$(((duration % 3600) / 60))

  echo ""
  echo "=============================================="
  echo "  Remote Benchmark Complete!"
  echo "=============================================="
  echo -e "  Succeeded : ${GREEN}$((total - failed)) / ${total}${NC}"
  echo -e "  Failed    : ${RED}${failed} / ${total}${NC}"
  echo -e "  Duration  : ${hours}h ${minutes}m"
  echo -e "  Results   : ${RESULTS_DIR}/${STRATEGY}/${SCENARIO}/iter${ITERATION}/"
  echo "=============================================="
}

main "$@"
