# Deployment reference app — `symfony` branch

A real Symfony 7.4 application for testing a Symfony deployment via Git clone,
`composer install` and a Node build step. It is deliberately small, but nothing
about it is stubbed: it boots the full framework, renders Twig, and serves
assets compiled by Webpack Encore.

## Acceptance criteria

```
GET /         →  200, "Deploy OK — Symfony", with version + marker + APP_ENV
GET /health   →  200, JSON, always — the payload carries the verdict
GET /assets   →  200; renders via Twig + Encore once the build has run,
                 otherwise a 200 JSON explanation (never a 500)
GET /log-test →  200; writes one line at every level to the application log
```

**Green means:** `/` renders with a Symfony version number, `/health` returns
`ok: true`, and `/health` → `node_build.entrypoints_present` is `true` after a
deploy whose build command ran.

## Requirements, exactly

- PHP **>= 8.2**. `composer.lock` is resolved with
  `config.platform.php = 8.2.0`, so `composer install` succeeds on PHP 8.2,
  8.3, 8.4 and 8.5. Verify with `composer check-platform-reqs`.
- Extensions: `ext-ctype`, `ext-iconv`. Both are near-universal.
- **No database.** Nothing to migrate, nothing to seed.
- Node **>= 18** for the build step. Not needed to serve the site.
- Document root **must** be `public/`.
- `var/` must be writable — Symfony writes its cache and logs there.

## Build and redeploy

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

`npm run build` runs `encore production`, which writes `public/build/`.
That directory is **gitignored and not committed**, so a fresh clone starts
unbuilt and the *not built → built* transition is genuinely testable.

To confirm a redeploy actually pulled new commits rather than restarting a
container: bump `DEPLOY_MARKER` in `src/Controller/HomeController.php`, push,
redeploy, and re-read `/health`.

## Application logs — the environment decides the file name

This is the part of the branch that exists specifically to test a log viewer.

Symfony does **not** have a fixed application-log file. `config/packages/monolog.yaml`
writes to:

```yaml
path: "%kernel.logs_dir%/%kernel.environment%.log"
```

so the file name is derived from `APP_ENV` **at runtime, on the server**:

| `APP_ENV` | Application log |
| --- | --- |
| `prod` (committed default) | `var/log/prod.log` |
| `dev` | `var/log/dev.log` |
| `test` | `var/log/test.log` |

Anything that reads the log has to resolve that name after the app boots.
Hardcoding `prod.log` looks correct on the default deployment and shows an
empty pane the moment the environment is switched.

### One upstream default worth knowing

Symfony's stock monolog recipe sends **prod** logs to `php://stderr` and writes
**no file at all**. On an untouched Symfony app, an empty or missing
`var/log/prod.log` is therefore correct behaviour, not a bug — the lines went to
the web server's error log instead.

This branch keeps that `php://stderr` handler *and* adds a file handler next to
it, so `prod.log` does fill here. Both shapes stay observable, and
`/health` → `logs.prod_also_writes_stderr` records that the stderr path is still
wired.

### Every line names its own environment

Log lines look like this:

```
[2026-07-29T11:03:45+00:00] app.INFO: QA visit env=dev route=/health marker=v4 symfony=7.4.14 php=8.4.14
```

The `env=` stamp is the point. A viewer that resolves the *wrong* file still
shows *a* file — and if both files held generic text, that mistake would read as
a pass. Because each line names the environment that wrote it, "the viewer
opened `dev.log` while `APP_ENV=prod`" is caught by reading the content, not by
trusting the file name.

### What `/health` reports

```
logs.app_env                  the environment the app actually booted in
logs.expected_file            the file name that environment implies
logs.expected_file_present    is it on disk
logs.expected_file_bytes      is it non-empty
logs.files_present            every *.log actually in var/log
logs.files[<name>].last_line  the tail of each, for content comparison
logs.dir_writable             false here means logging cannot work at all
```

Compare `logs.expected_file` against whatever file the viewer opened. If they
disagree, the resolution is wrong — no SSH needed.

### Test procedure

```bash
# 1. default deployment (APP_ENV=prod, as committed)
curl -s https://<site>/log-test
curl -s https://<site>/health | jq .logs
#    expect: expected_file "prod.log", present true, bytes > 0
#    the viewer must show prod.log, with lines reading env=prod

# 2. switch the environment, then redeploy / restart PHP
#    (write APP_ENV=dev into the environment editor, or into .env.local)
curl -s https://<site>/log-test
curl -s https://<site>/health | jq .logs
#    expect: expected_file "dev.log", present true, bytes > 0
#    the viewer must now show dev.log, with lines reading env=dev
#    a viewer still showing prod.log — or an empty pane — is the failure

# 3. re-hit /log-test and confirm the viewer's content changes
#    proves it is streaming live, not showing a file it read once
```

Step 3 matters: a stale cached read and a correct read look identical on a
single sample.

### Two ways to set the environment, and one local gotcha

Symfony reads `APP_ENV` from a real environment variable **first**, and falls
back to the `.env` files. Both work:

- **Real environment variable** — what a host passes through PHP-FPM or the
  container. Wins over `.env`.
- **`.env.local`** — Symfony's own convention, gitignored, overrides the
  committed `.env`. This is what a panel's environment editor writes.

Locally, `php -S` is the exception: PHP's default `variables_order=GPCS` does
not export the shell environment into `$_SERVER`, so `APP_ENV=dev php -S ...`
is silently ignored and `.env` wins. Use either:

```bash
APP_ENV=dev APP_DEBUG=1 php -d variables_order=EGPCS -S 127.0.0.1:8000 -t public
# or
printf 'APP_ENV=dev\nAPP_DEBUG=1\n' > .env.local && php -S 127.0.0.1:8000 -t public
```

Clear `var/cache` after switching, or the container from the previous
environment is reused.

## Why this branch is shaped the way it is

Several properties look like omissions and are not:

| Property | Tests |
| --- | --- |
| Committed `.env`, no `.env.example` | The control case for the `php` branch, which ships neither. A tool that seeds from `.env.example` finds nothing here. |
| `/` needs no build, no DB, no worker | A broken build or missing Node can never take the acceptance page down, so a red `/` means the deploy itself failed. |
| `/assets` guarded behind a file check | An unbuilt site degrades to a readable 200 instead of the 500 Encore throws on a missing `entrypoints.json`. |
| `public/build/` not committed | The deploy uses `git pull`; committed artifacts would collide with regenerated ones on every redeploy. |
| No CDN assets in `base.html.twig` | The page renders identically on a host with no outbound network access. |
| Log file name derived from `%kernel.environment%` | A log viewer that hardcodes `prod.log` gives an empty pane on a `dev` deployment. The name must be resolved at runtime. |
| A prod file handler added on top of upstream's stderr one | Upstream prod writes no file at all. Without this addition there is nothing for a viewer to show, so the feature could not be tested either way. |
| Every log line stamped `env=<environment>` | Distinguishes "viewer opened the right file" from "viewer opened a file". Generic text would let a wrong resolution read as a pass. |

## Notes

- `APP_SECRET` in `.env` is a visible placeholder, not a credential — this app
  has no sessions, forms or CSRF tokens, so nothing depends on it. The
  skeleton's `.env.dev` was deleted for the same reason: it shipped a generated
  secret that would only ever be noise in a public repo.
- `APP_ENV=prod` and `APP_DEBUG=0` are committed, so the app behaves on a test
  host the way it would in production rather than showing the debug toolbar.
  Overriding them is half the log test — see *Application logs* above.
- `var/log/` is gitignored. A fresh clone has no log directory at all; Symfony
  creates it on first boot. So "no log file yet" on a site that has never been
  hit is expected, and `/log-test` is the way to force one into existence.

## Local development

```bash
composer install
npm ci && npm run build
php -S localhost:8000 -t public
```
