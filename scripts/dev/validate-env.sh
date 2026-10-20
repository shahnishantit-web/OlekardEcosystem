#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_EXAMPLE="$ROOT_DIR/wallet-ecosystem/services/api/.env.example"

if [[ ! -f "$ENV_EXAMPLE" ]]; then
  echo "ERROR: .env.example not found at $ENV_EXAMPLE" >&2
  exit 1
fi

missing=()

while IFS= read -r line; do
  # Skip blank lines and comment lines
  [[ -z "$line" || "$line" =~ ^# ]] && continue

  # Extract key (before the first =)
  key="${line%%=*}"
  [[ -z "$key" ]] && continue

  if [[ -z "${!key:-}" ]]; then
    missing+=("$key")
  fi
done < "$ENV_EXAMPLE"

if [[ ${#missing[@]} -gt 0 ]]; then
  echo "ERROR: The following required environment variables are missing:" >&2
  for var in "${missing[@]}"; do
    echo "  - $var" >&2
  done
  echo "" >&2
  echo "Copy wallet-ecosystem/services/api/.env.example to .env and fill in the values." >&2
  exit 1
fi

echo "✓ All required environment variables are set."
