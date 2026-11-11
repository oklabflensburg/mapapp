# AccessApp

## What this project is

AccessApp is a map service that helps you find the most **comfortable** way from
A to B — a route that considers your special needs, not just the shortest
distance. Special needs can come from a disability, for example when you use a
wheelchair or are blind, or simply from preferences: you want a quiet way, a
route with less car traffic, a comfortable bike route, and so on.

You create a profile that configures your individual preferences; route
suggestions take this profile into account.

Another part of the app is an OpenStreetMap (OSM) editor, whose OSM tag
structure is much more customized to allow very detailed descriptions of routes
and buildings. Users help build that structure by submitting observations about
locations. Admin users review those observations and approve them to become
part of the map features.

Starting point: Vue 3 + Vite frontend with a PHP 8.5.12 / Symfony 8.1 backend (FrankenPHP via Docker).

## Structure

```
├── index.html, vite.config.ts, src/   # Vue frontend (root)
├── specs/                            # Specifications grouped by feature
├── scripts/
│   ├── testing/                      # Browser checks and frontend translation tests
│   ├── production/                   # Production map and translation data generators
│   └── data/                         # Cached downloads and intermediate data (git-ignored)
└── backend/                           # Symfony backend (Docker Compose)
    ├── compose.yaml                   # php (FrankenPHP) + database (PostGIS)
    ├── Dockerfile                     # frankenphp_dev / frankenphp_prod targets
    ├── frankenphp/                    # Caddyfile + PHP config
    ├── tag-translations/              # Shared XLIFF tag key/value catalogs
    └── src/, config/, public/         # Symfony app
```

See the [spec index](specs/README.md) for feature specifications and their status.

## Backend

The Compose project is `map`; containers and default volumes use the `map`
prefix. PHP and routing images also use the `map` prefix. Run both the frontend
and backend from this checkout; the older `code/js/accessapp` checkout expects
an incompatible database schema. Existing `api_*` database and photo volumes
must be copied to their `map_*` counterparts while the old stack is stopped
before starting a renamed installation. Keep the original volumes as backups.

```bash
cd backend
docker compose up -d --wait
```

- API: http://localhost:8080/api/hello
- Database: PostGIS on localhost:5433 (database `map`; credentials from `backend/.env`)

The first start runs `composer install` inside the container (Flex generates the
bundle configuration in `config/packages/`).

Tests:

```bash
docker compose exec php vendor/bin/phpunit
```

Tests use the configured database credentials and append `_test` to the database
name (`map_test` by default). Changing `POSTGRES_PASSWORD` in `.env` does not
update the password stored in an existing PostgreSQL volume; the database role's
password must also be updated.

## Frontend

```bash
npm install
npm run dev
```

- App: http://localhost:5173
- `/api` requests are proxied to the backend on port 8080 (see `vite.config.ts`)

Production build: `npm run build` (type-checks with `vue-tsc`, then Vite build incl. PWA assets).

## Scripts

Run the commands in this section from the repository root.
`scripts/production/` prepares map data and collects tag translation entries.
`scripts/testing/` contains browser checks and frontend translation tests; Symfony
tests stay in `backend/tests/`. See [Tag translations](#tag-translations) for the
complete extraction, translation and validation workflow.

Update translation files from both OSM node data and the backend with one
command in your **host terminal**, from the repository root:

```bash
npm run translations:sync
```

It prepares the Flensburg OSM source if missing, extracts node tag keys and
values, and syncs those together with stored backend tags and configured choices
into both language catalogs. Existing translations are preserved. The backend
Docker PHP service must be running. The command handles container file copies,
PHP execution and cleanup automatically.

| Production command | Purpose |
| --- | --- |
| `npm run translations:sync` | Refresh translation entries from OSM nodes, ways, relations and backend tags in one run. Uses host Node.js, Python 3 and Osmium, plus the Docker PHP service. First-time OSM preparation also needs `curl`. |
| `bash scripts/production/update-map-and-translations.sh` | Rebuild map GeoJSON, then run the same combined translation sync. |
| `bash scripts/production/prepare-flensburg.sh` | Download and process OSM data into `public/data/flensburg.geojson`. Requires `curl` and `osmium-tool`. |
| `bash scripts/production/extract-osm-tags.sh [input.pbf]` | Extract tag frequency TSVs from nodes, ways and relations into `scripts/data/`. Requires `osmium-tool` and Python 3. |
| `bash scripts/production/extract-node-tags.sh [input.pbf]` | Extract node tag frequency TSVs into `scripts/data/`. Requires `osmium-tool` and Python 3. |
| `node scripts/production/generate-tag-key-catalog.mjs [keys.tsv] [options]` | The translation sync entry point used by npm; explicit inputs and options allow collecting selected sources. |

Use `npm run translations:sync -- --local` to run PHP on the host instead of
Docker. Source PBFs, generated TSVs and map GeoJSON are git-ignored; the XLIFF
catalogs are committed to Git. Sync collects entries for translators and does not
automatically translate them.

For browser checks, install dependencies with `npm install` and Chromium with
`npx playwright install chromium`, then start the dev server.

| Testing scripts (under `scripts/testing/`) | Requirements |
| --- | --- |
| `check-deep-link.mjs`, `check-node-creation.mjs`, `check-node-relation.mjs`, `check-node-selection.mjs`, `check-routing.mjs` | Dev server at `http://localhost:5173` (override with `APP_URL`); use intercepted fixtures. |
| `check-osm-render.mjs`, `check-square-selection.mjs` | Dev server on port 5199 and generated `public/data/flensburg.geojson`. |
| `check-node-editing.mjs` | Dev server on port 5199, backend on port 8080, and generated GeoJSON. Writes test tags to the configured backend; use a development instance. Supports `APP_URL` and `API_URL`. |

Example: `node scripts/testing/check-node-creation.mjs`.

Frontend translation tests need Node.js and `npm install`, without a running
browser, backend or dev server:

```bash
node --test scripts/testing/tag-translations.test.mjs
```

## Adding nodes

Press the map's **Add node** (+) button to enter edit mode, then click the map
to place nodes; each click adds another one and nothing is sent yet. A dialog
below the controls shows the placed count, a name input, the **parent node**
(the first placed node, the anchor of the pending relation) and Save/Abort.
The first click chooses the parent for the batch: clicking an OSM feature attaches
all placed custom nodes directly to it. On empty space, the first custom node
becomes the parent and every following node is its direct child. Clicking an
existing child uses its parent, so creation never adds another hierarchy level.
Saving commits the batch: a single node becomes a plain node — the entered
name becomes its `name` tag and its tag editor opens — while two or more nodes
become members of a new node relation named with the entered name, whose tag
editor then opens. Abort (or Escape) discards the placed nodes and leaves
edit mode. Failed saves keep the placed nodes; Retry re-sends exactly the
unsaved ones.

Apply database migrations after updating the backend:

```bash
cd backend
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

`POST /api/nodes` accepts `provider` (`OSM` by default, or `local`),
`providerNodeId`, coordinates and tags. Local nodes use a generated UUID as their
provider ID; reusing that identity updates the node. An optional `parent` is
either `{ "id": 123 }` for a saved node or an OSM reference containing
`providerNodeId`, `latitude`, and `longitude`. Each node stores one nullable
`parent_id`; only custom nodes can be children. A parent can have multiple direct
children, and children cannot have children. Ordinary tag saves preserve the
parent; attempts to add a different parent are rejected. Responses retain
`provider`, `parentIds`, and `childIds`; `parentIds` and `parents` contain at most
one entry for API compatibility. The `parents` and
`children` summaries include each related node's ID, provider, provider ID,
nullable saved name and saved custom `tags`. The edit panel displays these
relations above the tags, including OSM reference parents that do not appear as
user markers. Each parent shows read-only OSM and custom tags in separate
groups. Original OSM tags come from the shared map dataset, including buildings
and areas; unavailable OSM data is distinguished from a parent with no tags.

The hierarchy migration flattens existing descendants to their root. It stops
for ambiguous root parents or cycles without a root, requiring those records to
be corrected before retrying. Rolling back restores the relation table using
the flattened links.

Run the deterministic creation browser check with the dev server running:

```bash
node scripts/testing/check-node-creation.mjs
node scripts/testing/check-node-selection.mjs
```

It uses intercepted API/OSM fixtures and does not change the development database.

The selection check covers overlapping children and highlighted OSM parents.
When saved markers overlap, the most recently created node receives the click;
editing an older node does not change that priority.

## Node relations

Logged-in editors can group multiple nodes into a **node relation** — for
example the tagged part of a road. Relations are usually built in edit mode:
place two or more nodes with the + button, optionally name the relation in
the dialog, and Save creates the relation from them (see "Adding nodes").

Existing nodes and OSM features can be related too: press the **Create a node
relation** (link) button, then click nodes or map features to add them
(clicking a member again removes it). Once two or more nodes are selected,
**Create relation** saves the relation and opens its tag editor. Press Escape
to leave relation mode.

Relations are street parts: their area is the corridor of a fixed half-width
(1 m around the member line, computed by PostGIS in
`backend/src/Service/StreetPartArea.php`). Routing uses that corridor, but
the map draws only the line through the members in order, rendered in amber
at a minimal, constant on-screen width: a dashed preview connects the
members collected in relation mode, and every saved relation shows its
member line. While browsing, clicking a relation's line selects the
relation; otherwise the lines are visual aids only — clicks pass through to
the nodes and features beneath them.

Routing uses these corridors as GraphHopper custom areas: when a logged-in
user has a route preference for a tag carried by a relation (see the
profile page), the corridor enters the request's custom model — avoid
levels block edges inside it, prefer levels penalize edges outside it
(`Routing::customModelFor()`), just like the circles around individually
tagged nodes.

The relation's tag editor works like the node editor: new tags are unapproved
observations; approved ones become read-only. Selecting a member node lists
its relations in the node panel under "Node relations", with an edit button
that opens the relation's tag editor. Admins review unapproved relation tags
in the admin UI under "Pending relation observations", mirroring the pending
observations queue for nodes.

`GET /api/node-groups` lists relations with their name, ordered member
summaries, tags and observations (visible tags follow the same approval rules
as node tags). `POST /api/node-groups` (editors only) creates a relation from
`members` — `{"id": 123}` for a saved node or an OSM reference with
`providerNodeId`, `latitude` and `longitude`; unknown OSM references are
materialized — and requires at least two members; an optional `name` names
it. Re-posting with an `id` updates it: members are additive (already-present
nodes are skipped), a non-null `name` replaces the current one, and the
request `tags` replace the current user's own tags, as with nodes.

Run the deterministic relation browser check with the dev server running:

```bash
node scripts/testing/check-node-relation.mjs
```

## OSM map data (Flensburg)

The map renders a read-only OSM feature layer (buildings, parks, streets, POIs
with their tags) from `public/data/flensburg.geojson`. The file is generated by
`scripts/production/prepare-flensburg.sh` and git-ignored; rebuild it with:

```bash
brew install osmium-tool   # once
scripts/production/prepare-flensburg.sh
```

The script downloads the Geofabrik Schleswig-Holstein extract, selects a
bounding box around Flensburg (`9.35,54.75,9.51,54.84` in west, south, east,
north order), keeps tagged features only (`building, leisure, amenity, shop,
tourism, natural, highway, name`), and exports GeoJSON. The download is cached
in `scripts/data/`.

A quick render check (needs the dev server on port 5199):

```bash
npm run dev -- --port 5199 &
node scripts/testing/check-osm-render.mjs
```

## Tag translations

Tag labels have one source of truth: the XLIFF 2.0 files in
`backend/tag-translations/`, one per language:

| File | Language |
| --- | --- |
| `tags.en.xlf` | English |
| `tags.de.xlf` | German |

OSM tags and tags created by users use the same catalogs. They supply labels for
node and relation tag tables, suggested observation keys and values, profile tag
priorities, and the admin tag list. General interface text, such as buttons and
validation messages, remains in `src/i18n/locales/`.

Translations affect display labels. Raw keys and values stay unchanged in the
database and tag APIs, and dropdown choices submit their raw values. Unknown or
untranslated keys replace underscores with spaces (`tactile_paving` becomes
`tactile paving`). Untranslated values remain verbatim, including names, URLs,
numbers and free text. There is no database override for translated labels.

### How catalog entries work

Each `<unit>` represents either a key or a value in the context of its key:

| Unit `name` | Meaning |
| --- | --- |
| `key:wheelchair` | Label for the key `wheelchair` |
| `value:["wheelchair","yes"]` | Label for the value `yes` under `wheelchair` |
| `value:["name","yes"]` | Separate label for the value `yes` under `name` |

Generation also writes reciprocal references using the namespace
`xmlns:tag="urn:accessapp:tag-references:1"` on the XLIFF root:

| Attribute | Meaning |
| --- | --- |
| `tag:valueRefs` on a key unit | Space-separated IDs of its contextual value units. |
| `tag:keyRef` on a value unit | ID of its key unit. |
| `tag:category` on key and value units | `osm` for OSM inputs; `custom` for database inputs. |
| `tag:ignore` on key and value units | `true` or `false`; an admin review flag, defaulting to `false`. |

For example, a key unit with `id="key_house"` can have
`tag:valueRefs="value_house_marktwache"`, and that value unit has
`tag:keyRef="key_house"`. These refer to actual unit IDs in the same file.
Given a key, follow its `tag:valueRefs`; given a raw value, find its contextual
value units and follow their `tag:keyRef`. A value such as `yes` can belong to
several keys, so reverse lookup can return several keys. These links include
untranslated entries too.

OSM key/pair TSV inputs are categorized as `osm`. Tags stored in the database,
admin-configured observation choices and route preference keys are categorized
as `custom` unless that same unit is also known from OSM. OSM takes precedence
for overlapping entries, and a database-only sync preserves an existing `osm`
category. Categories apply per key or contextual value: a custom value can belong
to an OSM key. Entries absent from the inputs retain their existing category.

`npm run translations:sync` rebuilds the references from all retained catalog
pairs, including entries absent from the current inputs. Existing unit IDs,
translations, translator notes and review states are preserved. Redundant
generated `Tag value: …` notes are removed; their context is already stored in
the unit name and key/value references. New values are linked in
both directions, and repeated generation does not duplicate references. Target
edits in the admin preserve these attributes; newly approved pairs receive their
references on the next generation run. Preserve `tag:keyRef`, `tag:valueRefs`
and `tag:category`, plus `tag:ignore`, when editing catalogs in translation software.

Context prevents a translation for one tag from changing the same value under
another tag. The contextual unit name and references identify its key without a
repeated value note. For example, a German value entry looks like this:

```xml
<unit id="value_wheelchair_yes" name="value:[&quot;wheelchair&quot;,&quot;yes&quot;]"
      tag:category="osm" tag:ignore="false" tag:keyRef="key_wheelchair">
  <segment state="translated">
    <source>Yes</source>
    <target>Ja</target>
  </segment>
</unit>
```

Open the `.xlf` files in translation software that supports XLIFF 2.0, or edit
`<target>` text directly. Preserve unit `id` and `name` attributes, source text,
and context notes. When editing XML by hand, escape characters such as `&` as
`&amp;` and `<` as `&lt;`.

New entries have empty targets and `state="initial"`. Complete targets in each
language file, including English where a raw identifier needs a friendlier label.
The sync script discovers entries; it does **not** perform machine translation.
Setting a review state alone does not activate or hide a translation: the app uses
nonempty targets and falls back for empty ones.

### Edit translations in the admin

Open **Translations → Tag translations** in the backend admin (or visit
`http://localhost:8080/admin/translations`). Only admins can access this page.

The editor groups each key and its contextual values in a bordered block, with
a shaded key row and indented value rows. Spacing separates different keys. Each row
has four columns: the raw **Key / value**, **Translation German**,
**Translation English**, and the **Ignore** checkbox. Search matches tag keys, values, source text, notes and
translations in either language. **Untranslated only** includes tags missing a
translation in either language. Results show 25 matching tags per page, with a
shared key row shown once above its values. Context notes are under **Notes**.

Use **Category** to select all entries, OSM, or Custom. **Ignore** selects all,
ignored, or not ignored entries. **Not ignored** is the default. Both filters combine with search and
**Untranslated only**, and stay selected across pagination and saves. A parent
key can remain visible as **context** for a matching value even if that key does
not match the metadata filters itself. Entries without a category appear as
**Uncategorized** and are included under all categories.

Each key/value row has an **Ignore** checkbox. **Save all changes** writes its
`tag:ignore` flag to both German and English catalogs alongside translation
edits. Ignoring a key also hides all of its values from **Not ignored**, without
changing their own saved flags. **All entries** and **Ignored** show these values
with an **Ignored via key** marker, so the group can be reviewed and restored.
Unchecking the key restores values whose own flags are not ignored. Missing flags
mean `false`.
Generation adds `tag:ignore="false"` where missing and preserves existing flags,
including `true`. Ignore marks translation work for filtering; it does not
remove published labels from the app. Stale ignore edits reject the entire
batch and preserve the checkbox and text drafts.

Edit any fields on the displayed page and click **Save all changes** once to
save both languages together. Save before changing filters or moving to another
page. Clearing a field returns that label to its normal fallback. Untouched
fields are not rewritten, and entries present in only one catalog can be
translated into the other language from the same form.

Saving updates `tags.de.xlf` and `tags.en.xlf` directly and sets changed entries
to `translated` (or `initial` for empty targets). Source text, unit identities,
notes and generated key/value references are preserved. Both files are locked
and all edits are validated and staged before either file is replaced. A stale
edit rejects the entire save, preserving both catalogs and showing all submitted
drafts in the form. There is no separate translation database. The app uses the
changed labels after reloading the frontend page. Admin saves and generation
share the same file locks.

In development, changes are written to `backend/tag-translations/` in your
checkout. Review and commit them as usual. The production Docker image makes
this directory writable by PHP, and Compose stores it in the persistent
`tag_translation_data` volume so edits survive container recreation. This volume
is initially seeded from the image's catalogs; later image deployments do not
overwrite its edited files. The production override explicitly mounts that
volume at `/app/tag-translations`, including when the development override is
also present. Its explicit Docker volume name defaults to
`api_tag_translation_data`, preserving the existing volume and remaining stable
when the deployment project name changes. Set `TAG_TRANSLATION_VOLUME_NAME` for
a separate installation and keep that value stable across its deployments.

Use the production Compose files explicitly when deploying, from `backend/`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait
```

This recreates the application container while reusing the existing catalog
volume. The development bind mount stays in `compose.override.yaml` for editing
catalogs in the checkout. No startup script copies image files over an existing
catalog volume. If adopting the volume on an existing installation that stored
catalogs elsewhere, copy its current XLIFF files into the volume before directing
traffic to the new container.

Back up or export the volume's catalogs separately
from the database, and copy reviewed catalog updates into the volume explicitly
when deploying changes made elsewhere. For example, from `backend/`:

```bash
# Export a production catalog for review or backup.
docker compose -f compose.yaml -f compose.prod.yaml cp \
  php:/app/tag-translations/tags.de.xlf /path/to/backup/tags.de.xlf
```

Custom deployments need a persistent, writable catalog directory. If multiple
PHP instances share it, configure `LOCK_DSN` to use a shared Symfony Lock store;
the default `flock` store coordinates processes in one container.

### Review observation translations

Under **Map data → Pending observations**, open a node's edit/review page.
Each pending tag shows three rows: raw **Key**, **Value** and **Observations**;
German **Key (DE)** and **Value (DE)**; then English **Key (EN)** and
**Value (EN)**. Key, value and translation fields are editable, prefilled with
the proposed tag properties (or existing published labels). **Save changes** saves the admin's changes on the tag
without approving it or changing a catalog. **Approve** saves the reviewed
key, value and translations, and writes their targets to `tags.<locale>.xlf`, then approves the
observation. The relation review screen offers the same controls. Clearing a
translation removes its published label when approved. Other language catalogs
stay unchanged unless their translations are edited.

Key/value edits update the shared tag wherever it is referenced. A key/value
pair that already belongs to another tag is rejected. Approval writes translations
under the edited identifiers; existing catalog entries for old identifiers are
preserved. Saving key/value changes alone does not publish translations.

Proposals are stored in the shared tag's `translations` JSON property, indexed
by language, with `keyTranslation` and `valueTranslation` fields. They are not
used as public labels before approval. Stale review forms and changed catalog
targets are rejected; the submitted draft remains visible. Already-published
labels can still be edited under **Translations → Tag translations**. Existing
catalog translations remain published after this update; the migration starts
existing tags with empty proposals and does not infer their authorship.

Apply the new migration before using these fields:

```bash
cd backend
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### Update translations with one command

Run this in your **host terminal**, from the repository root:

```bash
npm run translations:sync
```

The command performs the complete translation sync:

1. If `scripts/data/flensburg.osm.pbf` is missing, prepare the Flensburg OSM
   source using `prepare-flensburg.sh`. This first-time step also builds map GeoJSON.
2. Extract current node tag keys and key/value pairs from that PBF, replacing
   the intermediate frequency TSVs in `scripts/data/`.
3. Collect all stored backend tag key/value pairs, default observation keys and
   their configured values, and route preference keys.
4. Merge OSM and backend entries into `backend/tag-translations/tags.en.xlf`
   and `tags.de.xlf` in the same sync operation.

Existing targets, custom entries, notes and review states are preserved. Missing
entries are appended with empty targets. Entries remain in the catalogs even if
a tag disappears from a source. Output such as `de: 12 new translation units`
counts new entries, not completed translations. Repeated syncs with unchanged
source tags report zero new units.

Saving a user tag without translation edits does not edit a catalog automatically. Rerun this command when
you want to collect new tags for translation. It reads backend data without
changing database tags.

### Setup and Docker

The host needs Node.js, Python 3 and `osmium-tool` (`brew install osmium-tool` on
macOS). First-time OSM preparation also uses `curl`. Start the backend once:

```bash
# From the repository root, in your host terminal:
cd backend
docker compose up -d --wait
cd ..
npm run translations:sync
```

The command runs extraction on the host and PHP catalog sync inside the Docker
`php` service automatically. There is no need to enter a container shell or copy
TSV files yourself. In development, the container's `/app/tag-translations/`
is bind-mounted from `backend/tag-translations/`, so file changes appear in your
checkout immediately. The backend image mounts only `backend/` and does not
include the full workflow's Node.js, Python and Osmium tools; run the npm command
on the host.

For a configured host PHP runtime with the backend's required version, extensions
and Composer dependencies, use:

```bash
npm run translations:sync -- --local
```

This still collects both OSM tags and backend tags. `--local` selects where
PHP runs; it does not change which sources are included.

### Refresh the map and OSM source

Normal translation sync reads the existing Flensburg PBF and always re-extracts
tags from its nodes, ways and relations. It downloads and prepares OSM data only
when that PBF is missing.
To rebuild the map GeoJSON as well, run:

```bash
bash scripts/production/update-map-and-translations.sh
```

This prepares the map, then calls the same combined OSM/backend translation
sync. It reuses the cached Schleswig-Holstein download when present. To fetch a
newer extract, remove `scripts/data/schleswig-holstein-latest.osm.pbf` before
running this map update command. The workflow updates map data files and
translation catalogs; it does not import map features as database nodes.

The extractor collects tags on **nodes, ways and relations**, including building
keys such as `roof:levels` that may only occur on ways. Its generated
inputs are `scripts/data/flensburg-tag-keys.tsv` (count, key) and
`flensburg-tag-pairs.tsv` (count, key, value). They use tab-separated fields
with CSV quoting for quotes, tabs and line breaks. They are intermediate data;
edit translations in XLIFF, not these TSVs.

### Advanced: sync selected sources

The npm command and the direct Node.js entry point have the same behavior.
Without arguments, or with only `--local`, they refresh OSM inputs and include
backend tags automatically. Explicit input arguments or collection options select
only the sources you request:

| Argument or option | Effect |
| --- | --- |
| First positional argument | Collect keys from the supplied frequency TSV. |
| `--pairs path/to/pairs.tsv` | Collect contextual values and their keys from a pairs TSV. |
| `--database` | Collect stored backend tags and configured choices. |
| `--local` | Run PHP on the host instead of Docker; can be combined with source options. |
| `--import-legacy-labels` | Copy old database labels into the files, replacing conflicting targets; see migration below. |

To collect only backend tags, without extracting OSM data:

```bash
npm run translations:sync -- --database
```

To use another PBF instead of Flensburg and include backend tags:

```bash
bash scripts/production/extract-osm-tags.sh /path/to/town.osm.pbf
npm run translations:sync -- scripts/data/town-tag-keys.tsv \
  --pairs scripts/data/town-tag-pairs.tsv --database
```

Explicitly supplied missing or invalid TSV inputs fail before catalog edits. The
Node.js entry point delegates catalog editing to `app:tags:sync-translations`.
For example, the backend-only equivalent inside a container shell at `/app` is:

```bash
php bin/console app:tags:sync-translations --database
```

### Translate, validate and publish catalog changes

After syncing, complete the new targets in translation software and save the
returned XLIFF files to their original paths. Validate the catalogs:

```bash
cd backend
docker compose exec php php bin/console lint:xliff tag-translations
cd ..
```

Review and commit the changes in `backend/tag-translations/`, then deploy those
files with the backend. Generated PBF, TSV and GeoJSON files are git-ignored and
are not the source of truth for translated labels. To check the result in the
app, choose the language on the profile page and reload the page after changing
a catalog, because the frontend caches the loaded language for the session.

The backend reads the files directly, so catalog target edits do not require a
frontend build or a Symfony translation-cache clear. The public
`GET /api/i18n/tag-keys/{locale}` endpoint (`en` or `de`) returns `locale`, `labels`
(raw key to label), and `values` (JSON-encoded key/value pair to label). Its ETag
hashes the complete response and changes when the returned translations change.

`App\Service\TagKeyLabeler` supplies both the admin UI and that endpoint. The
Vue `tagKeyLabels` store loads the maps at startup and on language changes,
keeping raw tag identifiers outside vue-i18n's message-path syntax. Catalogs live
outside Symfony's general translation directory; runtime lookups stream the XML
and keep only nonempty targets, and worker processes reset their maps between
requests.

### Migrate old database labels

For an existing installation, copy any old admin-managed labels into XLIFF once,
before deploying the new label resolution:

```bash
node scripts/production/generate-tag-key-catalog.mjs --import-legacy-labels
```

Unlike normal sync, this explicit import **replaces conflicting file targets**
with the old database labels. Review and commit the result. The old
`tag_key_labels` table remains for data recovery, but runtime lookups do not read
it and the admin no longer offers a label editor. Future translation changes go
into XLIFF.

### Add a language

Create `backend/tag-translations/tags.<locale>.xlf` using the same unit identities,
with the matching XLIFF target language. Update
`TagKeyLabeler::SUPPORTED_LOCALES`, the frontend's `AppLocale`, and the profile
language choices; add the general UI messages for that language separately.
When changing fallback formatting, keep backend `TagKeyLabeler::prettify()` and
frontend `prettifyTagKey()` identical.

## Default observation tags

Admins choose the tag keys users are offered as ready-made observations, in
the backend admin UI under **Map data → Default observation tags**. Any key
works — OSM ones (e.g. `wheelchair`) and custom ones alike — and each key
can carry a list of **default values**; the admin list shows the readable
label, the raw key is stored. The keys and values are served by
`GET /api/default-observation-tags` and loaded once at startup by the
`defaultObservationTags` Pinia store. The node and relation editors offer
them in a searchable dropdown above the observation rows (options show the
readable label through `tagKeyLabels`; the search matches label and raw
key). Picking one adds a prefilled observation row whose raw key is fixed
(the relation editor shows its translated label with the raw key in a tooltip); when the key carries
default values, its value cell is a dropdown of those choices (clearable,
and editable so users can propose an own value) instead of the free-form
input. Keys already present on the node or relation are not offered. The
"Add observation" button for free-form keys stays, and its rows keep the
editable key and value inputs.

In the node editor, observation rows show **Key translation** and **Value
translation**, with the smaller raw key and value fields underneath. Translation
fields prefill from saved proposals or the current language catalog. Saving a
node stores translation edits as tag properties in the selected language, pending
admin review; the catalog changes only on approval. Raw tag identifiers stay
unchanged. Clearing a translation proposes returning to the normal label fallback. A language switch uses separate drafts, and
stale translations are rejected without discarding the form. The node response includes `observationTranslations`, keyed by raw tag key and
language, so saved proposals survive reopening the editor. The node save API
accepts optional `translationLocale` (`en` or `de`) and `translations` entries
with `key`, `value`, `keyTranslation`, `valueTranslation`, and the corresponding
`previousKeyTranslation` / `previousValueTranslation` for conflict checking.

## Routing stack

Walking routes are served by GraphHopper 11.1 (`graphhopper-web` library jar,
run in its own container; walker profiles `foot_shortest` and
`foot_wheelchair`). The backend proxies it as
`GET /api/route?fromLat=&fromLon=&toLat=&toLon=` and returns the shortest
walking route as `{ distance (m), time (s), points ([lat, lon] pairs) }`;
errors are `{ "error": "no_route" }` (422) or
`{ "error": "routing_unavailable" }` (503).

Start it once:

```bash
cd backend
docker compose --profile routing-data up   # downloads the Geofabrik Schleswig-Holstein extract
docker compose --profile routing up -d     # imports the graph on first start (takes a few minutes)
```

The OSM extract is cached in `backend/routing/data/`, the prepared graph in
the `graphhopper_graphs` volume. Set `GRAPHHOPPER_REBUILD_GRAPH=true` to force
a re-import. The container exposes GraphHopper directly on
`http://localhost:8989` (health: `/info`). The first start must finish the
graph import before routes resolve; watch
`docker compose logs -f routing` until the server announces its profiles.

The graph is memory-mapped (`graph.dataaccess.default_type: MMAP`) and the
heap defaults to 512 MB (`GRAPHHOPPER_XMX`), so the container runs in well
under 1 GB of RAM — the import over the full Schleswig-Holstein extract
included. Most of the container's resident memory is evictable page cache,
which the OS reclaims under memory pressure.

Frontend: press the route button (top-left), then click the map twice to set
start and end. The shortest walking route is drawn with a distance/time
summary; Escape leaves routing mode.

## Way vocabularies and street-part cross-sections

`backend/config/way-observation-vocabulary.json` controls new observations on
OSM ways by their exact `highway` value. Its small starter definitions cover
`footway`, `residential`, and an empty `motorway` vocabulary. Unlisted types allow
no new keys. File changes apply to the next request; reload the editor to refresh
choices. Invalid or missing definitions return `editing_vocabulary_unavailable`
(503). Buildings and point-node suggestions keep their existing flow.

`backend/config/aspect-catalog.json` defines cross-section types, attributes,
raw values with display labels, and routing predicates. It is file-backed and
requires no catalog seeding or aspect-specific database tables. File changes
apply on the next request; reload the editor to refresh its choices.

The relation editor's **Cross-section** composer groups ordinary tags using
`key.type.index.aspect = value`, for example:

```text
way.footwalk.1.width = "<1"
way.footwalk.1.surface = "asphalt"
way.footwalk.5.width = ">2"
```

The index is a position across the whole cross-section, starting at 1; repeated
types remain independent. Raw width tokens `<1`, `1-2`, `>2` display with catalog
labels such as `< 1 m`. Adding, reordering and removing editable rows rewrites
their tag keys. Approved attributes and their positions are read-only.
The frontend calls these rows **observations** (**Beobachtungen** in German).
Controls use vue-i18n; type names, attributes, values and profile preference
labels use the shared `backend/tag-translations/tags.{en,de}.xlf` catalogs.
Translation sync includes the file-backed definitions and their retired value
labels, so a complete sync preserves these entries. Indexed observation values
resolve an exact tag translation first, then the shared attribute translation.

`GET /api/editing-catalog` serves whole-way choices and string-identified
`aspectTypes`. Relation saves use the existing `tags: [{key, value}]` array;
responses use `tags` and creator-only `observations`. The former `aspects` input
is rejected with HTTP 422. Structured tags use the existing relation moderation
queue, with approval and supersession per full key. Only approved tags influence
routing. Preference keys use stable `aspect:` identifiers and existing
`/api/tag-priorities` storage; labels accompany `/api/route-preference-tags`.
Aspect `avoid` uses a 0.1 multiplier so passage remains possible.

For databases that used the previous aspect tables, stop editing during the
conversion and migration. Run these commands before serving the updated app:

```bash
cd backend
docker compose exec php php bin/console app:aspects:convert-legacy
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

Conversion preserves attribution, approval, positions, retired values and
preference IDs/ratings; it exports the deployed definitions into
`config/aspect-catalog.json`. Keep that exported file with the deployment.
Unknown mappings, empty legacy rows and conflicting tags stop conversion with
an actionable error. Reruns are safe. The migration verifies parity before
removing the four old tables; a fresh install never creates them. Restoring the
old model requires a backup; the removal migration has no automatic rollback.

Run `node scripts/testing/check-map-editing.mjs` with Vite running for the
fixture-based browser check (no development database writes).

The committed catalog works immediately. To validate it without creating map
data, use `app:aspects:load-demo --catalog-only --demo`. To load the five-element
example in dev/test through ordinary tag persistence, use an existing editor:

```bash
cd backend
docker compose exec php php bin/console app:aspects:load-demo editor@example.test --demo
```

The fixture in `config/examples/street-part.json` uses two independent footwalks,
a cycle lane, a parking strip and a roadway. `config/examples/aspect-catalog.json`
is the companion example catalog. The loader refuses production; reruns preserve
edits. Sign in as that editor and open
`http://localhost:5173/?lat=54.791&lon=9.437&zoom=18`, then click the part's line.
Demo member identities are synthetic references, not real OSM edits.
