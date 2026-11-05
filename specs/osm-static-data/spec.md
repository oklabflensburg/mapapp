# OSM Static Data Layer

**Status:** As-built spec, reverse-engineered from the existing implementation.
**Code:** `src/map/osmData.ts`, `src/map/featureIndex.ts`, `src/stores/osmData.ts`, `src/map/mapConfig.ts`
**Scope:** How the frontend loads, indexes, and serves the static OSM GeoJSON export. Rendering, styling, and hit-testing of the resulting features are covered by other specs (map layer), not this one.

## Problem / intent

The map renders a static export of Flensburg OSM data (`/data/flensburg.geojson`, a GeoJSON `FeatureCollection`). It is fetched once per app session, indexed for cheap viewport culling, and shared between the map renderer and the node editors so a saved user node referencing an OSM object can display that object's original tags. The map must keep working (without the OSM feature layer) when the export cannot be loaded.

## Requirements

### R1 — Single fetch per session
- `useOsmDataStore().load()` fetches the export from `OSM_DATA_URL` (`/data/flensburg.geojson`).
- The first call memoizes the in-flight promise; every further call returns the **same promise** — no second network request for the store's lifetime, and no re-fetch after a failure either.
- While the request is pending, `loading` is `true`; after it settles, `loading` is `false`.

### R2 — Failures degrade gracefully
- A non-OK HTTP response (any status) or a network error makes the fetch resolve to `undefined`.
- The store then sets `loadFailed = true`; `data` stays `undefined`.
- The application continues running without the OSM feature layer; nothing throws to the caller.
- The error is logged via `console.warn('Failed to load OSM features', …)`.

### R3 — Bounding-box index
- Each feature is returned as an `IndexedFeature`: the GeoJSON feature plus precomputed `minLon`/`minLat`/`maxLon`/`maxLat` covering every coordinate of its geometry (Point, MultiPoint, LineString, Polygon, MultiPolygon), so viewport culling never walks coordinates.

### R4 — Original OSM tags by feature id
- The store exposes `tagsById`: a `Map` from feature id string (e.g. `"n26013095"`) to its `properties` record.
- Features without an id are excluded from the map.
- Consumers use it to show the original OSM tags of a node referenced by a saved user node.

### R5 — Feature classification helpers (same module)
- `isMinorFeature(feature)` — true when the feature has a `building` property; buildings are the first features dropped when the visible set exceeds `MAX_VISIBLE_FEATURES` (20 000).
- `isAreaOutline(feature)` — true for a closed LineString (first coordinate equals last, more than 3 coordinates) **without** a `highway` tag; closed ways with a `highway` tag stay stroke-only paths.
- `bboxArea(feature)` — area of the feature's bounding box in degrees².

## Acceptance criteria

- **AC1 (R1):** Given a fresh app session, calling `load()` twice concurrently and then again after resolution issues exactly one `fetch` of `/data/flensburg.geojson`; all calls resolve with the same value.
- **AC2 (R2):** Given the export request returns HTTP 404, `load()` resolves (does not reject), `loadFailed` is `true`, `loading` is `false`, and `data` is `undefined`.
- **AC3 (R3):** For every indexed feature, the bbox contains all coordinates of its geometry.
- **AC4 (R4):** For a feature with id `"n123"` and properties `{amenity: "bench"}`, `tagsById.get("n123")` returns `{amenity: "bench"}`.
- **AC5 (R5):** A closed LineString of 5 coordinates with properties `{building: "yes"}` is `isMinorFeature` **and** `isAreaOutline`; the same geometry with `{highway: "footway"}` is `isMinorFeature=false` and `isAreaOutline=false`.

## Open questions / assumptions

- The export is a build-time static file served by the same origin; no auth applies to it.
- The dataset covers Flensburg (`DEFAULT_CENTER` [54.791, 9.437], zoom 14); regional coverage is a deployment property, not part of this spec.

## Verification

- No dedicated frontend unit tests exist for this module (Playwright e2e covers the map end to end).
- Behavior verified against code reading of `src/map/osmData.ts`, `src/stores/osmData.ts`, and `src/map/featureIndex.ts`; tests were **not executed in this session**.
