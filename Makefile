.PHONY: help frontend frontend-build backend backend-down backend-logs translations-sync

help:
	@printf '%s\n' \
	  'frontend          Start the Vite development server' \
	  'frontend-build    Type-check and build the frontend' \
	  'backend           Start the backend and database containers' \
	  'backend-down      Stop the backend containers' \
	  'backend-logs      Follow backend container logs' \
	  'translations-sync Sync translation catalogs'

frontend:
	npm run dev

frontend-build:
	npm run build

backend:
	docker compose -f backend/compose.yaml up -d --wait

backend-down:
	docker compose -f backend/compose.yaml down

backend-logs:
	docker compose -f backend/compose.yaml logs -f

translations:
	npm run translations:sync
