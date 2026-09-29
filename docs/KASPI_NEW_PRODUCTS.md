# Kaspi enrichment queue for new products

The store represents an uncategorized product through the required `products.category_id`
foreign key pointing to the inactive category whose slug is `bez-kategorii`. It is not
represented by a null category ID.

`kaspi:push-new-products` selects only products which are active, have valid existing
Kaspi SKU/slug safety properties, and belong to that exact service category. The scope
is enforced by the production Candidate API and is checked again immediately before
each item is prepared. Assigning a real category removes the product from subsequent
runs and from an in-progress run before it can be imported.

The command delegates resolution, Chromium collection, parsing, payload validation,
preview and import to the existing force-refresh pipeline. It does not assign a category
or maintain a second importer. Existing content hash deduplication makes repeat runs
idempotent. The `kaspi:push-production` SKU-file guard is unchanged.

Review without importing:

```bash
php artisan kaspi:push-new-products --dry-run
```

Process the bounded queue:

```bash
php artisan kaspi:push-new-products --execute
```

Each row reports SKU, product ID, name, storefront URL, status and reason. Prepared
rows include parsed and outgoing image counts. Execute rows include sent/stored counts
and `image_count_mismatch`; the summary aggregates those counts and mismatches.
