# AGENTS.md

## What this project is

AccessApp is a map service that helps users find the most *comfortable* way from
A to B — a route that considers their special needs, not just the shortest
distance. Special needs can come from a disability, for example when they use a
wheelchair or are blind, or simply from preferences: a quiet way, a route with
less car traffic, a comfortable bike route, and so on.

Users create a profile that configures their individual preferences; route
suggestions take this profile into account.

The app is also an OpenStreetMap (OSM) editor, whose OSM tag structure is much
more customized to allow very detailed descriptions of routes and buildings.
Users help build that structure by submitting observations about locations.
Admin users review those observations and approve them to become part of the
map features.

Stack and setup: Vue 3 + Vite frontend at the repository root, Symfony 8
(FrankenPHP via Docker) backend with PostGIS in `backend/`, GraphHopper for
routing. See `README.md` for the full structure, commands and workflow details,
and `backend/AGENTS.md` for backend-specific conventions.
