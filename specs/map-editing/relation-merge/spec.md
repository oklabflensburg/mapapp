# Relation Merge (admin decision for overlapping relations)

**Status:** Implemented and verified (2026-11-11).
**Code:** `backend/src/Service/NodeRelationMerge.php` (candidate detection, conflict listing, snapshot, atomic merge application), `backend/src/Controller/Admin/PendingRelationObservationCrudController.php` (review context + `merge` action), `backend/templates/admin/pending_relation_observation_edit.html.twig` (merge decision forms). Tests: `backend/tests/Controller/RelationMergeTest.php`.
**Related:** builds on [way-observations](../way-observations/spec.md) (whole-way identity), [street-part-aspects](../street-part-aspects/spec.md) (street parts, corridor areas, structured tags), [aspect-observation-mapping](../aspect-observation-mapping/spec.md) (the review-time update-vs-add precedent this feature extends to whole relations), [editor-observations](../editor-observations/spec.md) (visibility and approval lifecycle), [backend-admin-panel](../../administration/backend-admin-panel/spec.md) (R5–R7 review and approval).

## Problem / intent

Two editors independently mark parts of the same street: user 1 creates a node relation whose member nodes have a shared OSM way node as parent, tagging the covered part. User 2 later creates a second relation covering an **overlapping** part of the same way, assigning their own tags. Today the map renders two intersecting corridors, and routing sees two independent tag sets for the same physical place — with no way to combine them, since an editor cannot touch another editor's relation.

The two relations must be **merged into one** relation carrying the union of members and tags. The merge is detected by the system and decided by an **admin in the observation approval flow** (the pending-relation review screen), mirroring the update-vs-add decision of [aspect-observation-mapping](../aspect-observation-mapping/spec.md) — but at whole-relation granularity, with the admin also choosing **which relation survives**.

**Out of scope:** editor-triggered merges from the map UI (the admin flow is the only merge path in this revision; an editor-side proposal can be added later on top), notification history for merges, and merging relations that merely touch end-to-end without corridor overlap.

## Definitions

- **Street part (relation)** — a `NodeGroup` whose ordered members mark the course of a way; its **corridor** is the fixed-width area around the member line (see `StreetPartArea`, 1 m half-width).
- **Overlap** — two relations (a) share at least one **parent way node** (the `parent` of one of member A's nodes equals the parent of one of member B's nodes), and (b) their corridor areas geometrically intersect. Two relations on crossing ways with intersecting corridors do **not** overlap.
- **Merge candidate** — a relation with at least one **approved** tag that overlaps the relation under review. Fully pending relations are never candidates (the absorbed side of a merge is always the pending one or the reviewed one).
- **Surviving relation** — the relation the admin chooses to keep; the other is **absorbed**: deleted after its members and tag rows move onto the surviving relation.
- **Competing keys** — a tag key (exact key, e.g. `surface` or `way.footwalk.west.1.width`) that appears on both relations with differing value rows. Competing keys require an explicit admin resolution per row; identical `(tag, contributor)` rows on both sides are not competing (the surviving relation's row simply wins and the absorbed row is dropped — required by the unique constraint `(group, tag, user)` anyway).

## Requirements

### R1 — Overlap is detected, not proposed
- Saving a relation never blocks or warns: the second relation saves exactly as today and appears in the pending review queue as usual. Editors cannot see, flag, or trigger a merge.
- Overlap is computed **at review time** from the two relations' member parents and corridor geometries (PostGIS); there is no persisted merge state. If members or tags changed since, the recomputation decides — a relation that stopped overlapping (e.g. edited members) simply disappears from the candidate list, and a stale merge attempt is rejected (R5).
- Detection requires each relation to have its corridor geometry (≥ 2 members); relations without one are never candidates.

### R2 — The review screen offers the merge decision
- On the pending-relation review screen, every merge candidate is listed with a distinct merge form: candidate name/id, its approved tag summary, and a **target choice** — which of the two relations survives:
  - **Merge into candidate** — the reviewed pending relation is absorbed and deleted; its members and tags move onto the candidate.
  - **Merge into this relation** — the candidate is absorbed and deleted; its members and approved tags move onto the reviewed relation.
  The admin picks the target (agreed product decision); there is no fixed older-wins rule.
- The section renders only when candidates exist; a review without candidates looks and behaves exactly as today.
- Merging is an admin-only POST action with CSRF protection, separate from the per-tag approve/decline actions. It does **not** approve any pending tag by itself: absorbed pending rows stay pending on the surviving relation and the review continues there.

### R3 — Tag conflicts are resolved by the admin during the merge
- For every **competing key**, the merge form shows the rows from both relations (key, value, contributor, approved state) and the admin explicitly selects which rows survive. Within a competing key:
  - A checked row on the absorbed side moves onto the surviving relation, keeping its **contributor attribution and approval state**. Keeping an absorbed row whose exact key also has a checked-or-approved row on the **surviving** side is an **element replacement**: the surviving side's rows of that exact key are deleted without history (same replacement semantics as aspect-observation-mapping R4) unless the admin explicitly kept them via a competing selection that excludes the absorbed row.
  - Unchecked rows on the absorbed side are discarded with the absorbed relation; unchecked ones on the surviving side are deleted without history.
  - **Non-competing keys** move automatically without a decision: absorbed rows whose key is absent on the surviving relation move (attribution and approval state preserved); rows whose `(tag, contributor)` already exist on the surviving relation are dropped.
- Structured `way.<type>.<direction>.<index>.<aspect>` keys follow the same exact-key rule. As a consequence: distinct aspects of the same `(type, direction, index)` merge naturally (the absorbed `way.footwalk.west.1.surface` joins the surviving west footwalk 1 when only the width existed there), while the same aspect-key on both sides competes and must be resolved. Re-indexing or redirecting absorbed elements onto surviving element positions is **not** offered (admin infers from values what belongs together; out of scope like cross-index remapping in aspect mapping).
- The form pre-selects approved rows and leaves pending competitor rows unchecked; the admin can change every selection.
- Approved rows win over same-key pending rows on the merged relation: moving an approved row onto the surviving relation supersedes the survivor's pending rows of that exact key through the existing Doctrine approval listener — the same precedence every approval applies, regardless of the admin's checkbox.

### R4 — Members are merged in along-way order
- The surviving relation ends up with the **union** of member nodes: a member already present (same node) is never duplicated.
- New members from the absorbed relation are inserted at their **along-way position**: linear-referenced against the surviving relation's ordered member polyline (PostGIS location-along-line of each new member's coordinates), then the positions are renumbered consecutively. Members beyond either end are prepended/appended; equal positions keep the absorbed relation's internal order. (The backend has no OSM way geometry; the surviving member line is the reference because both corridors overlap, so the polyline orders the union consistently.)
- The resulting corridor area (recomputed as always from the member line by `StreetPartArea`) covers the union of both parts.

### R5 — Merge application is atomic and staleness-checked
- Applying a merge happens in one transaction: member union + reordering, tag moves, conflict resolutions, absorbed relation deletion, surviving relation `touch()`. Nothing is written if any step fails.
- The merge payload carries a snapshot of both relations' member node ids and conflicting tag row ids (with approved states) from render time. On submit, any mismatch — changed members, changed tags, relation deleted — returns **HTTP 409 with nothing changed**, and the admin re-reviews with fresh data. This extends the observation flow's approved-since-render conflict rule.
- Missing relation or candidate ids return 404; invalid payloads return 400 without changes; non-admins get 403; missing/invalid CSRF tokens are rejected, as today.

### R6 — State and visibility after the merge
- The surviving relation keeps its own `createdByUser` and name; if it has no name and the absorbed relation does, it adopts the absorbed name.
- The absorbed relation id is gone: it no longer appears in the groups API, the pending queue, or candidate lists; saving against the stale id fails as today ("Node relation does not exist.", 422).
- Both contributors' visibility follows the existing rules: approved rows are public (including anonymous); pending rows are visible only to their contributor; contributors keep their edit rights the absorbed relation gave them against the surviving relation. The contributor of the absorbed relation sees their still-pending rows on the surviving relation as usual.
- The frontend needs no change of its own: the groups list shows the merged relation (larger corridor, merged tags) after the next fetch; routers match the union corridor as before.
- Routing reads approved tags only; a merge never publishes a tag on its own (R2), so routing behavior changes only when the admin approves the moved pending rows in the follow-up review.

## Acceptance criteria

- **AC1 (R1/R2):** Given two relations whose member nodes share a parent way node and whose corridors intersect, the review screen of the pending one lists the approved one as a merge candidate with a target choice; given two relations on crossing ways, or sharing a way with disjoint corridors, no candidate is listed and the review renders as today.
- **AC2 (R3):** Given user 1's approved `surface=asphalt` and user 2's pending `surface=paved` on overlapping relations, the merge form shows both rows under one competing key; selecting the absorbed `surface=paved` and merging moves it (pending, attributed to user 2) onto the surviving relation — approving it later is a separate step.
- **AC3 (R3):** Given both relations carry an **approved** `surface` with different values and the admin selects only user 1's, the merged relation has exactly one approved `surface=asphalt` from user 1 and no trace of the other value — for anyone, including API and the pending queue.
- **AC4 (R3):** Given the absorbed relation has `way.footwalk.west.1.surface=asphalt` (approved) and the surviving has approved `way.footwalk.west.1.width="<1"` and no surface, the merged relation has one footwalk west 1 with both aspects and both attribution rows, without any admin decision on these keys.
- **AC5 (R4):** Given relation A covers nodes 1–3 of a way and overlapping relation B covers nodes 2–5, the merged relation contains nodes 1–5 exactly once each, in along-way order, with at most one assigned corridor geometry covering the union, and the member count is 5.
- **AC6 (R3/R5):** Given identical `(tag, contributor)` rows exist on both relations, merging keeps the surviving relation's row once — no unique-constraint failure, no duplicate.
- **AC7 (R5):** Given the candidate's members or a conflicting tag changed since the review screen rendered, submitting the merge returns 409 and changes nothing in either relation.
- **AC8 (R2/R6):** Given the reviewed pending relation is absorbed, it is gone from groups list and queue; its still-pending tags remain pending and visible to their contributor on the surviving relation, and the queue shows the surviving relation for the follow-up review.
- **AC9 (R6):** Given a merge with the pending relation surviving, it keeps appearing in the queue until its pending tags are resolved; the absorbed candidate's approved tags are public immediately under their original attribution.
- **AC10 (R2):** A merge action without admin role returns 403; without valid CSRF token it is rejected; both change nothing.
- **AC11 (R1):** Saving user 2's overlapping relation succeeds with today's semantics (no warning, no queue behavior change); the overlap affects only the admin review screen.

## Assumptions and scope

- Both relations in the user's scenario cover one way each (shared parent). Relations spanning multiple ways can still merge when any parent way node is shared and corridors intersect; multi-way ordering is not modeled specially (the reference polyline rule of R4 covers it).
- Only approved-carrying relations are candidates, per the definitions. Merging two fully pending relations by the same user is unnecessary (the creator can edit freely); merging two approved relations without a pending trigger cannot be initiated in this revision — a queue entry is the only merge entry point.
- Competing-key resolution is per exact key and per row; element-level (type, direction, index) re-assignment of structured tags is deliberately out of scope, consistent with aspect mapping's no-cross-type-mapping decision. Out of scope as well: merging more than two relations in one action (re-run for multiple candidates), notification/audit history for merges (`updatedAt` changes on the surviving relation only), and end-to-end touching relations (no corridor intersection).
- The surviving relation's members' parent ways are not re-derived; `parents` in the API result keep appearing from members as before.

## Implementation notes

- Candidate detection is one SQL pass (approved tag, shared parent way node via the members' `parent_id`, ≥ 2 members) plus a per-candidate corridor `ST_Intersects` of the 1 m buffered member lines, mirroring the geometry of `NodeGroupRepository::findAreaGeometriesByTagKeys`; nothing is persisted.
- Along-way ordering: new members are linear-referenced against the surviving relation's member line (PostGIS `ST_LineLocatePoint`); points clamped beyond either end are extrapolated along the first/last segment direction, so a member in front of the line sorts before it. Surviving members win fraction ties; the absorbed relation's internal order applies after that.
- Member positions are immutable (`NodeGroupTag`-style readonly column on `NodeGroupMember`), so the union rebuilds the member rows; the deletions flush inside the transaction before the inserts, because Doctrine inserts before it deletes and `(group, node)` must never exist twice mid-flight.
- The merge action rides on `POST /admin/node-group/{id}/merge/{candidateId}` with CSRF token `merge_{id}_{candidateId}`, `merge_target` (`candidate`/`reviewed`), `merge_keep[]` row ids and the hidden `merge_snapshot` (member ids in order plus every tag row id with tag id and approved state, sorted canonically). The controller routes pending follow-up reviews back onto the surviving relation's review screen.
- Conflicts are computed at render and at apply time from the same rule; the apply-time recomputation validates the `merge_keep` selection (unknown ids → 400) after the snapshot comparison (mismatch → 409) — both before any change.

## Verification

- **Passed — AC1 (detection):** `testReviewListsOnlyOverlappingApprovedRelations` — the shared-parent, corridor-intersecting approved relation is the only candidate; relations 30 m away, on a crossing way with intersecting corridors, or fully pending are never offered, and the form carries both targets, snapshot and CSRF inputs; both `surface` rows appear with approved-only defaults, while the one-sided `lit` key never competes.
- **Passed — AC2/AC5/AC8:** `testMergeIntoCandidateMovesPendingTagsAndMembers` — merging into the approved candidate yields the six-member union in along-way order (the `before` node in front, `after` behind), keeps the selected pending `surface` and the one-sided `lit` rows with attribution and pending state, deletes the absorbed relation, and saving against its stale id returns 422.
- **Passed — AC9/R6:** `testMergeIntoReviewedAbsorbsApprovedCandidate` — merging into the reviewed pending relation absorbs the approved candidate: its approved `surface` survives with the original attribution, both pending rows remain pending and private, the follow-up review renders on the surviving relation, and the absorbed id left the groups API.
- **Passed — AC3:** `testCompetingApprovedValuesResolveToTheSelectedRow` — with approved values on both sides, keeping only one leaves exactly one approved `surface=asphalt` with no trace of the other.
- **Passed — AC4:** `testDifferentAspectsOfTheSameElementMergeWithoutDecision` — an absorbed `way.footwalk.west.1.surface` joins the surviving `way.footwalk.west.1.width` without any checkbox, while the plain competing `surface` key resolves through the defaults. (Keys updated to the direction grammar and re-verified 2026-11-11; see [street-part-aspects](../street-part-aspects/spec.md) R1.)
- **Passed — AC6:** `testIdenticalContributorRowsNeverDuplicate` — the identical `(tag, contributor)` pair never surfaces in the decision, resolves to one surviving approved row, and hits no unique-constraint failure.
- **Passed — AC7:** `testStaleSnapshotIsRejectedWithoutChanges` — a candidate tag added after rendering makes the submit return 409 with both relations' members and tags untouched.
- **Passed — AC10:** `testMergeRequiresAdminRoleAndCsrfToken` — anonymous users hit the login wall, editors receive 403, and an admin with an invalid CSRF token changes nothing.
- **Passed — R5:** `testInvalidMergePayloadsAreRejected` — an unknown target or an unknown competing-row id returns 400 in one transaction with both relations unchanged.
- **Passed — AC11/R1:** `testSavingOverlappingRelationIsUnaffected` — a third editor saves another overlapping relation through the regular API without warnings (today's semantics), and the untouched pair's review screen still offers the merge.
- **Passed:** complete backend suite `docker compose exec php vendor/bin/phpunit` — 165 tests, 1288 assertions (includes all prior moderation, aspect-mapping, visibility and routing tests). `lint:container`, `lint:twig` on `templates/admin/`, `php -l` on the new files, `git diff --check`.
- **Not performed:** manual browser walkthrough of the review screen styling (covered functionally by the markup assertions; the section follows the existing review-screen patterns), and a live GraphHopper routing check (routing reads approved tags and union corridors through unchanged contracts).
- **Assumptions rechecked:** overlaps are review-time only — nothing in the editor flow changed; the frontend needed no change, the merged relation reaches the map through the existing groups API.

## Planned checks (retained from the design)

- Browser checks of the merge section styling in a development instance when the review screen gets its next UI pass.
