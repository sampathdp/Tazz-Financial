# End-to-End Tests

Full-stack Playwright E2E suite for the Tazz Investment & Credit Pvt Ltd. It drives the
real React frontend against the real Laravel API, covering every screen and the core
create/approve/pay/delete flows across all modules.

## What it covers (63 tests)

| Spec | Area | Highlights |
|------|------|-----------|
| `auth.setup.ts` | Auth bootstrap | Logs in once, saves storage state reused by all specs |
| `01-auth.spec.ts` | Authentication | Bad-password rejection, login, session persistence, logout |
| `02-navigation.spec.ts` | Navigation smoke | Opens **all 33 screens**, asserts each renders with no JS errors |
| `03-sidebar.spec.ts` | Sidebar | Section expand + item click navigation through every group |
| `04-masters.spec.ts` | Master Files | CRUD on Brand (config engine), Item, Customer, Supplier |
| `05-procurement.spec.ts` | Procurement | PO create → approve, seeded list, GRN & Tracking render |
| `06-sales.spec.ts` | Sales | Invoice payment, quotation list, sales return raise→approve, receipt post, expense CRUD |
| `07-stores.spec.ts` | Stores | Stock adjustment create, price-control edit, live stock/BIN/transfer |
| `08-admin.spec.ts` | Administration | User CRUD, company profile update, rep create, permission matrix |
| `09-sales-orders.spec.ts` | Sales Orders | Rep-order list, create → **convert to Invoice → convert to PO**, delete |

## Prerequisites

The suite expects the app to already be running against an **isolated test database**
(`absl_test`) so it never touches development data.

1. Create + seed the test DB (from `backend/`):
   ```sh
   # PowerShell
   $env:DB_DATABASE='absl_test'; php artisan migrate:fresh --seed --force
   ```

2. Start the backend against the test DB with relaxed rate limits (Windows needs both
   an IPv4 and an IPv6 listener so the Vite proxy's `localhost` target resolves either way):
   ```sh
   $env:DB_DATABASE='absl_test'; $env:LOGIN_RATE_LIMIT='100000'; $env:API_RATE_LIMIT='100000'
   php -S 127.0.0.1:8000 -t public server.php     # terminal A
   php -S [::1]:8000    -t public server.php       # terminal B
   ```
   `LOGIN_RATE_LIMIT` / `API_RATE_LIMIT` default to the production values (5/min and
   60/min) when unset — they only need raising for the automated burst a test run creates.

3. Start the frontend (from `frontend/`): `npm run dev:frontend` (serves on `:5173`,
   proxies `/api` → `:8000`).

### If port 8000 / 5173 are already in use

`localhost` resolves to `::1` (IPv6) first on Windows, so any *other* app listening
on `[::1]:8000` will silently shadow this project's backend through the Vite proxy.
If that happens (symptom: `Route not found` from an unfamiliar JSON shape), run the
test stack on its own ports instead of fighting for 8000 — no config edits needed:

```sh
# backend on 8001, CORS opened for the test frontend origin
$env:DB_DATABASE='absl_test'; $env:LOGIN_RATE_LIMIT='100000'; $env:API_RATE_LIMIT='100000'
$env:CORS_ALLOWED_ORIGINS='http://localhost:5174,http://127.0.0.1:5174'
php -S 127.0.0.1:8001 -t public server.php

# frontend on 5174, calling the API directly (bypasses the proxy entirely)
cd frontend; $env:VITE_API_URL='http://127.0.0.1:8001/api'; npx vite --port 5174 --strictPort

# point the suite at it
cd e2e; $env:E2E_BASE_URL='http://localhost:5174'; npm test
```

Note: a freshly started Vite server pre-bundles dependencies on the first page load,
which can exceed the setup timeout. If the very first run fails on `authenticate as
admin` with the screen stuck on `Loading…`, just re-run — the server is warm by then.

## Run

```sh
cd e2e
npm install            # first time
npx playwright install chromium   # first time
npm test               # run everything
npm run report         # open the HTML report
```

Seed credentials: `admin@absl.local` / `password` (seeded with the `admin` role).

## Notes

- Tests run serially (`workers: 1`) because they mutate shared DB state; created records
  are cleaned up or uniquely stamped so re-runs stay green.
- To reset to a pristine dataset, re-run the `migrate:fresh --seed` step above.
