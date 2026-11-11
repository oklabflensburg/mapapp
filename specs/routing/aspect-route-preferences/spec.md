# Aspect route preferences (alternative-aware surface/attribute routing)

**Status:** Implemented and verified (2026-11-09).
**Builds on:** [street part aspects](../../map-editing/street-part-aspects/spec.md) R5/R6 (preference storage and routing evaluation), [routing API](api-routing/spec.md) R4 (custom model shaping), [tag settings API](../../tag-settings/api-tag-settings/spec.md) (preference exposure and per-user priorities).
**Code:** `backend/config/aspect-catalog.json`, `backend/src/Service/AspectCatalog.php`, `backend/src/Service/StreetPartAspects.php`, `backend/src/Service/Routing.php`, `backend/src/Service/TagPriorityManager.php`, `backend/src/Controller/RoutingController.php`, `src/stores/settings.ts`, `src/views/ProfileView.vue`.

## Problem / intent

Users can already rate route preferences for plain tags and for the single shipped aspect predicate (`aspect:footwalk-wide`, footnote: any footwalk wider than 2 m). But the observed controlled-vocabulary attributes — the cross-section surfaces stored as `way.<type>.<direction>.<index>.<aspect>` tags — cannot drive routing in the situations users care about.

Example: a user wants to avoid sidewalks made of paving stones. Today they cannot express that: no paving-stone preference exists, and the current evaluation would mark a way as soon as *any* footwalk matched, even when a comfortable asphalt footwalk runs alongside. The desired semantics are *"avoid ways where the only available footwalks are paving stones"* — an offered alternative should keep the way routable.

Two changes follow: the catalog offers surface/attribute preferences for the shipped cross-section types, and preference matching understands whether a way offers a *better alternative*.

## Decisions (confirmed)

- **Centrally defined preferences.** The set of ratable aspect preferences stays catalog-defined (committed JSON file, as in aspects stage 1); users only choose a priority level per preference. Users do not compose their own type/aspect/value predicates.
- **Unknown values are not alternatives.** A footwalk whose surface was never observed does not make a way acceptable. A part is only exempt from an `avoid` matching preference if some element of the type carries an explicitly observed value *outside* the preference's selected values.

## Requirements

### R1 — Preference definitions with match modes

- Aspect route preferences remain entries of the `preferences` list in the committed aspect catalog (`backend/config/aspect-catalog.json`), validated at read by `AspectCatalog` with the existing failure contract (HTTP 503 `editing_vocabulary_unavailable` for catalog reads and structured-tag saves; nothing partially applied).
- Each preference keeps the existing fields — `key` (unique, `aspect:` prefix, ≤ 50 chars), `label`, `type`, `aspect`, `values` (nonempty, unique, from the type's aspect choices, retired values permitted as intentional predicates on existing tags) — and gains a required `match` field:
  - **`any_element`** — the preference matches a street part if at least one element of the type has an approved value for the aspect that is in `values`. This is the current behavior and stays the semantics of e.g. `aspect:footwalk-wide`.
  - **`no_alternative`** — the preference matches a street part if the part has at least one element of the type and **no** element of that type carries an approved value for the aspect **outside** `values`.
- `match` is explicit: a preference without `match` or with another value is a configuration error and fails the catalog read with the existing R1 failure contract. No silent default.
- A preference's `values` may list several tokens (e.g. `paving_stones` and `concrete`); `no_alternative` then requires an explicitly observed value outside *all* listed tokens to exempt the part.

### R2 — Catalog deliverable: surface preferences

- The shipped catalog defines, alongside the existing `aspect:footwalk-wide` (now explicitly `match: any_element`), one preference per `footwalk` surface value — `asphalt`, `paving_stones`, `concrete` — with stable keys (e.g. `aspect:footwalk-surface-paving_stones`), catalog labels, and `match: no_alternative`. Admins may later extend the file with further preferences (other types, aspects, modes) using the same contract; no admin write UI is added in this revision.
- The user's example scenario must be expressible and shipped: rating the paving-stones preference `avoid` keeps routes off ways whose footwalks are all paving stones, while ways with an observed asphalt (or otherwise non-paving-stone) footwalk stay attractive.
- Labels appear in English and German through the existing translation sync and shared catalog label mechanisms; raw keys stay language-independent identities. The `/api/route-preference-tags` response (`tags` + `labels`) is unchanged in shape.

### R3 — Exposure and per-user rating (unchanged contracts)

- `/api/route-preference-tags` exposes the new preference keys and labels next to the existing admin-exposed tags; anonymous visitors see the same list.
- Users rate each preference with the existing five levels (`neutral`, `avoid`, `try_to_avoid`, `prefer`, `strongly_prefer`) through the existing profile UI and `/api/tag-priorities` storage; anonymous users keep the localStorage fallback. No new database tables, API endpoints or request fields are introduced.
- Stored levels for preferences removed from the catalog are ignored but kept, exactly like the existing unexposed-tag rule (`TagPriorityManager::getPriorities`).
- `neutral` rates produce no routing effect for the preference, as today.

### R4 — Routing evaluation with alternatives

- `StreetPartAspects::routingAreas()` evaluates both modes over **approved structured tags only**, per street part and preference, at any valid direction and index, with exact parsed-segment matching (no substring or numeric bin-token confusion; each existing rule of aspects R6 carries over).
- **`any_element`:** the part's corridor matches if any element of the preference's type carries an approved value in `values` (unchanged behavior).
- **`no_alternative`:** the part's corridor matches iff
  1. at least one element of the type exists on the part (at any direction/index, with or without an approved value for the aspect), and
  2. no element of the type carries an approved value for the aspect outside `values`.

  Consequences, per the confirmed decision: an element without any approved value for the aspect never exempts a part; a part whose elements all have unknown values matches (incomplete data does not make a way acceptable); a part with **no** element of the type does not match (the preference is about the quality of available elements of that type, not their absence); among several elements of the type, one explicitly non-selected value exempts the part.
- Pending (unapproved) tags never influence routing; the approved value is authoritative per exact key, competing pending values never match.
- Matching corridors feed the existing GraphHopper per-request custom model unchanged: with the existing factors — `avoid` → 0.1 for `aspect:` keys, `try_to_avoid` → 0.3 (corridor penalized, never forbidden), `prefer`/`strongly_prefer` → 0.8/0.5 multiplied *outside* the matching corridors. Parts that match no rated preference, and ways without street parts at all, are unaffected.
- Multiple rated preferences evaluate independently; avoid and prefer ratings combine as today (statements accumulate, one statement per tag/preference).

### R5 — Compatibility

- `aspect:footwalk-wide` keeps its key, meaning and `any_element` matching; a user's stored rating keeps producing today's routes.
- Existing API contracts, `UserTagPriority` storage, translation sync and the composer are untouched; the only backend changes are catalog content, `AspectCatalog` validation, `StreetPartAspects` matching, and any keys the new catalog entries add.
- Fresh installs need no new migrations; the catalog file is the only shipped data change.

## Acceptance criteria

- **AC1 (R1):** A catalog preference with `match` missing or set to `sometimes` fails the catalog read; `/api/editing-catalog` responds 503 `editing_vocabulary_unavailable` and structured saves persist nothing. Restoring a valid file recovers without a restart (existing behavior).
- **AC2 (R2/R3):** With the shipped file, `/api/route-preference-tags` lists the three footwalk surface preferences with English/German labels next to `aspect:footwalk-wide`; a user rates `aspect:footwalk-surface-paving_stones` `avoid` and a later `GET /api/tag-priorities` (or profile reload) returns it; an anonymous visitor can set the same level locally.
- **AC3 (R4):** Given a street part whose only approved footwalk tags are `way.footwalk.west.1.surface = "paving_stones"`, the user's route request produces a corridor area for that preference in the custom model with factor 0.1; the same request for a neutral/anonymous user produces the neutral route.
- **AC4 (R4):** Given the street part R7 fixture (west footwalk asphalt, east footwalk paving stones), the paving-stones preference does **not** match (corridor unaffected) — an observed asphalt footwalk is an alternative; the same part does match an `any_element` paving-stones preference if one is configured.
- **AC5 (R4):** Given a part with `way.footwalk.west.1.surface = "paving_stones"` and a second footwalk with only width tags (no surface at all), the paving-stones preference matches — unknown values are not alternatives.
- **AC6 (R4):** Given a part whose elements are only `roadway`/`cycle_lane`/`parking` (no footwalk), the paving-stones preference does not match.
- **AC7 (R4):** Given the paving-stones surface tag exists only as a pending (unapproved) observation, routing is unaffected; approval makes it take effect.
- **AC8 (R5):** With the shipped file, a stored `prefer` rating for `aspect:footwalk-wide` still matches `way.footwalk.east.1.width = ">2"` and penalizes everything outside the corridor, as before.
- **AC9 (R3/R4):** Removing the paving-stones preference from the catalog removes it from the offered settings; the user's stored `avoid` level is ignored (and kept suitably) and the route request produces no statement for it.
- **AC10 (R1):** A `no_alternative` preference listing `paving_stones` and `concrete` matches a part whose only approved surface is `paving_stones`, and exempts a part that also has an approved `asphalt` footwalk.

## Scenarios (evaluation matrix)

Given preference `type=footwalk`, `aspect=surface`, `values=[paving_stones]`, `match=no_alternative`, rated `avoid`:

| Street part approved content | Matches? | Why |
| --- | --- | --- |
| footwalk west 1 `surface=paving_stones` | yes | only element, value selected |
| footwalk west 1 `surface=paving_stones`, footwalk east 1 `surface=asphalt` | no | asphalt is an explicit alternative |
| footwalk west 1 `surface=paving_stones`, footwalk east 1 (width only, no surface) | yes | unknown is not an alternative |
| footwalk west 1 (no surface tags at all) | yes | ≥ 1 footwalk, no alternative observed |
| roadway, cycle lane, parking only | no | no footwalk exists |
| footwalk west 1 `surface=asphalt` | no | single element is an alternative |
| paving_stones only as pending tag | no | pending tags never route |

The same content with `match=any_element` (e.g. a configured "any paving stones" preference): rows 1, 2, 3 match; rows 4–7 do not.

## Open questions / assumptions

- **Assumption (confirmed):** centrally defined preferences; per-user composition stays out of scope until requested.
- **Assumption (confirmed): unknown-not-alternative** applies symmetrically to `prefer` ratings: preferring asphalt (`any_element`) is unaffected by unknowns, because prefer matching stays `any_element` in the shipped file. Should someone configure a prefer-flavored `no_alternative` preference, the R4 rule applies unchanged.
- Zero elements of the type never match; a separate "prefer ways that have a footwalk at all" preference would be a future catalog entry, not part of this revision.
- Whole-way vocabulary tags (e.g. `surface` on a footway) remain outside this spec; only structured cross-section tags (`way.*` namespace) are evaluated here.
- Match modes concern only *routing corridor matching*; the composer, moderation and translation behavior of aspect tags are untouched.
- Catalog preferences may reference retired aspect values (existing rule); they keep matching already-stored approved tags but cannot be re-selected by new observations.

## Verification

- **Passed — AC1:** `AspectCatalogTest` — a preference without `match` or with `match: "sometimes"` fails the catalog read (503 contract unchanged); valid files including the shipped entries pass.
- **Passed — AC2/AC3–AC7/AC10:** full backend suite — 147 tests, 1153 assertions (`LegacyAspectConverterTest` extended for the match field). `MapEditingTest::testSurfacePreferencesOnlyMatchWithoutAnObservedAlternative` covers the evaluation matrix: only-paving matches, paving+asphalt exempt, paving+unknown matches, parts without footwalks unaffected, unapproved tags never route, `avoid` factor 0.1 with `in_` corridor conditions; `testRoutingMatchesTypeAndAspectAtAnyIndex` keeps `any_element` behavior (AC8). `TagPriorityControllerTest` asserts the full priorities map with the three new neutral keys.
- **Passed — AC2:** live API checks — `/api/route-preference-tags` returns the three surface preferences with labels; `/api/i18n/tag-keys/de` returns the German targets (`Gehweg aus Pflastersteinen` etc.); XLIFF `lint:xliff`.
- **Passed — AC2 (browser):** a temporary Playwright check against the dev server (both locales): the profile renders "Footwalk with paving stones" / "Gehweg aus Pflastersteinen" with level selects; deleted afterwards. `scripts/testing/check-map-editing.mjs` regression passed.
- **Passed:** `npm run build` (TypeScript/Vite/PWA), Symfony `lint:container`, `lint:twig`, `doctrine:schema:validate --skip-sync`, `git diff --check`.
- **Pre-existing issue, not this change:** `scripts/testing/check-routing.mjs` fails on this branch because the "Click the map to set a start and an end point" hint was removed in `0a98e83` ("ui improvements"); the script predates that commit and is stale independently of this feature.
- **Legacy conversion note (R1/R5):** `LegacyAspectConverter` imports legacy preferences as `any_element` and drops file preferences whose aspect the authoritative legacy deployment never defined, so exported legacy catalogs remain valid.
- No live GraphHopper request was made; corridor matching and custom-model factors were tested, consistent with the existing specs.
