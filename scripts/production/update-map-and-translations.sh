#!/usr/bin/env bash
# Rebuild the Flensburg map, extract node tags, and sync OSM/user translations.
# Usage: bash scripts/production/update-map-and-translations.sh [--local]
# Requires the same tools as the individual scripts and a running backend
# Docker PHP service (or a configured host PHP runtime with --local).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ "$#" -gt 1 ]; then
  echo "Usage: $0 [--local]" >&2
  exit 1
fi

case "${1:-}" in
  '') ;;
  --local) ;;
  --help|-h)
    echo "Usage: $0 [--local]"
    echo "Update map GeoJSON and sync OSM and stored user tag translations."
    echo "--local uses host PHP instead of the running Docker PHP service."
    exit 0
    ;;
  *)
    echo "Unknown option: $1" >&2
    echo "Usage: $0 [--local]" >&2
    exit 1
    ;;
esac

echo "Step 1/2: Prepare the Flensburg map data"
bash "${SCRIPT_DIR}/prepare-flensburg.sh"

echo "Step 2/2: Collect OSM node tags and sync OSM and backend translations"
node "${SCRIPT_DIR}/generate-tag-key-catalog.mjs" "$@"

echo "Map data and translation catalogs updated. New targets are ready for translation."
