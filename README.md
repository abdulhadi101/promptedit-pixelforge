# PromptEdit assessment: foundation overlay

This is NOT a full Laravel app. It is the app-layer code to drop onto a fresh Laravel + Breeze (Vue/Inertia) install.
It was written without PHP available, so it has not been executed. Run `php artisan test` first.

## Install
```bash
composer create-project laravel/laravel promptedit && cd promptedit
composer require laravel/breeze --dev && php artisan breeze:install vue   # pick PHPUnit when asked
cp -R /path/to/overlay/{app,database,routes,resources,tests} .           # merge, don't replace stock dirs
# apply config-snippets/SETUP_SNIPPETS.md, then:
php artisan migrate && php artisan test
php artisan queue:work   # and in another shell: php artisan schedule:work
```
Local webhooks: tunnel (ngrok/Expose/cloudflared) -> set the key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.
Use a separate Pixelforge key (and secret) for local and deployed.

## Design decisions (interview talking points)
- Money = integer nanodollars. Provider cost is exact (tokens x rate x 1000). User charge = cost x 125/100 rounded half-up to a nanodollar.
- Estimate shows min/expected/max (text tokens +-15%, reasoning range). Final price uses real usage and the generation's own rate snapshot.
- Local row (uuid) is created first and used as Idempotency-Key and `metadata.generation_id`, so a webhook that beats the POST response still matches.
- One state-change function (`GenerationStateApplier`): row lock, forward-only states, final states immutable. Webhooks, polling and submit failures all use it.
- Webhook dedupe: unique event id inserted in the same DB transaction as the state change.
- Retries: 429/503 honour Retry-After with jitter, bounded by 10 minutes; permanent errors fail the generation for free.
- `pixelforge:sync` polls stragglers; `pixelforge:reconcile` compares per-day provider costs with `GET /v1/usage` (billed by UTC completion day).
