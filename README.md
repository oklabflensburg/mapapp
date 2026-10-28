# AccessApp

Starting point: Vue 3 + Vite frontend with a PHP 8.5.12 / Symfony 8.1 backend (FrankenPHP via Docker).

## Structure

```
├── index.html, vite.config.ts, src/   # Vue frontend (root)
└── backend/                           # Symfony backend (Docker Compose)
    ├── compose.yaml                   # php (FrankenPHP) + database (PostGIS)
    ├── Dockerfile                     # frankenphp_dev / frankenphp_prod targets
    ├── frankenphp/                    # Caddyfile + PHP config
    └── src/, config/, public/         # Symfony app
```

## Backend

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

## Adding nodes

Press the map's **Add node** (+) button to enter edit mode, then click the map
to place nodes; each click adds another one and nothing is sent yet. A dialog
below the controls shows the placed count, a name input, the **parent node**
(the first placed node, the anchor of the pending relation) and Save/Abort.
Clicking an existing node or OSM feature places a child of that feature.
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
`providerNodeId`, `latitude`, and `longitude`. Parent links are additive and
ordinary tag saves preserve them. Responses include `provider`, `parentIds`, and
`childIds`; each node can have multiple parents and children. The `parents` and
`children` summaries include each related node's ID, provider, provider ID,
nullable saved name and saved custom `tags`. The edit panel displays these
relations above the tags, including OSM reference parents that do not appear as
user markers. Each parent shows read-only OSM and custom tags in separate
groups. Original OSM tags come from the shared map dataset, including buildings
and areas; unavailable OSM data is distinguished from a parent with no tags.

Run the deterministic creation browser check with the dev server running:

```bash
node scripts/check-node-creation.mjs
node scripts/check-node-selection.mjs
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

Relations are street parts: the area they cover is the corridor of a fixed
width (20 m around the member line, computed by PostGIS in
`backend/src/Service/StreetPartArea.php`) around the line through the
members in order. The map draws it in amber: a dashed preview connects the
members collected in relation mode, and every saved relation shows its
corridor outline. The lines are visual aids only — clicks pass through to
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
node scripts/check-node-relation.mjs
```

## OSM map data (Flensburg)

The map renders a read-only OSM feature layer (buildings, parks, streets, POIs
with their tags) from `public/data/flensburg.geojson`. The file is generated by
`scripts/prepare-flensburg.sh` and git-ignored; rebuild it with:

```bash
brew install osmium-tool   # once
scripts/prepare-flensburg.sh
```

The script downloads the Geofabrik Schleswig-Holstein extract and the
Flensburg city boundary (Nominatim), clips to the boundary, keeps tagged
features only (`building, leisure, amenity, shop, tourism, landuse, natural,
highway, name`), and exports GeoJSON (~32 MB, ~100k features). Downloads are
cached in `scripts/data/`.

A quick render check (needs the dev server on port 5199):

```bash
npm run dev -- --port 5199 &
node scripts/check-osm-render.mjs
```

## Tag key translations

Raw tag keys — OSM keys like `fire_hydrant:type` as well as keys users type
into tag editors — are shown as readable labels in the node/relation panels
and in the admin UI. Every lookup walks the same chain, and a key without any
entry still renders readably, so nothing blocks on translation coverage:

1. an **admin-managed label** from the `tag_key_labels` table (per key and
   locale, edited in the admin UI under "Tag key labels"); it overrides the
   catalogue and is how user-created keys get proper names without a deploy;
2. the **`tag_keys` translation catalogue**
   (`backend/translations/tag_keys.{en,de}.xlf`), generated from the OSM key
   import `scripts/data/flensburg-node-tag-keys.tsv`;
3. the **prettified key** as fallback (`tactile_paving` → `tactile paving`).

Regenerate the catalogues with:

```bash
node scripts/generate-tag-key-catalog.mjs
```

The script writes one `<unit>` per key (906 at the moment), prefills the
common keys from a German dictionary kept in the script, and uses the
prettified key for the rest. It merges: targets already in the `.xlf` files
are kept, so hand-edited translations survive a regeneration. Add real
translations by editing the `.xlf` files (or the dictionary) — only the
common keys need them, the tail stays on the prettified fallback.

The backend resolves labels in `App\Service\TagKeyLabeler` (used by the Tag
admin list) and serves the frontend a merged map (catalogue plus database
overrides) via the public endpoint:

```
GET /api/i18n/tag-keys/{locale}   # en | de → { "locale": ..., "labels": { "<key>": "<label>" } }
```

The response carries an ETag derived from the label table, so unchanged maps
revalidate as `304`.

The frontend keeps this map in the `tagKeyLabels` Pinia store
(`src/stores/tagKeyLabels.ts`), fetched once per locale in `main.ts` and
reloaded when the user switches the app language. It deliberately lives
**outside** vue-i18n: keys like `fire_hydrant:type` collide with vue-i18n's
message-path syntax. The store applies the same fallback chain client-side,
and the tag tables in `NodeTagPanel`, `RelationTagPanel` and `ParentNodeTags`
render `tagKeyLabels.label(key)` (editors still type raw keys). Keep
`TagKeyLabeler::prettify()` and `prettifyTagKey()` in sync when changing the
fallback.

Supported locales are `en` and `de`, defined by `TagKeyLabeler::SUPPORTED_LOCALES`
and the frontend's `AppLocale`.

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
