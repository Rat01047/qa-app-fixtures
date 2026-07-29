# Deployment reference app — `laravel-noenv` branch

Identical to the `laravel` branch in every way **except that it ships no
`.env.example`**. Sibling branches: `laravel`, `laravel-minimal`, `php`,
`symfony`, `bedrock`.

Do not add a `.env.example` here. That single missing file is the entire branch.

## Why this branch exists

Most Laravel repos ship a `.env.example`, and most host provisioning starts by
copying it. This branch is the case where there is nothing to copy, so the site
runs on whatever the host injects plus Laravel's own config defaults.

The `laravel` branch ships an `.env.example` that pins
`QUEUE_CONNECTION=database`, which makes the queue work out of the box. That is
convenient, and it hides the question. Here nothing is pinned — which is what a
real user's Laravel repo without a committed `.env.example` would get.

## The `APP_KEY` hazard

This is the specific thing to watch, and it is a well-known Laravel deployment
edge rather than anything host-specific.

`artisan key:generate` does not append `APP_KEY`. It *replaces* an existing
`APP_KEY=` line via `preg_replace`, and refuses when there is none:

```
ERROR  Unable to set application key. No APP_KEY variable was found in the .env file.
```

That `APP_KEY=` line normally arrives from `.env.example`. With no
`.env.example`, it has to come from whatever the host writes into `.env`
instead. If it does not, `key:generate` fails and every request afterwards dies
with `Illuminate\Encryption\MissingAppKeyException` — a 500 on `/`.

`key:generate` fails **loudly**, so the failure should be in the deployment log
either way. The question worth answering is whether a deploy that hit it is
reported as failed, or reported green over a broken site. A green deploy over a
500 is the more serious of the two outcomes.

**Deploy it and see.** Do not assume either result.

## What to check

- **Provisioning completes** even though there was no `.env.example` to copy.
- **`/` returns 200** — meaning `APP_KEY` was set despite no example file. If it
  returns 500 with `MissingAppKeyException`, check what the deployment log and
  the site status say.
- **The Environment editor** shows exactly the keys the host injected and
  nothing else. Compare against `health.env`, which reports what the app
  actually resolved.
- **Queue mode still works, or fails honestly.** `health.env.queue_connection`
  shows what the framework defaulted to. If a worker is enabled and jobs are
  never drained, that is a real finding about a Laravel site deployed without an
  `.env.example` — not a fixture bug.
- **Sessions and cache.** Both default to `database` in Laravel 12; the required
  tables ship in this repo's migrations, so they should work. Confirm rather
  than assume.

## Pairing

| Branch | `.env.example` | Expectation |
| --- | --- | --- |
| `laravel` | present, pins `QUEUE_CONNECTION=database` | Queue works immediately |
| `laravel-noenv` | **absent** | App boots on injected keys + framework defaults |
| `php` | absent (and no `.env` either) | Non-Laravel type; nothing injected at all |

Everything else — routes, `/health`, Horizon, Octane, Inertia SSR, the scheduler
and queue breadcrumbs — matches the `laravel` branch. See its README for how
each is verified.
