# autohimiki.kz — Phase A commercial sync: revised business rules

This revision is local only: no push, deployment, production writes or scheduler
activation. The operator reports that the previous Phase A production full sync,
dry-run and duplicate protection succeeded. That does not establish that the
report contains the entire nomenclature. Kaspi, images, MarketRadar/Satu and design
are outside this change.

## Root cause and new ownership rules

The original updater returned `not_found` for unknown exact SKUs and validated
price and quantity independently. Consequently a blank/bad price could leave a
product available when the incoming quantity was positive. The runner did not map
`Номенклатура` and had no confirmed-full-snapshot reconciliation step.

1C updates only `price`, `quantity`, `in_stock` on existing products. Query Builder
writes bypass observers and do not change `updated_at`, publication, name, slug,
category, SEO, attributes, descriptions or images. Existing full content import
continues to leave these three commercial fields under 1C ownership.

| Input | Price | Quantity | Availability |
|---|---|---|---|
| Existing SKU, valid price and quantity | Incoming | max(0, incoming) | quantity > 0 |
| Existing SKU, blank/invalid price | Preserve current | 0 | false |
| New SKU, blank/invalid price | 0 | 0 | false |
| Any SKU, valid price but blank/invalid quantity | Incoming | 0 | false |
| Absent SKU, full snapshot disabled or filtered run | Unchanged | Unchanged | Unchanged |
| Absent SKU, confirmed full snapshot and unfiltered run | Preserve current | 0 | false |

Availability is `valid_price && valid_quantity && quantity > 0`. Numeric zero is a
valid price under the existing validator. Negative prices, mixed separators,
malformed numbers and excessive precision are invalid. Quantity must be integral;
negative integers normalize to zero. Every blank/invalid field emits a diagnostic.

## New product architecture

`products.category_id` is mandatory. There is no dedicated intake status/category
called “Новые товары”. The existing `is_new` flag is a storefront merchandising
flag, not a moderation workflow. The minimal implementation reuses the existing
`Без категории` / `bez-kategorii` fallback of the content importer. If absent, it is
created inactive inside the same transaction. An existing category is not edited.

New products receive exact trimmed SKU, source name, validated commercial values,
a unique technical `onec-<UUID>` slug, category, timestamps and `is_active=false`.
Other fields retain schema defaults; `is_new` is not repurposed. No descriptions,
SEO, brand, media or Kaspi data are fabricated. Administrators can review and
publish through the existing product workflow. Subsequent 1C files never overwrite
that work. Missing or oversized new names are conflicts rather than invented names.
Dry-run creates neither products nor the fallback category.

## Input and full snapshot guard

Default directory: `/var/www/vhosts/autohimiki.kz/httpdocs/import1`.
Current operator-specified filename: `Остатки. Розница Экспорт (XLSX).xlsx`.
Accepted columns remain:

| Column | Header | Use |
|---|---|---|
| A | Ед. изм. | No write |
| B | Номенклатура | Name on creation only |
| C | Номенклатура.Код | Exact SKU string |
| D | Остаток на складе | Validated quantity |
| E | Розничная цена | Validated price |

Flat and warehouse layouts are supported, including summary/footer exclusion.
Duplicate source SKUs, numeric SKU cells, formulas, additional sheets/columns,
invalid headers/XML/ZIP and empty datasets are rejected. Maximum 20,000 rows.
Only surrounding SKU whitespace is trimmed: preserve leading zeros and Cyrillic,
no padding or name matching. A database collation match to a different SKU string
is a conflict, as are multiple target matches. Apply fails and rolls back; dry-run
reports the conflict without product/ledger writes.

Both historical fixtures contain 263 identical product rows, 10 blank prices,
plus warehouse totals in one layout. Neither headers, totals nor the filename
prove inclusion of zero-stock/inactive/all nomenclature items. The actual current
production bytes were not available in this workspace for this revision. Therefore
fullness is **unconfirmed**, and `ONEC_FULL_SNAPSHOT=false` is the safe default.
Ask the 1C report owner to verify filters and inclusion of zero-stock, inactive and
all catalog codes; compare the missing-SKU list with the intended catalog scope.

`ONEC_FULL_SNAPSHOT=true` permits absent-SKU zeroing only on an unfiltered full run.
Any `--sku` or `--limit`, even a limit larger than the file, disables zeroing.
The presence set always comes from the entire parsed file. Invalid-price source
rows are present rows, not missing rows. Only absent products whose quantity or
availability actually changes receive a missing receipt/count.

## Transactions, receipts and repeats

The existing database `import_run_locks.products` serializes imports through
commit. Intake validates stability, private staged bytes and SHA-256. Apply still
requires confirmed `ONEC_ORDER_SOURCE=ftp_mtime`; older/ambiguous snapshots are
rejected. Hash/mtime cannot detect an unseen old report reuploaded as new.

One transaction includes fallback category/product creation, commercial updates,
missing-SKU zeroing, file/row/chunk receipts, counters and completion watermark.
Runtime/database failure rolls them back together; the outer batch may remain as
`failed` for diagnosis. Cache invalidation happens after commit.

`import_commercial_rows` records `created` with SQL NULL before-values, actual
commercial after-values, product_id, SKU and diagnostics. Existing rows retain
`updated`/`unchanged`; missing changes use `missing_from_snapshot`, row_number=0,
and before/after triples. Source completion counts only receipts with row_number>0,
so missing receipts do not corrupt file completion. Created counts use the existing
`import_batches.created_count`; missing counts are separate from source updates.

A completed SHA-256 never reapplies, including after a configuration change. To
observe the revised rules in apply, use a genuine subsequent export with different
bytes; do not delete receipts or edit the live report to bypass the ledger. A
partial run can be followed by a full run for remaining rows, but a completed file
from filtered runs is still completed. Full-snapshot policy changes are not
retroactive. Scheduling stays disabled.

No new schema, migration or dependency is required by this revision. Existing
Phase A migration remains `2026_09_02_000001_add_commercial_import_ledger.php`;
do not rerun it on an already migrated installation. The populated ledger must
not be removed with migrate:rollback.

## Dry-run output and production review plan

Summary includes total_rows, selected_rows, matched, created_planned, updated,
unchanged, invalid_price, invalid_quantity, missing_from_snapshot_planned,
conflicts, diagnostics (message count), already_processed and full_snapshot.
Invalid-field counts describe selected input rows, including already-processed
ones. Planned changes exclude already-processed rows. `--debug` adds row plans;
new rows include sku, created_planned status, name, price, quantity, in_stock and
diagnostics, plus before/after. Dry-run writes no products, batches or ledger.

The following is a plan for **after separate approval and deployment**. None of
these production commands were run by this task. Use the PHP binary configured
for this Plesk site (the commands assume the previously documented PHP 8.3 path).
Keep effective Laravel config `onec.scheduled=false`, `onec.full_snapshot=false`.
Inspect cached config as well as .env: shell/.env overrides do not bypass an
existing config cache. Do not clear caches or change settings as part of this
read-only review.

```bash
cd /var/www/vhosts/autohimiki.kz/httpdocs
/opt/plesk/php/8.3/bin/php artisan config:show onec
/opt/plesk/php/8.3/bin/php artisan migrate:status
/opt/plesk/php/8.3/bin/php artisan onec:sync --dry-run --file='/var/www/vhosts/autohimiki.kz/httpdocs/import1/Остатки. Розница Экспорт (XLSX).xlsx'
/opt/plesk/php/8.3/bin/php artisan onec:sync --dry-run --file='/var/www/vhosts/autohimiki.kz/httpdocs/import1/Остатки. Розница Экспорт (XLSX).xlsx' --debug
```

Review counts, new names/SKUs, preserved prices with zero stock, diagnostics and
zero conflicts. Missing count must be zero while the guard is off. If the hash was
already completed, expect already_processed, not planned application of new rules.
For a SKU present in the output, substitute its exact value for VERIFIED_SKU:

```bash
/opt/plesk/php/8.3/bin/php artisan onec:sync --dry-run --file='/var/www/vhosts/autohimiki.kz/httpdocs/import1/Остатки. Розница Экспорт (XLSX).xlsx' --sku='VERIFIED_SKU' --limit=1 --debug
```

To evaluate full-snapshot zeroing before approving that mode, use an isolated
staging database copy, the untouched current XLSX and effective staging
`onec.full_snapshot=true`, with scheduling disabled. Run unfiltered dry-run and
review every missing row with the 1C owner. Do not enable the production flag until
completeness and intended scope are confirmed. Stop after review; production apply
and scheduling require separate authorization.

## Verification and rollback

Tests use PHP 8.3.30 and isolated SQLite in-memory databases with actual migrations.
They cover creation, exact text SKUs, bad prices/quantities, protected fields,
category creation without publication, hash repeats, filtered/full snapshot modes,
conflict reporting and atomic rollback after creation, updates and missing zeroing.
Historical workbook tests continue to run. No production database is used.

```powershell
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' vendor\bin\phpunit --filter OnecCommercialSyncTest --no-coverage
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' vendor\bin\phpunit --no-coverage
git diff --check
```

Runtime rollback is automatic. For a later operator-requested data rollback,
inspect the batch receipts and verify current values still equal recorded after
values before restoring `updated`/`missing_from_snapshot` triples. Created rows
have no before product: do not blindly delete them, because administrators may
have added content or other records may reference them. Review such products
individually. Preserve original ledger/hash/watermark to prevent accidental replay.
No media rollback is needed.

Local results (2026-09-02): dedicated suite **26 tests / 256 assertions passed**;
full suite **43 tests / 317 assertions, no failures, one pre-existing risky
output-buffer warning** in `PublicPagesSmokeTest::test_seo_page_and_filter_pages_load`
(line 270). Pint passed. `git diff --check` passed.

Changed files in this revision:

- `.env.example`
- `config/onec.php`
- `app/Console/Commands/OnecSyncCommand.php`
- `app/Services/Import/CommercialImportRunner.php`
- `app/Services/Import/PriceStockUpdater.php`
- `tests/Feature/OnecCommercialSyncTest.php`
- `docs/ONEC_PHASE_A_RUNBOOK.md`
