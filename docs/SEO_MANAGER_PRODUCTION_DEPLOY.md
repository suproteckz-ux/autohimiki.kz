# SEO Manager production deployment

This deployment adds a protected API for SEO drafts. It does not import a local
database, seed data, publish pages, or write products, prices, stock, orders,
Kaspi, Paloma, or Ozon records.

## One-time Plesk setting

In Plesk, add these application environment variables before caching Laravel
configuration:

```dotenv
SEO_MANAGER_WRITE_TOKEN_SHA256=<SHA-256 from the local protected token store>
SEO_MANAGER_READ_TOKEN_SHA256=
SEO_MANAGER_ALLOW_LOCAL_HTTP=false
```

The server receives only the SHA-256 hash. Keep the plaintext token only in the
Windows DPAPI store used by AUTOHIMIKI AI SEO MANAGER.

## Manual deployment commands

Export a database backup with Plesk before applying the migration. Then open
Plesk SSH and run the following commands from the real Laravel document root:

```bash
cd /var/www/vhosts/autohimiki.kz/httpdocs
test -f artisan && test -f artisan83 && test -f .env
git status --short
git branch --show-current
git fetch origin
git checkout main
git pull --ff-only origin main
git rev-parse HEAD
bash artisan83 down
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
bash artisan83 migrate:status
bash artisan83 migrate --force
bash artisan83 optimize:clear
bash artisan83 optimize
bash artisan83 filament:cache-components
bash artisan83 route:list --path=api/seo-manager
bash artisan83 up
curl --fail --silent --show-error https://autohimiki.kz/up
```

Stop if `git status --short` shows unknown server changes, if `git pull
--ff-only` fails, or if the checked-out commit differs from the reviewed commit.
The migration only adds `filter_criteria` to `seo_pages` and creates four
`seo_manager_*` tables. Do not run `db:seed`, import an SQLite/MySQL dump, or
copy any local database file.

After the API route exists, verify the scoped token from the SEO Manager
workstation with an authenticated `GET /api/seo-manager/v1/status`. Only after
that read-only check succeeds should production writes be enabled locally and
one inactive draft be sent. Publication remains a manual action in Filament.
