# LuckyCare+ — Frontend

Angular 22 + Tailwind CSS 4 web application for LuckyCare+.

## Requirements

- Node.js 22+ (the repository is developed with Node 26)
- The Laravel API running on `http://127.0.0.1:8000`
- PostgreSQL, Redis and MinIO from the repository root (`docker compose up -d`)

## Run the frontend and API together

Open two terminals from the repository root.

Terminal 1 — API:

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2 — web app:

```bash
cd frontend
npm start
```

Open `http://localhost:4200/`. `proxy.conf.json` forwards every `/api` request to
`http://127.0.0.1:8000`, so the browser only talks to the dev server and CORS is
never involved. `angular.json` wires the proxy into `serve.options.proxyConfig`.

If the API runs on a different host or port, update the proxy target and keep the
client's default `API_BASE_URL` (`/api/v1`) unchanged.

## Build and test

```bash
npm run build
npm test -- --run
```

The `test` script wraps Angular's Vitest builder and accepts the conventional
`--run` flag (the Angular 22 CLI itself exposes `--watch=false` instead).

Formatting:

```bash
npx prettier --check "src/**/*.{ts,html,css}"
```

## Structure

| Path                              | Contents                                             |
| --------------------------------- | ---------------------------------------------------- |
| `src/app/core/api`                | API base URL token and problem-details handling      |
| `src/app/core/auth`               | Session signals, interceptor and route guards        |
| `src/app/core/device`             | Stable non-sensitive `device_name`                   |
| `src/app/features/auth`           | Login, registration and phone verification           |
| `src/app/features/dashboard`      | Authenticated dashboard placeholder (`GET /me`)      |
| `src/app/features/placeholder`    | Under-construction pages for unimplemented IA routes |
| `src/app/shared/layout/app-shell` | Top bar, IA rail, user menu and logout               |

Design tokens live in `src/styles.css`. Use the named Tailwind tokens
(`bg-surface`, `text-ink-muted`, `rounded-lg`, …) instead of raw values.
