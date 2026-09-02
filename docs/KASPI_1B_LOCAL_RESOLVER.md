# KASPI-1B — read-only candidates and local Windows widget resolver

Target: autohimiki.kz. Reference implementation: autohimiya-laravel (a different
project). Merchant remains Avtoximiya, city remains 750000000. KASPI-1A, Product,
1C, CSP and scheduling are unchanged. No migrations or Product/media writes.

## API contract

GET `/api/internal/kaspi-content/candidates` accepts `sku` (optional exact string,
max 255), `limit` (1–100, default 25), `cursor` (nonnegative last product ID).
Returns `{data: [{sku, name, storefront_url, has_images, has_main_image, gallery_count, has_description}],
next_cursor: integer|null}`. No prices, stock, content body or ledger IDs are exposed.
Cursor is a pagination marker, not a commercial ledger identifier.

Selection always requires an existing active product, nonblank exact SKU and a
routable nonblank slug. Without `sku`, main_image OR description must be empty after
trimming. With explicit `?sku=РТ-00000074` (also РТ-00001158), content completeness
is ignored: this is a diagnostic/manual override, even with populated description,
main_image and gallery. Active/existence/exact matching rules remain enforced.
`has_main_image` means the field is populated, NOT that the image renders or exists;
`has_images` is retained as the same legacy alias. `gallery_count` counts existing
product_images rows only (not unique photos and not main_image plus gallery).
No filesystem/HTTP image availability check or comparison with Kaspi gallery occurs.
Names are returned for display, never matching. Incoming filter trims surrounding
spaces; stored SKU is returned unchanged. PHP exact comparison rejects collation-
equivalent but different strings. Products are scanned in bounded 100-row chunks,
and selection stops at limit+1 eligible products; no entire-catalog materialization.

Bearer token comes from `KASPI_INTERNAL_API_TOKEN`; empty/wrong token gives 401.
Production HTTP gives 403. Secure-request detection uses Laravel's trusted-proxy
handling, never an untrusted raw X-Forwarded-Proto header. Existing proxy settings
must correctly reflect HTTPS after separately authorized deployment. Invalid query
returns JSON 422. Rate limit is 60 requests/minute per IP, 429 when exceeded.
Authenticated data is private/no-store. GET does not mutate Product or media;
normal framework throttling may update its cache.

## Local command and environment

`kaspi:resolve-production --sku=... --limit=1 --cursor=0 --dry-run --debug`

All invocations are read-only, including without --dry-run. Default limit is 1,
maximum 100. The optional SKU is a single exact string. The command prints one
JSON result per candidate with sku, storefront_url, kaspi_url, status, diagnostics.
No eligible products prints an explanation. Any failed resolution returns nonzero;
CAPTCHA stops the batch immediately. There is no database history or POST endpoint.

The command, resolver and process runner enforce Windows CLI + APP_ENV=local +
KASPI_LOCAL_BROWSER_ENABLED=true. Node also refuses non-Windows/headless launch.
The dependency is pinned to Playwright 1.56.0, adapting the reference's 1.56 series.
No npm lifecycle script installs Chromium. Browser binaries are local-only.

The PHP client uses HTTPS, Authorization Bearer, connect timeout 5s, request timeout
30s, and does not follow redirects. It validates response shape, cursor progress,
exact requested SKU, duplicate rows and storefront origin. No blind retries.
Node uses Symfony Process argument arrays, configurable executable, 100s timeout.
Child environment denies inherited secrets, including Laravel .env/API/DB values,
and retains only basic Windows runtime/path/temp/profile/browser-path variables.
No token is passed to Node, Chromium, URLs or console diagnostics.

## Resolver sequence

1. Launch visible Chromium (`headless: false`, launch timeout 15s).
2. Open the API-provided same-origin storefront URL (navigation timeout 30s).
3. Reject unsuccessful/redirected storefront, CAPTCHA or missing widget.
4. Match exact data-merchant-sku, configured merchant and city; reject multiple matches.
5. Wait for the official widget iframe and verify its origin/path and merchant parameters.
6. Inspect only that iframe's anchors. No title search, Google or global page link scanning.
7. If no anchor, click its unique button and inspect popup/navigation URLs.
8. Reject CAPTCHA, ambiguity, missing/unloaded iframe, timeout or invalid URL.
9. Accept only HTTPS kaspi.kz/subdomain /shop/p/<slug>-<numeric-id>/ URLs without
   credentials/nondefault ports; discard query/hash in the reported canonical URL.

The browser operation has an 80s overall budget after launch; individual waits
are bounded. Chromium closes on success or failure. The resolver never collects
product HTML, description, attributes or photos. It does not bypass CAPTCHA.
Diagnostics are bounded status codes, process exit code and stderr byte count;
untrusted raw stdout/stderr and API responses are not echoed.

Adapted: CandidateService/Client, InternalApiAuthenticator, LocalNodeProcessRunner,
LocalUrlResolver and the reference widget/iframe/popup mechanism. Not copied:
ProductSlugger/name guesses, normalized/Paloma SKU matching, publisher, import API,
KaspiProductionPush, parser, collector, enrichment queues/admin, MarketRadar.

## First live smoke test — pending, NOT executed

This task does not deploy the new endpoint. First obtain separate deployment
approval and confirm GET endpoint availability on production. A live test cannot
pass while that endpoint is absent. Production requires only the PHP endpoint and
its configured secret; it never needs Node/Playwright/Chromium execution.

Local real API token was not available during implementation. Do not invent one or
commit it. After authorized endpoint installation, set the SAME secret locally and
on the API host through the normal secret configuration procedure. Do not print it.
Local `.env` requirements:

```dotenv
APP_ENV=local
KASPI_INTERNAL_API_TOKEN=<real secret, never commit>
KASPI_PRODUCTION_BASE_URL=https://autohimiki.kz
KASPI_LOCAL_BROWSER_ENABLED=true
KASPI_NODE_BINARY=node
KASPI_MERCHANT_ID=Avtoximiya
KASPI_CITY_ID=750000000
```

Only on the local Windows PC, from the target repository:

```powershell
npm ci --ignore-scripts
npx playwright install chromium
# Local config cache only, after local .env is configured:
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan config:clear
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan kaspi:resolve-production --sku=РТ-00001158 --limit=1 --debug
```

Run that single smoke test while watching the visible Chromium window. Expected:
storefront opens, widget resolves an actual HTTPS Kaspi product URL and command
prints status=resolved. No automatic retry after CAPTCHA/failure. If candidates
returns empty for an explicit SKU, inspect exact matching, active state and slug; content completeness no longer excludes it.
Live acceptance (production endpoint, visible window, real URL) remains pending.

## Offline verification

```powershell
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' vendor\bin\phpunit --filter 'KaspiCandidatesApiTest|KaspiCandidateClientTest|KaspiLocalResolverTest' --no-coverage
node --test scripts/kaspi-widget-resolver.test.mjs
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' vendor\bin\phpunit --no-coverage
```

HTTP/process fakes isolate automatic tests from production and Kaspi; Node tests
exercise URL selection without importing Playwright or launching a browser.

## Verification result and changed-file manifest

Automatic checks: new KASPI-1B PHP tests **19 / 185 assertions**; Node tests **5 passed**.
Full PHP suite **67 / 534 assertions**, no failures, including KASPI-1A, 1C and
storefront tests. One pre-existing risky output-buffer warning remains in
PublicPagesSmokeTest::test_seo_page_and_filter_pages_load (line 270).
Pint and git diff --check passed. No live browser smoke was run.

Paths below are relative to `C:\Users\anton\OneDrive\Documents\au\autohimiki.kz`:

Modified:
- `.env.example`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `config/services.php`
- `package.json`

Created:
- `app/Console/Commands/KaspiResolveProductionCommand.php`
- `app/Http/Controllers/InternalKaspiContentCandidatesController.php`
- `app/Services/Kaspi/KaspiInternalApiAuthenticator.php`
- `app/Services/Kaspi/KaspiLocalBrowserGuard.php`
- `app/Services/Kaspi/KaspiLocalNodeProcessRunner.php`
- `app/Services/Kaspi/KaspiLocalUrlResolver.php`
- `app/Services/Kaspi/KaspiProductionCandidateClient.php`
- `app/Services/Kaspi/KaspiProductionCandidateService.php`
- `app/Services/Kaspi/KaspiUrlRules.php`
- `routes/api.php`
- `scripts/kaspi-widget-resolver.mjs`
- `scripts/kaspi-widget-resolver.test.mjs`
- `tests/Feature/KaspiCandidateClientTest.php`
- `tests/Feature/KaspiCandidatesApiTest.php`
- `tests/Feature/KaspiLocalResolverTest.php`
- `package-lock.json`
- `docs/KASPI_1B_LOCAL_RESOLVER.md`
