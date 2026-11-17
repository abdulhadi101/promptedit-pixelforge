# Pixelforge image generation (PromptEdit take-home)

Laravel 13 + Vue 3 + Inertia app where signed-in users generate images through the
[fictional Pixelforge API](https://pixelforge.promptedit.dev/docs) and are billed
Pixelforge's exact cost + 25%, in integer nanodollars.

## Key decisions

- **Money is integer nanodollars everywhere** (1 USD = 1e9): Pixelforge reports costs with
  exactly 9 decimals, so provider costs are stored with zero loss and no float ever touches
  money. The user charge is cost x 1.25, rounded half-up to a whole nanodollar (at most
  $0.0000000005 in the user's favour).
- **Estimate vs settlement**: the price shown before submitting is min/expected/max because
  text tokens (±15%) and reasoning tokens are only known on completion; the final charge
  uses real usage x the generation's own `rates` snapshot.
- **Exactly-once settlement**: one code path (`GenerationStateApplier`) changes a
  generation's state or price — row-locked, forward-only, final states immutable — used by
  webhooks, the scheduled poller and submit failures alike. Webhook dedupe inserts the
  event id in the same transaction as the state change.
- **SQLite locking**: SQLite has no row-level `FOR UPDATE`; its transactions use
  `BEGIN IMMEDIATE` instead, serializing writers for settlement and credit reservations.
  This is appropriate for the low-traffic single-container take-home, not high write
  concurrency; PostgreSQL row locks are preferable at scale.
- **Submission**: the local row's UUID is both the `Idempotency-Key` and
  `metadata.generation_id`, created before calling Pixelforge; the queued submit job
  retries 429/503/connection errors with `Retry-After` + jitter (bounded) and fails
  permanently and free on 400/422/daily-limit.
- **Safety nets**: a scheduled poller covers undelivered webhooks;
  `pixelforge:reconcile` compares per-UTC-completion-day provider costs with
  `GET /v1/usage` and exits non-zero on any mismatch.

## What it does

- **Generate page** (`/generations`): prompt (max 4000 chars), size, quality, up to 4
  reference image URLs, with a live **min / expected / max** price estimate before submitting.
- **History** with live status (polls every 3s while in flight), estimated vs actual cost,
  and the image while its signed URL is valid (24h).
- **Exact money**: provider cost = tokens x the generation's own rate snapshot; user charge
  = cost x 1.25, rounded half-up to a nanodollar. Failed generations cost nothing.
- **Webhooks** with signature verification, at-least-once and out-of-order delivery handled
  (event-id dedupe in the same transaction as the state change; final states immutable).
- Scheduled poller for generations whose webhooks never arrived; `pixelforge:reconcile`
  compares per-UTC-day provider costs with `GET /v1/usage`.
- **Stretch goals**: admin report (`/admin/report`, provider costs shown next to live
  `/v1/usage` numbers) and credit balances with a fake "Buy credits" button that blocks
  unaffordable generations.

## Local setup

```bash
composer install
cp .env.example .env        # then set the values below
php artisan key:generate
npm install && npm run build
php artisan migrate
```

`.env` values (each environment needs its own Pixelforge key, created in the
[dashboard](https://pixelforge.promptedit.dev/register)):

```
PIXELFORGE_API_KEY=pf_...          # this environment's key
PIXELFORGE_WEBHOOK_SECRET=whsec_...# that key's signing secret
PIXELFORGE_ADMIN_EMAILS=you@example.com
APP_TIMEZONE=UTC
```

SQLite is preconfigured for local dev and tests. Run the three processes in separate
shells:

```bash
php artisan serve             # app
php artisan queue:work        # submit jobs
php artisan schedule:work     # pixelforge:sync every minute (webhook safety net)
```

For webhooks to reach localhost, expose the app with a tunnel (cloudflared/ngrok/Expose)
and set the key's webhook URL in the Pixelforge dashboard to
`https://<your-tunnel>/webhooks/pixelforge`.

```bash
php artisan test                      # full suite (64 tests)
php artisan pixelforge:reconcile      # our per-day provider costs vs GET /v1/usage
```

Try the provider's misbehaviour on purpose by putting these in prompts: `[blocked]`,
`[overloaded]`, `[fail]`, `[succeed]`, `[slow]`, `[duplicate]`.

## Deploy (Dokploy on a VPS)

The repo root has a `Dockerfile` that builds a **single container** (nginx + php-fpm +
supervisor running the queue worker and the scheduler) listening on port 80 — Dokploy
handles the domain and TLS. A `docker/` directory holds the nginx, PHP-FPM and supervisor
configuration. This deployment uses SQLite, so no database service is needed.

1. Create an application from this repo (Dockerfile build), open port `80`.
2. Add a **persistent volume** in Dokploy, mounted at `/var/lib/promptedit`. It keeps the
   SQLite database across container rebuilds and redeployments; without it, database data
   can be lost.
3. Set the environment variables on the app:

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-dokploy-domain
   APP_KEY=base64:...           # php artisan key:generate --show, run anywhere
   APP_TIMEZONE=UTC
   DB_CONNECTION=sqlite
   DB_DATABASE=/var/lib/promptedit/database.sqlite
   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=database
   LOG_CHANNEL=stderr
   PIXELFORGE_API_KEY=pf_prod_...            # a SEPARATE key for production
   PIXELFORGE_WEBHOOK_SECRET=whsec_prod_...  # that key's signing secret
   PIXELFORGE_ADMIN_EMAILS=you@example.com
   RUN_MIGRATIONS=true       # migrations run once at container start
   ```

4. Deploy, then in the Pixelforge dashboard set the **production key's** webhook URL to
   `https://your-dokploy-domain/webhooks/pixelforge`.
5. Verify:

   ```bash
   docker exec -it <container> php artisan pixelforge:reconcile   # must show no MISMATCH
   ```

   Generate a few images with `[fail]`, `[duplicate]` and `[slow]` prompts first so the
   reconcile check exercises failures and duplicate deliveries too.

## Repository layout notes

- `app/Support/Money.php` — all money is integer nanodollars; nothing else may touch money.
- `app/Services/GenerationStateApplier.php` — the **only** code path that settles a
  generation (row lock, forward-only states, event dedupe shares its transaction).
- `app/Services/Pixelforge/` — API client, cost calculator, typed exception.
- `tests/` — webhook signature/duplicate/out-of-order/replay, submit-job retries,
  idempotent submission, estimate math against the docs' worked example, reconcile, and
  both stretch features.

Secrets live only in `.env` (gitignored).
