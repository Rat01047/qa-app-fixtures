# Deployment reference app — `php` branch

Minimal plain-PHP application for testing a plain-PHP deployment via
Git import. Sibling branches: `laravel`, `laravel-minimal`, `symfony`,
`bedrock`.

- PHP requirement: `>= 7.4`
- Docroot: works from repo root **or** `public/` (both have an `index.php`)
- Deploy step: `composer install` (autoloads `src/App.php`), then the Node
  build if a build command is configured

## Acceptance

- `GET /` returns **200** with "Deploy OK — PHP"
- `GET /?health` returns JSON `{ "ok": true, ... }`

## What this branch is deliberately missing

**No `.env` and no `.env.example`.** This is the fixture's most important
property, not an oversight:

- DB and Redis values are injected into `.env` for **Laravel** sites; PHP and
  Symfony sites only get `.env.example` copied to `.env` if one exists.
- With neither file present, the site's Environment editor has nothing to read.
  That is the case this branch exists to exercise.

`/?health` reports `env_file.dotenv_present` and `env_file.dotenv_example_present`
so a deploy tool's behaviour can be checked against what is actually on disk.

The `symfony` branch is the control case: it ships a committed `.env`.

## Node build on a non-Laravel site

The Node install step runs whenever `package.json` exists — regardless of app
type — and is followed by the configured build command. This branch ships a
dependency-free `package.json` and `build.mjs` so that path is exercised without
pulling in a bundler.

- Build command **unset** → `/?health` shows `build.ran: false`
- Build command `npm run build` → `build.ran: true`, with the build timestamp,
  Node version and container hostname

Build output (`public/assets/`) is gitignored on purpose: the deploy uses
`git pull`, so committed build artifacts would conflict with regenerated ones
and break every subsequent redeploy.

## Redeploy delta

`App::DEPLOY_MARKER` in `src/App.php` is bumped by hand whenever a commit is
added, and is echoed on `/` and in `/?health`. It is the only reliable way to
prove a redeploy pulled new commits rather than just restarting containers.
