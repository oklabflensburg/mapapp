# Specs

As-built specifications, reverse-engineered from the existing implementation (frontend + Symfony backend) so future work can be planned and verified against documented behavior.

| Spec | Area |
| --- | --- |
| [osm-static-data](osm-static-data/spec.md) | Frontend: loading, indexing, and serving the static OSM GeoJSON export, and its failure behavior |
| [api-authentication](api-authentication/spec.md) | JSON session login: `/api/login`, `/api/me`, `/api/logout`, roles |
| [api-user-nodes](api-user-nodes/spec.md) | `/api/nodes`: list/save of local nodes and OSM observation tags, approval/visibility, parent relations, proposed translations |
| [api-node-groups](api-node-groups/spec.md) | `/api/node-groups`: street-part relations, members, corridor area, approval/visibility |
| [api-routing](api-routing/spec.md) | `/api/route`: GraphHopper walking-route proxy shaped by per-user tag priorities |
| [api-tag-settings](api-tag-settings/spec.md) | Admin-exposed tag metadata and per-user priorities: `/api/route-preference-tags`, `/api/tag-priorities`, `/api/default-observation-tags`, `/api/i18n/tag-keys/{locale}` |

Each spec carries its own requirement IDs (`R#`), acceptance criteria (`AC#`), open questions, and a verification note pointing at the existing backend tests. Not covered yet (candidate follow-ups): map rendering/styling & hit-testing layers, node/group edit panels, PWA behavior, admin moderation UI.
