# Pixelforge image generation (PromptEdit take-home)

Laravel 13 + Vue 3 + Inertia app where signed-in users generate images through the
[fictional Pixelforge API](https://pixelforge.promptedit.dev/docs) and are billed
Pixelforge's exact cost + 25%, in integer nanodollars. Every design choice and why is in
[DECISIONS.md](DECISIONS.md).

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
php artisan test                      # full suite (60 tests)
php artisan pixelforge:reconcile      # our per-day provider costs vs GET /v1/usage
```

Try the provider's misbehaviour on purpose by putting these in prompts: `[blocked]`,
`[overloaded]`, `[fail]`, `[succeed]`, `[slow]`, `[duplicate]`.

## Deploy (Docker on a VPS)

`deploy/` contains a Dockerfile (php-fpm + a Node stage that builds the assets), an nginx
config and a compose file with **five services**: `app` (php-fpm), `web` (nginx:80),
`db` (Postgres 17), `worker` (`queue:work`), `scheduler` (`schedule:work`).

```bash
# on the VPS, in deploy/
cat > .env <<'EOF'   # never commit this file
APP_URL=https://your-domain
APP_KEY=base64:...            # php artisan key:generate --show, from any machine
DB_PASSWORD=...
PIXELFORGE_API_KEY=pf_prod_...            # a SEPARATE key for production
PIXELFORGE_WEBHOOK_SECRET=whsec_prod_...
PIXELFORGE_ADMIN_EMAILS=you@example.com
EOF

docker compose build
docker compose up -d
docker compose run --rm app php artisan migrate --force
```

Then in the Pixelforge dashboard, set the **production key's** webhook URL to
`https://your-domain/webhooks/pixelforge` (TLS-terminating reverse proxy optional but
recommended in front of nginx). Verify with:

```bash
docker compose exec app php artisan pixelforge:reconcile   # must show no MISMATCH
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

Secrets live only in `.env` (gitignored). `AGENT_PROMPT.md` is the task statement this
app was built for, kept on purpose.
