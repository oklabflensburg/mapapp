#!/bin/sh
# Downloads the OSM extract GraphHopper imports. Runs in the routing-data
# compose profile (curlimages/curl) with backend/routing/data mounted at
# /data; the routing container reads it from /data/osm.
set -eu

GEOFABRIK_URL="https://download.geofabrik.de/europe/germany/schleswig-holstein-latest.osm.pbf"
OUT_FILE="/data/schleswig-holstein-latest.osm.pbf"

if [ -s "$OUT_FILE" ]; then
    echo "Using cached extract: $OUT_FILE"
    exit 0
fi

echo "Downloading Geofabrik Schleswig-Holstein extract..."
curl --fail --location --retry 5 --retry-all-errors \
    --connect-timeout 20 --max-time 900 \
    --user-agent "AccessApp routing data setup (local development)" \
    --output "$OUT_FILE.part" \
    "$GEOFABRIK_URL"

mv "$OUT_FILE.part" "$OUT_FILE"
echo "Done: $OUT_FILE"
