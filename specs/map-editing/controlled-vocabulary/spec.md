# Controlled way-observation vocabulary

**Status:** Whole-way stage 1 implemented and verified; stage 2 remains deferred. R5/AC10 examples now use tag-based cross-sections and are verified by the updated HTTP/browser checks.
**Related:** [Way observations R5](../way-observations/spec.md), [street-part aspects](../street-part-aspects/spec.md), [tag labels](../../tag-settings/api-tag-settings/spec.md), [admin panel](../../administration/backend-admin-panel/spec.md).

## Problem and scope

Way observation restrictions need an explicit source of allowed keys and values. Start with a small, version-controlled backend file so the editing flow can be tested without configuring database records. Later, admins manage the same definitions in the backend, persisted in the database.

The vocabulary contract here covers whole-way observations classified by OSM `highway`. R5 additionally requires meaningful street-part aspect examples, whose model is defined in the separate aspect spec. Routing preferences, node suggestions, buildings and areas remain separate. Stage 1 is the current implementation scope.

## Requirements

### R1 — Definition contract (both stages)
- A vocabulary maps each exact, case-sensitive `highway` value to an ordered list of entries: `{ "key": "<raw tag key>", "values": ["<raw value>", …] }`.
- A key is unique within a way type; the same key may have different choices on different types. Values are unique within an entry and preserve their configured order.
- Type names, keys and values are nonblank strings of at most 255 characters. Invalid shapes, duplicate definitions and duplicate values are configuration errors; never silently discard entries or resolve duplicates by last-write-wins.
- An empty values list explicitly permits free-form, nonblank values within the normal observation validation limits. A nonempty list permits only its exact values for new observations.
- An absent type or empty entry list allows no new observations. There is no inheritance or fallback to global observation suggestions.
- Raw identifiers are stable storage/API identities. Display labels use the existing English/German tag translation catalog and fallbacks; vocabulary files do not duplicate translations.

### R2 — Stage 1: file-based testing
- One committed backend JSON file is the authoritative way vocabulary. It is read by the backend; both API choices and save validation use the same validated definitions. Database vocabulary records are not merged into this stage.
- Developers maintain the file in the repository and deploy it with the backend. There is no vocabulary write API, admin file editor, way-vocabulary database seeding requirement or vocabulary schema migration in this stage. Observations themselves continue using existing database storage.
- Ship small test definitions with different choices for `footway` and `residential`, a free-text key, and an explicitly empty type. These are test data, not an exhaustive accessibility taxonomy.
- Valid edits become available on the next backend request; a browser reload fetches the new choices. Live updates to an already-open editor are unnecessary; saves always validate against the current server vocabulary.
- Missing, unreadable or invalid files are service/configuration failures, distinct from a deliberately empty vocabulary. Catalog reads and way-observation saves return HTTP 503 with `{ "error": "editing_vocabulary_unavailable" }`; saves persist nothing. Log enough context to locate the bad definition.

### R3 — API, editor and validation
- Public `GET /api/editing-catalog` exposes `wayVocabularies` in the R1 shape. Empty maps serialize as `{}`, and `aspectTypes` describes string-identified structured-tag choices under the [aspect spec](../street-part-aspects/spec.md), without database aspect IDs. Backend storage details and database IDs are not exposed for way vocabulary entries.
- The editor offers only the selected way type's keys and configured values. An unconfigured type has a no-observations state. A failed catalog load instead shows an error/retry state and prevents new additions; it never enables free-form keys.
- The backend derives the way type from its trusted OSM data using the way identity, never from a client-claimed `highway` value. Missing features/types grant no new keys.
- New disallowed keys or values return HTTP 422 and leave the entire save unchanged. A stale editor retains its draft and shows the save error so the user can reload current choices.
- Preserve Way Observations R5 grandfathering: existing creator-owned observations remain visible/editable when their keys leave the vocabulary; unchanged approved pairs remain read-only and may be echoed during saves. This exception belongs to the existing observation and creator, not to other users or ways.
- Removing definitions never deletes, rewrites or unapproves observations. An unchanged stored value removed from a still-configured key remains valid when resaving; replacement values must come from that key's current choices (or free text when its list is empty).
- Vocabulary membership does not grant editing permission or approve observations. Existing editor/admin permissions, moderation and visibility rules apply.

### R4 — Stage 2: database and admin management (later)
- This future stage concerns whole-way vocabulary definitions only; it does not authorize dedicated aspect tables. Persist way types and their ordered key/value definitions in the database, with the same uniqueness and validation rules as R1. Admins can create, inspect, edit, reorder and remove definitions in the backend admin panel; other users cannot change them.
- Removing a type/key/value withdraws it from new choices without changing stored observations (R3). Changing a raw identifier is removal plus addition, never an implicit observation migration. Label changes use the existing translation workflow.
- Validate each configuration save atomically; invalid edits leave the published vocabulary unchanged. Successful saves affect subsequent catalog reads and observation validation; already-open browsers reload as in R2.
- Import the file definitions, preserving raw identifiers and order, before switching to database authority. Verify catalog parity and saved-observation compatibility. After switching, the database is the sole authority; no automatic file fallback or dual writes. Client contracts and existing observation records stay compatible.

### R5 — Meaningful street examples for testing
- The example-data deliverable must include both vocabulary/catalog definitions and a complete ordered street-part cross-section, as specified in [Street Part Aspects R7](../street-part-aspects/spec.md). Flat way tags alone do not demonstrate the street model.
- Supply file-based example definitions for `footwalk`, `cycle_lane`, `parking` (car parking lane) and `roadway`, with per-type width bins, surface choices and roadway lane-count choices. Include two independent footwalk elements on the same street part, with different width and surface values.
- Keep the file-based cross-section catalog separate from whole-way `wayVocabularies`. Store cross-section values as ordinary tags (`way.footwalk.1.width`, `way.footwalk.5.width`, etc.), never as separate aspect records; repeated types remain independent through their index. Raw tokens and display labels follow Street Part Aspects R1/R7.
- Ship reproducible test fixtures and brief loading instructions alongside the examples; a tester must be able to open the complete cross-section without manually creating every definition and structured tag, or seeding aspect tables. Loading examples must be explicit and must not alter production map data automatically.

## Proposed file and test data

Assumed path: `backend/config/way-observation-vocabulary.json`. This file contains only the whole-way vocabulary below. It is one part of the required example data; companion aspect definitions and a street-part fixture must also implement R5.

```json
{
  "wayVocabularies": {
    "footway": [
      { "key": "surface", "values": ["asphalt", "paving_stones", "gravel"] },
      { "key": "tactile_paving", "values": ["yes", "no"] },
      { "key": "note", "values": [] }
    ],
    "residential": [
      { "key": "surface", "values": ["asphalt", "concrete"] },
      { "key": "lit", "values": ["yes", "no"] }
    ],
    "motorway": []
  }
}
```

## Acceptance criteria

- **AC1 (R1/R2/R3):** With the example file, a catalog read and editor reload offer the listed keys/values in order; a footway permits free-text `note`, while a residential way does not.
- **AC2 (R1/R3):** A footway save with `surface=gravel` succeeds subject to normal permissions/moderation; `surface=concrete` or `lit=yes` returns 422 with no partial write. Forging a client way type cannot bypass this.
- **AC3 (R1/R3):** `motorway`, an unlisted highway value and a way without `highway` offer no additions and reject new keys. Global suggestions never appear as a fallback.
- **AC4 (R1/R2):** Invalid JSON, duplicate types/keys/values, malformed entries, and a missing/unreadable file produce the R2 failure response. A valid empty map instead returns 200 with `wayVocabularies: {}`.
- **AC5 (R2/R3):** After a valid file edit, the next catalog read and browser reload reflect it without a frontend build or database vocabulary changes. A stale save is checked against the new definitions and retains its draft after rejection.
- **AC6 (R3):** Removing a key/value preserves saved observations and their visibility. The creator can edit a grandfathered key and resave an unchanged removed value; another editor cannot create that retired key/value. Approved content is not editable through this exception.
- **AC7 (R2/R3):** Anonymous visitors can read the catalog; unauthorized users cannot save observations. An unavailable catalog produces a retry state distinct from an empty vocabulary.
- **AC8 (R4, later):** An admin can manage ordered definitions; a non-admin is denied. Duplicate/invalid updates leave the published database vocabulary unchanged, and retiring a choice preserves observations.
- **AC9 (R4, later):** Importing and switching to database authority returns equivalent way vocabulary JSON, preserves stored observations and requires no frontend contract change. Subsequent file edits do not affect the active vocabulary.
- **AC10 (R5):** Following the example loading instructions exposes the five-aspect cross-section in Street Part Aspects R7. Both footwalks retain their distinct widths/surfaces after save and reload; editing one leaves the other unchanged. All example values are selectable from the supplied file-based definitions and persisted in the existing tags arrays, without aspect tables.

## Assumptions and verification

- Stage 1 uses the proposed JSON path and starter definitions, reload-based refresh, and grandfathering of unchanged removed values.
- **Passed — AC1–AC7:** loader/configuration tests, HTTP catalog/validation checks and fixture-based browser coverage. See the [shared verification record](../tasks.md) for methods and limits.
- **Deferred — AC8/AC9:** database/admin management and import/parity checks belong to stage 2. No vocabulary database authority is introduced in stage 1.

## Stage 1 implementation (2026-11-07)

Implemented and checked; see [shared tasks and verification](../tasks.md) for commands, outcomes and limitations. Stage 1 uses the committed JSON file exclusively. Stage 2 is deferred.
