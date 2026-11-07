# Specs

Specifications are grouped by product feature. Each spec keeps its own requirement
IDs (`R#`), acceptance criteria (`AC#`), open questions, and verification notes.
Most describe existing behavior; future designs are marked explicitly.

| Feature | Specs |
| --- | --- |
| Map | [Flensburg basemap](map/flensburg-map/spec.md) — public map, rendering and OSM overlay; [OSM static data](map/osm-static-data/spec.md) — loading, indexing, original tags and failure behavior |
| Map editing | [Editor observations](map-editing/editor-observations/spec.md) — selection, tagging, approval and visibility; [Way observations](map-editing/way-observations/spec.md) — whole-way identity and markers; [Controlled vocabulary](map-editing/controlled-vocabulary/spec.md) — file-based way definitions, later database/admin management; [Street part aspects](map-editing/street-part-aspects/spec.md) — revised tag-based cross-sections (`key.type.index.aspect`), no extra aspect tables; [Aspect observation mapping](map-editing/aspect-observation-mapping/spec.md) — admin update-vs-add decision for pending aspect observations in review |
| Authentication | [Authentication API](authentication/api-authentication/spec.md) — session login, current user, logout and roles |
| Routing | [Routing API](routing/api-routing/spec.md) — GraphHopper walking routes shaped by user priorities; [Aspect route preferences](routing/aspect-route-preferences/spec.md) — alternative-aware routing prios from controlled-vocabulary aspect observations |
| Tag settings | [Tag settings and translations API](tag-settings/api-tag-settings/spec.md) — route preference metadata, per-user priorities, observation suggestions and shared translations |
| Administration | [Backend admin panel](administration/backend-admin-panel/spec.md) — access, map-data management, moderation, tag configuration and translation editing |

Keep frontend and backend contracts for the same feature together. Add new specs
under `specs/<feature>/<spec-slug>/spec.md`; related task files belong beside their
spec. Cross-cutting specs have one home and link to related features.

The original map/editor rebuild reading order remains:

1. [Flensburg basemap](map/flensburg-map/spec.md).
2. [Editor observations](map-editing/editor-observations/spec.md).
3. [Way observations](map-editing/way-observations/spec.md).
4. [Street part aspects](map-editing/street-part-aspects/spec.md) (revised tag-storage design).

Candidate follow-ups: buildings, areas, local node creation, parent relations,
street parts as built, node/group edit panels, and PWA behavior.
