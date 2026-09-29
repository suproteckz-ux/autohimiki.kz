# Kaspi gallery regression investigation

## Live follow-up: 2026-09-29

After the operator reproduced parsed=1 on commit 439c7b4, the existing local Chromium
collector successfully fetched card 169459233 (HTTP 200). Its BACKEND gallery already
contains seven images: one PNG and six numeric filenames ending with a bare dot,
such as `https://resources.cdn-kaspi.kz/img/m/p/p44/p7e/161575978.?format=gallery-large`.
The parser rejected those six as `unsupported_extension`. This is the confirmed
cause on the captured live response; the earlier incomplete-BACKEND hypothesis below
did not explain this card.

Running old and fixed parsers against the identical captured HTML returned 1 and 7
respectively. The fix accepts only this numeric bare-dot path form on the exact Kaspi
product CDN with gallery-large format (existing normalization upgrades smaller gallery
variants). MIME, decoded image validation, download limits and URL protections remain
unchanged. ProductionBridge is unchanged from 439c7b4.

The full local capture remains in ignored storage/framework/testing; only the selected
product card/gallery/description/specifications are committed in
tests/Fixtures/Kaspi/169459233-gallery.json, with no headers, cookies or credentials.
No additional debug mode was necessary. Regression tests cover the actual seven URLs
and rejection of unrelated formats, filenames and hosts. All Kaspi tests: 156 passed,
4598 assertions. No production dry-run or execute was run by the agent. The same
single-SKU dry-run below is the next operator verification; expected parsed/sent=7.

## Evidence and limits

Target: SKU `РТ-00001534`, product 612, Kaspi card 169459233.
No persisted HTML/snapshot or diagnostic log for the reported run was found locally.
The collector returns HTML through stdout; the command prints diagnostics to the console.
No production requests or repeat imports were performed during this offline investigation.
The exact input of the historical production run remains unknown. Seven live images
are reported by the operator, not independently verified by this investigation.

## Reproduced defect and history

`KaspiEnrichmentParser::collectImageCandidates` returned immediately whenever BACKEND
provided any image, including a lone primary image. It therefore skipped the existing
identity-matched JSON-LD and product-gallery DOM sources even when they contained seven
photos. Loss occurs before payload construction in this reproduced scenario.

The early return exists in initial commit `7385bb3e36ab3b4fff32f87d5b6b18793490709d`.
Commit `b570bd7a8a07b14a8dbbac9b815f1660314e8acd` contains the multi-image importer and
storefront gallery. Force refresh was added in `acef421566fcc138ac98eb8607a94bc0c4c75079`.
History does not establish a later commit changing seven photos to one. A complete
BACKEND gallery still returned seven before this fix. A change in live Kaspi markup
cannot be confirmed without the original HTML.

## Minimal correction

Merge the already supported BACKEND, matching JSON-LD and scoped DOM image sources,
then use existing normalization and deduplication. Keep primary first and og:image
as fallback. No new parser, downloader, endpoint, schema or storefront implementation.

Force-refresh preview now includes `kaspi_images_parsed` and `images_to_send`.
Result includes `images_sent` and `images_stored`; the latter comes from the existing
endpoint gallery count plus the main photo, and is null for missing/invalid counts.

## Offline verification

Fixtures are synthetic, not saved live Kaspi HTML. Before the fix, two regression
cases failed (primary + JSON-LD gallery; primary + DOM gallery); full BACKEND passed.

The integrated fixture for this SKU verifies:

| Stage | Count |
| --- | ---: |
| Parser unique image URLs | 7 |
| Bridge payload | 7 |
| Local test endpoint receives | 7 |
| Main image | 1 |
| Ordered product_images rows | 6 |
| Storefront thumbnails | 7 |

HTTP, browser collection and image downloading are mocked. Real application parsing,
bridge, endpoint, refresh service and storefront run against isolated test storage/DB.
Repeat refresh is unchanged and creates no duplicates. Description and attributes
import; all other product fields remain unchanged, including commercial fields and SEO.

- New regression tests: 4 passed, 111 assertions.
- All Kaspi PHP tests: 154 passed, 4589 assertions.
- Collector/resolver Node tests: 12 passed.
- New test file formatted with Pint; git diff --check passed.

## Manual single-SKU verification after review

Run from the project root in PowerShell. These commands were NOT executed here.
Create a dedicated UTF-8 file, avoiding the previous kaspi-sku.txt:

```powershell
[IO.File]::WriteAllText("$PWD\kaspi-gallery-one-sku.txt", "РТ-00001534", [Text.UTF8Encoding]::new($false))
php artisan kaspi:push-production --sku-file="kaspi-gallery-one-sku.txt" --dry-run --force-content-refresh --diagnostics -vvv
```

Verify exactly one SKU, the expected identity, and both parsed/prepared image counts
equal seven. If counts differ, stop and preserve diagnostics. After review and when
the receiving production version is appropriate, manually run:

```powershell
php artisan kaspi:push-production --sku-file="kaspi-gallery-one-sku.txt" --execute --force-content-refresh --diagnostics -vvv
```

Expected: images_sent=7, images_stored=7, main plus six gallery images. This is an
expectation from the regression fixture, not a claim of completed live verification.

Массовый импорт не запускался.
Production deploy не выполнялся.
SEO-изменения не затронуты.
Push не выполнялся.
