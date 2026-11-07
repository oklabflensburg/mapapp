# Routing API (GraphHopper proxy with tag-based route shaping)

**Status:** As-built spec, reverse-engineered from the existing implementation.
**Code:** `backend/src/Controller/RoutingController.php`, `backend/src/Service/Routing.php`, `backend/src/Service/NoRouteFoundException.php`, `backend/src/Service/RoutingUnavailableException.php`, `src/stores/routing.ts`, `backend/routing/` (GraphHopper deployment)
**Scope:** `GET /api/route` — a walking-route proxy in front of a self-hosted GraphHopper, shaped by the signed-in user's tag priorities (see `api-tag-settings` for how priorities are stored).

## Problem / intent

The map computes walking routes between two coordinates. Users can declare per-tag route preferences (e.g. avoid stairs, prefer tactile paving); the backend translates those into a per-request GraphHopper custom model and returns the resulting path. Anonymous users get the neutral profile routing.

## Requirements

### R1 — Request contract
- `GET /api/route?fromLat=&fromLon=&toLat=&toLon=` — all four in range (lat ±90, lon ±180) and not blank.
- Validation failure → HTTP 422 (via `MapQueryString(validationFailedStatusCode: 422)`).
- Public; anonymous requests are allowed.

### R2 — Route result
- Success → HTTP 200 with `{ "distance": <meters, rounded float>, "time": <seconds int>, "points": [[lat, lon], …] }`.
- Points are Leaflet-ordered `[lat, lon]` pairs along the path (GraphHopper's `[lon, lat]` order is swapped server-side).

### R3 — Error contract
- GraphHopper cannot connect the two points (client error / no path element) → HTTP 422 `{ "error": "no_route" }`.
- GraphHopper unreachable (transport error) → HTTP 503 `{ "error": "routing_unavailable" }`.
- Upstream HTTP timeout: 15 s.

### R4 — Tag-priority route shaping
- The signed-in user's saved tag priorities (`TagPriorityManager::getPriorities`, optionally empty for anonymous) drive a per-request custom model:
  - **avoid** levels penalize ways near tagged app nodes, inside street-part areas carrying the tag, and OSM road classes mapped to the tag by an admin; factors: `avoid = 0`, `try_to_avoid = 0.3`.
  - **prefer** levels penalize everything *else* (per-request custom-model constraint); factors: `prefer = 0.8`, `strongly_prefer = 0.5`.
  - Tagged app nodes apply within **2 m** of the way they mark (the area radius GraphHopper intersects with edges).
  - Street-part group areas apply along their whole member course, not just around member nodes.
- With no priorities at all, the backend issues a plain GET with the configured profile (no custom model) and GraphHopper's profile-level custom model from `graphhopper.yml` applies unchanged.
- With any priorities, the request becomes a POST with the custom model appended to the profile's own.
- The frontend asks only with the two query points; it never sends priorities in the route request — the backend resolves them from the session.

### R5 — Frontend store behavior
- `useRoutingStore`: in routing mode the first map click sets the start, the second sets the end and triggers `requestRoute()`; a third click restarts with a new start. While `loading`, clicks are ignored.
- HTTP 422 sets `noRoute`; any other failure sets `failed`; success stores the route.

## Acceptance criteria

- **AC1 (R1):** Given `toLat` missing, the response is 422 without a route calculation.
- **AC2 (R2):** Given two routable points, the response is 200 and every `points` entry is a `[lat, lon]` pair usable directly by Leaflet.
- **AC3 (R3):** Given GraphHopper down, the response is 503 with `{"error": "routing_unavailable"}`; the frontend shows the generic failure, not "no route".
- **AC4 (R3):** Given two points GraphHopper cannot connect, the response is 422 with `{"error": "no_route"}` and the frontend shows the no-route state.
- **AC5 (R4):** Given a user with `stairs: avoid`, ways carrying/adjacent to `stairs`-tagged nodes or street-parts are excluded from the custom model search space (factor 0); the same request from an anonymous user returns the neutral profile route.
- **AC6 (R4):** Given a user with no saved priorities, the backend sends the plain profile GET to GraphHopper.

## Open questions / assumptions

- The GraphHopper base URL comes from `GRAPHHOPPER_BASE_URL`; the profile name is fixed in `Routing::PROFILE`.
- Priority semantics (which tags exist, their road-class mappings) are admin-managed and covered by `api-tag-settings`.
- Aspect-preference corridor matching with alternative-aware match modes is specified separately in [aspect route preferences](../aspect-route-preferences/spec.md), extending R4.

## Verification

- `backend/tests/Controller/RoutingControllerTest.php` and `backend/tests/Service/RoutingTest.php` cover the contract and custom-model generation.
- Not executed in this session; evidence is from code reading.
