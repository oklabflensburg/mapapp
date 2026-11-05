# Node Groups API (relations / street parts)

**Status:** As-built spec, reverse-engineered from the existing implementation.
**Code:** `backend/src/Controller/NodeGroupController.php`, `backend/src/Service/NodeGroupManager.php`, `backend/src/Service/StreetPartArea.php`, `backend/src/Api/SaveNodeGroupRequest.php`, `backend/src/Api/NodeGroupResponse.php`, `src/stores/nodeGroups.ts`
**Scope:** Creating and listing "relations": ordered node groups that mark the course of a way — e.g. the tagged part of a street — whose area is the corridor around the member line. (The UI calls these relations; the domain name is node group / street part.)

## Problem / intent

Editors mark a stretch of a street (between two or more nodes) and tag it as one object. The tags live on the whole part and the group's `area` is a polygon corridor computed around the straight-line course through the ordered members, so the frontend can shade the affected street part and routing can avoid/prefer it.

## Requirements

### R1 — List relations
- `GET /api/node-groups` is public.
- Relations are ordered by most recent update.
- Visible to a caller when any of the group's tags is approved, or the caller created the relation (mirroring the pending-review rule for nodes).
- Response: array of `NodeGroupResponse`: `id`, `name`, `members` (ordered `RelatedNodeResponse` objects: `{id, provider, providerNodeId, name, tags, latitude, longitude}`), `area` (GeoJSON polygon geometry of the corridor, or `null` when the group has fewer than two members), `tags`, `observations` (caller's unapproved pairs), `approved` (whether **any** group tag is approved), `createdAt`, `updatedAt`, `parents` (distinct parents of the member nodes, as `RelatedNodeResponse`).
- Tag visibility matches the nodes rule: approved tags for everyone; unapproved only for their creator.

### R2 — Save relation (create or update)
- `POST /api/node-groups` requires `ROLE_EDITOR`; otherwise 401/403.
- Body (`SaveNodeGroupRequest`): `id` (optional positive int; set = update), `name` (≤255 chars; `null` keeps the current name, a trimmed-to-empty string clears it), `members` (ordered list), `tags` (key/value pairs, same validation as node tags).
- Creating requires **at least two members**, else 422 `An area needs at least two points.`
- Updating a non-existent `id` → 422 `Node relation does not exist.`
- Members are either `{"id": <saved node id>}` (must exist, else 422 `Relation member node does not exist.`) **or** an OSM reference `{"providerNodeId", "latitude", "longitude"}` (auto-creates an `osm`-type OSM node when unknown). Supplying both, neither, a non-positive/id-type-unsafe id, an empty >64-char feature id, or out-of-range coordinates → 422 with the matching violation message.
- Tag replace semantics are per user (the caller's own additions are replaced; other users' tags preserved), and an admin's own additions are immediately approved; duplicate key/value pairs are tolerated.
- Runs in one transaction; responds 201 for a new relation, 200 for an update, both with the `NodeGroupResponse`.

### R3 — Area geometry
- The `area` is a GeoJSON polygon geometry (`{type: "Polygon", coordinates: …}`) computed by `StreetPartArea` as the corridor around the member line, and `null` when fewer than two usable members exist.

### R4 — Frontend store behavior
- `useNodeGroupsStore`: `load()` fetches the list (failures set `loadFailed`); `save(input)` posts and upserts by group id (failures set `saveFailed`); `byMember` maps `provider:providerNodeId` of every member to the groups containing it.

## Acceptance criteria

- **AC1 (R1):** Given an anonymous visitor, only relations with at least one approved tag are listed; the creator also sees their own unapproved relations.
- **AC2 (R2):** Given an editor creating a relation with one member, the response is 422 `An area needs at least two points.`
- **AC3 (R2):** Given a create with two OSM references (`providerNodeId` + coordinates), the response is 201 with an `area` polygon built from the two member coordinates.
- **AC4 (R2):** Given a member entry containing both `id` and `providerNodeId`, the response is 422 `Specify a saved node ID or an OSM reference, not both.`
- **AC5 (R2):** Given an update posting an existing relation id with `name: null`, the relation's previous name is unchanged; with `name: "  "` it is cleared.
- **AC6 (R2, tag rules):** Given a non-admin editor adds `{key: "tactile_paving", value: "yes"}`, the tag appears in `observations` (unapproved) until moderated; an admin's save shows it in `tags` immediately.

## Open questions / assumptions

- Exact corridor width and geometry algorithm live in `StreetPartArea`; this spec pins only that a polygon (or `null`) is returned.
- Removing members on update: the current API adds/resolves members; removal semantics are not exposed by the request shape (only `id` reference adds). The frontend treats saves as full updates of the returned group.

## Verification

- `backend/tests/Controller/NodeGroupControllerTest.php` covers list/save/visibility/area behavior.
- Not executed in this session; evidence is from code reading.
