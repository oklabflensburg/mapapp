# AccessApp

Starting point: Vue 3 + Vite frontend with a PHP 8.5 / Symfony 8.1 backend (FrankenPHP via Docker).

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
- Database: PostGIS on localhost:5433 (user `app`, password `!ChangeMe!`, db `api`)

The first start runs `composer install` inside the container (Flex generates the
bundle configuration in `config/packages/`).

Tests:

```bash
docker compose exec php vendor/bin/phpunit
```

## Frontend

```bash
npm install
npm run dev
```

- App: http://localhost:5173
- `/api` requests are proxied to the backend on port 8080 (see `vite.config.ts`)

Production build: `npm run build` (type-checks with `vue-tsc`, then Vite build incl. PWA assets).

## Routing stack

The GraphHopper routing service in `backend/compose.yaml` is behind the `routing`
compose profile and has no build context yet. It is skipped by default; start it
explicitly with `docker compose --profile routing up` once `backend/routing/` exists.
