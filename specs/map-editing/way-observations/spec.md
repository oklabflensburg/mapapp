# Way Observations (tagging streets and other ways)

**Status:** Step 3 of the node spec series. As-built, reverse-engineered from the existing implementation — **except R5**, which is a NEW design refinement (controlled per-type vocabulary) agreed with the product owner.
**Code:** `src/map/osmFeatureLayer.ts`, `src/map/userNodeLayer.ts`, `src/map/featureIndex.ts`, `src/components/NodeTagPanel.vue`, `src/stores/userNodes.ts`
**Related:** builds directly on [editor-observations](../editor-observations/spec.md) — every rule there applies unchanged to ways; this spec adds only what is specific to way features.

## Problem / intent

Editors tag streets and other ways: an editor clicks a way on the map (a gray street line or any other line feature), sees the way's OSM tags, and adds observations to the **way as a whole** — e.g. `surface`, `tactile_paving`, `lit` along that street. The observation belongs to the entire way, not to the clicked spot. Point nodes were step 2's [assumption coverage]; buildings and closed areas come in later steps — they are **out of scope here**.

## Definitions

- **Way** — a `LineString` feature of the OSM export with an id like `"w4603722"`, carrying its original OSM tags (`highway`, `name`, `surface`, …). Ways render as strokes, never as filled areas.
- **Node identity of a way** — the pair (`OSM`, way feature id); the shared identity for all observations on that way.
- **Way type** — the way's `highway` tag value (`footway`, `residential`, `steps`, …), read directly from its OSM tags.
- **Type vocabulary** — the admin-controlled set of observation keys (each with its selectable values) that applies to a way type; maintained in the backend (definition UI is a separate, later spec).

## Requirements

### R1 — Ways are first-class selectable features
- Every way in the export is clickable along its whole course; since ways render as strokes, the extended hit tolerance (10 px) applies, and only the stroke is hit-tested — unlike closed area outlines, ways never hit-test across their enclosed space (a closed way with a `highway` tag is a path, not an area).
- Clicking a way opens the node editor (step 2 R1) for the way identity: provider `OSM`, its way feature id, and the click position's coordinates.
- The "OSM tags" section lists the way's own OSM tags (e.g. `highway=footway`, `name=…`) with the same bilingual labeling as before.

### R2 — The observation applies to the whole way
- Observations are saved against the way identity, not a position along it. Clicking a different segment of the same way later opens the same observation state (same identity: `OSM` + way id).
- The saved coordinates attached to the overlay come from the click position where it was first saved; they position the overlay marker, they do not limit the observation to that spot.
- All step 2 rules apply unchanged — **except** the observation keys offered, which are restricted per way type by R5: add rows only from the type's vocabulary, optional translation proposals, editor-only editing, admin approval, creator-only visibility until approved.

### R3 — Overlay markers for ways with saved observations
- Ways with saved observations get a marker on top of the OSM layer (drawn above the OSM features, newest drawn last for stable hit priority), so an editor sees which ways were worked on even without clicking them.
- Marker style: green for saved overlays; amber distinguishes only saved *local* nodes pending approval (a local node feature, not this way flow).
- Clicking the marker reopens the way's editor with the original OSM tags merged in from the export (looked up by the way feature id).
- Anonymous users never see markers for ways whose observations are all still unapproved — such overlays do not exist for them (step 2 R6).

### R4 — Rendering stays by feature type
- Ways keep their step 1 rendering (gray stroke for highways, purple for unknown/custom line features); observations do not change the way's basemap styling.
- Marking only **part** of a street (a street part/relation between two nodes) is a separate later step of this series and explicitly out of scope here; this spec tags the way as a whole.

### R5 — Type-specific controlled observation vocabulary (NEW — design refinement, not yet implemented)
- Editors may add to a way **only** the observation keys that the admin's vocabulary defines for that way's type (`highway` value): the observations section offers exactly the type's keys, with each key's selectable values where the vocabulary configures them. Free-form key entry on ways is removed — a change to today's free-form behavior.
- The restriction is enforced at both ends: the editor UI offers no other keys, and the backend rejects observations with keys outside the vocabulary for that way type (validation error).
- The vocabulary itself is defined and maintained by admins in the backend; **how** it is defined is out of scope here and belongs to its own spec (along the lines of today's default-observation-tag admin section, but keyed by way type).
- The way type is read from the way's OSM `highway` tag at selection time; no classification step happens in the app.
- **Ways without a `highway` tag** (railways, waterways, boundaries, …) have no way type and therefore no vocabulary: no observations can be added to them until an admin covers them. That is intended behavior, not a gap.
- **Way types without a configured (empty) vocabulary** mean "no observations", too — there is **no fallback** to today's global default observation tags for ways.
- **Values:** each vocabulary key carries an admin-defined value list; when the list is empty the value stays free-form text.
- **Grandfathered observations** saved before this restriction (keys outside their way type's vocabulary) keep displaying and stay editable for their creators until moderated; approval supersession cleans them up as usual.

## Acceptance criteria

- **AC1 (R1):** Given an editor clicks a street line, the node editor opens with that way's feature id and its OSM tags displayed.
- **AC2 (R1):** Given a closed way with `highway=footway`, clicking inside the enclosed loop misses it (no area hit-test); only the stroke selects it.
- **AC3 (R2):** Given an editor saved `surface=uneven` on a way, clicking any other segment of that way shows the same observation in the editor.
- **AC4 (R3):** Given a way with an approved observation, a green marker sits on the map at its saved position; clicking it opens the way editor with the way's original OSM tags visible.
- **AC5 (R3, R6-of-step-2):** Given a way with only unapproved observations, an anonymous visitor sees no marker for it in the node list, but the way itself (from the export) renders normally.
- **AC6 (R5):** Given a `footway` whose vocabulary defines `surface` and `tactile_paving`, the editor can add exactly those observation keys to it — no free-form key input is offered.
- **AC7 (R5):** Given an editor submits an observation with a key outside the way type's vocabulary, the backend rejects the save with a validation error and nothing is stored.
- **AC8 (R5):** Given a `residential` street, its vocabulary differs from the `footway` one, and only its own keys are offered when it is selected.
- **AC9 (R5):** Given a way without a `highway` tag, or of a type whose vocabulary is empty/not configured, the editor is offered no observation keys and a save attempt with any key is rejected; today's global observation suggestions do not appear for ways.

## Open questions / assumptions

- **Way segments vs. whole ways:** OSM ways can be long; observations cannot currently be limited to a segment of one way. The as-built street-part marking (relations) is the planned later step for that; recorded so it stays visible as follow-up.
- Copying way tags into routing (`osmRoadClassesByTag`) is an admin-managed mapping consumed by `api-routing`; it is not part of the editing flow here.
- The way overlay marker's position is the remembered click position, not a way-specific anchor (e.g. midpoint) — deliberate as-built behavior, noted in case the position rule should be specified deliberately later.

## Confirmed decisions (previously open)

The R5 edge cases were confirmed as intended behavior with the product owner and are recorded in R5 itself: untyped and empty-vocabulary ways are **not** observable; there is **no global fallback**; values are admin-listed with free-form only when the list is empty; saved grandfathered observations remain visible/editable for their creators until moderation cleans them up.

## Verification

- Same verification coverage as step 2 (backend tag/visibility tests, Playwright e2e for panel interactions); the way-specific selection geometry is exercised by the e2e flow but has no dedicated test asserting closed-highway ways stay stroke-only.
- Verified by code reading of `osmFeatureLayer.ts` (`isAreaOutline` excludes highway ways), `userNodeLayer.ts`, and the export sample; tests were **not executed in this session**.
- **R5 is unimplemented design**: its ACs (AC6–AC8) are the acceptance baseline for the future vocabulary spec and its implementation, not descriptions of existing tests.
