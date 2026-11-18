# Way direction indicators (arrows on ways and node relations)

**Status:** Implemented and verified (2026-11-18).
**Code:** `src/map/directionArrows.ts` (chevron geometry), `src/map/nodeGroupLayer.ts` (relation and preview arrows), `src/map/osmFeatureLayer.ts` (way arrows, arrow recoloring on selection), `src/stores/settings.ts` + `src/views/ProfileView.vue` + `src/components/OsmMap.vue` (the client setting, its profile toggle and the re-render)
**Browser check:** `scripts/testing/check-direction-arrows.mjs`
**Related:** [Flensburg basemap](../flensburg-map/spec.md) (way rendering), [Way observations](../../map-editing/way-observations/spec.md) (way selection), [Street part aspects](../../map-editing/street-part-aspects/spec.md) (direction grammar that motivates this feature)

## Problem / intent

An OSM way is an **ordered** sequence of nodes, and a node relation (street part) is an ordered list of member nodes. That order is direction: the cross-section grammar tags a street part's elements by side (`way.<type>.<direction>.<index>.<aspect>` with `north`, `south`, `east`, `west`, `center`), and which side of the street is "west" depends on which way the way/relation runs. Today the map shows only undirected lines, so an editor composing or reviewing a cross-section cannot see whether the relation runs, say, west-to-east or east-to-west — the direction tags are unverifiable on the map.

The map must show the direction of every way and relation line, so the direction grammar becomes readable against the actual geometry.

## Definitions

- **Direction of an OSM way** — the order of the way's geometry coordinates, which equals the OSM node order of the way. First coordinate = first node, last coordinate = last node.
- **Direction of a node relation** — the order of the relation's member list (as returned by `/api/node-groups` and rendered today); the backend stores members with an explicit member order.
- **Arrowhead** — a small on-map indicator placed on a line, rotated to point along the line's direction of travel (first node/coordinate toward the last).

## Requirements

### R1 — Direction is derived from existing geometry, no new data
- Way direction comes from the coordinate order of the LineString geometry already rendered by the OSM feature layer; relation direction comes from the member order already used to draw the relation line. No backend, API or export change is needed for this feature.
- A relation's direction is its **own member order**, not the direction of the underlying OSM way it follows; the two can legitimately differ.

### R2 — OSM ways show direction arrows
- Every rendered line feature of the OSM layer (LineString geometry — the gray highway strokes and the custom/purple line features) carries at least one arrowhead pointing from its first coordinate toward its last.
- **Closed ways rendered as strokes** (LineString whose first and last coordinate coincide, e.g. a roundabout) also get arrowheads, following the loop's coordinate order around the loop.
- Point features and Polygon/area geometries get no arrowheads.

### R3 — Node relations show direction arrows
- Every rendered relation line — pending (amber), approved (green) or selected (highlighted) — carries arrowheads in its member order.
- The dashed relation-mode **preview** line gets live arrowheads too, so the direction is visible while the relation is being collected and updated as nodes are placed.

### R4 — Placement and readability
- Every line that renders gets at least one arrowhead; a line long enough on screen (default assumption: longer than ~150 screen pixels) gets repeating arrowheads along its course at regular screen-distance intervals, so a long street's direction stays readable along its whole length.
- An arrowhead sits **on** the line, oriented along the local segment bearing at its placement point, sized in screen pixels so it stays legible at every zoom level and neither shrinks to invisibility when zoomed out nor dominates when zoomed in.
- Arrows are rebuilt together with their layer on pan/zoom changes, like the lines themselves; the visibility filtering of the OSM layer (`MAX_VISIBLE_FEATURES` thinning) applies to a way's arrowheads exactly as to its stroke — a thinned way shows no arrows.

### R5 — Arrows do not interfere with existing interaction
- Arrowheads are purely visual overlays: they are not hit-testable and never swallow, block or redirect clicks. Clicking where an arrowhead sits behaves exactly like clicking the underlying way stroke or relation line today (selection, extended stroke tolerance, click-passthrough in create/relation/routing modes all unchanged).
- Arrows do not change existing line styling (colors, weights, opacity, approved/pending/selected distinctions) and introduce no new visibility rules: whoever sees a given line today sees its arrows.

### R6 — Arrows are configurable (client setting)
- Each visitor can turn the way-direction arrows off and on in the profile page's client settings; the change applies to the map immediately and is remembered on the device (localStorage, backend storage is not involved for anonymous visitors and not used at all for this setting).
- The setting applies uniformly to every arrowhead covered here: OSM way strokes, node relations and the relation-mode preview.
- **Default: on** — the arrows render unless the visitor turned them off.
- Turning the arrows off changes only the arrowheads: line styling, interaction and visibility behave exactly as before.

## Acceptance criteria

- **AC1 (R2):** Given a way whose coordinates run west-to-east, its arrowhead(s) point east; reversing the geometry reverses the arrows on reload.
- **AC2 (R2):** Given a closed-way stroke (e.g. a roundabout loop), arrows follow the loop consistently in coordinate order.
- **AC3 (R2):** Given a viewport with point features and area polygons, no arrowheads appear on them.
- **AC4 (R3):** Given a relation with members ordered A → B → C, its line shows arrows from A toward C; the selected relation keeps its arrows under the highlight styling, and the dashed preview shows arrows while collecting.
- **AC5 (R4):** Given a long street filling most of the screen width, several arrowheads appear at regular spacing, all constant in screen size across zoom levels.
- **AC6 (R5):** Given a way stroke with an arrowhead on it, clicking the arrowhead's screen position selects that way exactly as clicking the stroke beside it does.
- **AC7 (R1):** The feature works against the current `/api` and OSM export with no data or API changes.
- **AC8 (R6):** Given the profile setting is switched off (and the page reloaded), ways, loops and relations render without arrowheads while their strokes stay unchanged; switching it back on restores the arrows.

## Open questions / assumptions

- **Arrow spacing/size values** are not yet agreed; the defaults above (repeat past ~150 px, constant screen size) are recorded as assumptions, tunable during implementation without changing the requirements.
- **Rendering approach** (Leaflet canvas-drawn decorations vs. rotated markers) is an implementation choice; the spec deliberately does not prescribe it. Adding a runtime dependency for it (e.g. a polyline-decorator package) would need a separate decision.
- **Dense viewports:** at low zoom the OSM layer already thins minor features; arrows inherit that behavior. No extra arrow-specific thinning is specified.
- It is assumed that anonymous visitors and editors see the same arrow behavior for every line each of them can already see (relation visibility rules are unchanged).

## Verified decisions (product owner)

- Arrows appear on **both** OSM ways and node relations.
- Originally the arrows were decided to be always shown without a toggle; revised on 2026-11-18: they are now **configurable per visitor** (profile setting), with **on as the default**.

## Verification

- **Passed — build:** `npm run build` (vue-tsc + Vite) is clean.
- **Passed — direction check:** `node scripts/testing/check-direction-arrows.mjs` (Vite running, `APP_URL` set) — AC1: a straight way's single arrowhead points along coordinate order and flips when the geometry is reversed on reload; AC2: the closed-way loop carries arrowheads along its course (top and right edges sampled); AC4: several evenly spread arrowheads on a long line (AC5's spacing); R3: the saved relation's arrowhead follows member order, and the dashed edit-mode preview gets live arrowheads in collection order; R5: a click on an arrowhead's off-line wing still selects the way underneath (the editor drawer opens) instead of the arrowhead swallowing it; AC8: the profile page's toggle turns every arrowhead off (strokes stay rendered, verified on the way, the loop and the relation after a reload) and back on. The check intercepts the geojson and all API calls, so it never touches the development database.
- **Passed — regression checks:** `check-node-relation` (relation creation, preview, line click, zoom, reload), `check-relation-approval-color`, `check-node-selection` and `check-map-editing` all pass unchanged with arrows enabled.
- **Not covered by the browser check:** AC3 (no arrowheads on points and areas) is guaranteed by construction — the way loops only render arrows for `LineString` geometry in `osmFeatureLayer.ts` — and is backed by code reading, not sampled pixels. AC7 (no data/API change) likewise holds by construction: the arrows are computed client-side from already-loaded geometry (`R1`).
- **Pre-existing failure, unrelated:** `check-osm-render` fails on `main` exactly the same way before and after this change ("no empty spot found in the viewport"); its deselect step can no longer find an empty click target in the current Flensburg export, not an arrow issue.
