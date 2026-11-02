#!/usr/bin/env bash
# Backwards-compatible node-only extraction. Translation sync uses all OSM objects.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
exec bash "${SCRIPT_DIR}/extract-osm-tags.sh" "${1:-${ROOT_DIR}/scripts/data/flensburg.osm.pbf}" "${ROOT_DIR}/scripts/data" --nodes
