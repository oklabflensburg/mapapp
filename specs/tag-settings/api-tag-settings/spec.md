# Tag Settings & i18n API (admin-configured tag metadata and user priorities)

**Status:** As-built spec, reverse-engineered from the existing implementation.
**Code:** `backend/src/Controller/TagPriorityController.php`, `backend/src/Controller/TagKeyLabelController.php`, `backend/src/Service/TagPriorityManager.php`, `backend/src/Service/TagKeyLabeler.php`, `src/stores/settings.ts`, `src/stores/tagKeyLabels.ts`
**Scope:** The metadata endpoints that tell the SPA which tags an admin exposed (route preferences), the per-user priority persistence, and the shared file-backed tag-key translation catalog for the UI. Admin CRUD for these entities lives in EasyAdmin and is out of scope.

## Problem / intent

Admins decide which tag keys are route preferences and how tag keys/values are shown in the UI. Users pick a priority level per exposed tag; the SPA needs the choices for anonymous visitors too (their priorities stay client-side). A shared bilingual catalog labels tag keys and values in the node editors.

## Requirements

### R1 — Route preference tags
- `GET /api/route-preference-tags` is public (anonymous visitors see the same settings panels read-only).
- Response: `{ "tags": ["<key>", …] }` — the tag keys an admin exposed (from `RoutePreferenceTag`), possibly with admin-configured GraphHopper road-class mappings (used by `api-routing`, not exposed here).
- Frontend: initializes with the hard fallback `['stairs', 'toilets']` and replaces it on success; a failed request keeps the fallback.

### R2 — Tag priorities (read)
- `GET /api/tag-priorities` requires authentication (`IS_AUTHENTICATED`).
- Response: `{ "priorities": { "<tag>": "<level>" } }`; levels are exactly `neutral`, `avoid`, `try_to_avoid`, `prefer`, `strongly_prefer`.
- Frontend: merges over the localStorage copy (`accessapp.settings.tagPriorities`), dropping unknown levels and non-string keys.

### R3 — Tag priorities (write)
- `PUT /api/tag-priorities` requires authentication; body `{ "priorities": { "<tag>": "<level>" } }`.
- Every level must be one of the five choices, else HTTP 422.
- Response: the stored `{ "priorities": … }` after save (per-user upsert).
- Frontend: `setTagPriority` writes locally + synchronously PUTs the whole map when logged in (fire-and-forget; failures are logged, anonymous stays localStorage-only).

### R4 — Tag key translations (i18n)
- `GET /api/i18n/tag-keys/{locale}` — `locale` ∈ {`en`, `de`}, anything else → HTTP 404 `Unsupported locale "…"`.
- Public + cacheable: the response carries a public `Cache-Control` and an ETag (SHA-256 of the body); a matching `If-None-Match` answers **304 without a body**.
- Response: `{ "locale": "<locale>", "labels": { "<key>": "<label>" }, "values": { "<json [key,value]>": "<label>" } }` from the shared file-backed catalog.
- Frontend: a locale switch replaces labels wholesale; a sequence guard makes a slow stale response never overwrite a newer one; missing labels fall back to prettified keys (`foo_bar` → human-readable), and `yes`/`no` values use the static i18n strings.

## Acceptance criteria

- **AC1 (R1):** Given an anonymous visitor, `GET /api/route-preference-tags` returns 200 with the admin-managed tag key list.
- **AC2 (R2):** Given an anonymous visitor, `GET /api/tag-priorities` is rejected (401) — priorities are per-user data.
- **AC3 (R3):** Given an authenticated user PUTting `{priorities: {"stairs": "avoid"}}`, the response contains `"stairs": "avoid"` and a later GET returns the same value.
- **AC4 (R3):** Given a PUT with level `"sometimes"`, the response is 422 and nothing is stored.
- **AC5 (R4):** Given the client sends the previous body's ETag unchanged, the response is 304 with an empty body.
- **AC6 (R4):** Given `locale=fr`, the response is 404.
- **AC7 (R1–R4, resilience):** Given any of these endpoints fails, the SPA keeps functioning with the localStorage/fallback values and only logs a warning.

## Open questions / assumptions

- Locale set is exactly `en`/`de`; adding a locale means touching `TagKeyLabeler::SUPPORTED_LOCALES` and this contract.
- The tag-priority ↔ GraphHopper road-class mapping is internal to the backend (consumed by `api-routing`), not part of any response here.
- The former default observation tags endpoint and admin CRUD have been removed; structured observations come from the aspects catalog (see [street-part-aspects](../../map-editing/street-part-aspects/spec.md)).

## Verification

- `backend/tests/Controller/TagPriorityControllerTest.php`, `TagKeyLabelControllerTest.php`, `RoutePreferenceTagCrudTest.php` cover these endpoints.
- Not executed in this session; evidence is from code reading.
