#!/bin/sh
# GraphHopper container entrypoint. Imports the OSM extract on first start
# (or when GRAPHHOPPER_REBUILD_GRAPH=true) and then serves the routing API.
set -eu

GRAPH_DIR=/data/graphhopper
OSM_FILE=/data/osm/schleswig-holstein-latest.osm.pbf

if [ ! -f "$OSM_FILE" ]; then
    echo "No OSM extract at $OSM_FILE." >&2
    echo "Download it first: docker compose --profile routing-data up" >&2
    exit 1
fi

if [ "${GRAPHHOPPER_REBUILD_GRAPH:-false}" = "true" ] && [ -d "$GRAPH_DIR" ]; then
    echo "GRAPHHOPPER_REBUILD_GRAPH=true: removing $GRAPH_DIR"
    rm -rf "$GRAPH_DIR"
fi

exec java -Xmx"${GRAPHHOPPER_XMX:-2g}" -jar /app/graphhopper.jar server /app/graphhopper.yml
