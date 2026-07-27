# RazbudiSe Frontend

Next.js public frontend for the RazbudiSe custom publishing platform.

## Local Development

From the repository root, copy the frontend example environment file:

```bash
cp frontend/.env.example frontend/.env
```

Install dependencies and start the development server on the project port:

```bash
cd frontend
npm install
npm run dev -- --hostname 0.0.0.0 --port 3001
```

Open `http://localhost:3001`.

For local development outside Docker, keep the API URLs pointed at the Laravel server:

```bash
NEXT_PUBLIC_API_BASE_URL=http://localhost:8001/api
INTERNAL_API_BASE_URL=http://localhost:8001/api
```

Docker Compose overrides `INTERNAL_API_BASE_URL` to `http://backend:8000/api` so server-side rendering can call the backend container directly.

## Verification

```bash
npm test
npm run lint
npm run build
```

Run browser smoke tests against an already-running local frontend:

```bash
PLAYWRIGHT_SKIP_WEB_SERVER=1 npm run test:e2e
```

To let Playwright start the frontend dev server automatically, omit `PLAYWRIGHT_SKIP_WEB_SERVER`.

The public frontend should render the homepage, article pages, category archives, tag archives, search, events, author profiles, `robots.txt`, and `sitemap.xml`.
