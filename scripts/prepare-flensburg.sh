#!/usr/bin/env bash
#
# Build public/data/flensburg.geojson from the Geofabrik Schleswig-Holstein
# extract: download, clip to the Flensburg city boundary, keep tagged
# features only, and export as GeoJSON for the frontend map layer.
#
# Requires: curl, osmium-tool (brew install osmium-tool), python3
#
# Re-running is safe: every step is idempotent and downloads are cached in
# scripts/data/ (remove the cached files to force a fresh download).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="${ROOT_DIR}/scripts/data"
OUT_DIR="${ROOT_DIR}/public/data"

GEOFABRIK_URL="https://download.geofabrik.de/europe/germany/schleswig-holstein-latest.osm.pbf"
NOMINATIM_URL="https://nominatim.openstreetmap.org/search?city=Flensburg&state=Schleswig-Holstein&country=Germany&format=jsonv2&polygon_geojson=1"
USER_AGENT="AccessApp data prep (local development setup)"

PBF="${DATA_DIR}/schleswig-holstein-latest.osm.pbf"
NOMINATIM_JSON="${DATA_DIR}/nominatim-flensburg.json"
BOUNDARY_GEOJSON="${DATA_DIR}/flensburg-boundary.geojson"

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

if [ ! -f "${BOUNDARY_GEOJSON}" ]; then
  if [ ! -f "${NOMINATIM_JSON}" ]; then
    echo "Fetching Flensburg city boundary from Nominatim..."
    curl --fail --user-agent "${USER_AGENT}" --output "${NOMINATIM_JSON}" "${NOMINATIM_URL}"
  fi
  echo "Extracting boundary polygon..."
  python3 - "${NOMINATIM_JSON}" "${BOUNDARY_GEOJSON}" <<'PY'
import json, sys

with open(sys.argv[1]) as f:
    results = json.load(f)

result = results[0]
if result.get("osm_type") != "relation" or result.get("type") != "administrative":
    raise SystemExit("Nominatim result is not an administrative boundary relation")

with open(sys.argv[2], "w") as f:
    json.dump({"type": "FeatureCollection", "features": [
        {"type": "Feature", "properties": {}, "geometry": result["geojson"]}
    ]}, f)
PY
else
  echo "Using cached boundary: ${BOUNDARY_GEOJSON}"
fi

echo "Clipping to Flensburg city boundary..."
osmium extract --polygon "${BOUNDARY_GEOJSON}" --overwrite -o "${CITY_PBF}" "${PBF}"

echo "Filtering to tagged features (${TAGS//\//, })..."
osmium tags-filter --overwrite -o "${FILTERED_PBF}" "${CITY_PBF}" ${TAGS//\// }

echo "Exporting GeoJSON to ${GEOJSON}..."
osmium export \
  --geometry-types point,linestring,polygon \
  --overwrite \
  -o "${GEOJSON}" \
  "${FILTERED_PBF}"

echo "Done: $(du -h "${GEOJSON}" | cut -f1) ${GEOJSON}"
