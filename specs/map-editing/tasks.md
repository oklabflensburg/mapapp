# Map editing implementation

## Current revision: aspects stored as tags (2026-11-07)

**Status:** Implemented and verified against the revised [aspect spec](street-part-aspects/spec.md). Earlier entity-model evidence is retained below as history.

- [x] R1/R4, AC2/AC10: replace aspect-specific instance/type/attribute/preference storage with existing tags and file-backed definitions; validate `key.type.index.aspect` and raw values. Verify fresh installs need no aspect tables.
- [x] R2/R3, AC1–AC4/AC9/AC12: serialize the composer through `tags`, remove the separate `aspects` API field, preserve read-only approved content, and use existing exact-key moderation. Verify atomic rejection, isolation, ordering and independent repeated types.
- [x] R4–R6, AC5–AC8: evaluate approved structured tags by parsed type/aspect at any index; retain profile storage, labels and retired-value compatibility. Verify corridor matching and pending-tag exclusion.
- [x] R7, AC9: update catalog/demo files and loader to raw tag values (`way.footwalk.1.width=<1`), without aspect-table seeding; verify normal save/reload and reordering.
- [x] R8, AC11: convert legacy records and predicate tokens with attribution/approval preserved, abort on unknown mappings/conflicts, verify parity/reruns, then remove old storage. Reject legacy `aspects` writes explicitly.
- [x] Run focused backend/browser checks and production build; record new evidence against AC1–AC12.

### Verification of the tag-based revision

- **Passed:** complete backend suite — 144 tests, 1140 assertions. Subsequent focused checks including fresh-install migration and any-index routing cases — 29 tests, 137 assertions (`MapEditingTest`, `AspectCatalogTest`, `LegacyAspectConverterTest`, `TagPriorityControllerTest`).
- **Passed:** `npm run build` — TypeScript, Vite and PWA output; existing bundle-size warning.
- **Passed:** `APP_URL=http://localhost:5174 node scripts/testing/check-map-editing.mjs` — controlled way choices, catalog empty/error recovery, tag payloads without `aspects`, failed-save draft retention, insertion/reordering, save/reload, two independent footwalks, partial-approval locks and profile label.
- **Passed:** `APP_URL=http://localhost:5174 node scripts/testing/check-node-relation.mjs` — existing relation creation, geometry, selection and reload regression.
- **Passed:** Symfony `lint:container`, `doctrine:schema:validate --skip-sync`, moderation-template `lint:twig`; live catalog returns four string-identified types with raw tokens/labels and no aspect IDs.
- **Migration applied:** `app:aspects:convert-legacy` followed by `doctrine:migrations:migrate --no-interaction` in local development and test databases. `Version20261107083314` removes the four obsolete tables only after parity verification. The prior uncommitted prototype version now creates no tables on fresh installs. Unrelated schema drift was excluded.
- Conversion tests preserve creator, approval, retired labels, order and stable preference IDs/ratings; verify reruns, unknown-data/collision failure without writes, and fresh installation without aspect tables.
- Routing was checked through approved corridor evaluation and GraphHopper custom-model generation; no live GraphHopper request was made. Catalog authoring remains file-based; whole-way vocabulary stage 2 is still deferred.

## Historical implementation: separate aspect entities

- [x] Vocabulary stage 1 R1–R3 / Way R5: committed JSON definitions, shared validated reader, trusted OSM classification, atomic validation and grandfathering, restricted UI with empty/error/retry states (vocabulary AC1–AC7; Way AC6–AC9).
- [x] Aspects R1–R3: ordered typed cross-sections, atomic per-user replacement, private observations, admin review and supersession (AC1–AC4).
- [x] Aspects R4–R6: admin catalogs/preferences, shared profile storage/labels, approved corridor evaluation and retired-bin preservation (AC5–AC8).
- [x] Aspects R7 / AC9: committed example catalog and five-aspect fixture, explicit dev/test demo loader, save/reload and independent sidewalk checks.
- [x] Verify existing editing behavior, production frontend build, backend suite and browser workflows.

Stage 2 vocabulary database/admin management and import/parity checks (AC8/AC9) remain explicitly deferred by the controlled-vocabulary spec. Stage 1 has no vocabulary database authority or migration.

## Decisions and assumptions

Use the existing Doctrine/API/session/EasyAdmin stack. Aspect names, any-one matching and monolingual bin labels follow the design defaults. For removed aspect bins, **AC8 is the acceptance baseline**: stored values retain their labels and keep matching existing preferences; new rows cannot select retired bins. The revised R4 now explicitly retains matching for stored retired values, resolving the former R4/AC8 conflict. Aspect `avoid` uses 0.1; existing tag avoidance retains its established factors.

## Verification (2026-11-07)

- **Passed:** `cd backend && docker compose exec -T php vendor/bin/phpunit` — 131 tests, 1100 assertions. Includes `MapEditingTest` and `EditingVocabularyTest`: configuration validation/refresh, 503 contract, allowed/retired observations, per-editor visibility, atomic rollback, admin review/configuration/permissions, re-moderation, preference storage, approved corridor matching, nonzero avoid penalty, retained-bin routing, explicit/idempotent demo loading and independent sidewalk edits.
- **Passed:** `npm run build` — TypeScript, Vite and PWA assets; existing chunk-size warning.
- **Passed:** `node scripts/testing/check-map-editing.mjs` — way choices, error/retry, rejected-save draft retention, cross-section insertion/reordering/save/reload, profile label, and supplied five-aspect example.
- **Passed:** `check-node-selection.mjs` and `check-node-relation.mjs` — existing fixture-based browser regressions.
- **Passed:** Symfony `lint:container`, `doctrine:schema:validate --skip-sync`, updated moderation-template `lint:twig`, and `git diff --check`.
- **Blocked, existing issue:** `node --test scripts/testing/tag-translations.test.mjs` cannot start: Node ESM rejects the directory import `../i18n` in `tagKeyLabels.ts`. No translation assertions executed.
- **Historical migration:** `Version20261107073924` was generated and applied to the test database for the separate-table model. It is superseded by the new storage requirements; do not treat it as setup guidance for the revised design. Conversion/removal remains pending. Unrelated schema drift was excluded.

No live GraphHopper route was requested; routing behavior is verified through corridor evaluation and custom-model generation.

## Empty aspect catalog fix (2026-11-07)

- **Fixed:** the development catalog contained no aspect types, leaving Add aspect disabled without an explanation. Loaded the four supplied definitions via the new `app:aspects:load-demo --catalog-only --demo` option; no map objects were created.
- **Fixed:** the composer explains empty/error states and can reload a previously cached empty catalog without discarding drafts.
- **Passed:** production build; `MapEditingTest` (6 tests, 63 assertions, including catalog-only loading); `check-map-editing.mjs` (disabled/empty state → reload → enabled Add aspect, plus existing workflows); live API returns all four aspect types; `git diff --check`.

## Frontend observation terminology and translations (2026-11-07)

- Replaced user-facing Aspect/Aspekt with Observation/Beobachtung in row legends, controls, accessible names and empty-catalog messages.
- Type choices, attribute labels, surfaces, range values and profile preference labels now use the existing shared tag translations; English/German targets are published in XLIFF. Exact indexed value translations take precedence over shared attribute translations and catalog fallbacks.
- Translation sync collects file-backed definitions, including retired labels and preference keys, so complete syncs preserve these translations.
- **Passed:** production build; XLIFF validation; 10 focused backend tests / 126 assertions (translation sync, label lookup and API); map-editing browser workflow including English/German labels and language switching; live translation API checks; `git diff --check`.
