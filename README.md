# Astatine SharePoint portal

A small CakePHP 5 app that signs members in with Microsoft Entra ID and shows
the files of the Teams channels they belong to (General excluded), read live
from Microsoft Graph.

## Run locally

```sh
composer install
cp config/.env.example config/.env   # fill in the Entra values
php -S localhost:8765 -t webroot webroot/index.php
vendor/bin/phpunit --no-coverage
```

Requires PHP 8.2+ with the `intl` extension.

## Run with Docker

Every push to `main` runs the tests and publishes an image to
`ghcr.io/<owner>/<repo>:latest`.

```sh
cp .env.docker.example .env.docker     # fill in the values
echo <github token with read:packages> | docker login ghcr.io -u <user> --password-stdin
docker compose up -d                   # serves on http://localhost:8080
docker compose pull && docker compose up -d   # later: update to the newest image
```

`docker compose up --build` builds the image from this checkout instead of pulling it.

Put it behind an HTTPS reverse proxy: the session cookie is marked Secure and
Entra only accepts `https://` redirect URIs (besides `http://localhost`).

| Variable | Purpose |
|---|---|
| `SECURITY_SALT` | Required. Long random string. |
| `APP_FULL_BASE_URL` | Required in production (host-header protection). |
| `ENTRA_CLIENT_ID` / `ENTRA_CLIENT_SECRET` / `ENTRA_TENANT_ID` / `ENTRA_REDIRECT_URI` | Entra app registration. |
| `REQUEST_ACCESS_URL` | Fallback target of the "Request access" button (a `mailto:` link). |
| `REQUEST_ACCESS_SITE` / `REQUEST_ACCESS_LIST` | SharePoint site and list that receive access requests. Empty site: "Request access" falls back to `REQUEST_ACCESS_URL`. |
| `REQUEST_ACCESS_CHANNEL_IDS` | Comma-separated channel IDs that may be requested. Empty offers none. |
| `REQUEST_ACCESS_TEAM_IDS` | Optional. Restrict the request form to these teams (default: the user's own teams). |
| `WEBAPPS_URL` | Target of the "Back to webapps" button. |
| `DEBUG` | `true` only for local development. |

## Deploying to webapps.astatine.utwente.nl

**Entra app registration** (single tenant, platform "Web", redirect URI exactly `https://webapps.astatine.utwente.nl/callback`):

| Type | Permission | Used for |
|---|---|---|
| Delegated | `User.Read` | Name and e-mail of the signed-in member |
| Delegated | `Team.ReadBasic.All`, `Channel.ReadBasic.All` | Listing the member's teams and channels |
| Delegated | `Files.Read.All` | Browsing channel files as the member (Teams membership decides what they see) |
| Application | `Channel.ReadBasic.All` | Listing channels for the access-request form |
| Application | `Sites.Selected` | Writing requests to one SharePoint list (grant it `write` on that site only) |

Both application permissions are only needed for the access-request form. Grant admin consent for all of them.

**Access-request form (optional).** Create a SharePoint list with the columns `Title` (e-mail), `Name`, `Committee` and `Note`, then set `REQUEST_ACCESS_SITE` and `REQUEST_ACCESS_LIST`. List channel IDs with Graph (`GET /teams/{team-id}/channels`) and put the ones members may request in `REQUEST_ACCESS_CHANNEL_IDS`; any channel not listed is never offered or accepted.

**Hosting requirements**
- Serve it over HTTPS (reverse proxy to port 80 of the container): the session cookie is `Secure`, and Entra only accepts `https://` redirect URIs.
- Serve it at the root of its own host name. The routes are not prefixed, so a sub-path such as `/portal/` is not supported without changes.
- Set `APP_FULL_BASE_URL` to the public address; requests with another `Host` header are rejected.
- `SECURITY_SALT` must be set and stay the same between deploys.
- Sessions are files inside the container. A new container signs everyone out; this is harmless. Logs go to the `portal-logs` volume.
- No database or mail server is needed.

**Troubleshooting.** A committee missing from the overview means the member is not in that private channel in Teams, or Graph refused its files. Check `logs/debug.log` for `Graph GET` and `Channel access probe` lines.
