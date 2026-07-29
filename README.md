# Bedrock — deployment reference app

A real [Roots Bedrock](https://roots.io/bedrock/) site, kept deliberately close to
upstream, used to exercise a Bedrock deployment end to end: clone,
`composer install`, environment file, database, Redis object cache, and the
WordPress admin.

Unlike the other branches in this repo, `/` is **not** a QA acceptance page. A
Bedrock fixture is only worth anything if the site it deploys is a genuine
WordPress site, so `/` serves WordPress and the QA surface lives on its own
paths:

| Path | Serves | Status code |
| --- | --- | --- |
| `/` | Real WordPress (installer on first boot, then the site) | WordPress decides |
| `/qa` | Plain-HTML acceptance page — no theme, no query, no posts table needed | 200 |
| `/qa-health` | JSON health document | Always 200 |

`/qa` and `/qa-health` come from `web/app/mu-plugins/qa-endpoints.php`, a
single-file must-use plugin with no dependencies. It hooks `muplugins_loaded`,
which runs after `$wpdb` exists but before WordPress checks whether it is
installed — so both paths answer on a freshly deployed site whose database is
reachable but whose tables have not been created yet. That window is exactly
when a deploy is most likely to be wrong, and it is the window a `/`-only fixture
cannot see into.

## Why Bedrock is its own case

Three things differ from a plain WordPress install, and each is a way a deploy
can fail:

1. **Separated web root.** The document root is `web/`, not the project root.
   Point the server at the project root and every request 404s or leaks
   `composer.json`.
2. **Composer-managed core.** WordPress itself is a Composer package installed
   into `web/wp` (`extra.wordpress-install-dir`). No `composer install`, no
   WordPress — there is no core to fall back on.
3. **Config from `.env`.** `config/application.php` reads the environment
   through phpdotenv and `Roots\WPConfig\Config`. `WP_HOME`, `WP_SITEURL`,
   `DB_NAME`, `DB_USER` and `DB_PASSWORD` are **required** — phpdotenv throws if
   they are missing, which is a hard 500 before WordPress starts.

`/qa-health` reports on all three: `bedrock.abspath_is_web_wp`,
`composer.wp_core_installed_to`, `composer.vendor_present`,
`bedrock.dotenv_present`, `bedrock.wp_env`, `bedrock.content_dir`.

## Deploying it

Any host that can run PHP 8.0+ with Composer. Nothing here is host-specific.

```bash
composer install
cp .env.example .env      # then edit: WP_HOME, WP_SITEURL, DB_*
# document root -> web/
```

Then open `/qa`. If it renders, PHP, the autoloader, `.env` and the Bedrock
bootstrap are all working. Open `/` to run the WordPress installer.

Requirements, exactly:

- PHP **>= 8.0** (`composer.json`). Built and locked against 8.4.
- MySQL or MariaDB — a real database, not SQLite.
- Redis **optional**. Without it the site works; `redis-cache` simply never
  activates and `/qa-health` reports `redis.dropin_present: false`.
- Document root **must** be `web/`.

Locked versions: WordPress `6.9.5` via `roots/wordpress`, plus `redis-cache`
and the `twentytwentyfour` theme from wpackagist. `composer.lock` is committed, so
`composer install` is reproducible; `composer update` is not needed and should
not be run as part of a deploy test.

## What to check on a deploy

**Green means:** `/qa` renders with a version number; `/qa-health` returns
`database.connected: true`; `bedrock.abspath_is_web_wp: true`;
`composer.vendor_present: true`; `/` shows the WordPress installer or the site.

**Read the body, not the status code.** `/qa-health` is always HTTP 200 by
design — a 200 with `database.ok: false` is a **failure**, and reporting it in
the payload is the point. A health endpoint that 500s tells you only that
something broke; this one tells you which thing.

Specific things worth a look:

- **`vendor/` missing** — the single most common Bedrock deploy failure. The
  site is a hard 500 with no useful output, because `web/wp-config.php` requires
  the autoloader on line 1. `/qa` will not answer either. This is the
  distinguishing symptom.
- **Salts.** `.env.example` ships `generateme` for all eight, on purpose.
  `/qa-health` reports `bedrock.salts_are_placeholders`. A production-intent
  deploy that leaves them is a real finding, not a fixture quirk.
- **`WP_ENV`.** Defaults to `production`, which sets `DISALLOW_FILE_MODS = true`
  — so plugin and theme install is disabled in the admin, by design.
  `config/environments/development.php` re-enables it. If a tester reports "I
  can't install plugins", check `bedrock.wp_env` first.
- **Redis.** `redis.host`, `redis.prefix`, `redis.ext_redis_loaded` and
  `redis.dropin_present` are separate fields on purpose: configured is not the
  same as serving. `dropin_present` only turns true once the `redis-cache`
  plugin has been activated and its object cache drop-in written.
  `WP_REDIS_PREFIX` namespaces this site's keys — on a host that scopes the
  site's Redis user to a fixed key prefix, this value must match that prefix or
  Redis refuses the write.
- **Cron.** `DISABLE_WP_CRON` is unset by default, so WordPress fires cron on
  page loads. Set it to `true` only when a real cron runs
  `wp cron event run --due-now`.

## Redeploy verification

`DEPLOY_MARKER` in `web/app/mu-plugins/qa-endpoints.php` is bumped by hand
(`bedrock-v1`). Change it, push, redeploy, and check `/qa-health`. If the marker
did not change, the deploy did not pull new commits — regardless of what the
deploy log said.

## Layout

```
config/
  application.php            base config; reads .env, defines ABSPATH -> web/wp
  environments/
    development.php          WP_DEBUG on, DISALLOW_FILE_MODS off
    staging.php              production plus DISALLOW_INDEXING
web/
  index.php                  front controller
  wp-config.php              autoloader + config/application.php only
  app/                       WP_CONTENT_DIR (themes, plugins, uploads)
    mu-plugins/
      bedrock-autoloader.php upstream: lets regular plugins run as mu-plugins
      qa-endpoints.php       /qa and /qa-health
  wp/                        WordPress core, installed by Composer (gitignored)
```

`web/wp`, `vendor/`, `.env` and everything under `web/app/uploads` are
gitignored — nothing generated is committed.

## Not for production

This is a test fixture. `/qa` and `/qa-health` are unauthenticated and report
configuration detail about the site. Deploy it to throwaway test sites only.
