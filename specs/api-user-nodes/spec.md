# User Nodes API (nodes + observation tags + parent relations)

**Status:** As-built spec, reverse-engineered from the existing implementation.
**Code:** `backend/src/Controller/NodeController.php`, `backend/src/Service/UserNodeManager.php`, `backend/src/Api/SaveUserNodeRequest.php`, `backend/src/Api/UserNodeResponse.php`, `backend/src/Service/ObservationTranslations.php`, `backend/src/Doctrine/TagApprovalListener.php`, `src/stores/userNodes.ts`
**Scope:** Persisting and reading local map points and editors' tag edits ("observations") on OSM objects, including directed parent relations and optional proposed translations for observations. Admin-side moderation is covered by `api-tag-settings`-adjacent admin specs, not here — except the approval rules that leak into the public contract.

## Problem / intent

Logged-in editors place new nodes on the map ("local" nodes) and add custom OSM-style key/value tags to existing OSM features (provider `OSM`) or their own local nodes. An admin approves observed tags before they become visible to other users. A node can reference one parent node to express a simple parent–child hierarchy (e.g. an entrance belonging to a building).

## Data model invariants

- A node is identified by the pair `(provider, providerNodeId)`, provider ∈ {`OSM`, `local`}. Saving an existing pair updates; otherwise it creates (HTTP 201).
- Tags are per-user-per-node: a user's save replaces **their own** tag additions on the node while preserving other users' tags. One node can carry the same key with different values.
- Approval is per node/tag: an editor's tags start unapproved; an admin's own additions on a save are immediately approved.
- A local node is approved at creation only when its creator is an admin; OSM-reference nodes are approved from the start but their **added tags** still need approval to publish the overlay.
- Approving any tag on a node (the `TagApprovalListener` onFlush hook) also approves the node itself and deletes unapproved tags with the same key on that node (superseded values).

## Requirements

### R1 — List nodes
- `GET /api/nodes` is public.
- Returns nodes of node type `user` that are visible to the caller:
  - the caller's own nodes (created by the caller's user) are always visible;
  - otherwise the node must be approved **and** (`provider === 'local'` or any of its node tags is approved) — OSM overlays publish only once a tag was approved.
- Response: a JSON array of `UserNodeResponse` objects with `id`, `providerNodeId`, `latitude`, `longitude`, `tags` (key/value pairs visible to the caller), `observations` (the caller's own unapproved pairs), `observationTranslations` (same pairs plus per-locale `keyTranslation`/`valueTranslation` from the shared tag translations), `approved`, `createdAt`, `updatedAt`, `provider`, `parentIds`/`childIds` (arrays; zero or one parent, array shape kept for compatibility), `parents`/`children` (full `RelatedNodeResponse` objects, filtered by the same visibility rules; an invisible parent is omitted as if absent).
- `RelatedNodeResponse`: `{ id, provider, providerNodeId, name, tags, latitude, longitude }` where `name` is the first seen non-empty `name` tag value visible to the caller.
- Tag visibility: approved tags for everyone; unapproved tags only to their creator.

### R2 — Save node (create or update)
- `POST /api/nodes` requires `ROLE_EDITOR` (admins inherit it); otherwise HTTP 401/403 before the controller.
- Body (`SaveUserNodeRequest`): `providerNodeId` (non-empty, ≤64 chars), `latitude` (−90…90), `longitude` (−180…180), `tags` (list of `{key, value}`, both non-empty trimmed strings ≤255 chars), `provider` (`OSM`|`local`, default `OSM`), optional `parent`, `translationLocale` (`en`|`de`, default `en`), optional `translations` (≤500 entries; strings must be valid XML text; each entry may carry `key`, `value`, `keyTranslation`, `valueTranslation`, `previousKeyTranslation`, `previousValueTranslation`).
- Invalid payload → HTTP 422 with validation errors.
- Runs in one transaction; responds 201 (created) or 200 (updated) with the saved node as `UserNodeResponse` (shaped like the list items).
- When the save includes a `parent`, the parent is resolved server-side and attached (`setParent`); the frontend reloads the full list afterwards because relations changed.

### R3 — Parent reference rules
- A parent is either a saved node `{ id }` or an OSM reference `{ providerNodeId, latitude, longitude }` — never both.
- `{ id }`: the node must exist, else HTTP 422 `Parent node does not exist.`
- OSM reference: the referenced OSM node is reused or auto-created (node type `osm`, provider `OSM`, with the given coordinates); a node can never become its own parent (422 `A node cannot be its own parent.`), also checked for the OSM-reference case where the ids match.
- The effective parent is the referenced parent's own parent collapsed upward (`$parent->getParent() ?? $parent`) so chains stay one level deep.

### R4 — Observation translations
- Every submitted translation entry must belong to a submitted tag pair, else HTTP 422 `Translations must belong to a submitted observation.`
- Optimistic concurrency: when the stored translation differs from the submitted `previousKeyTranslation`/`previousValueTranslation`, the save fails with **HTTP 409** `The proposed translation changed. Reload before saving again.`
- A changed translation is stored on the shared `Tag` (key/value → per-locale translations) and resets that node tag's approval to unapproved, re-entering the moderation queue.

### R5 — Frontend store behavior
- `useUserNodesStore`: `load()` fetches the list; failures set `loadFailed` and keep stale data.
- `save(input)` posts the node and upserts it into the local list (key `provider:providerNodeId`); it refreshes related parents/children labels from the saved node; a 409 sets `saveConflict`; any failure sets `saveFailed` and returns `null`.

## Acceptance criteria

- **AC1 (R1):** Given an anonymous visitor, `GET /api/nodes` returns only nodes that are approved **and** (local or carrying an approved tag); their unapproved tags are not in `tags`.
- **AC2 (R1):** Given the editor who created an unapproved observation, the same node appears in their list with that pair in both `tags` and `observations`.
- **AC3 (R2):** Given an editor saving a new `(OSM, n123)` node, the response is 201; saving the same identity again returns 200 with updated coordinates.
- **AC4 (R2):** Given an anonymous visitor, `POST /api/nodes` is rejected (401) and no node is written.
- **AC5 (R3):** Given a parent reference to a non-existent saved id, the response is 422 with `Parent node does not exist.`
- **AC6 (R4):** Given a concurrent editor changed the stored translation since the caller loaded it, the save returns 409 and the frontend shows a conflict.
- **AC7 (R2/R4):** Given a translation entry whose key/value pair is not among the submitted tags, the response is 422.

## Open questions / assumptions

- Creator attribution falls back to an `anonymous@example.com` user when no token is present; in practice saves always come from authenticated editors (R2 gate).
- The admin moderation flow (approve/decline in EasyAdmin) is out of scope; its public contract side effects (visibility, superseded tag cleanup, node auto-approval) are captured above.

## Verification

- `backend/tests/Controller/NodeControllerTest.php`, `NodeCreationTest.php`, `CrossEditorTagTest.php`, `ObservationTranslationTest.php`, `ModerationDeclineTest.php`, `TagApprovalCleanupTest.php` cover the list/save/validation/visibility/approval behavior.
- Not executed in this session; evidence is from code reading.
