# Flensburg Basemap (anonymous map view)

**Status:** Step 1 of the rebuilt node/map spec series. As-built, reverse-engineered from the existing implementation.
**Code:** `src/components/OsmMap.vue`, `src/map/osmFeatureLayer.ts`, `src/map/featureStyling.ts`, `src/map/mapConfig.ts`
**Related:** Data loading and indexing of the OSM export are specified in [osm-static-data](../osm-static-data/spec.md). Everything a logged-in editor can do (selecting, creating, tagging nodes) is intentionally **not** part of this spec; it will follow in later steps.

## Problem / intent

Anyone — without an account, without logging in — can open the app and see a map of Flensburg: a Leaflet map on the standard OpenStreetMap tile background, overlaid with the area's OSM data (nodes, ways/roads, buildings, and areas), including the OSM tags of every feature, so the data is inspectable, and later steps can attach editing to it.

## Requirements

### R1 — Public access
- The map view loads and works fully without authentication. No login is required to see the map, pan, or zoom.
- Anonymous users see the same OSM data overlay as logged-in users; nothing on the basemap requires a session.
- Editing is a later step and out of scope here, but editing must visibly *require* login; this spec guarantees that view-only remains available to everyone.

### R2 — Leaflet map with OSM background tiles
- The map is a Leaflet `L.map` with a **canvas renderer** (10 px hit tolerance so thin lines stay clickable), max zoom 22.
- Background tiles come from `https://tile.openstreetmap.org/{z}/{x}/{y}.png` with the OSM contributors attribution, native tile zoom up to 19 — deeper zooms upscale the native tiles.
- The map opens centered on Flensburg (`[54.791, 9.437]`) at zoom 14 unless the URL pins a position (`?lat=&lon=&zoom=`), which then takes precedence (e.g. the admin node preview at zoom 18).
- Panning and zooming re-render the overlay layers, debounced by 150 ms; moving never deselects.

### R3 — OSM data overlay
- The overlay is the static export `/data/flensburg.geojson` (a GeoJSON `FeatureCollection`), loaded once per session (see [osm-static-data](../osm-static-data/spec.md)).
- The export contains the OSM features of the covered area:
  - **nodes** — point features (rendered as circle markers),
  - **ways** — line features such as roads/highways,
  - **buildings** — closed ways with a `building` tag,
  - **areas** — closed ways marking parks, forests, water, landuse, etc.
- Every feature keeps its **original OSM tags** in its GeoJSON `properties`, and every feature carries its OSM feature id (e.g. `"n26013095"`).
- OSM tags on the basemap are read-only data; the app never writes back to OSM.

### R4 — Rendering rules
- Only features intersecting the current viewport (plus a 10 % padding band) are rendered.
- When more than 20 000 features would be visible, minor features (`building` tagged) are dropped until the user zooms in; streets, areas, and point nodes stay visible at every zoom.
- Draw order: large areas first, small features last, so contained features (a building inside a park) stack above their surroundings.
- Styling by OSM tags:
  - buildings: beige outline/fill;
  - parks, gardens, playgrounds, forest, grass, recreation ground, wood, scrub: green;
  - water and waterways: blue;
  - highways (roads/paths): gray stroke, weight 1.5;
  - anything else (unknown/custom point features): purple.
- Landuse areas covering whole neighborhoods do not capture clicks: clicks inside them pass through to the features they contain; closed areas also hit-test across their enclosed area, not only their outline stroke.

### R5 — Resilience
- If the OSM export fails to load, the basemap (R2) still fully works without the data overlay, and the app keeps running (failure flags per [osm-static-data](../osm-static-data/spec.md)).

## Acceptance criteria

- **AC1 (R1):** Given a fresh browser session without any login, opening the app shows the Flensburg map with the OSM data overlay; pan and zoom work.
- **AC2 (R2):** The map shows the OpenStreetMap attribution; zooming beyond 19 continues to work (upscaled tiles) up to zoom 22.
- **AC3 (R2):** Given a URL with `?lat=&lon=&zoom=`, the map opens on that position at that zoom instead of the Flensburg default.
- **AC4 (R3):** Given a building in the export, it is rendered as a filled beige area; given a park, as a green area; given a road, as a gray line; all keep their OSM tags available on the feature.
- **AC5 (R4):** At very low zoom (potentially > 20 000 visible features), buildings disappear while roads, areas, and point nodes stay visible.
- **AC6 (R4):** Clicking empty ground inside a `landuse` area does not select the landuse area; clicking a `landuse` area's own stroke does.
- **AC7 (R5):** Given the GeoJSON export returns a network error, the map view still loads and pans/zooms normally with the tile background.

## Open questions / assumptions

- Coverage is greater Flensburg; the export file is a deployment artifact (`public/data/flensburg.geojson`), regional coverage itself is not part of this spec.
- The tile URL is hard-coded to openstreetmap.org and subject to their tile usage policy — an assumption, not a requirement.

## Verification

- Click-through and overlay rendering are covered by the Playwright end-to-end suite (`npm test`); the store/layer logic so far has no dedicated unit tests.
- This spec was verified by code reading (`OsmMap.vue`, `osmFeatureLayer.ts`, `featureStyling.ts`); tests were **not executed in this session**.
