# Backend admin panel

**Status:** As-built specification, reverse-engineered from the existing implementation.
**Code:** `backend/src/Controller/Admin/`, `backend/templates/admin/`, `backend/src/Controller/SecurityController.php`, `backend/config/packages/security.yaml`; moderation and translation services under `backend/src/Service/` and `backend/src/Doctrine/TagApprovalListener.php`.
**Scope:** The Symfony/EasyAdmin browser interface for access management, map records, observation moderation, tag configuration and bilingual translations.
**Related:** [api-authentication](../../authentication/api-authentication/spec.md), [editor-observations](../../map-editing/editor-observations/spec.md), [api-tag-settings](../../tag-settings/api-tag-settings/spec.md), [api-routing](../../routing/api-routing/spec.md).

## Problem / intent

Admins need to manage who can contribute, review contributions before publication, and configure the tag vocabulary, routing settings and labels used by AccessApp. The backend provides a server-rendered admin panel, separate from the Vue map interface.

## Requirements

### R1 — Authentication and permissions

- `GET /admin/login` publicly displays an email/password form with CSRF protection. Invalid credentials return to the form with an error; successful admin login opens the admin panel.
- All other `/admin` pages and actions require `ROLE_ADMIN`. Anonymous visitors are redirected to login; authenticated users without admin rights receive a styled HTTP 403 page with login/logout links.
- Admin browser authentication uses its own session firewall context; logging into the JSON API does not establish an admin login. `ROLE_ADMIN` inherits `ROLE_EDITOR`.
- The sidebar offers Log out through `/admin/logout`.

### R2 — Entry point and navigation

- `/admin` displays the title **AccessApp Admin** and redirects to the Users list rather than a statistics dashboard.
- The sidebar exposes these sections in order:

| Section | Pages |
| --- | --- |
| Access | Users; User roles |
| User settings | Route preference tags |
| Translations | Tag translations |
| Map data | Nodes; Pending observations; Tags; Default observation tags; Node tags; Node types; Node providers |

- Ordinary entity pages use EasyAdmin lists, search, pagination and create/edit/delete controls. Review pages have the restrictions in R5. Custom queue and translation routes re-enter through the dashboard when needed to obtain the EasyAdmin layout context.

### R3 — Users and roles

- Users expose name, email, password and assigned user roles. Search matches name or email. Password input appears only on forms; password hashes are not displayed in lists.
- Creating a user requires a password. Saving a supplied password hashes it; leaving it blank on edit preserves the stored password.
- User roles expose a searchable name, such as `admin` or `editor`; these produce security roles such as `ROLE_ADMIN` and `ROLE_EDITOR`.

### R4 — Map records and shared tags

| Page | Exposed fields | Search |
| --- | --- | --- |
| Nodes | Node type, provider, provider node ID, latitude, longitude, node-tag associations, creator | Provider node ID |
| Tags | Raw key and value | Raw key or value |
| Node tags | Node, tag, creator | Node's provider ID, tag key/value, creator's name |
| Node types | Name | Name |
| Node providers | Name | Name |

- Node creation/update timestamps are managed by the entity and configured as detail-only fields, never editable inputs. Node approval is not an editable form field.
- A node edit screen places the form and pending-tag review beside a lazy-loaded frontend map preview centered on the node at zoom 18. Preview uses the configured frontend URL (default `http://localhost:5173`).
- Local-provider nodes additionally show a read-only raw custom-tag table, with an empty message when no custom tags exist.
- Tag lists display published translated keys and values when available; forms and search use raw identifiers. Tags also show their node assignments and attribution. Node-tag pages show the creator.
- A tag is a shared key/value record: editing its identifiers changes it everywhere it is referenced. There is no global approval switch on Tags or Node tags; approval belongs to a tag's assignment to a node or area.

### R5 — Pending observation queue and review screens

- **Pending observations** combines nodes and areas (`NodeGroup` relations) that have at least one unapproved tag. A node's own approval flag does not determine queue membership.
- Each row shows type (`Node` or `Area`), observed record, distinct pending tags, record creator, latest pending-tag change time and a Review link. Rows are ordered by latest pending-tag change, newest first. The combined table has no search or pagination controls.
- An empty queue displays **No pending observations.**
- Node review shows pending tags, a map preview and an **Edit node** link. Area review shows name, member count, creator, links to member nodes and a preview centered on the first member (Flensburg coordinates `54.791, 9.437` when there are no members).
- Review pages do not offer record creation, deletion, batch deletion or a general record Save form. Area membership is managed through the map, not an admin area CRUD page.
- Pending tags are grouped into expandable key sections, with the first section open. Identical tags from multiple contributors share one row and observation count; values with more observations appear first within a key.
- **Next pending** visits the next record of the same kind in ascending ID order, wrapping to the first. Node navigation preserves node-edit versus review mode. With no matching pending records, it returns to the combined queue with a warning. This traversal order differs from the queue's newest-first order.

### R6 — Approving and declining observations

- **Approve** approves every contributor's assignment of the selected shared tag on this node or area. Assignments on other nodes/areas retain their own approval state.
- Approval removes other unapproved assignments with the same key on the same record, while retaining already-approved tags. Approving a node tag also approves its node. Published observations become visible to everyone, as specified in [editor-observations](../../map-editing/editor-observations/spec.md).
- **Decline** deletes all unapproved assignments of the selected shared tag on this record. It preserves the shared tag itself, the node/area, approved assignments, other values and assignments elsewhere. There is no rejection-history record or reason field.
- Approve and Decline are admin-only POST actions with CSRF protection. Missing assignment IDs return 404. Invalid action tokens are rejected; declining an assignment approved since rendering returns HTTP 409 without deleting it.
- Successful actions return to the referring admin screen when accepted by the controller, otherwise to the combined queue. Resolving the last pending tag removes the record from the queue on reload.

### R7 — Reviewing proposed translations

- Each grouped observation has editable raw key/value fields and German/English key/value translation fields, prefilled from proposals or published labels, plus **Save changes**, **Approve** and **Decline**.
- Save changes stores identifiers and translation proposals on the shared tag without approving the observation or modifying published catalogs. Proposals are not runtime label overrides.
- Approve saves reviewed fields and publishes translations to `tag-translations/tags.{de,en}.xlf` before approving the assignments. Clearing a reviewed translation removes that published label; renaming identifiers publishes under the new identities and preserves old catalog entries.
- Raw key/value fields must be nonblank, at most 255 characters, XML-compatible and must not duplicate another tag's pair. Translation fields allow empty text and at most 20,000 XML-compatible characters.
- Stale tag/proposal versions, changed catalog targets and invalid review fields prevent approval; the full review form responds with HTTP 422 and retains the draft with errors. Publication failure leaves the observation pending. Decline has an independent form and does not depend on draft translation validity.

### R8 — Route preferences and observation vocabulary

- **Route preference tags** manages unique raw tag keys and an optional GraphHopper road-class choice. Search matches the raw key. No class means the preference applies to app nodes; a mapped class also supports avoidance of matching OSM ways, as detailed in [api-routing](../../routing/api-routing/spec.md).
- **Default observation tags** manages unique raw tag keys and ordered default values. An empty value list permits free-form input. OSM and custom keys are both allowed. Lists use published key labels; forms/search retain raw keys.
- These settings feed the public `/api/route-preference-tags` and `/api/default-observation-tags` contracts in [api-tag-settings](../../tag-settings/api-tag-settings/spec.md). The existing panel provides a global suggestion vocabulary; per-feature-type vocabularies and aspect-based routing in later design specs are outside this as-built scope.

### R9 — Published translation catalog editor

- **Tag translations** (`/admin/translations`, GET/POST) displays grouped keys and contextual values with German and English target fields, one shared Ignore checkbox per unit, category and expandable notes. Raw unit identities are not editable.
- Search matches raw keys/values, source text, notes and either language. **Untranslated only** includes a key/value context missing a target in either language. Category filters are All, OSM and Custom; Ignore filters are Not ignored (default), All entries and Ignored. Filters combine and persist across saves and pagination.
- Pagination uses 25 matching key/value contexts per page, sharing each parent key row once. Keys may remain as context for matching values despite their own metadata filters. No matches produces an explicit empty message.
- **Save all changes** submits the displayed page in one CSRF-protected batch for both languages. Save before changing pages or filters; there is no autosave. Empty targets restore label fallback; untouched fields are not rewritten. Missing language counterparts can be created for known units.
- Ignore writes `tag:ignore` to both catalogs. An ignored key makes its values effectively ignored without changing their own flags; these values show **Ignored via key**. Ignore filters translation work and does not disable published labels.
- Saves preserve source text, identities, notes and generated key/value references. Changed targets become `translated`, or `initial` when empty. Admin edits and catalog sync share Symfony file locking; both catalogs are validated and staged before replacement.
- Stale changed targets/ignore flags, duplicate or unknown units, invalid XML/text, invalid CSRF and file-save errors reject the batch and retain submitted drafts with HTTP 422. Successful saves redirect with HTTP 303 and a success message. Invalid filter query parameters return HTTP 400.
- Published catalogs are file-backed, separate from Doctrine data; production requires writable persistent catalog storage. Frontend reload fetches updated labels through the existing i18n API. Catalog generation/sync and deployment/backup procedures remain documented in `README.md`.

## Acceptance criteria

- **AC1 (R1–R2):** An anonymous `/admin` request redirects to login; invalid credentials show an error; a valid admin login reaches Users and the sidebar; an authenticated editor receives the styled 403 page.
- **AC2 (R3):** Creating a user stores a hash, editing with an empty password preserves it, and supplying a new password replaces it; assigned roles determine access.
- **AC3 (R4):** Node forms expose the listed fields without timestamps/approval inputs; node edit shows its centered map preview. Tag forms retain raw identifiers while lists resolve published labels and attribution.
- **AC4 (R5):** A node and area with pending tags appear together with correctly typed review links; approved-only records do not appear. Review groups identical observations, counts contributors and offers no record Save/Create/Delete controls.
- **AC5 (R5):** Next pending wraps within the current record kind; exhausting that kind returns to the queue with a warning. An entirely empty queue displays its empty message.
- **AC6 (R6):** Approving a grouped value approves its contributors here, removes competing pending same-key values here and approves the node where applicable; approved values and assignments elsewhere survive.
- **AC7 (R6):** Decline removes only grouped pending assignments here. Invalid tokens cannot mutate them; a stale decline after approval returns 409; a missing assignment returns 404.
- **AC8 (R7):** Save changes retains a pending proposal without publishing labels; Approve publishes reviewed translations under edited identifiers. Duplicate identifiers, invalid fields, stale proposals or changed targets prevent approval and preserve the draft.
- **AC9 (R8):** An admin-created route preference appears in its public metadata API; a default observation key with values appears in its API, while an empty value list leaves input free-form.
- **AC10 (R9):** A single catalog save publishes German/English edits and shared ignore flags; ignoring a key hides its values from Not ignored while runtime labels remain available. Filters survive pagination and saves.
- **AC11 (R9):** A stale edit in either language rejects the whole changed batch, leaves both files unchanged and retains drafts. Invalid CSRF, unknown/duplicate units and invalid XML also reject the batch. Clearing targets restores fallback.

## Assumptions / boundaries

- This records current behavior; it does not propose a redesign. The existing Doctrine persistence, EasyAdmin/Twig UI and SecurityBundle form login remain the baseline.
- No new API, migration or implementation tasks are required by this documentation change. OSM upstream editing, bulk moderation, audit history, granular admin permissions and future aspect/vocabulary designs are not provided by this panel specification.
- There are no unresolved product decisions for describing the current panel. Detailed database deletion constraints and complete framework CRUD error presentation are not specified here.
- Node-tag forms expose attribution associations, but successful manual creation/reassignment is unverified: the entity associations are readonly and have no setters. The field inventory is not evidence that all generic CRUD writes work. User/role/type/provider writes also lack dedicated CRUD test coverage; no last-admin protection or friendly referenced-record deletion behavior is established.

## Verification

Code and existing tests were inspected; runtime acceptance criteria were **not executed in this session**. Relevant evidence and checks:

| Criteria | Existing tests under `backend/tests/` |
| --- | --- |
| AC1 | `Controller/AdminDashboardTest.php`, `AdminLoginFormTest.php` |
| AC2 | Controller implementation inspected; no dedicated password-preservation CRUD test identified |
| AC3 | `Controller/NodeCrudTest.php`, `NodeTagCrudTest.php`, `TagCrudTest.php` (partial coverage) |
| AC4–AC5 | `Controller/PendingObservationListTest.php`, `PendingObservationCrudTest.php`, `PendingRelationObservationCrudTest.php`, `NodeCrudTest.php` (navigation also inspected in code) |
| AC6–AC7 | `Controller/TagApprovalCleanupTest.php`, `CrossEditorTagTest.php`, `ModerationDeclineTest.php` |
| AC8 | `Controller/ObservationTranslationTest.php` |
| AC9 | `Controller/RoutePreferenceTagCrudTest.php`, `DefaultObservationTagCrudTest.php` |
| AC10–AC11 | `Controller/TagTranslationControllerTest.php`, `Service/TagTranslationCatalogEditorTest.php` |

For runtime verification, run `docker compose exec php vendor/bin/phpunit` from `backend/` with the test database available, then manually check the UI and coverage gaps above. Test-file references indicate existing coverage, not a passing run or proof of every listed scenario.
