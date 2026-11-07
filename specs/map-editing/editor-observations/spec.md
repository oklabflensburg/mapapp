# Observations on OSM Features (editor click-to-tag flow)

**Status:** Step 2 of the node spec series. As-built, reverse-engineered from the existing implementation.
**Code:** `src/components/NodeTagPanel.vue`, `src/views/HomeView.vue`, `src/components/OsmMap.vue`, `src/map/osmFeatureLayer.ts`, `src/stores/userNodes.ts`, `src/stores/defaultObservationTags.ts`; backend approval: `backend/src/Service/UserNodeManager.php`, `backend/src/Doctrine/TagApprovalListener.php`
**Related:** builds on [flensburg-map](../../map/flensburg-map/spec.md) (feature click and OSM tags data) and [api-authentication](../../authentication/api-authentication/spec.md) (roles). Node/observation *persistence API* details will be a later step.

## Problem / intent

A logged-in editor clicks an OSM feature on the map (a way node, but also ways, buildings, and areas) and sees its OSM tags. The editor can add **observations** — custom key/value tags about, e.g., accessibility — to that feature. Observations are ordinary custom tags presented as "Observation(s)" pending review: until an admin approves them in the backend, only their creator sees them; once approved they become regular tags visible to everyone.

## Definitions

- **OSM tags** — the read-only tags from the OSM data export, shown for every selected feature.
- **Custom tags / observations** — key/value pairs added through the app. An observation is a custom tag that is not yet approved. Approval flips its presentation: from the editing "Observations" section to the read-only tag list of the node.

Street-part cross-section attributes also use ordinary observation tags, with keys such as `way.footwalk.1.width` and values such as `<1`. Their grammar, composer and exact-key moderation contract are defined in [Street Part Aspects](../street-part-aspects/spec.md); they have no separate aspect persistence or approval state. This extension is implemented through the existing tag lifecycle.

## Requirements

### R1 — Selecting a feature opens the node editor
- A logged-in user with the editor role (`ROLE_EDITOR`; admins inherit it via the role hierarchy) clicks an OSM feature on the map.
- The bottom drawer "node editor" opens for that feature, identified by its OSM feature id (e.g. `"n26013095"`), with the click position's coordinates.
- Clicking the feature again keeps it selected; clicking empty map space closes the panel and deselects; pan/zoom never deselects.
- Anonymous users can open the panel and see everything read-only, but the editable sections show a login-required hint instead of inputs (editing requires `canEdit`: editor or admin role).

### R2 — OSM tags are shown read-only
- The panel shows an "OSM tags" section listing the raw OSM tags of the selected feature from the static data export (see [osm-static-data](../../map/osm-static-data/spec.md)).
- Keys and values display through the shared bilingual tag-key translations when available; otherwise they fall back to prettified keys and raw values.
- OSM tags are never editable here.

### R3 — Adding observations
- An editor sees the "Observations" section with an add control and can create observation rows as free-form key/value pairs.
- Admin-exposed default observation tags (see [api-tag-settings](../../tag-settings/api-tag-settings/spec.md)) appear as a searchable suggestion dropdown; picking one adds a row with that key. For keys with configured selectable values, the value is a dropdown; otherwise the value is free-form text.
- Each observation row can additionally carry a proposed translation for its key and value in the active app locale (`en`/`de`); the translation proposal is sent with the save. *(The full translation-proposal workflow is its own later spec step.)*
- When the selected feature was saved before, the panel loads its existing state: the editor's own unapproved pairs populate the editable observations rows; approved custom tags are listed read-only (they are moderated — editors cannot change approved content, not even their own once approved).

### R4 — Saving
- Saving persists all rows (approved list unchanged + the editor's observation rows, including their translation drafts) in one request; the coordinate and identity of the selected feature go with it (provider `OSM`, its feature id).
- The panel stays open after a successful save and re-splits the sections from the server response; new/edited observations remain presented as "Observations" (they are unapproved).
- Save failures are shown in the panel: a generic save-failed message, or a translation-conflict message when the server rejected the observation's translation as changed (HTTP 409).

### R5 — Approval by an admin
- Created observation tags start **unapproved**; only an admin can approve them, through the backend admin (EasyAdmin pending observations), not through the map app.
- Admins' own additions are approved immediately on save (admin self-approval).
- On approval of an observation, the backend also: approves the node itself, and removes other unapproved tags with the **same key** on that same node — the approved value supersedes them. Approval is per node: approving a value here never publishes it on other nodes.

### R6 — Visibility of observations vs. tags
- **Unapproved observations** are visible only to the user who created them (in maps, lists, and the API). No other user — anonymous or logged-in — sees them, not even as a "pending" hint.
- **Approved observations** are visible to **all** users (anonymous included) as regular tags of the node.
- Consequence for OSM overlays: while none of an editor's observations on an OSM feature is approved, the whole saved observation set is invisible to others — the underlying OSM feature still renders from the export (step 1) and its raw OSM tags remain visible to everyone.

## Acceptance criteria

- **AC1 (R1):** Given a logged-in editor, clicking a way node on the map opens the node editor for that OSM feature; the same click position with no feature hit closes the panel.
- **AC2 (R1):** Given an anonymous visitor, the panel opens read-only with a login hint instead of the add-observation inputs.
- **AC3 (R2):** The "OSM tags" section shows exactly the feature's OSM tags from the export, with translated labels when the catalog provides them.
- **AC4 (R3):** Given the admin exposed `wheelchair` with values `yes`/`no` as a default observation tag, picking it offers a yes/no value dropdown; a key not in the list can be typed free-form.
- **AC5 (R4):** After a successful save the panel stays open and the editor's saved pairs remain in the Observations section (unapproved).
- **AC6 (R5):** Given the editor saved `surface=uneven` on a feature, no other user's API response contains it until an admin approves it.
- **AC7 (R5):** Given an admin approves the editor's `surface=paving_stones` observation, another editor's still-unapproved `surface=asphalt` on the **same node** is removed; the same unapproved pair on a different node is untouched.
- **AC8 (R6):** Given an approved observation on an OSM feature, an anonymous user sees it as a tag of that feature; the creator sees it in the read-only tag list, no longer as an editable observation.

## Open questions / assumptions

- "Way node" in the problem statement generalizes to any OSM feature (nodes, ways, buildings, areas) — the flow is identical; recorded here deliberately.
- Approved-tag read-only treatment is a presentation rule (`NodeTagPanel`), backed server-side by per-user assignment; the exact request shape is the later API-persistence step.
- Anonymous users seeing read-only observation state of their own? Not possible — anonymous adds nothing; an editor who logs out loses their own-pending view (server-side session user based).

## Verification

- Backend: `backend/tests/Controller/CrossEditorTagTest.php` (per-user visibility), `TagApprovalCleanupTest.php` (superseded same-key cleanup, node approval), `ModerationDeclineTest.php` (decline behavior), `ObservationTranslationTest.php` (translations), `PendingObservationListTest.php`/`PendingObservationCrudTest.php` (admin moderation).
- Frontend: Playwright e2e (`npm test`) covers panel interactions; no dedicated unit tests for the panel.
- Specs verified by code reading; tests were **not executed in this session**.

## Implementation verification (2026-11-07)

Implemented and checked; see [shared tasks and verification](../tasks.md) for commands, outcomes and limitations. Existing visibility, moderation and editor behavior remain covered by the backend suite and browser regressions.
