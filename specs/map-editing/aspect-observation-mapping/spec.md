# Aspect Observation Mapping (admin update-vs-add decision)

**Status:** Implemented and verified (2026-11-08).
**Code:** `backend/src/Service/AspectObservationApproval.php` (mapping targets, effective tags, publish/replacement), `backend/src/Controller/Admin/TagCrudController.php` (`approveNodeGroupTag` mapped branch, `reviewTranslations` effective-tag factory), `backend/src/Controller/Admin/PendingRelationObservationCrudController.php` (review context), `backend/templates/admin/_unapproved_tags.html.twig` (publish-target select), `backend/templates/admin/pending_relation_observation_edit.html.twig` + `_review_styles.html.twig` (approved cross-section). Tests: `backend/tests/Controller/AspectObservationMappingTest.php`.
**Related:** extends [street-part-aspects](../street-part-aspects/spec.md) (R3 moderation), [backend-admin-panel](../../administration/backend-admin-panel/spec.md) (R5–R7 review and approval), [editor-observations](../editor-observations/spec.md) (R5–R6 lifecycle). Situated in the moderation flow; no editor-side changes.

## Problem / intent

Once a street part has approved cross-section elements, the editor composer locks the structure: a new observation always arrives as a *new proposed element* at the next free index (`way.footwalk.3.width = "&gt;2"`) — an editor cannot express "the existing footwalk 1 is actually wider than the approved value says". Today the admin can only approve the pending observation as submitted (publishing a third footwalk) or decline it. There is no way to publish it as a *correction of an existing element* — replacing the approved `way.footwalk.1.width = "&lt;1"` with `"&gt;2"`.

The admin review must therefore let an admin decide, per pending structured observation, whether approval **adds a new element** or **updates an existing element of the same type**.

## Definitions

- **Proposed key** — the exact key the contributor submitted, e.g. `way.footwalk.3.width`.
- **Effective key** — the key under which the observation is actually published: the proposed key (add case) or `way.<type>.<targetIndex>.<aspect>` (update case).
- **Existing element** — a cross-section element `(type, index)` on the same street part that has at least one **approved** structured tag.
- **Mapping** — the admin's choice of the effective key for a pending structured observation, made as part of the approve action.

## Requirements

### R1 — Review context for structured observations
- On an area (node group) review screen, pending structured `way.*` tags are presented together with the street part's current **approved cross-section**: elements grouped by `(type, index)`, showing their approved aspect values with display labels.
- Non-structured pending tags and node (point) reviews are presented exactly as before; this feature adds context only for structured keys.

### R2 — Mapping choice per pending aspect tag
- Each pending structured tag row (grouped as today by identical key/value) carries a target choice applied at approval:
  - **Add as new element (default):** publish under the proposed key, exactly as today.
  - **Update existing element:** choose one existing element of the **same type** (e.g. `footwalk 1 (width <1, surface asphalt)`); publish under `way.<type>.<targetIndex>.<aspect>`.
- The target list contains only existing elements of the same type; there is no free-form index input, renumbering, or cross-type mapping in this revision. The granularity is the aspect tag: each pending key/value row is mapped independently, matching today's per-tag approval granularity.
- Decline is unchanged: it ignores the mapping and deletes all unapproved assignments of the proposed shared tag on this record.
- Editors cannot flag intent from the composer; the admin infers update-vs-add from the submitted value against the approved cross-section. This is a agreed product decision, not a gap.

### R3 — Approve as new element
- Behavior is identical to today's approval: the tag is published under the proposed key, all other unapproved assignments with the same proposed key on this record are superseded/removed, and competing approved content is untouched.
- The proposal's index already avoids collisions: the composer assigns `max index + 1` over all visible rows, and approved structure locks renumbering, so approve-as-submitted cannot overwrite or join a different approved element.

### R4 — Approve as update of an existing element
- The pending observation is published under the **effective key** `way.<type>.<targetIndex>.<aspect>` with the observed value, approved immediately, keeping the **original contributor's attribution**.
- The previously approved assignment of that exact effective key on this record — if any — is **replaced: deleted without history** (no rejection record, no unapproved remnant), consistent with the panel having no history. Other aspects of the target element are untouched; approving a `width` update never changes the element's `surface`.
- All unapproved assignments with the **proposed** key on this record are superseded, exactly as a normal approval of that key would do. Unapproved assignments already carrying the **effective** key on this record are superseded by the approval of the effective key (same rule as any approval).
- If the target element has no approved value for that aspect yet, approval **adds** the aspect to the target element ("this observation describes element X") without creating a new element.
- If the resulting effective key/value already exists as an approved assignment with the same contributor, the action reduces to superseding the pending proposal (no duplicate approved assignment is created).

### R5 — Concurrency and action semantics
- Approve and decline remain admin-only POST actions with CSRF protection; missing assignment IDs return 404 and invalid tokens are rejected, as today.
- A mapping becomes **stale** if, since rendering, the target element's aspect value changed (or the target element no longer has approved tags): the action returns HTTP 409 with nothing changed, and the admin re-reviews with fresh data. This extends today's approved-since-render conflict rule, which remains in force unchanged.

### R6 — Translation review interacts with the mapping
- When the admin approves structured tags through the combined observation/translation review form, identifier and translation changes publish under the **effective** (mapped) key, never the proposed key. On an update, catalog entries for the proposed `way.<type>.<n>.<aspect>` key identity are not created.
- The review form offers only Approve and Decline (no draft save): Approve saves the reviewed fields and publishes together with the approval, and an implicit submission changes nothing. Decline stays independent of the mapping and of field validity.

### R7 — Visibility after the decision
- After approval (either mode), the contributor no longer sees their pending observation under the proposed key; everyone — anonymous included — sees the published tag under the effective key, subject to existing street-part visibility rules.
- Approved content remains read-only for editors, including for the contributor of the superseding observation.
- Replacing a formerly approved value (e.g. `<1`) leaves no trace for anyone: it is gone from lists, API and routing, per R4.

## Acceptance criteria

- **AC1 (R1):** Given an area with approved `way.footwalk.1.width = "<1"` and `way.footwalk.2.width = "1-2"` plus a pending `way.footwalk.3.width = ">2"`, its review screen shows the approved footwalk elements with their values alongside the pending observation.
- **AC2 (R3):** Given the above, approving as new element publishes exactly `way.footwalk.3.width = ">2"`; footwalks 1 and 2 keep `<1` and `1-2`; competing pending `way.footwalk.3.width` values from other contributors are removed.
- **AC3 (R4):** Given the above, approving with target `footwalk 1` publishes one approved `way.footwalk.1.width = ">2"`, deletes the approved `"<1"` assignment without remnant, leaves footwalk 2 (`1-2`) untouched, and creates no `footwalk.3` tag; an anonymous API response shows `">2"` and no trace of `"<1"`.
- **AC4 (R4):** Given existing `way.footwalk.1.surface = "asphalt"` and a pending `way.footwalk.2.width = ">2"` mapped to `footwalk 1`, the result is one element with `surface = "asphalt"` and `width = ">2"`; footwalk 2 disappears entirely, and no pending `footwalk.2.width` remains.
- **AC5 (R4):** Given the AC3 setup with a second contributor's pending `way.footwalk.3.width = "1-2"`, approving the `">2"` observation as update of `footwalk 1` also removes that second pending proposal from the queue.
- **AC6 (R2):** Given a pending structured observation, the target list offers only existing approved elements of the same type plus add-as-submitted; a pending observation cannot be mapped onto a plain node or a non-structured tag.
- **AC7 (R5):** Given the target's approved width changed after the review screen rendered, the mapped approval returns 409 and changes nothing; the same stale rule as today applies to plain approvals.
- **AC8 (R6):** Given an update approval with edited German labels, the published label is stored under `way.footwalk.1.width`; no catalog entry for `way.footwalk.3.width` is created.
- **AC9 (R2):** Given the pending observation is declined, it is deleted for all contributors as today, regardless of a chosen mapping, and no approved content changes.
- **AC10 (R7):** Given AC3 happened, the contributor's saved draft no longer offers the published key as an editable observation (it is read-only approved content everywhere).

## Assumptions and scope

- Structured `way.*` tags live on street parts (node group areas); this spec changes only the **area review** flow. Node reviews, ordinary tags and routing evaluation (which reads approved tags) need no changes.
- The target choice lists only elements with at least one approved tag; a fully pending element cannot be a mapping target (same-index pending proposals already merge naturally when approved as submitted).
- Replacing an approved assignment is administrative; only the admin initiates it, so there is no editor-triggered replacement path and no notification/notification-history requirement.
- Future options deliberately out of scope: renumbering approved elements, cross-type remapping, editor-flagged corrections, and merge/history records. Indexes retain their sequence semantics (no geographic left/right meaning).
- The proposed index cannot collide with an approved element of a different type in practice (composer assigns `max+1`, structure locked); no conflict handling is specified for it.

## Implementation notes

- The mapping rides on the existing approve action: `POST /admin/node-group-tag/{id}/approve` with a top-level `target_index` field whose value is the JSON payload of the selected review-screen option (`{"index":"1","aspectValue":"<1"}`) — the empty default option approves as submitted. The field stays outside the translation form's namespace, so the form's validation is unaffected.
- `AspectObservationApproval` keeps the decision logic: target options are existing approved elements of the same type; the payload's `aspectValue` snapshot enables the 409 staleness check; `effectiveTag()` finds or creates (unpersisted until publication succeeds) the shared tag under the effective key, seeded with the observation's translation proposals; `apply()` mirrors a plain approval's contributor handling and removes superseded/replaced assignments, with the Doctrine `TagApprovalListener` idempotently covering same-key cleanup on flush.
- Editors and the composer are untouched: the structure lock and next-free-index behavior remain the constraint that makes the admin decision necessary (see [street-part-aspects](../street-part-aspects/spec.md) R2).

## Verification

- **Passed — AC1/AC6 (review context):** `testReviewShowsApprovedCrossSectionAndSameTypeTargetsOnly` — approved cross-section table renders with element summaries; the publish-target select offers footwalk 1 and footwalk 2 with their approved values, never the roadway element.
- **Passed — AC2 (add as submitted):** `testApproveAsSubmittedStillAddsTheProposedElement` — default approval publishes `way.footwalk.3.width` with prior behavior; approved footwalks untouched.
- **Passed — AC3/AC5 (update replaces without history):** `testApproveAsUpdateReplacesApprovedValueWithoutHistory` and `testApproveAsUpdateSupersedesCompetingPendingProposalOfProposedKey` — one approved `way.footwalk.1.width=">2"` replaces `"<1"`, `surface` and footwalk 2 survive, footwalk 3 never publishes, another editor's competing pending proposal is superseded.
- **Passed — AC4 (missing aspect):** `testApproveAsUpdateAddsMissingAspectOfTarget` — mapping a pending surface onto footwalk 2 adds the aspect to the element; the element's width survives.
- **Passed — AC7 (staleness):** `testStaleTargetIsRejectedWithoutChanges` — a changed target value returns 409 with the observation still pending and no data changes.
- **Passed — AC8 (translations):** `testMappedApprovalPublishesTranslationsUnderEffectiveKeyOnly` — with the catalog service swapped to a throwaway copy (as in `ObservationTranslationTest`), reviewed German labels publish under `key:way.footwalk.1.width` and no catalog unit is created for the proposed `way.footwalk.3.width`.
- **Passed — invalid scope:** `testMappingTargetOnUnstructuredObservationIsRejected` — a mapping payload on a plain tag (`lit`) returns 400 and changes nothing.
- **Passed:** complete backend suite `docker compose exec php vendor/bin/phpunit` — 155 tests, 1194 assertions (includes all prior moderation, visibility, decline and translation tests). `lint:container` and `lint:twig` on the changed templates; `git diff --check`.
- **Not performed:** manual browser walkthrough of the review screen styling (covered functionally by the markup assertions), and a live routing check (routing reads approved tags unchanged; corridor matching is covered by the existing suite per [street-part-aspects](../street-part-aspects/spec.md)). AC9 (decline) and AC10 (visibility) rest on the unchanged existing contracts exercised by `ModerationDeclineTest`/`CrossEditorTagTest`; a decline ignores the mapping because it only reads the shared tag, not the request field.

## Planned checks (retained from the design)

- Backend tests must cover mapping approval paths for add and update, replacement-without-history, same-key supersession of proposed and effective keys, stale-target 409, translation publishing under the effective key, decline independence, and anonymous/creator visibility of the outcome.
- Browser checks of the area review screen: approved cross-section context, the same-type target list, CSRF/409 flows; decline unchanged.
- Routing spot-check: an approved footwalk-width preference evaluating `>2` matches the element after an update and no longer matches `<1`.
