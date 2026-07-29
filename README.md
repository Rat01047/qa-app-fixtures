# Deployment reference app — `laravel` branch

A real Laravel 12 application used to verify a Laravel deployment
support end to end. It is deliberately built so that every Laravel-specific
feature a deploy tool offers can be *observed*, not merely inferred from config.

Sibling branches: `laravel-minimal`, `laravel-noenv`, `php`, `symfony`, `bedrock`. See
[Branch matrix](#branch-matrix).

## Acceptance

The homepage is self-contained — no Vite build, no database, no queue — so it
returns **200** after `composer install` + `php artisan key:generate` alone:

```
GET /  →  ✓ Deploy OK — Laravel
```

Anything that needs a build, a DB or a worker lives on its own route, so a
broken sub-feature can never take the acceptance page down.

## Routes

| Route             | Needs                | Proves                                                     |
| ----------------- | -------------------- | ---------------------------------------------------------- |
| `/`               | nothing              | Provisioning + deploy succeeded                             |
| `/health`         | nothing (DB optional)| Everything below, as one JSON document                      |
| `/queue/dispatch` | DB                   | Enqueues a job for a worker or Horizon to drain             |
| `/queue/dispatch?fail=1` | DB            | Enqueues a job that always throws — exercises retries        |
| `/inertia`        | `npm run build`      | Inertia renders; SSR on/off is distinguishable via `curl`   |
| `/horizon`        | Horizon + Redis      | Horizon's own dashboard                                     |

### `/health`

Always returns **200** — the payload, never the status code, carries the
verdict. Each probe is isolated, so an unreachable Redis reports as an error
string instead of 500ing the page.

```json
{
  "deploy_marker": "v3",
  "detect":    { "horizon": true, "octane": true, "inertia": true,
                 "ssr_ready": true, "maintenance": false },
  "env":       { "queue_connection": "database", "redis_prefix": "...",
                 "horizon_prefix": "horizon:" },
  "scheduler": { "last_run": { "ran_at": "...", "run_count": 7 } },
  "queue":     { "pending_jobs": 0, "last_processed": { "job_id": "..." } },
  "redis":     { "ping": "PONG" }
}
```

`detect` reports the same facts a deploy tool's Laravel feature detection reads out
of `composer.json`, so panel detection can be diffed against ground truth on the
box without SSH.

## What each deployment setting should do

### Scheduler

With the scheduler enabled, the site runs `php artisan schedule:run` once a
minute. `routes/console.php` registers a per-minute task that writes a
breadcrumb.

- Scheduler **on** → `health.scheduler.last_run.run_count` climbs
- Scheduler **off** → `run_count` frozen

A scheduler that is configured is not the same as a scheduler that ran;
`run_count` is what proves it.

### Queue worker / Horizon

`QUEUE_CONNECTION=database` (from `.env.example`) and the `jobs` table ship with
the repo, so a worker needs no extra setup.

1. `GET /queue/dispatch`
2. `health.queue.pending_jobs` → **1** while queue mode is **Off**
3. Switch to **Queue worker** or **Horizon** → drops to **0**, and
   `health.queue.last_processed` names the job and container

Raising the worker **processes** setting above 1 should put more than one worker
on the queue — check that `last_processed.hostname` varies across dispatches.

#### Failures and retries

`GET /queue/dispatch?fail=1` enqueues a job that throws every time. Each attempt
appends to `health.queue.recent_failed_attempts`, and the row lands in
`failed_jobs` once attempts are exhausted.

This is what makes the worker's **tries** setting falsifiable: a worker that
silently drops failures looks identical to one that retries correctly unless the
attempts are counted. Set tries to 3, dispatch, and expect attempts `1, 2, 3`
followed by `failed_jobs: 1`.

#### Horizon and the Redis key prefix

Horizon is installed, so the **Horizon** queue option is offered. Worth knowing
when testing it: Horizon does not use `REDIS_PREFIX`. It applies its own key
prefix from `config/horizon.php` (default `horizon:`). On any host that scopes a
site's Redis user to a fixed key prefix, the two disagree and Redis refuses the
write.

`health.env` reports both `redis_prefix` and `horizon_prefix` so the mismatch is
visible without SSH. If Horizon cannot write, align `HORIZON_PREFIX` in `.env`
with `REDIS_PREFIX` and restart — that is a real-world Horizon deployment gotcha,
not a fixture bug.

`predis/predis` is installed as a fallback if the PHP image lacks the `redis`
extension: set `REDIS_CLIENT=predis`.

### Inertia SSR

`inertiajs/inertia-laravel` + React 19 are installed, and
`npm run build` (`vite build && vite build --ssr`) emits
**`bootstrap/ssr/ssr.js`** — the conventional Laravel SSR bundle path, which is
what `ssr_ready` detection looks for and what the SSR process runs.

Build output is **not committed**, on purpose:

- it gives a genuine *not built → built* transition to test, covering both SSR
  UI states and the behaviour of an SSR process started before a bundle exists;
- committing it would make the deploy's `git pull` conflict with regenerated
  assets and break every subsequent redeploy.

So `ssr_ready` is `false` until the site's **build command** is set to
`npm run build` and the site is redeployed.

Telling SSR on from off with no browser — the page prints
`INERTIA_PAGE_RENDERED`:

```bash
curl -s https://<site>/inertia | grep -c INERTIA_PAGE_RENDERED
# 1 → the SSR server rendered it     0 → React hydrated it client-side
```

The SSR bundle is built with `ssr.noExternal`, so it runs standalone and cannot
break on a missing or stale `node_modules` in the deployed tree.

### Octane

`laravel/octane` is installed **solely** so the `octane` detection flag is true
somewhere. A deploy tool consumes it in exactly one place — a "Managed support is coming
soon" notice on the Laravel settings page. No compose service, no server
command. No Octane server binary (Swoole / RoadRunner / FrankenPHP) is
installed, and none is needed: nothing here ever runs `octane:start`.

Expected: the notice appears, and nothing else about the site changes.

### Maintenance mode

`php artisan down` creates `storage/framework/down`, which a deploy tool reads back as
`maintenance`. `health.detect.maintenance` mirrors it.

### Redeploy delta

`QA_DEPLOY_MARKER` in `routes/web.php` is bumped by hand each time a commit is
added to this branch, and is echoed on `/` and in `/health`. It is the only
reliable way to prove a redeploy actually pulled new commits rather than just
restarting containers.

## Notes on the build

- `.npmrc` sets `ignore-scripts=true`, and it stays on as supply-chain hygiene.
  `package-lock.json` proves it is safe here: the **only** package with an
  install script is `fsevents`, which is darwin-only and optional, and the
  rolldown / lightningcss linux binaries (`x64-gnu`, `x64-musl`, arm64) are all
  present as optional dependencies that npm resolves without running scripts.
  Verify after any dependency change:

  ```bash
  node -e "const p=require('./package-lock.json').packages; \
    console.log(Object.keys(p).filter(k=>p[k].hasInstallScript))"
  # expect [] or only node_modules/fsevents
  ```
- `composer.json` declares `php: ^8.2` and `composer.lock` is resolved with
  `config.platform.php = 8.2.0`, so `composer install` succeeds on PHP 8.2,
  8.3, 8.4 and 8.5. The branch is meant to deploy anywhere, not to fail.
- The `bunny` font plugin was removed from `vite.config.js`: it fetched fonts
  over the network at build time, which makes server-side builds flaky.

## Branch matrix

| Branch            | App type | Purpose                                                                |
| ----------------- | -------- | ---------------------------------------------------------------------- |
| `laravel`         | Laravel  | Full feature surface — scheduler, queue, Horizon, Octane, Inertia SSR   |
| `laravel-minimal` | Laravel  | No Horizon, no Inertia — controls must stay hidden / gated              |
| `laravel-noenv`   | Laravel  | Same as `laravel` but ships **no** `.env.example`                       |
| `php`             | PHP      | Plain PHP; ships **no** `.env` and **no** `.env.example`                |
| `symfony`         | Symfony  | Symfony 7.4 + Webpack Encore; ships a committed `.env`                  |
| `bedrock`         | Bedrock  | Bedrock / WordPress                                                     |

## Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm ci && npm run build
php artisan serve
```
