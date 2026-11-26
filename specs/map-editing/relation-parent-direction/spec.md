# Relation Parent Direction (automatic direction inheritance)

**Status:** Implemented and verified (2026-11-22).
Implemented change (2026-11-25): the mechanics of this spec carry over unchanged under [observation relations](../observation-relations/spec.md) — every observation relation, now one per observation, inherits its parent way's direction individually, which keeps `left`/`right` sides comparable across separate observations of one element. 
**Code:** `src/lib/parentDirection.ts` (offset projection and ordering), `src/stores/osmData.ts` (`lineStringById`), `src/components/OsmMap.vue` (batch parent way, preview/member ordering), `src/map/nodeGroupLayer.ts` (preview arrows). Browser checks: `scripts/testing/check-node-creation.mjs`, `scripts/testing/check-direction-arrows.mjs`.
**Related:** [way-direction indicators](../../map/way-direction/spec.md) (direction of a way/relation and the arrow rendering this feature reuses), [editor observations](../editor-observations/spec.md) (creation flow), [street part aspects](../street-part-aspects/spec.md) (whose `left`/`right` sides are only meaningful against a direction).

## Problem / intent

A node relation's direction is its member order ([way-direction indicators](../../map/way-direction/spec.md) R1), and today members are stored **in the order the editor clicked them**. An editor marking a street part on a street therefore gets a direction that depends on click order: collecting the same two nodes west-to-east or east-to-west produces relations running in opposite directions — and with the side-based cross-section grammar, `sidewalk.left` then describes different physical sidewalks in otherwise identical parts.

When the relation has a parent with a direction — every node of the new batch was placed on the same OSM way (the street the part belongs to) — the relation must always adopt that parent's direction automatically, whatever the placement order, and show it on the map with arrows.

## Definitions

- **Parent (of a relation)** — the parent node identity shared by all of the new relation's member nodes; in practice the OSM way the nodes were placed on (a relation's `parents` are derived from its members' parents, see `NodeGroupManager::toResponse`).
- **Parent direction** — the parent way's direction of travel: the coordinate order of its LineString geometry (first coordinate toward last), exactly what the way-direction arrows render on the selected way.
- **Offset along the way** — the along-distance (in meters, equirectangular approximation) of a point's nearest point on the parent way's polyline; points beyond either end clamp to 0 or the total length. Ties keep placement order.
- **Correction** — reordering the batch ascending by offset, so the relation's member order runs with the parent way's direction.

## Requirements

### R1 — A new relation inherits a single parent way's direction

- When a relation is created from the placed batch (create mode), and **every** member node of the batch resolves to the **same single OSM way** (a LineString feature in the static export), the saved member order is the members' order **along that way** (ascending offset), regardless of the placement order. The relation therefore always runs in the parent street's direction.
- If the parent way's geometry is not available (export not loaded, feature missing or not a LineString), the affected batch keeps placement order. Nothing errors.
- The relation may extend beyond the parent way's endpoints (nodes placed past either end); their clipped offsets keep the resulting order well-defined.
- Cases that get **no correction** (placement order is kept): no parent at all, mixed parents, an OSM point-node parent, a local-node parent, or members with differing parents across the batch. Only `way` features carry a direction; a cross-street part spanning two ways has no single inheritable direction.

### R2 — The collection preview shows the inherited direction with arrows

- While the batch is being collected, the dashed preview line through the pending/saved nodes shows the **corrected** order, so the preview geometry already runs with the parent.
- When the correction applies, the preview line carries **direction arrowheads** pointing along the parent way's direction (same chevrons as the direction arrows feature, pending amber color), so the editor sees the inherited direction live from the second placed node on.
- Without a correctable single parent way, the preview stays exactly as before — ordered by placement and **no arrowheads** (the parentless preview remains arrowless).
- The arrows follow the existing client setting: the preview arrows render only while the way-direction arrows setting is on.

### R3 — After creation, the arrows immediately show the inherited direction

- The new relation is opened/selected as today after its save; the selected relation's existing direction arrows (rendered for the selected line when the setting is on) then point along the inherited direction without any extra step.

### R4 — Scope: client-side, creation only

- The correction happens in the client when composing the create save: the group save request lists the members in the inherited order. The backend contract is unchanged — the backend persists the submitted member order, as before, and remains the authority that a relation's direction is its stored member order.
- Existing relations are untouched (no reordering on edit or update saves); only newly created relations inherit.

## Acceptance criteria

- **AC1 (R1):** Given Street Alpha rendered west-to-east and an editor placing a node at its eastern position first and its western position second, the created relation's members are submitted western node first — and selecting the relation shows arrows pointing west-to-east, matching the street's own arrows.
- **AC2 (R1):** Given a batch with mixed parents (two streets), no parents (empty map), or an OSM node parent, the relation's member order is exactly the placement order (unchanged behavior).
- **AC3 (R2):** Given the AC1 collection before saving, the dashed preview line shows arrowheads pointing west-to-east (preview arrows appear only in the inherits-direction case).
- **AC4 (R2):** Given the arrows setting is off, no preview arrowheads render; the preview and relation lines themselves are unchanged.
- **AC5 (R4):** Given the same relation edited afterwards (members unchanged in the request), editing does not reorder members; only creation applies the correction.

## Assumptions and scope

- "The same direction as the parent" means the parent **way's** coordinate order; relation-to-relation parents do not exist in the data model (a relation's parents are the member nodes' parent identities), so the single parent way is the only parent with a direction. Parts on the same street then run consistently, so side tags (`sidewalk.left`) describe the same physical side across parts of one street.
- Offsets use an equirectangular meter approximation at the way's own latitude — ample for ordering; nothing is persisted about offsets.
- Cycles/manual reordering UIs do not exist for relation members; creation order is the only ordering path today.
- Backend-side correction was ruled out: the backend has no OSM way geometry (the static export is a frontend concern, see [OSM static data](../../map/osm-static-data/spec.md)).

## Verification (2026-11-22)

- **Passed — AC3/AC4:** `check-direction-arrows.mjs` new scenarios: two placements on the (reversed, eastward-running) street collected against the way's direction show preview arrowheads running **with** the street at the preview's midpoint (`assertHorizontalArrowDirection(..., false)`), and the same direction-known preview shows **no** arrowheads with the arrows setting off; the parentless-with-arrows-on assertion (`preview has no arrows`) and all selection/passthrough/setting assertions still pass — the whole check passes.
- **Passed — AC1:** `check-node-creation.mjs` gained the reverse-placement scenario (two placements on Street Alpha, east first; created relation submitted with members west→east). The check as a whole aborts earlier at the pre-existing drawer-save failures of the current `refactoring` commit (unrelated; they exist without these changes too), so the scenario was additionally run directly right after the check's last known-good section via a temporary copy in the same file: both placements carry parent `w-street-a` and the group save lists the western node first — `TEMP PASS` observed, temporary copy removed again. Once the pre-existing failures are fixed, the scenario runs in its permanent position.
- **Passed — AC2:** the same run covers the pre-existing cross-street scenario (mixed way parents) and parentless batches keeping placement order; the mixed-parent case is additionally pinned by `check-node-selection.mjs`'s creation-order assertions, which still pass.
- **Passed — regressions:** `npm run build` (vue-tsc + Vite); `check-map-editing.mjs`, `check-node-relation.mjs`, `check-relation-approval-color.mjs`, `check-direction-arrows.mjs`, `check-node-selection.mjs` all pass. Backend untouched (no suite change; the full suite last passed green with the naming revision).
- **Pre-existing failures (not this feature):** `check-node-creation.mjs` (drawer tag-save disabled-state messaging) and `check-routing.mjs` (routing status element) both fail identically with these changes stashed.
- **Not performed:** AC5 as a dedicated edit-session probe (member updates are governed by the unchanged update-save contract; creation is the only correction site by code construction — the create save is the only place `orderedBatchNodes` is used for members).
