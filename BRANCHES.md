# Deployment reference apps

One branch per app type. Each is a real, deployable application, not a stub —
the point is to test provisioning, deployment and per-type settings against
something that genuinely runs.

`main` tracks `bedrock` so a default clone is still a working site. Every
branch has its own README with acceptance criteria and the reasoning behind its
contents.

Nothing here is host-specific. Each branch installs with stock
`composer install` / `npm ci` (`pnpm install` for `koel`) and needs no tooling
beyond PHP, Composer and Node.

### Synthetic — built to make deployment behaviour observable

| Branch | App type | Role |
| --- | --- | --- |
| `laravel` | Laravel 12 | Full feature surface — scheduler, queue, Horizon, Octane, Inertia SSR |
| `laravel-minimal` | Laravel 12 | Negative control — none of the detected packages |
| `laravel-noenv` | Laravel 12 | Same as `laravel` but ships **no** `.env.example` |
| `laravel-9` | Laravel 9 | **End-of-life** framework line — old app skeleton, old Vite, `composer update` refuses to resolve it |
| `php` | PHP | Plain PHP, no framework; exercises a Node build step on a non-Laravel site |
| `symfony` | Symfony 7.4 | Webpack Encore build; committed `.env` |
| `bedrock` | Bedrock | A real Roots Bedrock site — separated web root, Composer-managed WordPress, config from `.env` |
| `main` | Bedrock | Mirror of `bedrock` plus this index |

### Real-world — upstream apps written with no deployment tool in mind

Vendored unmodified except for a `QA-NOTES.md`. The synthetic branches prove
features work when built by someone who knew what was being tested; these ask
whether they work on somebody else's app. Neither has a deploy marker or a
`/health` — patching them in would forfeit the property that makes them useful.

| Branch | Upstream | Reaches |
| --- | --- | --- |
| `koel` | koel/koel v9.11.0 | **pnpm** (`pnpm@10.32.1` + `pnpm-lock.yaml`), chained build, `ext-gd` / `ext-exif`, `php >=8.3` |
| `firefly-iii` | firefly-iii/firefly-iii | **`php >=8.5`**, 14 required extensions, `package.json` with **no build script**, a `postinstall` (`patch-package --error-on-fail`) that can fail the deploy |

## PHP version support

The Laravel branches pin `config.platform.php = 8.2.0`, so one committed
`composer.lock` installs on **8.2, 8.3, 8.4 and 8.5** — verified with
`composer check-platform-reqs`. They are meant to deploy anywhere.

| Branch | `composer.json` | Installs on |
| --- | --- | --- |
| `laravel`, `laravel-minimal`, `laravel-noenv` | `^8.2` | 8.2 – 8.5 |
| `laravel-9` | `^8.0.2` | 8.1 – 8.4 (platform pinned to `8.1.0`) |
| `symfony` | `>=8.2` | 8.2 – 8.5 |
| `php` | `>=7.4` | 7.4 and up |
| `bedrock`, `main` | `>=8.0` | 8.0 and up |
| `koel` | `>=8.3` | 8.3 and up |
| `firefly-iii` | `>=8.5` | 8.5 only |

## Why the branches differ on purpose

Several properties look like omissions and are not. They are what makes a case
falsifiable, and each branch README says so in place:

| Property | Where | Tests |
| --- | --- | --- |
| No `.env`, no `.env.example` | `php` | Environment editor with nothing to read |
| Committed `.env` | `symfony` | The control case for the above |
| No `package.json` at all | `bedrock` | Builder must skip the Node step, not fail the deploy |
| `package.json` with no dependencies | `php` | Node build runs for a non-Laravel site |
| No Horizon / Octane / Inertia | `laravel-minimal` | Feature detection gates the UI correctly |
| Horizon + Octane + Inertia + SSR | `laravel` | Those same controls appear and work |
| No `.env.example` on a **Laravel** site | `laravel-noenv` | `key:generate` has no `APP_KEY=` line to replace |
| `php >=8.5` | `firefly-iii` | Deploy must fail honestly when the version is unavailable |
| End-of-life framework, advisories on the lock | `laravel-9` | `composer install` deploys; `composer update` fails. A deploy must run the right one |
| Pre-Laravel-11 app skeleton | `laravel-9` | `app/Http/Kernel.php` still exists — anything patching it by path must gate on the major version |
| pnpm instead of npm | `koel` | A package manager other than npm, driven by its own lockfile |
| Composer-managed WordPress core | `bedrock` | A deploy that forgets `composer install` has no core to fall back on |

Both Laravel `detect` branches answer the same `/health` shape, so the negative
control is diffable against the full one:

```bash
curl -s https://<site>/health | python3 -m json.tool | grep -A5 '"detect"'
# laravel          horizon/octane/inertia/ssr_ready -> true
# laravel-minimal  all four -> false
# laravel-9        all four -> false, plus a "legacy" block the others do not have
```

A tool that offers Horizon, Octane or SSR controls on `laravel-minimal` is
over-detecting, and this is what proves it without SSH.

## One branch is meant to go red

- **`laravel-noenv`** — a Laravel app with no `.env.example` has nothing for
  `artisan key:generate` to rewrite, because that command replaces an existing
  `APP_KEY=` line rather than appending one. Verified behaviour: `key:generate`
  aborts with `file_get_contents(.env): Failed to open stream`, and every page
  is a 500 carrying `MissingAppKeyException — No application encryption key has
  been specified`. Supply a `.env` with an `APP_KEY=` line and the same tree
  returns 200. The question worth answering is whether the deploy reports a
  failure or goes green over a broken site.

`firefly-iii` will also fail `composer install` wherever PHP 8.5 is
unavailable. That is a prediction about the host, not a defect in the branch —
the deployment log should name the PHP platform requirement rather than failing
vaguely.

## Conventions shared by the branches

- **Acceptance page** — `GET /` returns 200 with `Deploy OK — <Type>` and needs
  no build, database or worker, so a broken sub-feature can never take it down.
  `bedrock` is the deliberate exception: `/` serves real WordPress and the
  acceptance page moves to `/qa`, because a WordPress site is only meaningful
  if WordPress is actually what answers.
- **Health JSON** — `GET /health` (`/?health` on `php`, `/qa-health` on
  `bedrock`) always returns **200** and carries the verdict in the payload,
  never in the status code. Probes are isolated: an unreachable Redis reports an
  error string rather than 500ing.
- **Deploy marker** — a hand-bumped constant echoed on the acceptance page and
  in the health JSON. It is the only reliable proof that a redeploy pulled new
  commits instead of just restarting containers. Bump it, push, redeploy,
  re-read.
- **Nothing installed is committed.** On `bedrock` that includes plugins and
  themes: they are declared in `composer.json`, installed into `web/app` by
  `composer/installers`, and excluded by `.gitignore`. `/qa-health` reports
  `composer.locked` beside `content.plugins` / `content.themes`, so "the lock
  file promises it" and "it is on the deployed disk" can be diffed. A package in
  one list and not the other means `composer install` did not finish — on a site
  that still serves HTTP 200.
- **No committed build output.** A deploy that uses `git pull` would find
  committed artifacts conflicting with regenerated ones, breaking every
  subsequent redeploy. Leaving them out also gives a genuine
  *not built → built* transition to test.
- **Redis via predis on Laravel.** `predis/predis` is a pure-PHP client, so
  `REDIS_CLIENT=predis` works on hosts with no `ext-redis`. Switching to
  `phpredis` where the extension is absent throws `Class "Redis" not found`
  from every Redis call.

## Verified

Built and run locally on PHP 8.4.20 / Node 22, from a clean checkout of each
branch:

| Branch | Install | Build | `GET /` | Health |
| --- | --- | --- | --- | --- |
| `laravel` | ✓ | `npm run build` ✓ | 200 `Deploy OK — Laravel` | `ok:true`, all four `detect` true, queue drains |
| `laravel-minimal` | ✓ | `npm ci` + `npm run build` ✓ | 200 `Deploy OK — Laravel` | `ok:true`, all four `detect` false |
| `laravel-noenv` | ✓ | — | 500 by design | see above |
| `php` | ✓ | `npm run build` ✓ | 200 `Deploy OK — PHP` | `ok:true`, `build.ran:true` |
| `symfony` | ✓ | `encore production` ✓ | 200 `Deploy OK — Symfony` | `ok:true`, entrypoints + manifest present |
| `bedrock` | ✓ | — | 200 real WordPress 6.9.5 | `/qa` 200 listing 3 plugins + 2 themes, `/qa-health` `ok:true`, `database.connected:true`, `content` matches `composer.locked` |
| `laravel-9` | ✓ `install` (4 advisories reported) | `vite build` ✓ (Vite 4.5.14) | 200 `Deploy OK — Laravel`, `9.52.21` | `ok:true`, `detect` all false, `legacy.major:9`, both kernel files true |

`koel` and `firefly-iii` are vendored upstream and were checked at the manifest
level only — package manager, lockfile, PHP constraint and extension list.

## Test sites, not production sites

Every branch exposes an unauthenticated acceptance page and health endpoint
that report configuration detail about the running site. That is the point, and
it is also why these belong on throwaway test sites only.
