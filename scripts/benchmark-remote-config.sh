#!/bin/bash
# ============================================================
# Remote Benchmark Configuration
#
# Source this file before running run-remote-benchmark.sh.
# You can also set these environment variables directly.
# ============================================================

# ─────────────────────────────────────────────
# Required
# ─────────────────────────────────────────────

# Benchmark mode — must be "remote" for remote execution
export BENCHMARK_MODE="remote"

# LMS application base URL (reachable from k6 VPS)
export BASE_URL="https://lms.example.com"

# SSH target for LMS VPS (user@host)
export LMS_SSH_HOST="user@lms-vps"

# LMS project directory on LMS VPS
export LMS_PROJECT_DIR="/home/user/Projects/lms"

# k6 project directory on k6 VPS (may be same as LMS_PROJECT_DIR if cloned)
export K6_PROJECT_DIR="/home/user/Projects/lms"

# Local result directory on k6 VPS
export RESULTS_DIR="${K6_PROJECT_DIR}/benchmark-results"

# ─────────────────────────────────────────────
# Optional
# ─────────────────────────────────────────────

# SSH options (e.g. -p 2222 -i ~/.ssh/benchmark-key)
export SSH_OPTS=""

# rsync options (e.g. --rsh='ssh -p 2222')
export RSYNC_OPTS=""

# Remote artifact directory on LMS VPS (default: /tmp/lms-benchmark-artifacts)
# export REMOTE_ARTIFACT_DIR="/tmp/lms-benchmark-artifacts"

# VU levels (space-separated)
# export VU_LEVELS="100 250 500 750 1000 1500 2000"

# Number of benchmark iterations per combination
# export BENCHMARK_ITERATIONS=3

# Redis Cluster mode
# export CLUSTER_MODE=true
