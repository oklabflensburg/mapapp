# Street Part Aspects (tag-based cross-sections and routing)

**Status:** Implemented and verified (2026-11-07). This tag-based design supersedes the separate aspect-table model and its earlier verification results.
**Builds on:** street parts (`NodeGroupManager`, `StreetPartArea`), existing tag storage and moderation, and routing via `Routing` custom models and `TagPriorityManager`.
**Related:** [controlled vocabulary](../controlled-vocabulary/spec.md), [editor observations](../editor-observations/spec.md), [shared tasks and historical verification](../tasks.md), [aspect route preferences](../../routing/aspect-route-preferences/spec.md) (extends R5/R6 with match modes).

## Problem / intent

Describe a street part's cross-section as an ordered sequence of elements with independent width, surface and other attributes. Store every attribute as an ordinary tag using `key.type.index.aspect = value`, without extra aspect database tables. The composer and router interpret these tags; tags remain the single source of truth.

## Definitions

- **Street part** — a node group whose ordered members describe a way's course and routing corridor.
- **Cross-section element** — one typed element at an ordered position across the street, such as a footwalk or cycle lane. The former aspect-row concept is a grouping of tags, not a persisted entity.
- **Aspect** — the attribute named in the final key segment, such as `width` or `surface`.
- **Catalog** — allowed types, aspects and values. Numeric attributes use ordered range tokens with separate display labels, not free numeric inputs.
- **Preference definition** — type + aspect + selected raw values + display label. Users rate these definitions using existing tag priorities.

## Requirements

### R1 — Tag storage and key grammar
- Store cross-section attributes through existing `Tag` / `NodeGroupTag` storage, including creator attribution and approval. No separate aspect-instance table, attribute-value JSON column on a street part, or parallel persisted cross-section representation is allowed.
- The exact key format is `<key>.<type>.<index>.<aspect>`. For this street-part feature, `key` is the literal namespace `way`; `type` identifies the cross-section element (e.g. `footwalk`, `cycle_lane`, `parking`, `roadway`); `index` is its positive, 1-based position across the complete cross-section; `aspect` names an attribute (e.g. `width`, `surface`, `lanes`). Example: `way.footwalk.1.width = "<1"`.
- Indexes are global across the cross-section, not counters per type. Tags sharing `(key, type, index)` describe one element. Repeated types use different indexes: `way.footwalk.1.width` and `way.footwalk.5.width` describe independent footwalks. Positions have no geographic left/right semantics.
- Keys have exactly four nonempty dot-separated segments. Type/aspect identifiers are case-sensitive catalog identifiers without dots; indexes use canonical decimal integers (`1`, `2`, …), without signs, zero or leading zeros. Apply existing 255-character key/value limits. Reject malformed reserved `way.` keys, unknown types/aspects, conflicting types at one index within an editor's submitted cross-section, and multiple values for the same full key in that submission.
- Store raw value tokens such as `<1`, `1-2`, `>2`, `asphalt`; display labels such as `< 1 m`, `1 m – 2 m`, `> 2 m` are metadata. Values must come from the aspect's configured choices, subject to the retired-value rule in R4.
- Existing ordinary tags remain valid alongside structured tags. Zero structured tags means an empty cross-section. A row needs at least one attribute tag to exist; do not add an aspect record or sentinel tag for an empty row.

### R2 — Editor and API
- User-facing rows and actions are named “Observation” in English and “Beobachtung” in German. Controls use vue-i18n; type, attribute, value and profile preference labels use the shared XLIFF catalogs and update on language changes.
- Keep the cross-section composer as an ordered list of typed rows. It groups visible structured tags by type/index and sorts indexes numerically; it offers catalog value choices for each aspect.
- Add, insert, reorder, remove and type changes update the corresponding tag keys/values. Within the editable draft, insert/reorder/remove assigns positions `1…n` and rewrites every affected key consistently; type changes discard attributes not defined for the new type. No stale keys from the editor's replaced draft remain after saving.
- Approved tags are read-only under the existing observation rules. Ordinary editors cannot remove, renumber or replace approved content through the composer; structural changes touching it require admin moderation. Other users' tags are preserved.
- `POST /api/node-groups` accepts structured attributes in the existing `tags` array. Responses expose them through existing `tags` and creator-only `observations` arrays, with the same inclusion rules as other tags. There is no separate `aspects` request/response field or aspect ID.
- Example tag entry: `{ "key": "way.footwalk.1.width", "value": "<1" }`. Send these entries together with ordinary tags in the same save; validation and persistence are atomic. Invalid structured tags return HTTP 422 without changing members, tags or other part data; retain the draft and show the save error.
- A tags-only part stays supported. Saving an empty composer removes only the current editor's editable structured tags under the existing tag replacement contract; it preserves ordinary tags and approved/other-user content.
- An empty catalog explains why Add is disabled. Catalog failure shows an error/retry state; reload preserves drafts. A missing/invalid definition file returns HTTP 503 with `{ "error": "editing_vocabulary_unavailable" }` for catalog reads and structured-tag saves, with no partial writes.

### R3 — Approval and visibility through tags
- Structured tags use the existing observation lifecycle: editor additions are unapproved and creator-only; admin additions are approved immediately. Approved tags are public. Existing street-part visibility rules still apply.
- Approval is per full tag key, not per element or separate aspect object. Approving `way.footwalk.1.width` supersedes competing pending values for that exact key on the same part; it does not approve or delete `way.footwalk.1.surface`, `way.footwalk.5.width`, or tags on another part.
- Moderation uses the existing relation-observation queue. A changed value is a new unapproved observation; published content remains read-only for editors. Do not add aspect-specific approval state or a separate aspect moderation queue.
- Reconstruct public cross-sections only from approved tags and the creator's draft view only from tags visible to that creator. Partial approval may publish an element's width before its surface; never infer approval of sibling tags. Conflicting pending values remain separate observations, not silently merged into published data.

### R4 — Definitions and configuration
- Define types, their ordered aspects, raw choices/display labels, and routing predicates without new aspect-specific tables (including type, attribute and preference tables). Use a committed backend JSON catalog for this revision and reuse existing tag metadata/translation and priority storage where applicable.
- Public `GET /api/editing-catalog` exposes the type/aspect choices needed by the composer, identified by raw strings, without database aspect IDs. Frontend choices and backend validation use the same validated catalog. Admin editing of existing tag metadata remains available; a new aspect-catalog admin write UI is outside this revision.
- Retiring a raw value removes it from new choices but preserves stored tags and labels. Existing unchanged creator-owned values may be resaved; replacement values must use current choices. Preferences still referencing retired values keep matching already-stored approved tags. Changing a raw identifier is removal/addition unless an explicit migration maps it.

### R5 — Profile and preference storage
- Keep the existing preference levels: `neutral`, `avoid`, `try_to_avoid`, `prefer`, `strongly_prefer`.
- Expose catalog preference identifiers/labels alongside existing `/api/route-preference-tags` choices. Reuse `/api/tag-priorities` per-user storage and anonymous localStorage; no aspect-preference table is introduced. Preference identifiers are stable and independent of any element index.

### R6 — Routing evaluation
- Match a preference against approved structured tags only: namespace `way`, required type and aspect, value in the definition's selected raw values, at any valid index. A footwalk width preference can match `way.footwalk.1.width` or `way.footwalk.5.width` independently.
- Match parsed segments exactly; `footwalk` must not match another type by substring. Do not compare bin tokens numerically or treat `1` and `10` as the same index.
- Matching parts contribute their existing corridor area to the current GraphHopper custom model. Avoid penalizes inside the corridor without forbidding it; prefer penalizes outside. Parts without matching tags are unaffected; pending tags never influence routing.

### R7 — Reproducible examples
- Ship catalog definitions and an explicitly loaded test/demo street-part fixture using ordinary tags. No aspect-table seeding is required; loading must not alter production map data automatically.
- Define `footwalk`, `cycle_lane`, `parking`, `roadway`, each with width tokens `<1`, `1-2`, `>2` (labels `< 1 m`, `1 m – 2 m`, `> 2 m`) and surfaces `asphalt`, `paving_stones`, `concrete`. Roadway additionally offers lane tokens `1`, `2`, `>2`.
- Supply this five-element cross-section; each cell becomes its own tag (e.g. row 1 is `way.footwalk.1.width = ">2"` plus `way.footwalk.1.surface = "asphalt"`).

| Index | Type | Width token | Surface | Lanes token |
| --- | --- | --- | --- | --- |
| 1 | `footwalk` | `>2` | `asphalt` | — |
| 2 | `cycle_lane` | `1-2` | `asphalt` | — |
| 3 | `parking` | `>2` | `concrete` | — |
| 4 | `roadway` | `>2` | `asphalt` | `2` |
| 5 | `footwalk` | `1-2` | `paving_stones` | — |

- Include the requested narrow-footwalk example `way.footwalk.1.width = "<1"` as an alternate edit. Save/reload must preserve the independent values of both footwalks.

### R8 — Transition from the previous model
- Replace the current separate-entity persistence and `aspects` API field; update composer, moderation, routing, catalog and fixtures together. Do not retain dual writes or require aspect-specific tables for fresh installs.
- If the previous aspect migration has been applied, convert existing rows into tags before removing the old storage, preserving part, creator, approval, type, position and every attribute. Map legacy `sidewalk` to `footwalk` and width labels `< 1 m`, `1 m – 2 m`, `> 2 m` to `<1`, `1-2`, `>2`; preserve preference identifiers/ratings while updating predicate tokens. Preserve retired choices and labels too.
- Unknown mappings or collisions with differing existing tag values must stop conversion with an actionable report; never silently overwrite or publish data. Conversion must be transactional and safe to rerun. Remove old tables only after conversion/parity checks pass.
- Legacy clients submitting `aspects` receive an explicit validation error and no write; never silently ignore that data. Existing clients using ordinary tags continue to work, subject to normal tag replacement semantics.

## Acceptance criteria

- **AC1 (R1/R2):** Saving and reloading the R7 cross-section through `tags` reconstructs the same ordered rows, raw values and display labels, without aspect records or an `aspects` payload.
- **AC2 (R1/R2):** `way.footwalk.1.width=<1` is accepted when configured; zero/leading-zero indexes, missing/extra segments, unknown type/aspect, invalid values and conflicting submitted keys return 422 with no partial save.
- **AC3 (R3):** Pending structured tags are visible only to their creator, and part visibility follows existing relation rules. Anonymous/other-user responses contain no private aspect values.
- **AC4 (R3):** Approving `way.footwalk.1.width` publishes only that tag and cleans up same-key pending competitors on that part. Surface, index 5 and other parts are untouched; editor changes re-enter moderation.
- **AC5 (R4/R5):** A user can rate a catalog footwalk-width preference `prefer` and retrieve it later via existing priority storage, without aspect-specific tables.
- **AC6 (R6):** A preference selecting width `>2` matches an approved `way.footwalk.5.width = ">2"`, but not a pending tag, a roadway width, or a footwalk width `<1`; matching corridors affect the route custom model.
- **AC7 (R6):** `avoid` penalizes matching corridor areas without forbidding them.
- **AC8 (R4):** Retiring `1-2` preserves stored values/labels and existing preference matches, prevents new selections, and permits unchanged creator-owned resaves.
- **AC9 (R1/R2/R7):** The supplied five-element fixture loads through normal tag persistence; editing the first footwalk leaves index 5 unchanged. Reordering editable rows rewrites all affected keys and leaves no stale draft keys.
- **AC10 (R1/R4/R8):** A fresh installation supports catalog, save/reload, moderation and routing without any aspect-specific database tables. Existing ordinary tags still work.
- **AC11 (R8):** Conversion preserves attribution, approval, order, attributes and preference ratings; reruns duplicate nothing. Conflicting/unknown data aborts without data loss. Legacy `aspects` submissions fail explicitly without a partial write.
- **AC12 (R2/R3):** An ordinary editor cannot remove or renumber approved tags; approving only width never publishes a pending surface or routes using that surface. Catalog failure retains the draft and writes nothing.

## Assumptions and scope

- Retain the previous global cross-section position semantics for `index`; it is not a per-type counter. Only the `way` namespace is defined here; buildings and other namespaces are future work.
- File-backed aspect definitions replace the prior dedicated admin catalog tables in this revision. Whole-way vocabulary stage 2 remains a separate future feature, not a reason to add aspect tables.
- Bin boundaries remain catalog documentation, not computed numeric intervals. Display labels use the existing translation/fallback mechanisms.
- Geographic placement/shading of elements is outside scope. Ordinary street-part tags and whole-way vocabulary remain supported independently.

## Verification

- **Passed — documentation:** local links, whitespace, unique requirement/acceptance IDs and requirement references checked across the five updated files; `git diff --check -- specs` passed.
- **Passed — AC1–AC12:** updated HTTP, catalog, conversion/fresh-install and browser checks; see the [current verification record](../tasks.md). Earlier entity-model results are historical only.
- Backend checks must cover tag grammar/atomic saves, visibility and exact-key moderation, tag-derived routing, retired choices, conversion parity/conflicts and installation without aspect tables.
- Browser checks must cover composer tag serialization, numeric ordering, independent repeated types, reordering, approved read-only behavior, save/reload, catalog errors and profile levels.
- Conversion and removal migration were applied to the local development and test databases. The original prototype migration is a no-op for fresh installs; the removal migration refuses to proceed without catalog/tag parity. No live GraphHopper request was made; corridor matching and custom-model factors were tested.
