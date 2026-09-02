# autohimiki.kz — Phase A: 1C commercial sync

Branch: `feat/onec-commercial-sync`, based on `origin/main` at `5d1e586`.
Implementation commit: `6e1f6e9` (runbook is a separate documentation commit).
Only Phase A is implemented. No deployment was performed. Kaspi implementation
and MarketRadar runtime changes are outside this PR.

## Ownership

1C owns `products.price`, `products.quantity`, `products.in_stock`. Kaspi is the
future external source for photos, description and buy widget. MarketRadar/Satu
are deprecated external sources; existing local media, including `catalog/satu`,
are preserved. No storage links, public media, product schemas from the reference
project, or Kaspi files were changed. The full content importer can no longer
overwrite the three commercial fields; new content-only products start at zero
price/stock and unavailable until 1C supplies them.

The server snapshot has a ProductResource with editable price/stock that is
absent from this repository. Disable those production-only form/inline writers
before enabling automation. This PR cannot claim to have repaired absent source
files. Inspect the deployed resource and any maintenance scripts first.

## Reused implementation and repairs

`ImportFileParser` still owns reading; commercial XLSX uses its new strict mode
and the already installed OpenSpout dependency. `ColumnMapper` keeps the raw
commercial values until `PriceStockUpdater` validates each field independently.
`ImportWizardPage` and `onec:sync` both call `CommercialImportRunner` and the same
prices_only updater. Full import is not used by automatic 1C sync.

`ProcessImportJob` now runs sequential `ImportChunkJob` instances and
`FinalizeImportJob` inside one transaction, instead of dispatching independent
chunks and estimating a delay for finalization. `import_run_locks.products` is a
database row lock held through commit. It covers both commercial and full import
runs; it does not depend on an expiring cache lease. `import_chunks` unique receipts
and persisted total/processed counters replace cache read/modify/write progress.
Completed/failed batches cannot be prematurely finalized. Old standalone chunk
jobs are rejected; drain old import queues before installing the release.

`onec_files.sha256` is unique. Per-SKU `import_commercial_rows` receipts allow a
one-SKU test followed by the full file without applying that SKU twice. A file
is completed only when all source rows have receipts, committed with the writes.
Unknown SKUs and invalid values are diagnostic outcomes, not permission to create
products. A completed file is not retried just because some rows had diagnostics;
correct the source and provide a new export for those rows.

Any database/runtime exception rolls back the entire run, including product
writes, file/row/chunk receipts and counters. Retry starts from the last committed
state. Exact before/after triples for each matched SKU, including unchanged rows,
remain in the relational ledger; they are not truncated. Existing admin JSON
previews retain the first 1000 changed products only. Cache invalidation happens
after commit. New commercial runs are excluded from the old batch retention task.

The historical 016 migration now checks columns individually because current
014 already creates them. The new migration adds the commercial ledger and
missing counters without dropping data. Its down migration refuses to erase a
nonempty ledger. No new dependency was added. Existing `composer.lock` has a
generated placeholder content hash and incomplete package metadata: do not run
a fresh production dependency installation from that file as part of this PR.
Dependency-lock repair is a separate prerequisite for a clean-server build.

## File contract and ordering

Input defaults to `/var/www/vhosts/autohimiki.kz/httpdocs/import1`.
The delivered name can remain `Остатки. Розница Экспорт (XLSX).xlsx`.
No FTP credentials or changes to 1C export are needed.

Accepted workbook: one sheet, exactly these five data columns:

| Column | Header | Use |
|---|---|---|
| A | Ед. изм. | Ignored for writes |
| B | Номенклатура | Ignored for writes, never a matching key |
| C | Номенклатура.Код | Exact string products.sku |
| D | Остаток на складе | Validated integer quantity, in_stock derived |
| E | Розничная цена | Validated nonnegative decimal, at most 2 decimal places |

Supported layouts: flat first-row headers; or row 1 `Склад` plus quantity/price,
row 2 the first three headers, row 3 warehouse summary. A final `Итого` is excluded.
Data after the total, additional nonempty columns, duplicate SKU (including
case-only duplicates), numeric SKU cells, formulas, multiple sheets, unknown
headers, invalid XML/ZIP and empty datasets are rejected before writes.
The ZIP and expanded XML sizes are bounded; row limit is 20,000.

SKUs preserve Cyrillic and leading zeros; only surrounding whitespace is trimmed.
No inferred padding, fuzzy/name matching or marketplace identity is used. A DB
collation match to a different string is rejected as unknown, and multiple target
matches raise a conflict. Missing source SKUs do not zero absent catalog products.

Blank/malformed/negative price preserves the old price and emits a diagnostic;
numeric zero is valid. Dot or comma decimal separators and correctly grouped
spaces/NBSP are accepted, mixed comma/dot notation is rejected. Quantity must be
integral (including `2.000`); fractions and arbitrary text preserve stock with a
diagnostic. Nonpositive integral quantity becomes 0/false. Price errors do not
prevent a valid quantity from being applied, and vice versa.

Intake ignores temporary names, requires a sufficiently old mtime, then compares
size/mtime and source/staged SHA-256 around copying to private staging. A newer
unstable file blocks falling back to an older file. Apply accepts only the newest
file from the configured directory; equal-time differing files require review.
`--file` can inspect another location in dry-run, but cannot use it for apply.
Completed hashes are skipped even under another filename. A known older hash
cannot refill missing rows after a newer snapshot was applied.

The workbook has no verified generation timestamp. Before setting
`ONEC_ORDER_SOURCE=ftp_mtime`, confirm that the existing FTP producer's filesystem
timestamps preserve export order and that older reports are not reuploaded with
new timestamps. Until confirmed, apply refuses; dry-run still works. If that
assumption is false, leave apply disabled and review source ordering. Hashes and
mtime cannot identify an unseen old report reuploaded as if new.

Manual prices_only uploads must match the current stable FTP file by hash, using
the FTP timestamp rather than the browser upload timestamp. The fixed mapping is
enforced server-side. Generic full-import preview remains available.

## Local verification and remaining gates

Verification on 2026-09-02: dedicated 1C suite **20 tests / 137 assertions, all
passed**; complete suite **37 tests / 198 assertions, no failures, one pre-existing
risky output-buffer warning** in `PublicPagesSmokeTest::test_seo_page_and_filter_pages_load`.
Pint passed for the new core/command/test files and rewritten jobs after formatting.

Tests use PHP 8.3.30 and an isolated SQLite in-memory DB built from the actual
category/brand/product/image/import migrations. Product fixtures intentionally
contain manual SEO/content/legacy image references and inactive publication state.
No local or production catalog database was used for writes.

Both unmodified historical XLSX fixtures have 263 identical product rows and 10
blank prices. Their provenance/checksums are in `tests/Fixtures/onec/README.md`.
The current production FTP file has not yet been supplied; current-file acceptance
is **pending**, not inferred from historical samples. Also pending are live MySQL
schema/collation validation, Plesk scheduler inspection and production-only writer
removal. Keep the PR draft and scheduling disabled until these gates are satisfied.

One real source SKU tested locally: `РТ-00001343`. The existing *test* product
starts at price 99.50, quantity 9, in_stock=false. Dry-run reports 263 source rows,
1 selected row, planned price 2700.00, quantity 2, in_stock=true; it writes no batch,
file ledger or product data. Apply updates exactly those three fields; all other
product columns and gallery records compare equal. Repeated apply is duplicate.
This is not a statement about the current production price of that SKU.

Run regression suite on Windows:

```powershell
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' vendor\bin\phpunit --filter OnecCommercialSyncTest --no-coverage
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' vendor\bin\phpunit --no-coverage
```

The 1C suite covers both real layouts, totals, SKU strings, blank/bad prices,
zero/negative/bad stock, unknown/conflicting SKU, duplicate source SKU/hash/chunk,
partial→full processing, failure after writes followed by retry, before/after
journal, after-commit cache invalidation, old/ambiguous/unstable inputs, altered
staged bytes, manual-upload mismatch, fixed manual mapping, full-import ownership,
and command dry-run. The existing SEO page smoke test reports an output-buffer
risky warning independently of Phase A; preserve that distinction in test results.

## Plesk deployment — operator-run, not executed by this task

1. Supply the current FTP XLSX for validation and confirm the actual product/import
   schema with read-only inspection. Verify products and ledger tables use a
   transactional engine (InnoDB). Confirm the target merchant project is autohimiki.kz.
2. In Plesk Scheduled Tasks, identify the existing Laravel `schedule:run` job and
   its PHP binary/user. Inspect `crontab -l` and any supervisor configuration for
   competing schedulers. Keep exactly one mechanism. Do not start schedule:work
   alongside Plesk schedule:run. Pause the scheduler and existing import workers;
   finish or explicitly cancel old standalone chunk/finalizer jobs before upgrade.
3. Back up the database and record the current code revision. Preserve `.env`,
   `storage/app/public`, `public/storage`, existing vendor and production-only files.
   Inspect production git status; resolve local code differences before changing
   checkout. Do not overwrite the production-only admin resource blindly.
4. After PR approval/merge, deploy that reviewed main revision using the site's
   existing method. For a clean git-managed checkout:

```bash
cd /var/www/vhosts/autohimiki.kz/httpdocs
git status --short
git rev-parse HEAD   # record as previous release
git fetch origin main
git merge --ff-only origin/main
```

5. Select the PHP binary shown by Plesk. The following commands use PHP 8.3;
   verify `/opt/plesk/php/8.3/bin/php` exists and is the site's selected version.
   Inspect migration status before applying. On an existing deployment with older
   migrations applied, apply only the new additive migration; do not blindly run
   all historical migrations (the repository also lacks a users-creation migration).

```bash
PHP=/opt/plesk/php/8.3/bin/php
test -x "$PHP" || exit 1
"$PHP" -m
"$PHP" artisan migrate:status
"$PHP" artisan migrate --path=database/migrations/2026_09_02_000001_add_commercial_import_ledger.php --force
```

6. Configure `.env` (no FTP password):

```dotenv
ONEC_INPUT_DIRECTORY=/var/www/vhosts/autohimiki.kz/httpdocs/import1
ONEC_STABLE_SECONDS=60
ONEC_SCHEDULE_ENABLED=false
ONEC_ORDER_SOURCE=
DB_QUEUE_RETRY_AFTER=360
REDIS_QUEUE_RETRY_AFTER=360
```

Ensure the PHP user can read import1 and write storage/app/private/onec. The latter
must not be served by HTTP. Document Root stays `httpdocs/public`; no storage-link
recreation is needed. Existing queue worker timeout must allow the 300-second job;
retry_after must exceed it (360 seconds). Keep the production scheduler paused.

```bash
"$PHP" artisan config:cache
"$PHP" artisan onec:sync --dry-run --debug
"$PHP" artisan onec:sync --dry-run --sku='РТ-00001343' --limit=1 --debug
```

Explicit-file production dry-run:

```bash
/opt/plesk/php/8.3/bin/php /var/www/vhosts/autohimiki.kz/httpdocs/artisan onec:sync --dry-run --file='/var/www/vhosts/autohimiki.kz/httpdocs/import1/Остатки. Розница Экспорт (XLSX).xlsx' --debug
```

7. Review row count/header interpretation, unknown SKUs and diagnostics against the
   current file. Confirm `РТ-00001343` actually exists in current file and catalog;
   otherwise select a confirmed existing SKU from that dry-run. Snapshot all its
   columns and gallery. Disable production-only admin/maintenance commercial writers.
   After confirming source timestamp ordering, set `ONEC_ORDER_SOURCE=ftp_mtime`,
   run config:cache, and execute the one-SKU test:

```bash
/opt/plesk/php/8.3/bin/php /var/www/vhosts/autohimiki.kz/httpdocs/artisan onec:sync --sku='РТ-00001343' --limit=1 --debug
```

Record batch_id from output; compare products and import_commercial_rows before/after.
Run the exact command again: status must be duplicate, with no second application.
Check the three changed fields and all protected fields, blank prices, zero stock,
bad values, unknown SKU and interrupted/retried import on staging. Do not fabricate
bad rows in the live 1C report for testing.

8. Only after successful validation, manually run full `onec:sync`, inspect its
   statistics/diagnostics, then set `ONEC_SCHEDULE_ENABLED=true`, config:cache and
   resume the single authoritative scheduler. Inspect `artisan schedule:list`.
   The app checks every five minutes; Plesk normally calls `artisan schedule:run`
   every minute. Retain required non-1C scheduled jobs. Restart/drain queue workers
   using the existing hosting mechanism; do not install a competing supervisor.

## Targeted data rollback

First disable scheduling, pause importer workers/manual runs and wait for the
current transaction to finish. Keep current Phase A code while restoring data.
Use the batch_id printed by the command. Inspect:

```sql
SELECT id, sku, product_id, status, before_values, after_values, diagnostics
FROM import_commercial_rows WHERE import_batch_id = YOUR_BATCH_ID ORDER BY id;
```

In `artisan tinker`, the following is a guarded rollback template. Set the actual
batch id; first keep `$apply=false` to review. A changed SKU or newer commercial
values causes a conflict and rolls back the whole operation. Do not restore the
entire database over later unrelated administrator changes.

```php
$batchId = 123; // replace with the reviewed run
$apply = false;
$result = DB::transaction(function () use ($batchId, $apply) {
    app(App\Services\Import\CommercialImportRunner::class)->lock();
    $rows = DB::table('import_commercial_rows')->where('import_batch_id', $batchId)
        ->where('status', 'updated')->orderBy('id')->get();
    $plan = [];
    foreach ($rows as $row) {
        $product = DB::table('products')->where('id', $row->product_id)->lockForUpdate()->first();
        if (!$product || $product->sku !== $row->sku) { throw new RuntimeException('SKU conflict'); }
        $current = ['price' => number_format((float)$product->price, 2, '.', ''),
            'quantity' => (int)$product->quantity, 'in_stock' => (bool)$product->in_stock];
        if ($current !== json_decode($row->after_values, true)) { throw new RuntimeException('Newer data: '.$row->sku); }
        $before = json_decode($row->before_values, true);
        $plan[] = ['sku' => $row->sku, 'restore' => $before];
        if ($apply) {
            DB::table('products')->where('id', $product->id)->update($before);
            DB::table('import_commercial_rows')->where('id', $row->id)->update(['status' => 'rolled_back']);
        }
    }
    if ($apply) {
        DB::afterCommit(function () use ($batchId) {
            App\Services\CacheService::forgetProducts();
            Log::notice('1C batch rolled back', ['batch_id' => $batchId]);
        });
    }
    return $plan;
});
$result;
```

Review, then rerun with `$apply=true`. Keep original before/after values, file hashes
and chronological watermark. The old file must not immediately reapply after a
rollback. Do not delete ledger rows or run migrate:rollback on a populated ledger.
For code rollback, return to the recorded previous reviewed revision using the
existing release mechanism with import automation still disabled. Do not restart
the old unsafe importer automatically. No media rollback is necessary: Phase A
never writes images, links or content through the commercial path.

## Changed-file manifest

* `.env.example`, `.gitignore`, `config/onec.php`, `config/queue.php`, `routes/console.php`
* `app/Console/Commands/OnecSyncCommand.php`
* `app/Filament/Pages/ImportWizardPage.php`, `app/Models/ImportBatch.php`
* `app/Jobs/Import/ProcessImportJob.php`, `ImportChunkJob.php`, `FinalizeImportJob.php`
* `app/Services/Import/ImportFileParser.php`, `ColumnMapper.php`, `PriceStockUpdater.php`,
  `FullProductImporter.php`, `CommercialValues.php`, `CommercialImportRunner.php`, `OnecFileIntake.php`
* `database/migrations/2025_01_016_update_import_batches.php`
* `database/migrations/2026_09_02_000001_add_commercial_import_ledger.php`
* `tests/Feature/OnecCommercialSyncTest.php`
* `tests/Fixtures/onec/flat.xlsx`, `warehouse.xlsx`, `README.md`
* `docs/ONEC_PHASE_A_RUNBOOK.md`

The three pre-existing audit documents were left outside this change. Commit hashes
and the draft PR URL are supplied in the final task report.
