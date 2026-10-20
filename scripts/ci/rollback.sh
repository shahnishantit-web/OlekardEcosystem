#!/usr/bin/env bash
set -euo pipefail

PREVIOUS_TAG="${1:?Usage: rollback.sh <previous-image-tag>}"

echo "==> Rolling back to: $PREVIOUS_TAG"

# Re-deploy the previous image (provider-agnostic: update your deploy mechanism here)
echo "  --> Triggering re-deploy of image tag: $PREVIOUS_TAG"
# Example: docker pull myregistry/api:$PREVIOUS_TAG && docker service update --image myregistry/api:$PREVIOUS_TAG api

echo "==> Waiting for health check..."
sleep 10

API_URL="${API_BASE_URL:-http://localhost:8000}"

if curl -sf "${API_URL}/health" > /dev/null; then
    echo "==> Rollback SUCCEEDED: /health returned 200"
    exit 0
else
    echo "==> Rollback FAILED: /health did not return 200"
    exit 1
fi
