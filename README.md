# Laravel 9 — legacy deployment reference app

A stock Laravel **9.52.21** application, the last release of a line that has
reached end of life. Everything else in this repository targets a currently
supported framework; this branch exists because real customers do not upgrade on
schedule, and a deploy tool meets their old apps whether it planned to or not.

| Path | Serves | Status code |
| --- | --- | --- |
| `/` | Plain-HTML acceptance page — no Vite build, no database | 200 |
| `/health` | JSON health document | Always 200 |

`/health` answers the same `detect` shape as the Laravel 12 branches, so all
three are diffable with one command, and adds a `legacy` block for the facts
that are true only because the framework is old.

## What this branch tests that the others cannot

### 1. Composer refuses to resolve it

Laravel 9 carries published security advisories. On a modern Composer, a plain
`composer update` **fails outright**:

```
Your requirements could not be resolved to an installable set of packages.
  - Root composer.json requires laravel/framework ^9.19, found
    laravel/framework[v9.19.0, ..., v9.52.21] but these were not loaded,
    because they are affected by security advisories
```

`composer.json` therefore sets `config.audit.block-insecure: false`, and
`composer.lock` is committed. The consequence is the test:

- **`composer install`** — succeeds, prints
  `Found 4 security vulnerability advisories affecting 1 package`, and deploys.
- **`composer update`** — is a different command with a different job, and on a
  legacy app it is the wrong one.

A deploy that runs `update` instead of `install` fails here. That is correct
behaviour to discover on a fixture rather than on a customer's site, and it is
the single most valuable thing this branch does.

### 2. The old application skeleton

Laravel 11 removed `app/Http/Kernel.php` and `app/Console/Kernel.php`, moving
middleware and scheduling into `bootstrap/app.php`. Laravel 9 still has both.
Anything that edits those files by path — registering middleware, adding a
scheduled command, injecting a trusted-proxy setting — must branch on the major
version. `/health` reports `legacy.http_kernel_file` and
`legacy.console_kernel_file` so the assumption can be checked from outside.

### 3. An older build toolchain

Vite **4.5.14**, not the Vite 6/7 the current branches use, and
`laravel-vite-plugin` 0.x. It builds cleanly on Node 22, but it is a genuinely
different toolchain and pins genuinely older transitive dependencies.

`legacy.vite_hot_file` reports whether `public/hot` was left behind. That file
is Vite's dev-server marker: if a build leaves it in place, every production
page points `@vite` at a dev server that is not running, and the site loads with
no CSS and no JS while still returning 200.

## Deploying it

```bash
composer install          # NOT `composer update` — see above
cp .env.example .env
php artisan key:generate
npm ci && npm run build   # optional; `/` and `/health` do not need it
# document root -> public/
```

Requirements, exactly:

- PHP **^8.0.2** per `composer.json`. `config.platform.php` is pinned to
  `8.1.0`, so the committed lock installs on **8.1 through 8.4** —
  `composer check-platform-reqs` reports no failures.
- No database needed for `/` or `/health`. `.env.example` ships the stock MySQL
  block; the QA routes never touch it.
- Node only if you want the assets. The homepage deliberately does not use
  `resources/views/welcome.blade.php`, because that view calls `@vite` and would
  500 on an unbuilt production deploy.

## What to check on a deploy

**Green means:** `/` returns 200 showing `Laravel: 9.52.21`; `/health` returns
`legacy.major: 9`, `end_of_life: true`, both kernel files `true`, and
`vite_hot_file: false`.

**Read the body, not the status code.** `/health` is always HTTP 200 by design.
A 200 whose payload says `laravel_version: 12.x` would mean the wrong branch
was deployed — the status code alone cannot tell you that.

Specific things worth a look:

- **PHP version selection.** This app must not be handed PHP 8.5. Laravel 9's
  dependencies were never tested there, and the failure mode is deprecation
  notices and fatals at runtime rather than a clean refusal at install time.
- **Did anything try to "modernise" the app?** If `app/Http/Kernel.php` is gone
  after a deploy, something rewrote the skeleton. `/health` catches it.
- **`public/hot`.** Should be absent in production. If `legacy.vite_hot_file` is
  `true`, the build ran in dev mode.
- **The advisory output.** `composer install` prints a vulnerability count. A
  deploy pipeline that treats any Composer output on stderr as a failure will
  go red on a site that deployed perfectly well.

## Redeploy verification

`QA_DEPLOY_MARKER` in `routes/web.php` is bumped by hand (`l9-v1`). Change it,
push, redeploy, and check `/health`. If the marker did not change, the deploy
did not pull new commits — regardless of what the deploy log said.

## Not for production

This is a test fixture running a framework version with known, unpatched
vulnerabilities. `/health` is unauthenticated and reports configuration detail.
Deploy it to throwaway test sites only, and do not put real data behind it.
