# Astatine SharePoint portal

A small CakePHP 5 app that signs members in with Microsoft Entra ID and shows
the files of the Teams channels they belong to (General excluded), read live
from Microsoft Graph. See [PROJECT_PLAN.md](PROJECT_PLAN.md) for the original brief.

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
docker run -p 8080:80 \
  -e SECURITY_SALT=<long random string> \
  -e APP_FULL_BASE_URL=https://webapps.astatine.utwente.nl \
  -e ENTRA_CLIENT_ID=... -e ENTRA_CLIENT_SECRET=... -e ENTRA_TENANT_ID=... \
  -e ENTRA_REDIRECT_URI=https://webapps.astatine.utwente.nl/callback \
  ghcr.io/<owner>/<repo>:latest
```

Put it behind an HTTPS reverse proxy: the session cookie is marked Secure and
Entra only accepts `https://` redirect URIs (besides `http://localhost`).

| Variable | Purpose |
|---|---|
| `SECURITY_SALT` | Required. Long random string. |
| `APP_FULL_BASE_URL` | Required in production (host-header protection). |
| `ENTRA_CLIENT_ID` / `ENTRA_CLIENT_SECRET` / `ENTRA_TENANT_ID` / `ENTRA_REDIRECT_URI` | Entra app registration. |
| `REQUEST_ACCESS_URL` | Target of the "Request access" button. |
| `WEBAPPS_URL` | Target of the "Back to webapps" button. |
| `DEBUG` | `true` only for local development. |
