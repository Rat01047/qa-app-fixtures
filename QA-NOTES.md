# Deployment reference app — `firefly-iii` branch

**Unmodified upstream [firefly-iii/firefly-iii](https://github.com/firefly-iii/firefly-iii)**,
vendored at `cdf1721e1d2d97ba7749285b113e9a5a69bbd969` (shallow copy, upstream
history not carried). This file is the only addition — the application is
untouched on purpose.

A large, mature, self-hosted Laravel application that knows nothing about the deployment tool.
Where the synthetic `laravel` branch proves features work when built to be
provable, this one asks whether they work on somebody else's real app.

## Why firefly-iii specifically

| Property | Value | Why it matters |
| --- | --- | --- |
| PHP | **`>=8.5`** | Likely **above** the highest version the picker offers |
| PHP extensions | **14 required** — `bcmath`, `curl`, `fileinfo`, `iconv`, `intl`, `json`, `mbstring`, `openssl`, `pdo`, `session`, `simplexml`, `tokenizer`, `xml`, `xmlwriter` | The most demanding extension surface of any branch |
| `package.json` | one dependency, **no build script** | Node install must run, build must be skipped |
| `postinstall` | `patch-package --error-on-fail` | An install script that can fail the deploy by design |
| Laravel | `^13` | Same major as the synthetic fixtures |

### The PHP 8.5 constraint is the headline

`composer.json` requires `php: >=8.5`. If the version picker tops out below that,
`composer install` cannot succeed, and the interesting question is what the
deploy reports.

That is the test: a deploy that goes green while the site is broken is worth far
more attention than one that fails and says why. The `laravel` branch has a
similar deterministic failure (its lockfile needs 8.4 while `composer.json` says
`^8.3`), but that one is a lockfile subtlety; this is a plain, loudly-declared
platform requirement.

If PHP 8.5 **is** offered, this instead becomes the most demanding successful
deploy in the repo — 14 extensions on the real platform image.

### `postinstall` and `--error-on-fail`

The only Node work is `npm ci` plus `patch-package --error-on-fail`, which
applies `patches/admin-lte+4.0.0.patch`. Two things worth watching:

- The flag means a patch that does not apply **fails the install**, and so the
  deploy. A genuine failing-build path that comes from upstream rather than
  being staged.
- This branch has **no `.npmrc`**, so unlike the `laravel` branch its install
  scripts do run. It is the counterpart case to `laravel`'s
  `ignore-scripts=true`.

### No build script

`package.json` defines no `build`, and the frontend assets are committed
upstream. So **leave the site's build command empty.** The Node install step
should still run (because `package.json` exists) and the build step should be
skipped rather than failing. The `symfony` branch covers "no `package.json` at
all"; this covers "`package.json` but nothing to build".

## Deploying it

1. app type **Laravel**, PHP **the highest available** — record what the
   picker actually offers
2. Build command: **empty**. Package manager: npm
3. Needs a database. Laravel sites are provisioned with a `DB_*` block; confirm
   it lands
4. `php artisan migrate --force` runs as part of the Laravel deploy script

## Acceptance

Two valid outcomes, and which one occurs is the finding:

- **PHP < 8.5 offered** → deploy **fails** at `composer install`, and the
  deployment log names the deploy tool requirement. Site status must reflect the
  failure.
- **PHP 8.5 offered** → deploy succeeds, `GET /` returns 200 and serves the
  firefly-iii setup or login page, with no 500s in `storage/logs/`.

Either way, the Laravel settings page should detect no Horizon, no Octane and no
Inertia.

## What this branch cannot do

No deploy marker and no `/health`. It is upstream code and should stay that way.
Use the `laravel` branch for marker-based redeploy checks and breadcrumb-based
scheduler and queue proofs.

## Refreshing it

```bash
git clone --depth 1 https://github.com/firefly-iii/firefly-iii.git
# copy the tree over this branch, keep this file, commit
```

Record the new upstream SHA at the top when you do.
