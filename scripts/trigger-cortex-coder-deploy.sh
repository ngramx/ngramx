#!/usr/bin/env bash
#
# Trigger the Cortex Coder deploy hook on Forge, which runs `ngramx update`
# in the coding-agent container so it picks up the release we just published.
#
# Run from semantic-release's `success` hook (see .releaserc.yml), i.e. after
# @semantic-release/github has created the release and uploaded ngramx.phar —
# `ngramx update` reads /releases/latest and needs that asset to exist.
#
# Never fails the release: the release itself is already published by this
# point, so a deploy problem is reported and left for a manual retry.

set -uo pipefail

if [ -z "${FORGE_DEPLOY_TRIGGER_URL:-}" ]; then
  echo "⚠️  FORGE_DEPLOY_TRIGGER_URL not set — skipping Cortex Coder deploy."
  echo "    Add it to this repository's GitHub Actions secrets to enable it."
  exit 0
fi

echo "🚀 Triggering Cortex Coder deployment..."

if response=$(curl -sf -X POST --max-time 30 "$FORGE_DEPLOY_TRIGGER_URL" 2>&1); then
  echo "✅ Cortex Coder deploy triggered"
  echo "Response: $response"
else
  exit_code=$?
  echo "⚠️  Cortex Coder deploy trigger failed (curl exit code: $exit_code)"
  echo "Response: $response"
  echo "    The release published fine. Deploy manually with:"
  echo "      docker exec --user root coding-agent ngramx update"
fi

exit 0
