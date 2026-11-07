# Specs

Reverse-engineered as-built specifications for the existing behavior (frontend + Symfony backend) and the step-by-step rebuild of the node series starting from the basemap.

**Node spec series (built step by step from scratch):**
1. [flensburg-map](flensburg-map/spec.md) — anonymous Leaflet map of Flensburg with the OSM data overlay and original OSM tags
2. [editor-observations](editor-observations/spec.md) — editor clicks an OSM feature, sees its OSM tags, adds observations; admin approval and per-user visibility
3. [way-observations](way-observations/spec.md) — observations on ways: tagging a street line as a whole (selection, whole-way identity, overlay markers) + NEW admin-controlled per-type (`highway`) observation vocabulary (design refinement R5)
4. [street-part-aspects](street-part-aspects/spec.md) — **new feature design:** cross-section aspects on street parts (typed attributes, observation-style approval) and admin-defined aspect-based routing preferences (e.g. prefer sidewalk ≥ 2 m)
5. *(next steps: buildings, areas, local node creation, parent relations, street-parts as-built spec, …)*

**Other specs:**

| Spec | Area |
| --- | --- |
| [backend-admin-panel](backend-admin-panel/spec.md) | Backend EasyAdmin panel: access and map-data management, observation moderation, tag configuration and bilingual translation editing |
| [osm-static-data](osm-static-data/spec.md) | Frontend: loading, indexing, and serving the static OSM GeoJSON export, and its failure behavior |
| [api-authentication](api-authentication/spec.md) | JSON session login: `/api/login`, `/api/me`, `/api/logout`, roles |
| [api-routing](api-routing/spec.md) | `/api/route`: GraphHopper walking-route proxy shaped by per-user tag priorities |
| [api-tag-settings](api-tag-settings/spec.md) | Admin-exposed tag metadata and per-user priorities: `/api/route-preference-tags`, `/api/tag-priorities`, `/api/default-observation-tags`, `/api/i18n/tag-keys/{locale}` |

Each spec carries its own requirement IDs (`R#`), acceptance criteria (`AC#`), open questions, and a verification note pointing at the existing backend tests. Not covered yet (candidate follow-ups): node/group edit panels, PWA behavior.
