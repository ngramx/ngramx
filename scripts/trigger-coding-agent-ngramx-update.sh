#!/usr/bin/env bash
#
# Ask the Cortex Coder API to refresh its ngramx.phar after we publish a release.
# Runs from semantic-release's `success` hook (see .releaserc.yml), after
# @semantic-release/github has uploaded ngramx.phar — `ngramx update` reads
# /releases/latest and needs that asset to exist.
#
# This is intentionally NOT the Forge deploy hook: a full deploy stops containers
# and clears every per-ticket worktree. We only replace /usr/local/bin/ngramx.
#
# Never fails the release: the release itself is already published by this point.

set -uo pipefail

url="${CODING_AGENT_UPDATE_URL:-}"
api_key="${CODING_AGENT_API_KEY:-}"

if [ -z "$url" ] || [ -z "$api_key" ]; then
  echo "⚠️  CODING_AGENT_UPDATE_URL and/or CODING_AGENT_API_KEY not set — skipping ngramx refresh on Cortex Coder."
  echo "    Add both to this repository's GitHub Actions secrets to enable it."
  exit 0
fi

echo "🚀 Requesting ngramx update on Cortex Coder..."

if response=$(curl -sf -X POST --max-time 120 \
  -H "x-api-key: $api_key" \
  -H "Content-Type: application/json" \
  -d '{}' \
  "$url" 2>&1); then
  echo "✅ Cortex Coder ngramx update completed"
  echo "Response: $response"
else
  exit_code=$?
  echo "⚠️  Cortex Coder ngramx update failed (curl exit code: $exit_code)"
  echo "Response: $response"
  echo "    The release published fine. Retry manually with:"
  echo "      curl -X POST -H \"x-api-key: …\" \"$url\""
  echo "    or: docker exec --user root coding-agent ngramx update"
fi

exit 0
