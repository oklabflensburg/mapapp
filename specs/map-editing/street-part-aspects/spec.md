# Street Part Aspects (cross-section model + aspect-based routing)

**Status:** NEW FEATURE DESIGN — not yet implemented. This is the first spec in the series that specifies future behavior; the existing as-built specs are marked accordingly.
**Builds on:** street parts as implemented today (`backend/src/Service/NodeGroupManager.php`, `StreetPartArea.php` — a node group whose ordered members mark the course of a way, with a corridor area and per-editor tag moderation), routing via `Service/Routing.php` custom models and `Service/TagPriorityManager.php`. A dedicated as-built spec for street parts is still a pending step of this series.
**Decisions already taken (with the product owner):** aspects attach **per street part**; attributes are **typed** (admin-defined); routing preferences are **admin-defined** entries that users rate with levels (like today's tag priorities); aspects follow the **observation approval flow**.

## Problem / intent

A street part today can only carry flat key/value tags ("sidewalk=yes"), which cannot say *what the street is made of across its width*. This feature models the **cross-section** of a street part: an ordered sequence of **aspects** — e.g. sidewalk (width 2 m, surface asphalt), parking strip (width 2.2 m), main roadway (2 lanes), cycle lane, sidewalk — each with typed attributes. The cross-section data feeds routing: a user can prefer ways whose street part has "a sidewalk with at least 2 m width", and the router applies that along the part's corridor.

## Definitions

- **Street part** — as built: a node group of ordered member nodes along a way, with a corridor area; the unit of "part of a street".
- **Aspect** — one element of a street part's cross-section: an aspect type plus typed attribute values, at an ordered position across the street (position 1…n, editor-defined order, e.g. left → right).
- **Aspect type** — admin-defined catalog entry (e.g. `sidewalk`, `parking`, `roadway`); defines which attributes exist for aspects of that type.
- **Attribute definition** — per aspect type: a key plus its admin-defined value set. Numeric attributes are modeled as **ordered range bins** with labels like `< 1 m`, `1 m – 2 m`, `> 2 m`; enumerated attributes are value lists (e.g. surface: `asphalt`, `paving_stones`). Bins are ordered so "at least" selections are naturally expressed by selecting a suffix/any subset of bins.
- **Preference definition** — an admin-defined routing preference: aspect type + attribute + **selected range bins or enum values** + display label, e.g. width bins {`> 2 m`} — "Sidewalk at least 2 m wide". Users rate each with a level.

## Requirements

### R1 — Cross-section data model
- A street part owns zero or more aspects, ordered by position; the position is a stable sequence number across the cross-section, not a geographic side (no left/right semantics yet).
- Each aspect references exactly one aspect type; every numeric attribute stores **one of its definition's range bins** (e.g. width: `< 1 m` | `1 m – 2 m` | `> 2 m`), every enumerated attribute stores one of its value-list entries. Free numeric inputs are never stored — values are always binned labels, so they present like tag values ("1 m – 2 m") in editors, lists, and translations alike.
- Values outside the defined bins/values cannot be stored; adding a bin later is additive, removing one keeps existing aspects' stored bins valid but unroutable where no preference references them (see R4).
- Multiple aspects of the same type in one cross-section are allowed (e.g. two sidewalks) — distinguished by position, not identity constraints.
- Aspect data lives per street part: the same street can have several parts with different cross-sections.

### R2 — Editing
- The street part editor gains a cross-section composer: an ordered list of aspect rows. An editor adds a row, picks an aspect type (from the admin catalog), and fills the type's attributes by selecting a range bin from each ordered bin list (e.g. width: `< 1 m` → `> 2 m`) or a value from an enum dropdown; rows can be inserted, reordered, and removed.
- Saving a street part saves its cross-section atomically with the part; empty cross-sections are valid (a part can have only tags, as today).
- Type changes of an existing row discard values that the new type does not define.

### R3 — Approval and visibility (observation flow, unchanged rules)
- Aspects added by an editor start **unapproved** and are visible only to their creator; an admin approves them through the backend, together with the part's tags in the moderation queue.
- Approved aspects become visible to all users as part of the street part's published cross-section.
- Editor saves replace **the editor's own** aspects on that part (per-creator attribution, like tags); other users' aspects are preserved.
- Approval supersession is aspect-scoped: approving an aspect removes another editor's unapproved aspect of the **same type and position** on the same part only (values changed by re-editing go back to unapproved).
- A street part with aspects but no approved ones stays pending-review overall, exactly as today: only its creator sees it, everyone else sees neither its tags nor its cross-section.

### R4 — Admin configuration
- Admins manage, in the backend admin: the aspect type catalog, each type's attribute definitions (key, ordering, and its range bins or enum values — bins ordered and labeled, e.g. `< 1 m`, `1 m – 2 m`, `> 2 m`), and the routing preference definitions (aspect type + attribute + selected bins/values + label). Changing a type's value sets must not corrupt existing aspects: removed bins stay stored on existing aspects but are no longer offered in editors; preferences referencing removed bins simply stop matching.
- Preference definitions are exposed alongside — and in the same shape as — today's `/api/route-preference-tags`: a keyed list the profile page renders as level selectors for authenticated and anonymous users alike.

### R5 — Profile & storage of preferences
- Users set a level (`neutral` | `avoid` | `try_to_avoid` | `prefer` | `strongly_prefer`) per preference definition, reusing today's tag-priority storage contract (`/api/tag-priorities`, per-user upsert, localStorage fallback for anonymous).

### R6 — Routing evaluation
- For a request, the backend evaluates each **approved** cross-section against the user's non-neutral preference definitions: a street part matches a definition when **at least one** approved aspect of the required type has the preference's attribute set to one of the preference's **selected bins/values** (e.g. width ∈ {`> 2 m`}, or {`1 m – 2 m`, `> 2 m`} for "at least about 2 m").
- Matching street parts contribute their **existing corridor area** to the preference — the exact mechanism GraphHopper already uses for tagged street parts: avoid levels multiply ways inside the corridor area, prefer levels multiply everything outside it. No new routing mechanism is introduced.
- Parts without aspects, or whose cross-section does not match, are unaffected by that preference. Unapproved aspects never influence routing.
- The example: "prefer ways which have a sidewalk with at least 2 m width" = preference definition *sidewalk / width / bins {`> 2 m`}* rated `prefer` → routes bias toward corridor areas of street parts whose approved cross-section contains such a sidewalk.

## Acceptance criteria

- **AC1 (R1/R2):** Given an editor composes the example cross-section (sidewalk width bin `1 m – 2 m`, surface asphalt / parking width `1 m – 2 m` / roadway lanes bin `2` / cycle lane / sidewalk) on a street part, saving shows the same ordered cross-section with the same bin labels to the editor after reload.
- **AC2 (R1):** Given the width attribute offers only bins `< 1 m`, `1 m – 2 m`, `> 2 m`, no other value (typed or from another attribute's set) can be stored; the editor offers exactly those three labels.
- **AC3 (R3):** Given the editor's saved aspects are unapproved, another user sees the street part without cross-section — or the entire part not at all, per today's part-visibility rule.
- **AC4 (R3):** Given an admin approves the part, all users see the approved cross-section; a later edit by the original editor re-enters moderation without touching other users' approved aspects.
- **AC5 (R4/R5):** Given an admin defines "Sidewalk at least 2 m" (aspect `sidewalk`, attribute width, bins {`> 2 m`}), a logged-in user can rate it `prefer` and a later GET of their priorities returns it.
- **AC6 (R6):** Given a user rates that preference `prefer`, a route request prefers the corridor area of a street part whose approved cross-section contains a sidewalk with width bin `> 2 m` over a part whose sidewalk bin is `< 1 m`; the same aspect unapproved does not trigger it.
- **AC7 (R6):** Given a preference rated `avoid`, ways inside matching corridor areas are penalized (not forbidden), matching today's avoid semantics.
- **AC8 (R4):** Given an admin removes the `1 m – 2 m` bin from the width attribute, an aspect already stored with it still holds it and renders its label, new editors no longer offer it, and preferences that included it keep matching stored aspects only.

## Open questions / assumptions

- **Naming:** "aspect" is the working name (alternatives: cross-section segment, element) — to confirm before implementation.
- **Predicate quantifier:** "a sidewalk ≥ 2 m" means **any one** aspect of the type whose bin is among the preference's selected bins — assumed, confirm.
- **Bin boundaries:** bins are labels, not numeric intervals — where "1 m – 2 m" ends is admin documentation, not data; a preference expresses "at least about 2 m" by selecting the bins the admin names. Deliberate simplification to keep values presentable like tags.
- **Bin label translations:** labels are admin-authored strings; whether they need per-locale translation (like tag-key labels) is open — assumed monolingual per site language for now.
- **Geometry:** aspects are not geographically placed (no "which side of the street"), positions are just order. Drawings/shading of the cross-section on the map are out of scope for this step.
- Whether existing flat street-part **tags** stay alongside aspects (assumed: yes, both, aspects optional) — pending the street-parts as-built spec.
- GraphHopper constraint unchanged: per-request custom models can only *lower* priority, so road-class-based preferences still cannot use the prefer levels; aspect-based ones go through areas and are unaffected.

## Verification plan (once implemented)

- Backend: aspect CRUD + validation tests; moderation tests mirroring `PendingRelationObservationCrudTest.php`/`CrossEditorTagTest.php`; routing-evaluation tests mirroring `RoutingTest.php` (custom model contains the matching parts' corridor areas and correct factors).
- Frontend: Playwright e2e for the cross-section composer and the profile level selectors.
- Nothing of this exists yet; this spec is the acceptance baseline for those tests.
