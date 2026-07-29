# Deployment reference app — `laravel-minimal` branch

A stock Laravel 12 application with **none** of the optional packages a deploy tool
detects. It is the negative control for the `laravel` branch, which has all of
them. Sibling branches: `laravel`, `php`, `symfony`, `bedrock`.

Keep this branch boring. Its entire value is what it does not contain — if
Horizon, Inertia or an SSR bundle is ever added here, the gating tests below
stop testing anything.

## Acceptance

- `GET /` returns **200** with "Deploy OK — Laravel"
- `GET /health` returns JSON `{ "ok": true, ... }`

No Vite build and no database needed, so the homepage works after
`composer install` + `php artisan key:generate` alone.

## What must NOT be detected

Laravel feature detection reads `composer.json` and looks for
`bootstrap/ssr/ssr.js`, so on this branch every flag it can report is false:

| Detected | Value here | Expected UI |
| --- | --- | --- |
| `horizon` | false | Queue mode offers **Off** and **Queue worker** only — no Horizon option |
| `inertia` | false | SSR card shows the install prompt for `inertiajs/inertia-laravel`, with no toggle |
| `ssr_ready` | false | No SSR toggle, and no SSR process started for the site |
| `octane` | false | No Octane notice |

The scheduler card is **not** gated on detection and should still appear.

A site on this branch must also never end up running a Horizon or SSR process,
since neither package is installed for it to run.

## Pairing with `laravel`

Run the same case on both branches:

- `laravel-minimal` → control hidden
- `laravel` → control visible and functional

A control hidden on both proves nothing, and one visible on both means
detection is not actually reading `composer.json`. Testing a single branch
cannot distinguish either failure from success.
