# Initial Architecture

RazbudiSe is structured as a monorepo with a Laravel backend, Filament admin panel, public Next.js frontend, and Docker Compose services for local development.

## Repository Layout

- `backend/` contains the Laravel application, Filament admin panel provider, API routes, migrations, queue configuration, and future CMS domain code.
- `frontend/` contains the public Next.js application with TypeScript and a small API client layer.
- `docker/` contains local Dockerfiles and PHP configuration.
- `docs/` contains architecture and planning notes.
- `docker-compose.yml` starts the local app services and dependencies.

## Backend

The backend is API-first. Public and future mobile clients should consume versioned API routes under `/api/v1`. The initial `/api/v1/status` endpoint exposes a small contract that confirms the API is available, mobile-ready, and has AI author generation disabled.

Filament is installed as the backoffice panel foundation at `/backoffice`. CMS models, resources, policies, form requests, API resources, jobs, and services should be added in later tasks following Laravel conventions.

Search is prepared through Laravel Scout and the local Meilisearch service. Queue work is prepared through Laravel's database queue driver and the `queue` Compose service.

## Frontend

The frontend uses the Next.js App Router and TypeScript. Server-rendered pages should use the API client in `frontend/src/lib/api/client.ts` instead of hardcoded content once backend endpoints exist.

The first page is a shell for the public publishing experience. It is intentionally not a mobile app and does not implement AI author generation.

## Local Services

Docker Compose includes:

- `backend` for Laravel HTTP requests, exposed at `http://localhost:8001` by default while listening on container port `8000`.
- `queue` for Laravel queue workers using the database queue connection.
- `scheduler` for Laravel Scheduler, including the every-minute `articles:publish-scheduled` task.
- `frontend` for the Next.js dev server, exposed at `http://localhost:3001` by default while listening on container port `3000`.
- `database` for PostgreSQL.
- `redis` for cache/session-ready local infrastructure.
- `meilisearch` for search indexing.

No production credentials are committed. Local example passwords are development-only and can be changed in `.env`.
