# URL-02 / URL-02.1 — manual production runbook

## Scope and review artifacts

### Current operator-reported state / URL-02.1

URL-02 was merged and deployed; the operator reports the production migration committed 140 rows with 0 blocked using approval `282b4a927af2a6ddd9b6191478fb16063e19a301ea7ff93c113ee10472c56123`.
This is operator-provided evidence, not a new production inspection by Codex. **Do not repeat steps 1–3 for this completed migration.** Use section 4 after separately reviewing/deploying URL-02.1. This change does not commit, push, deploy, access Hoster/Plesk, run migration or modify production.

### Original URL-02 preparation record (historical)

- Base: `6cb8e3fe790c2bfdb189fa7d8ba7f1590f774f61`.
- Branch: `codex/autohimiki-url-migration`; no commit, push, PR or deploy by Codex.
- `URL02_PUBLIC_MANIFEST.json` / `.csv`: all 140 public `onec-*` candidates, fetched with HTTP GET, including SKU, Product JSON-LD name, old/new URL, canonical and collision status. The JSON also inventories all 108 readable slugs to preserve.
- Public proposals have **not** been checked against inactive products or private redirect history. No production DB connection was made. Never treat this file as execution approval or a rollback receipt.
- The authoritative command examines **all current products (including inactive)** and **both ends of all redirects (including inactive)**. It reports every active `onec-*` candidate and blocks malformed UUIDs, custom canonicals and history conflicts.
- Full public inventory still measured 248 products / 140 onec / 108 readable. All 140 old URLs returned 200 with self-canonical. No public proposed-target collisions or custom public canonicals were found. Stored canonical values remain unknown.

## Implementation / invariants

`ProductSlugAllocator` uses the installed Laravel `Str::slug`, lowercase, strict ASCII route-compatible segments, and a 200-character limit including suffix. Empty/untransliteratable names fail. Stable bytewise SKU order determines `name`, `name-1`, `name-2` allocation. It never emits another `onec-*` URL. Historical addresses are reserved, even inactive redirects and absolute URLs.

`products:migrate-onec-slugs --dry-run` issues SELECTs only. It does not change database or caches. It emits a SHA-256 approval bound to the plan, canonical origin and complete source products/redirects. An unrelated product update also makes approval stale; rerun and review. Dry-run cannot prove production HTTP results in advance.

Execution requires that exact `--approve` hash. It checks MySQL InnoDB and unique indexes, locks source rows, rebuilds the plan and rechecks approval. The **whole batch is one transaction**: a single blocked product or write failure prevents every migration in that batch. No partial successes are hidden. Changes bypass ProductObserver and write only `products.slug`, `products.canonical_url`, and required redirect rows; even product timestamps stay unchanged.

Null/empty canonical remains unchanged. An exact old self-canonical (absolute on APP_URL or relative old path) becomes the absolute new URL. Any other stored canonical blocks execution for review. No automatic destruction of deliberate canonical overrides.

No outgoing redirect from a current old URL is silently overwritten. Existing histories provably terminating at that product are flattened directly to the new URL; a history shadowing another current product is rejected. Unrelated redirects remain unchanged. Every migration source gets one 301 to its new URL.

After commit, existing `CacheService::forgetProducts()` and `forgetRedirects()` invalidate homepage/product sitemap/index/redirect caches. A cache error is reported as **committed DB + failed cache**, with a nonzero command exit, not as a rolled-back DB. `products:url-cache-clear` is the retry command.

Product model saves now wrap observer history and product persistence in one transaction. Slug/history validation rejects historical reuse, A→B→A and failed-update orphan redirects. Admin bulk publication now saves models rather than bypassing observers. An inactive technical draft receives a readable slug on activation. New active models using the DB default active flag are also covered. Later name changes retain the published slug. Full content import uses the shared allocator for new products; existing product names can still update without changing their slug. Category logic/routes/schema are untouched.

No schema migration is needed. Existing tables/unique indexes are used. As with Laravel observers generally, arbitrary raw SQL/query-builder activation bypasses model safeguards; the existing admin bulk activation bypass has been removed. Commercial import still uses raw writes ONLY to create inactive drafts/update commercial fields, as before.

## 1C and Kaspi

1C identity is the exact SKU; ID/SKU/price/quantity/in_stock/category/content/images/attributes are untouched by the URL migration. A regression uses the actual commercial updater to create an inactive draft, publish it, then update the same product by SKU without changing its slug/name.

Kaspi candidate URLs are generated from the current DB slug. The bridge fetches candidates fresh per invocation; batch candidates and prepared `storefront_url` payloads live in process memory. No persistent reusable storefront-URL cache/table was found in the production integration. Old console/debug exports, externally saved payloads, open resolver sessions and in-flight batches may contain stale URLs.

**Minimum safe handling:** finish/stop active Kaspi enrichment before migration, discard prepared payloads/candidate snapshots and restart the existing commands afterward to fetch fresh candidates. Existing strict `storefront_mismatch` rejection is preserved; an old payload is never silently rebound. Merchant/city/SKU/widget/API/content logic and services are unchanged. New URL candidate + widget + import and rejection of stale payload are regression-tested. Do not run Windows/Chromium-only Kaspi resolver on Hoster.

## Manual Hoster.kz/Plesk execution

These are instructions for the operator **after review, a separate commit/merge approval, and deployment from main**. URL-02 merged as `16fb8a7ade6dce12484ab283f37eaddc9d8678eb`. The operator reports its production migration completed with count=140, blocked=0, status=committed. URL-02.1 remains a separate uncommitted verification improvement; deploy only after its own review and approval. Do not rerun the completed migration.

The repository's `artisan83` wrapper uses `/opt/alt/php83/usr/bin/php`. `config/onec.php` identifies the Hoster tree `/var/www/vhosts/autohimiki.kz/httpdocs`. Confirm this is the deployed Laravel root containing `artisan`, `artisan83`, `.env`, and `vendor`; if Plesk uses a different root, use its actual Laravel root.

### 1. Pause writers and prepare backup

1. Stop/finish current manual imports and local Kaspi enrichment. Pause the site's actual 1C scheduled task / scheduler and queue workers using the operator's existing Plesk controls. Confirm no import is running. `artisan down` alone does not stop background jobs or console imports.
2. At the deployed Laravel root:

```bash
bash artisan83 down
```

3. Deploy the reviewed **main** through the existing manual Plesk workflow. Do not execute URL migration as a deployment hook. No new schema migration or Vite build is required for URL-02.
4. Confirm the deployed commit:

```bash
git branch --show-current
git rev-parse HEAD
bash artisan83 help products:migrate-onec-slugs
bash artisan83 help products:verify-url-migration
bash artisan83 help products:rollback-onec-slugs
```

Compare HEAD with the recorded reviewed merge SHA. If it differs, stop. If OPcache is not configured to detect new PHP files, refresh the site's PHP worker through the normal Plesk deployment process.

5. Export the **actual database assigned to this site** using Plesk Databases → Export Dump / existing verified backup workflow. Record the backup name, completion and size and retain it outside the public document root. Credentials and the real DB name are intentionally not guessed here. Do not rely on the existence of an old scheduled backup.

### 2. Authoritative dry-run

Use a new report directory for each attempt; keep execution receipts permanently. The following example is the first URL-02 attempt only. Stop if it already exists; do not overwrite a receipt.

```bash
mkdir -m 700 storage/app/private/url02-run-01
bash artisan83 products:migrate-onec-slugs --dry-run --expect=140 --json > storage/app/private/url02-run-01/dry-run.json
```

Require exit status 0. Inspect the complete JSON, not just the last line:

- `base_url` exactly `https://autohimiki.kz`;
- `count=140`, `blocked=0`, all rows eligible;
- old slug/SKU set equals the 140 public manifest rows;
- all target slugs/readable URLs reviewed;
- canonical status automatic or old self-canonical updated;
- history flattening and any deterministic suffixes understood;
- no changes to existing readable products or drafts.

If count or identity set differs, **stop and reconcile** with a fresh sitemap/DB inventory. Do not simply change `--expect` to make execution succeed. Hidden draft/history reservations can legitimately change proposed suffixes; review the authoritative DB proposal rather than blindly copying the public manifest.

Identity/count check and approval extraction (only after human review of that JSON):

```bash
URL02_APPROVAL=$(/opt/alt/php83/usr/bin/php -r '$p=json_decode(file_get_contents("storage/app/private/url02-run-01/dry-run.json"),true,512,JSON_THROW_ON_ERROR); $m=json_decode(file_get_contents("docs/URL02_PUBLIC_MANIFEST.json"),true,512,JSON_THROW_ON_ERROR); $a=array_column($p["rows"],"sku","old_slug"); $b=array_column($m["rows"],"sku","old_slug"); ksort($a); ksort($b); if($p["count"]!==140 || $p["blocked"]!==0 || $p["base_url"]!=="https://autohimiki.kz" || $a!==$b) exit(1); echo $p["approval"];')
```

Require exit status 0 and a nonempty 64-character approval. Any product/redirect change after dry-run invalidates it; rerun dry-run and review.

### 3. Execute once and keep receipt

```bash
bash artisan83 products:migrate-onec-slugs --expect=140 --approve="$URL02_APPROVAL" --json > storage/app/private/url02-run-01/receipt.json
```

Require exit status 0, `status=committed`, `count=140`, `blocked=0`, `cache_status=cleared`. Download/preserve `receipt.json`: it contains mappings, protected-field hashes and rollback evidence. It is private operational data.

If `status=committed` but caches failed, keep traffic closed and run:

```bash
bash artisan83 products:url-cache-clear
```

Do not rerun execution over the same receipt filename. If the process was interrupted or output truncated, inspect DB state and the backup before deciding what to do; a lost console response is not proof of rollback. A successful re-run after migration plans zero candidates and leaves slugs/history unchanged, but it is not a replacement for the original receipt.

### 4. Verify current state (URL-02.1)

After deploying the separately reviewed URL-02.1 change, use Laravel Toolkit:

```bash
php artisan products:verify-url-migration --json
```

In a Toolkit form that supplies `php artisan` itself, enter `products:verify-url-migration --json`. No receipt file, shell redirection, migration rerun or manual JSON copying is needed. Without `--json` the command also works and retains JSON output for compatibility.

Require exit code 0, `status=ok`, `mode=current_state`, `expected_redirects=140`, `redirects_checked=140` for the original set, and every error counter plus `failed` equal to zero. Additional onec history rows are also checked, so `redirects_checked` can grow after later publications. Exit 1 means failed checks or an incomplete check; with `--json` even input/configuration errors are JSON (`status=error`).

The mode performs SELECTs only, does not save models, dispatch the HTTP kernel, call cache APIs, invalidate/warm caches or make external HTTP requests. It uses the committed public manifest's **old paths and exact SKUs** to detect missing/inactive redirects and wrong-product targets. Proposed new slugs in that manifest are deliberately ignored: actual current DB slugs are authoritative. The manifest must match APP_URL and contain a valid nonempty identity set; absent/invalid evidence fails closed.

Checks include all active technical/invalid slugs, case-insensitive duplicate slugs (including inactive rows), each expected old redirect, active readable target of the expected SKU, direct target without shadowing, chains/self redirects/loops, the same `seoCanonical()` method used by the product template, and XML from the same product sitemap builder used by the public controller. Old onec URLs must be absent; each target must occur once. Active redirect records represent 301 under the existing HandleRedirects middleware.

**Limits:** this verifies current DB/application state, not HTTP 301/200 responses, rendered head/noindex, stale server/CDN caches or web-server overrides. Sitemap verification explicitly uses **uncached application XML** and leaves the published cache untouched. A valid target represents the active Product selected by the product controller; rendering/availability requires HTTP checks. SKU is compared with the pre-migration public manifest, but original DB ID, historical SKU ownership and protected field fingerprints were only emitted in the execution receipt. Without that receipt these cannot be proven. Redirect rows store no product ID, migration batch or before/after hash. Do not reconstruct a receipt from current data or call the public proposal an execution receipt.

Pause concurrent catalogue edits/imports when interpreting the report: this is a read-only observation, not a locked multi-table snapshot. If checks fail, inspect the listed failures; the verifier never repairs data or reruns migration.

#### Optional stronger receipt / HTTP verification

If the original execution receipt was retained, the existing exact before/after verification is still available:

```bash
php artisan products:verify-url-migration storage/app/private/url02-run-01/receipt.json --json
```

Receipt mode checks `status=committed`, matching origin and row count, old/new URL and slug pairs, product ID and protected-field hash. It preserves the original bounded sequential HTTP checks: one sitemap request and two requests per product (281 for 140), without following redirects. Require `status=ok`, `mode=receipt`, `checked=140`, `failed=0`. It checks old 301 directly to new, new 200, a single self-canonical, no noindex, public sitemap inclusion/exclusion and exact non-URL fields including timestamps. Ordinary later price/stock/content updates can therefore fail its historical fingerprint check; investigate rather than undo legitimate changes.

Run HTTP receipt verification after leaving maintenance mode and with writers paused. If the original stdout receipt was lost, current-state verification remains available, but exact forensic verification and controlled receipt-based rollback do not. Never invent the missing receipt.

Manually check homepage, one category, search, related cards, Mitsuji's unchanged readable URL, one migrated product, Kaspi widget SKU/merchant/city and WhatsApp link destination. No need to submit an order or send a message. Category URLs must remain unchanged.

Resume the actual paused scheduled tasks/workers. Start a **fresh** local Kaspi run/candidate retrieval; do not reuse prepared old payloads. Monitor Plesk/web access logs for 404 and redirect errors, and Search Console URL Inspection/sitemap processing. Keep old 301 URLs indefinitely; never reassign historical paths.

## Controlled rollback

Rollback uses the original successful `receipt.json`; it does not restore prices, stocks, content or an entire database. It refuses to proceed if product URL/canonical state or redirect history changed since migration, or a migrated product is no longer active. Commercial/content changes are preserved; changes between rollback dry-run and execute invalidate that approval.

1. Pause writers again and enter maintenance with `bash artisan83 down`.
2. Generate and review rollback dry-run:

```bash
bash artisan83 products:rollback-onec-slugs storage/app/private/url02-run-01/receipt.json --dry-run > storage/app/private/url02-run-01/rollback-plan.json
URL02_ROLLBACK_APPROVAL=$(/opt/alt/php83/usr/bin/php -r '$p=json_decode(file_get_contents("storage/app/private/url02-run-01/rollback-plan.json"),true,512,JSON_THROW_ON_ERROR); if($p["status"]!=="rollback_dry_run_no_changes" || $p["count"]!==140) exit(1); echo $p["approval"];')
```

Require zero exit codes and review every `restore_slug`/canonical. Then:

```bash
bash artisan83 products:rollback-onec-slugs storage/app/private/url02-run-01/receipt.json --approve="$URL02_ROLLBACK_APPROVAL" > storage/app/private/url02-run-01/rollback-receipt.json
```

3. Require `rollback_committed` and cleared caches (or run `products:url-cache-clear`). Prior onec URLs now return 200/self-canonical; new readable URLs are retained as direct **301 → prior onec URL**. Earlier histories are flattened to that same restored URL, avoiding new 404s/chains/loops.
4. Run `bash artisan83 up`; verify old/new samples and current sitemap in the reversed direction. The forward verifier intentionally does not accept a rollback receipt. Resume writers only after checks. Restart Kaspi with fresh candidates again.
5. If rollback refuses changed URL history, **stop**; preserve both receipts and request review of the intervening changes. Do not force a historical slug through the admin observer and do not run an ad hoc inverse UPDATE. A full database backup is a disaster-recovery fallback requiring reconciliation of subsequent data, not the routine rollback method.
6. Keep URL-02 code during rollback. Rolling back code alone does not undo DB URL changes. Do not automatically rerun forward migration afterward: the newly exposed readable addresses are now historical/reserved and require a reviewed remigration plan.

## Limits / acceptance gate

- Public source data are verified; private draft counts, production redirects, stored canonical overrides and real production MySQL behavior have not been inspected/executed by Codex.
- Local tests use proven SQLite `:memory:` only. MySQL-specific engine/index guards are implemented but no production engine was contacted.
- No schema changes, category changes, frontend asset rebuild, 1C contract or Kaspi service changes.
- URL-02.1 is ready for code review; after its separately approved deploy, run the no-argument verifier. The operator reports the URL-02 migration already completed; do not repeat its dry-run/execution workflow to verify URL-02.1.

## URL-02.1 local verification (2026-09-16)

- `composer validate`: valid. `composer install --dry-run`: nothing to install, update or remove.
- `php artisan test`: **173 passed, 0 failed, 0 risky; 2359 assertions** (SQLite `:memory:`, array cache/session).
- Regressions cover no-argument/JSON commands, SELECT-only queries with cache calls forbidden and no HTTP traffic, all 140 expected mappings, missing/inactive/wrong redirects, chains/self/loops, duplicate and invalid slugs, missing SKU/inactive product, canonical and sitemap errors, actual slugs differing from proposals, and both receipt command forms.
- `git diff --check`: passed. No dependency/frontend changes, commit, push, PR, merge, deploy or production access.

## Original URL-02 local verification (historical, before commit/merge)

- Full PHP suite: **157 passed, 0 failed, 0 risky; 2245 assertions**, PHP 8.3.30 / PHPUnit 11.5.56. Baseline was 123 / 1301.
- Tests explicitly guard SQLite `:memory:` before creating migration fixtures. No production or persistent local database write mode was used.
- All 140 public manifest proposals were loaded into an isolated synthetic database with all 108 preserved readable slugs. DB dry-run: 140 eligible, 0 blocked. Execution verified every old 301/new 200/canonical pair; all 108 readable records and categories remained unchanged. These are test fixtures, not a copy of production DB or evidence about hidden production history.
- Tests cover SELECT-only dry-run, stable suffixes/current draft reservations, active/inactive/absolute history reservations, normalized incoming history targets, custom canonical rejection, stale approvals/count mismatch, all-or-nothing DB failure, model update failure rollback, future publication (including admin bulk), real commercial updater identity, full import name stability, Kaspi current/stale payloads, verification failures and rollback safety.
- `composer validate`: valid.
- `composer install --dry-run`: nothing to install, update or remove; lock/platform checks passed.
- Pint on new URL services/commands/tests/script and ProductObserver: passed.
- `git diff --check`: passed. No frontend files changed and no Vite assets rebuilt.
- HEAD and local origin/main remain `6cb8e3fe790c2bfdb189fa7d8ba7f1590f774f61`; no commit/push/PR/merge/deploy. The three pre-existing untracked audit documents were not edited.
