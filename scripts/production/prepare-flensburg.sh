#!/usr/bin/env bash
#
# Build public/data/flensburg.geojson from the Geofabrik Schleswig-Holstein
# extract: download, extract a bounding box around Flensburg, keep tagged
# features only, and export as GeoJSON for the frontend map layer.
#
# Requires: curl, osmium-tool (brew install osmium-tool)
#
# Re-running is safe: every step is idempotent and downloads are cached in
# scripts/data/ (remove the cached files to force a fresh download).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DATA_DIR="${ROOT_DIR}/scripts/data"
OUT_DIR="${ROOT_DIR}/public/data"

GEOFABRIK_URL="https://download.geofabrik.de/europe/germany/schleswig-holstein-latest.osm.pbf"
USER_AGENT="AccessApp data prep (local development setup)"

PBF="${DATA_DIR}/schleswig-holstein-latest.osm.pbf"
# west,south,east,north; includes Flensburg and its immediate surroundings.
FLENSBURG_BBOX="9.35,54.75,9.51,54.84"

# Keep only features carrying at least one of these tags. landuse is left
# out on purpose: its areas (e.g. residential) cover whole neighbourhoods
# and would swallow clicks meant for the features inside them.
TAGS="building/leisure/amenity/shop/tourism/natural/highway/name"

CITY_PBF="${DATA_DIR}/flensburg.osm.pbf"
FILTERED_PBF="${DATA_DIR}/flensburg-tagged.osm.pbf"
GEOJSON="${OUT_DIR}/flensburg.geojson"

mkdir -p "${DATA_DIR}" "${OUT_DIR}"

if [ ! -f "${PBF}" ]; then
  echo "Downloading Geofabrik Schleswig-Holstein extract..."
  curl --fail --location --user-agent "${USER_AGENT}" --output "${PBF}" "${GEOFABRIK_URL}"
else
  echo "Using cached extract: ${PBF}"
fi

echo "Extracting Flensburg bounding box (${FLENSBURG_BBOX})..."
osmium extract --bbox "${FLENSBURG_BBOX}" --overwrite -o "${CITY_PBF}" "${PBF}"

echo "Filtering to tagged features (${TAGS//\//, })..."
osmium tags-filter --overwrite -o "${FILTERED_PBF}" "${CITY_PBF}" ${TAGS//\// }

echo "Exporting GeoJSON to ${GEOJSON}..."
# --add-unique-id type_id writes the OSM id (e.g. "node/123", "way/456") into
# each feature's "id" member, so the frontend can reference the original
# object when creating user nodes.
osmium export \
  --geometry-types point,linestring,polygon \
  --add-unique-id type_id \
  --overwrite \
  -o "${GEOJSON}" \
  "${FILTERED_PBF}"

echo "Done: $(du -h "${GEOJSON}" | cut -f1) ${GEOJSON}"
