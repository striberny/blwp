# BLWP — Buli Widgets Data Backend

Standalone PHP service that fetches football data from [api-sports.io](https://api-sports.io),
normalises it, and publishes it as static JSON for the **Buli Widgets** WordPress plugin.

This is a **separate application** from the plugin. They never share code, dependencies or
file paths — the only connection is HTTP:

| Direction     | What happens                                                 |
| ------------- | ------------------------------------------------------------ |
| Plugin → here | Registers/updates its site config, triggers manual refreshes |
| Here → plugin | Publishes `data/*.json` files that the widget JS fetches     |
| Plugin → here | Loads team/league logos through the `v1/img.php` proxy       |

---

## What it does

- **Fetches** fixtures, results and league standings from api-sports.io
- **Normalises** them: German round names, corrected team names, trimmed payloads
- **Classifies** each game as `live`, `fixtures` or `results`
- **Calculates** the Bundesliga table locally (so it can include in-play scores)
- **Publishes** the result as JSON files that any web server can serve statically
- **Proxies** CDN images, resizing and converting them to WebP on the way through

There is no database. All state lives in JSON files on disk.

---

## Repository layout (development)

```
blwp/
├── api/                          ← WEB ROOT in development (https://blwp.test/api/)
│   ├── .htaccess                 dev-host CORS origin (Apache only — ignored by nginx)
│   ├── cache/img/                generated WebP cache (gitignored)
│   ├── data/                     ← the public, statically-served output
│   │   ├── .htaccess             CORS + 60s cache under Apache (dev only)
│   │   ├── {domain}.json         per-site fixtures / results / live
│   │   └── standings.json        shared Bundesliga table
│   └── v1/                       HTTP endpoints
│       ├── register.php          site registration (plugin → here)
│       ├── fetch.php             manual refresh (plugin → here)
│       ├── assets.php            lists all logos a site needs
│       └── img.php               image proxy (resize + WebP + disk cache)
└── backend/                      ← library/CLI code, never web-served
    ├── bootstrap.php             environment detection + path constants
    ├── config/
    │   ├── api-config.php        limits, intervals, wiring
    │   ├── secrets.php           api_key, shared_secret, Telegram  ⚠ GITIGNORED
    │   ├── secrets.example.php   template — copy to secrets.php
    │   ├── site-mapping.json     per-site config + tokens      ⚠ GITIGNORED
    │   ├── site-mapping.example.json    template
    │   ├── cron-state.json       runtime state + standings cache
    │   └── alerts.json            alert throttle state          ⚠ GITIGNORED
    ├── cron/
    │   ├── fetch_all.php         CLI entry point (the scheduled job)
    │   └── check_health.php      exit 0 when healthy, 1 when not
    ├── inc/
    │   ├── APIFootball.php       api-sports.io client
    │   └── ClubConfig.php        unused DTO
    ├── lib/
    │   ├── cors.php              allowed-origin list
    │   ├── logging.php           blwp_log() / blwp_log_verbose() + rotation
    │   ├── notify.php            blwp_notify() → Telegram, throttled
    │   ├── utils.php             domain logic: standings, names, rounds
    │   └── fetch-functions.php   fetch_site_data / update_global_standings / categorizeGames
    └── logs/                     api.log, fetch-debug.log
```

> **Everything under `backend/` is private.** It contains the API key, all site tokens
> and the logs. `backend/.htaccess` denies direct web access so this holds true in
> development too — see [operations.md](docs/operations.md#security).
>
> ⚠ **`.htaccess` files are Apache-only.** Production runs **nginx**, which ignores them
> completely. There, `/home/deploy/blwp/` is protected by sitting outside the document root,
> and the CORS/cache headers on `api/data/*.json` must come from the nginx vhost — see
> [static JSON headers](docs/operations.md#static-json-headers).

---

## Quick start (development)

Assuming Laragon with the web root pointed at this folder and the host `blwp.test`.

**First create the secrets file.** It holds live credentials and is gitignored, so a fresh
clone does not have one. Without it every entry point fails immediately with a clear error:

```powershell
Copy-Item backend\config\secrets.example.php backend\config\secrets.php
# then fill in api_key and shared_secret
```

```powershell
# Run the scheduled job once, by hand
php backend/cron/fetch_all.php

# Trigger a refresh for one site over HTTP (token from config/site-mapping.json)
#   https://blwp.test/api/v1/fetch.php?domain=testwp.test&token=<api_token>

# Exercise the image proxy
#   https://blwp.test/api/v1/img.php?url=<urlencoded media.api-sports.io URL>&s=20
```

Published output:

```powershell
# https://blwp.test/api/data/testwp.test.json
# https://blwp.test/api/data/standings.json
```

---

## The contract with the WordPress plugin

Two things must stay in sync between the repos:

1. **`site-mapping.json`** — this service's record of which sites may be served.
   Written by `v1/register.php` on the plugin's request.
2. **HTTP shapes** — request parameters and JSON field names consumed by
   `assets/js/main.js` in the plugin repo.

Both are specified in [docs/reference.md](docs/reference.md). Treat that file as the
interface contract: changing a field name here breaks the plugin silently.

---

## Deployment

Production does **not** mirror the development folder structure. The two subtrees are
deployed to different places:

| Development                            | Production                                                             |
| -------------------------------------- | ---------------------------------------------------------------------- |
| `blwp/api/` → `https://blwp.test/api/` | contents → `/var/www/api.fcbinside.de/htdocs/`                         |
| `blwp/backend/`                        | contents → `/home/deploy/blwp/` (**flattened**, no `backend/` segment) |

Full instructions in [docs/operations.md](docs/operations.md).

---

## Documentation

| Document                                     | Contents                                                                          |
| -------------------------------------------- | --------------------------------------------------------------------------------- |
| [docs/architecture.md](docs/architecture.md) | How the pieces fit together, request flows, design decisions and their rationale  |
| [docs/reference.md](docs/reference.md)       | HTTP endpoint reference, JSON file schemas, config keys, status/round/name tables |
| [docs/operations.md](docs/operations.md)     | Cron, deployment, logs, cache, quota, troubleshooting, housekeeping               |

---

## Known issues

See [Known issues & debt](docs/architecture.md#known-issues--debt) in the architecture
document for the current list, including tracked secrets, hardcoded paths and dead code.
