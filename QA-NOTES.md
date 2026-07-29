# Deployment reference app — `koel` branch

**Unmodified upstream [koel/koel](https://github.com/koel/koel) v9.11.0**, vendored
at `81a3ca88d727bbe51ccb4054b2a2ff3a796e19a4` (shallow copy, upstream history not
carried). This file is the only addition — the application is untouched on
purpose.

The synthetic `laravel` branch is built to make the deployment features observable. This
branch does the opposite job: it is a real, popular Laravel application that knows
nothing about the deployment tool, so it answers "does this work on an app we did not design for
ourselves?"

## Why koel specifically

It exercises paths no other branch in this repo touches:

| Property | Value | Why it matters |
| --- | --- | --- |
| Package manager | **pnpm** (`pnpm@10.32.1`, `pnpm-lock.yaml`) | The only branch that deploys with pnpm. Every other branch is npm. |
| Build command | `pnpm run build` → `vp build && pnpm run build:sw` | Two chained builds, including a service worker |
| PHP extensions | `ext-gd`, `ext-exif`, `ext-SimpleXML`, `ext-fileinfo`, `ext-json` | Tests whether the deploy tool's PHP image actually ships them |
| Composer scripts | `post-autoload-dump`, `post-install-cmd`, `koel:init` | Real lifecycle hooks, not a bare skeleton |
| PHP | `>=8.3` | Deployable on the supported versions |
| Laravel | `^13.0` | Same major as the synthetic fixtures |

koel pins its package manager in `package.json`, and its `pnpm-lock.yaml` is
committed. A pnpm install that honours the lockfile fails when the lockfile and
`package.json` disagree, so it is worth confirming the install resolved from the
lockfile rather than falling back to an unpinned resolve — a fallback that always
fires means the lockfile path is never actually tested.

## Deploying it

Unlike the synthetic branches, koel is **not** a one-request acceptance check. It
needs a database and an init step.

1. app type **Laravel**, PHP **8.3** or **8.4**
2. Build command: `pnpm run build`, package manager **pnpm**
3. After the first deploy, `.env` needs a real `DB_*` block — Laravel sites are
   provisioned with one, so confirm it lands before running anything else
4. `php artisan koel:init --no-interaction` (creates schema and the admin user)

## Acceptance

- `GET /` returns **200** and serves koel's login page
- No 500s in `storage/logs/laravel.log`
- The pnpm install resolved from the committed lockfile, and `vp build` plus
  `build:sw` both produced output
- The Laravel settings page detects **no** Horizon, **no** Inertia, **no**
  Octane — koel uses none of them, so all three controls stay gated. That makes
  it a second, independent confirmation of the same gating `laravel-minimal`
  covers, on an app nobody tailored for the test.

## What this branch cannot do

No deploy marker and no `/health`. It is upstream code and should stay that way —
patching it would forfeit exactly the property that makes it useful. For
marker-based redeploy checks and breadcrumb-based scheduler and queue proofs, use
the `laravel` branch.

## Refreshing it

```bash
git clone --depth 1 https://github.com/koel/koel.git
# copy the tree over this branch, keep this file, commit
```

Record the new upstream SHA at the top when you do.
