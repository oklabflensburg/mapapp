# OSM-based aspect naming (cross-section grammar revision)

**Status:** Implemented and verified (2026-11-22). Supersedes the naming parts of [street-part aspects](../street-part-aspects/spec.md) (R1 direction tokens and type names, R4/R7 catalog identifiers, R5 preference-key prefix) and touches the grammar references of [aspect observation mapping](../aspect-observation-mapping/spec.md) and [way direction indicators](../../map/way-direction/spec.md); their mechanics are unchanged. Backwards compatibility is explicitly dropped: existing observation content was deleted wholesale instead of migrated (R5).
Revised same day: the `way.` namespace prefix is removed entirely — structured keys are bare `<type>.<side>.<index>.<aspect>` (e.g. `sidewalk.left.1.width`), detected by shape (see R1); see the second verification entry.
**Code:** `backend/src/Service/StreetPartAspects.php` (grammar, `SIDES`), `backend/src/Service/AspectCatalog.php` (catalog and preference-key validation), `backend/src/Service/Routing.php` (preference-key prefix), `backend/config/aspect-catalog.json` (type names, preference keys), `src/lib/crossSection.ts` (frontend grammar, canonical order), `src/components/CrossSectionComposer.vue` and `src/i18n/locales/en.ts`/`de.ts` (side labels), admin review templates and tests across both suites.

## Problem / intent

The revised cross-section grammar in [street-part aspects](../street-part-aspects/spec.md) identifies an element's position across the street with **compass tokens** (`way.footwalk.west.1.width`). Compass tokens are the wrong model for street cross-sections: which side of a street is "west" depends on the street's bearing, and OSM — the data model this app builds on — tags the sides of a way as `left`/`right` **relative to the way's direction of travel** (`sidewalk:left`, `cycleway:right`). The grammar also invents type names (`footwalk`, `cycle_lane`) where OSM has established tags (`sidewalk`, `cycleway`), and its preference keys use a colon prefix (`aspect:footwalk-wide`) while structured keys use dots.

Revise the naming scheme so an OSM contributor recognizes it:

1. Replace the compass directions (`north`, `south`, `east`, `west`) with `left`, `right`, `center` relative to the direction of travel, as in OSM.
2. Adopt OSM tag names for element types, aspects and values wherever an OSM tag exists.
3. Use `.` exclusively as the separator; `:` never appears in a structured key or preference identifier.
4. No backwards compatibility: all existing observation nodes and tags are deleted rather than migrated.

## Definitions

- **Side** — the element's position across the cross-section relative to the street part's direction of travel: `left`, `right`, or `center` (the carriageway itself). Replaces the former *direction* segment.
- **Direction of travel** — the node relation's own member order (first member toward last), the same direction the [way-direction arrows](../../map/way-direction/spec.md) display. It may differ from the underlying OSM way's node order.
- **OSM tag name** — the primary OSM key that describes the element, e.g. `sidewalk` (OSM `sidewalk:left:width`), `cycleway` (OSM `cycleway:left`), `parking` (OSM `parking:lane`/`parking:left`). Where OSM has no counterpart (`roadway`), the existing identifier stays.
- **Position, element, aspect, catalog** — unchanged meanings from [street-part aspects](../street-part-aspects/spec.md), with *direction* renamed to *side*.

## Requirements

### R1 — Key grammar with sides, OSM names, dots only

- The exact key format is `<type>.<side>.<index>.<aspect>` — no namespace prefix, matching the OSM tags the names come from. `side` is one of the fixed tokens `left`, `right`, `center`; `index` is the element's positive, 1-based position **within its side**. Example: `sidewalk.left.1.width = ">2"`, `roadway.center.1.lanes = "2"`.
- `.` is the only separator. A structured key or preference identifier never contains `:`.
- **Types adopt OSM tag names where one exists:** `footwalk` → `sidewalk`, `cycle_lane` → `cycleway`; `parking` and `roadway` keep their identifiers. New catalog types must reuse an existing OSM key name when one describes the element; inventing a name where OSM has one is a configuration error.
- **Aspects keep OSM key names:** `width`, `surface`, `lanes` are already OSM keys and are unchanged. New aspects must be OSM key names where applicable.
- **Values reuse OSM values where the aspect mirrors an OSM key:** the surface choices (`asphalt`, `paving_stones`, `concrete`) are already OSM `surface` values. The width and lane **range bins** (`<1`, `1-2`, `>2`, lane tokens `1`, `2`, `>2`) remain app-specific tokens — OSM uses free numeric values there, so there is nothing to adopt.
- **Structured-key detection is shape-based** (`StreetPartAspects::isStructuredKey`): a key is structured when it parses as `<type>.<side>.<index>.<aspect>`, or — without a namespace marking intent — when it carries a side token as the second dot segment of a four-or-more-segment key. The latter keeps near-grammar typos a validation error: zero or leading-zero indexes, empty segments and extra trailing segments return 422 instead of silently becoming ordinary tags.
- Validation keeps all existing structural rules: exactly four nonempty dot-separated segments for valid keys; the side must be one of the three fixed tokens; indexes are canonical decimal integers without signs, zero or leading zeros; 255-character limits apply; one type per `(side, index)` position; the same type/index on different sides describes independent elements. Reserved-but-malformed keys plus unknown types/aspects/values and conflicting keys in one submission return HTTP 422 with no partial save, retaining the editor's draft. Keys with no side token in the second dot segment are **ordinary tags**: the previous grammar's shapes (`way.sidewalk.left.1.width`, `sidewalk.<index>.<aspect>`), compass-side keys (`sidewalk.west.1.width`) and colon-separated OSM-style keys (`sidewalk:left:width`) save as plain tags.
- Storage, moderation, visibility and structure-lock mechanics from [street-part aspects](../street-part-aspects/spec.md) are unchanged except for the tokens: approval is still per full key, approved sides/types are read-only for editors, and a position is the pair `(side, index)`.

### R2 — Side semantics relative to direction of travel

- `left` and `right` are relative to the street part's **direction of travel** — its own member order, exactly what the direction arrows display (OSM convention: `sidewalk:left` is on the left when looking along the way). `center` is the carriageway between them. Sides are defined on the relation, not on the underlying OSM way, since the two can legitimately differ (see [way-direction indicators](../../map/way-direction/spec.md) R1).
- The composer orders rows by the canonical side order `left`, `center`, `right`, then numerically by index within each side. New rows default to `center` and take the next free index within their side; insert/reorder/remove renumbers `1…n` **per side** and rewrites every affected key.
- The editor UI labels the three tokens (Left/Links, Center/Mitte, Right/Rechts) through the existing vue-i18n catalogs; former compass labels (North/Norden, …) are removed from the active catalogs.

### R3 — Catalog and preference identifiers

- `backend/config/aspect-catalog.json` renames the element types to `sidewalk`, `cycleway`, `parking`, `roadway`; their aspects, values and labels are otherwise unchanged. The five-element demo cross-section is restated in the new grammar: left 1 `sidewalk` (>2, asphalt), left 2 `cycleway` (1-2, asphalt), left 3 `parking` (>2, concrete), center 1 `roadway` (>2, asphalt, 2), right 1 `sidewalk` (1-2, paving_stones).
- Preference identifiers switch from the `aspect:` prefix to `aspect.` with the same ≤50-character limit, e.g. `aspect:footwalk-wide` → `aspect.sidewalk-wide`; renamed types rename their preference keys accordingly (`aspect:footwalk-asphalt` → `aspect.sidewalk-asphalt`).
- `GET /api/editing-catalog` exposes the OSM-named types under `aspectTypes` and the dot-prefixed preference keys, with labels through the existing translation workflow. Frontend choices and backend validation use the same validated catalog, as today.

### R4 — Routing with dot-separated preference keys

- `Routing.php`'s special-casing of aspect preferences changes from the `aspect:` prefix to the `aspect.` prefix; no other routing rule changes. Matching remains exact-segment, at any valid side and index, on approved structured tags only (keys found by the shape-based detection); pending tags never influence routing; corridor matching, `avoid`/`prefer` semantics and the GraphHopper custom model are untouched.
- `/api/tag-priorities` storage is reused unchanged; preference identifiers remain stable and index-independent (now `aspect.*` keys).

### R5 — No compatibility; delete all existing observation content

- There is **no migration** from any previous grammar (four-segment `way.<type>.<index>.<aspect>`, compass `way.<type>.<direction>.<index>.<aspect>`, or the legacy aspect tables). Instead, all existing **user-created map content is deleted**: observation nodes, node relations (street parts), their structured and ordinary group tags, pending observations awaiting review, and unapproved drafts. Static OSM data is untouched.
- The reset is a documented, transactional operator action run once against the development database (see Implementation notes); afterwards the database contains no `way.*` structured tags and no user-created nodes/relations. The app must start and serve an empty observation state without errors.
- Per-user stored priorities that reference retired `aspect:*` preference keys are deleted together with the content reset; the catalog never offers them again.
- Compatibility machinery is removed: `StreetPartAspects::parseLegacy`, `LegacyAspectConverter`, `AspectDirectionMigration`, `ConvertLegacyAspectsCommand` and `MigrateAspectDirectionsCommand` are deleted. Fresh installs and the reset database support the new grammar only; nothing in the active code base, catalog, fixture, i18n catalogs or tests references compass sides, `footwalk`, `cycle_lane`, four-segment keys or `aspect:` keys.
- Historical spec documents keep their old-grammar examples as records; only this spec and the two supersede notes (see Status) change.

## Acceptance criteria

- **AC1 (R1/R2):** Saving and reloading the five-element fixture (R3) through `tags` reconstructs the same ordered rows in `left`, `center`, `right` order with the same raw values and display labels, without aspect records or an `aspects` payload. Example tag: `{"key": "sidewalk.left.1.width", "value": ">2"}`.
- **AC2 (R1):** `sidewalk.left.1.width = "<1"` is accepted; reserved-but-malformed keys (zero/leading-zero indexes, extra segments, empty type), unknown types/aspects and invalid values return 422 with no partial save and a retained draft; keys without a side token in the second segment (including `way.`-prefixed, side-less, compass-side and colon-separated shapes) save as ordinary tags.
- **AC3 (R2):** Given a relation with members ordered A → B, the direction arrows point A toward B (existing behavior), and an element tagged `sidewalk.left.1.*` lies on the left-hand side of that direction — verifiable in the browser against the arrows.
- **AC4 (R3):** A user rates `aspect.sidewalk-wide` `prefer` and retrieves it later; the catalog offers only `aspect.*` keys (no `aspect:` key anywhere); routing matches a preference selecting width `>2` against an approved `sidewalk.right.1.width = ">2"` and its corridor affects the route custom model; a pending tag, a roadway width or a `sidewalk` width `<1` does not match.
- **AC5 (R4):** `avoid` on an `aspect.*` preference penalizes matching corridor areas without forbidding them (unchanged semantics under the new prefix).
- **AC6 (R5):** After the reset, no user-created nodes, relations, group tags (structured or ordinary), pending reviews or drafts remain; stored priorities contain no `aspect:*` keys; `LegacyAspectConverter`, `AspectDirectionMigration`, both console commands and `parseLegacy` no longer exist in the code base.
- **AC7 (R5):** A repository search finds no `north`/`south`/`east`/`west` side tokens, `footwalk`, `cycle_lane`, `aspect:` keys or colon grammar in backend source, frontend source, catalogs, fixtures and i18n locales (spec documents and git history excluded).
- **AC8 (R1/R2):** The composer keeps its mechanics under the new tokens: new rows default `center`, per-side renumbering rewrites all affected keys leaving no stale draft keys, an approved element's side/type is read-only for editors, and approving `sidewalk.left.1.width` publishes only that tag and supersedes same-key pending competitors (see [aspect observation mapping](../aspect-observation-mapping/spec.md), whose update-vs-add flow and option payloads carry `side` instead of `direction`).

## Assumptions and scope

- The type mapping (`footwalk` → `sidewalk`, `cycle_lane` → `cycleway`, `parking`, `roadway` unchanged), keeping the per-side index segment, switching preference keys to `aspect.`, and deleting **all** user-created observation content (not just structured tags) were explicit product decisions confirmed with the requester; the reset is one-time and irreversible by design.
- Sides are relative to the **relation's** member order, consistent with the arrow feature; if a street part's relation runs opposite to the OSM way, its left/right disagree with the OSM way's — accepted, as with direction arrows.
- Width/lane range bins stay app-specific tokens; aligning them with OSM numeric widths is future work.
- Whole-way observations (plain OSM keys like `surface`, `lit` via `wayVocabularies`) and plain node tags are already OSM-named and unchanged by this spec.
- Internal storage prefixes that are not tag or preference identifiers (e.g. the `key:` label-catalog prefix) are not part of the grammar and may keep their form.
- Geographic placement/shading of elements stays out of scope, as before.

## Implementation notes

- **Grammar parsers:** update the regex in `StreetPartAspects::parse` (`(left|right|center)` replacing the compass alternation) and `DIRECTIONS` to `['left', 'center', 'right']` (canonical order doubles as the token list); mirror both in `src/lib/crossSection.ts` (`DIRECTIONS`, `parseAspectKey`). Direction references in `NodeGroupManager`, `NodeRelationMerge`, `AspectObservationApproval` (review-screen summary and target payloads `{side, index, aspectValue}`), `CrossSectionComposer.vue`, `NodeTagPanel.vue`, `ObservationTagRows.vue` and the admin templates follow mechanically.
- **Catalog:** rename types in `aspect-catalog.json`, switch preference keys to `aspect.`, and update `AspectCatalog`'s key validation (prefix + length) and `Routing.php`'s `aspect:` special-casing. i18n: replace the compass direction labels with Left/Center/Right in `en.ts`/`de.ts`.
- **Reset:** one transactional operator action that truncates the user-created content tables (user nodes, node groups, node group tags, tags, pending reviews/drafts, per-user tag priorities) — e.g. a small throwaway SQL script or a one-off console command documented in the backend README. It is not shipped as a permanent productivity feature.
- **Cleanup:** delete `ConvertLegacyAspectsCommand`, `MigrateAspectDirectionsCommand`, `LegacyAspectConverter`, `AspectDirectionMigration`, `StreetPartAspects::parseLegacy` and their tests; update all fixture-based tests (`AspectDirectionMigrationTest` disappears, `AspectObservationMappingTest`, grammar, routing, moderation, visibility suites) to the new tokens.
- **Static data:** the local development database's legacy tags (noted in street-part-aspects' verification as pending a direction migration) are covered by the reset instead of the migration.

## Verification (2026-11-22)

### Prefix-drop revision (second pass, same day)

- **Rationale:** the `way.` namespace had no OSM counterpart (`sidewalk.left:width` carries no prefix), so the prefix is gone entirely; structured keys are now bare `<type>.<side>.<index>.<aspect>`, with shape-based detection (`StreetPartAspects::isStructuredKey`: parses, or a 4+-segment key with a side token in the second segment) replacing the old prefix match in `StreetPartAspects::validate` and the approved-echo rule in `NodeGroupManager`. The review-flow effective key (`TagCrudController`) and the frontend grammar (`crossSection.ts`, `CrossSectionComposer.vue`) build prefix-less keys; no migration, the reset database holds no `way.*` tags.
- **Passed — backend:** full suite `docker compose exec -T php vendor/bin/phpunit` — 163 tests, 1271 assertions. `testGrammarAndLegacyPayloadsAreRejectedAtomically` now pins the new boundary: reserved-but-malformed keys (zero/leading-zero index, empty type, extra segment) and unknown type/aspect return 422; the new `testFormerGrammarShapesAreOrdinaryTags` pins that `way.sidewalk.left.1.width`, `sidewalk.<index>.<aspect>`, compass-side and colon-separated shapes save as ordinary tags. Mapping, merge, visibility, routing and catalog tests run against prefix-less keys.
- **Passed — frontend:** `npm run build` (vue-tsc + Vite); `check-map-editing.mjs` exercises composer save/reload, per-side renumbering, structure locking, the five-element fixture and EN/DE labels with bare keys (`sidewalk.left.1.width` etc.); `check-node-relation.mjs`, `check-relation-approval-color.mjs`, `check-node-selection.mjs`, `check-direction-arrows.mjs`, `check-node-creation.mjs`'s direction-inheritance scenario unaffected. `lint:container`/`lint:twig` still pass (from the same day's first pass).
- **Not repeated:** dev-database reset (already empty; nothing stored under the old grammar remains), XLIFF sync (configured choices unchanged; the catalog holds no `key:way.*` units).

### First pass (grammar revision, featuring the compass-to-side migration)

- **Passed — backend:** complete suite `docker compose exec -T php vendor/bin/phpunit` — 162 tests, 1268 assertions (7 direction-migration/legacy-converter tests removed with their subjects). Cover the new grammar (`way.<type>.<side>.<index>.<aspect>`; four-segment, compass-side and colon keys rejected 422 — AC2), per-side positions and one-type-per-position, routing under the `aspect.` prefix (AC4/AC5, `avoid` factor 0.1), moderation/visibility/mapping flows with `side` payloads (AC8), catalog validation with dot-prefixed preference keys, and `SyncTagTranslationsCommand` collecting `key:sidewalk`/`key:aspect.sidewalk-wide`. `lint:container` and `lint:twig` pass; live `GET /api/editing-catalog` on the reset dev database serves `sidewalk` first.
- **Passed — frontend:** `npm run build` (vue-tsc + Vite) and the fixture-based browser check `check-map-editing.mjs`: composer save/reload with left/center/right tokens, new rows default `center`, per-side renumbering, approved structure/side locking, the five-element fixture (left sidewalk, cycle way, parking; center roadway; right sidewalk) and English/German controls (Side/Seite, Left/Links, Center/Mitte, Right/Rechts) with `aspect.` profile labels. `check-node-relation.mjs`, `check-relation-approval-color.mjs`, `check-direction-arrows.mjs` and `check-node-selection.mjs` still pass.
- **Passed — AC6 (reset):** transactional wipe against the development database (`node_group_tags`, `node_group_members`, `node_groups`, `node_tags`, `tags`, local-provider `nodes`, `user_tag_priorities` rows with `aspect:%` keys) — local nodes 0, groups 0, group tags 0, tags 0, 5 static OSM provider nodes preserved; the app serves the empty observation state.
- **Passed — AC7 (search):** no `footwalk`/`cycle_lane`/`aspect:` keys or `DIRECTIONS`/legacy classes remain in backend source, frontend source, catalogs, fixtures, scripts or i18n locales. Stale `key:footwalk`/`key:cycle_lane`/`key:aspect:footwalk-*` XLIFF units were removed after `translations:sync -- --database` generated `key:sidewalk`/`key:cycleway`/`key:aspect.sidewalk-*` (both catalogs validate). Compass words remain only as ordinary (non-side) fixtures: `entrance=north` tag values, group names like `Hochallee north part`, and the deliberate compass-rejection keys AC2 needs.
- **Not verified:** AC3's leftness relative to live arrows is verified by construction — arrows render a relation's member order and `side` is defined on that same member order — but no automated check asserts geometric leftness (the composer renders no spatial cross-section; geographic placement remains out of scope). **Pre-existing failure:** `check-node-creation.mjs` fails on the drawer Save button also on the unmodified `refactoring` commit (verified by stashing this revision); unrelated to this spec.
- **Not performed:** live GraphHopper request (corridor matching covered by the suite, as before).

## Planned checks (retained from the design; all covered above)

- Backend grammar/routing/moderation coverage with the new tokens; browser composer checks; reset against the development database; repo-wide old-token search.
