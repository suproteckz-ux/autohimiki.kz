<?php

namespace App\Services\Ozon;

use App\Models\OzonProductLink;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

/** Admin orchestration only: all Seller API writes remain in OzonExporter. */
class OzonAdmin
{
    public const MAX_SELECTED = 10;

    public function __construct(private OzonPayload $payload, private OzonExporter $exporter, private OzonAdminSettings $settings) {}

    public function authorize(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function check(Product $product): array
    {
        $this->authorize();
        $product = $product->fresh(['images', 'ozonLink']);
        $errors = [];
        $warnings = ['Проверка локальная: дубликаты Ozon, доступность фото и обязательные атрибуты API ещё не проверены.',
            'Аннотация определяется точечно по category/type при отправке. ТН ВЭД и документы заполните в Ozon.'];
        try {
            $this->payload->validate($product);
            $this->settings->mapping();
            if (config('ozon.vat') === null || config('ozon.vat') === '') {
                throw new \RuntimeException('ozon_vat_missing');
            }
            if ($product->ozonLink) {
                throw new \RuntimeException('ozon_already_linked: повторный import и изменение цены запрещены');
            }
        } catch (\Throwable $e) {
            $errors[] = $this->exporter->safeError($e);
        }
        $images = $this->payload->images($product);
        if (! $images) {
            $errors[] = 'Нет локальных HTTPS-фото';
        }
        $descriptionText = trim((string) $product->description);
        if (! $descriptionText) {
            $warnings[] = 'Нет описания';
        }
        if (! $product->is_active) {
            $warnings[] = 'Товар не активен на сайте';
        }
        if (! config('ozon.enabled')) {
            $warnings[] = 'OZON_ENABLED=false: отправка и остатки заблокированы';
        }
        $settings = $this->settings->read();
        $report = [
            'SKU' => $product->sku,
            'Название' => $product->name,
            'Локальная категория' => $product->category?->name ?? '—',
            'Цена сайта' => (string) $product->price.' ₸',
            'Остаток (после публикации)' => $this->payload->quantity($product),
            'Главное фото' => $product->main_image ? 'Есть' : 'Нет',
            'Фото галерея' => $product->images->count().' шт.',
            'Описание' => $descriptionText !== '' ? 'Есть ('.mb_strlen($descriptionText).' симв.)' : 'Нет',
            // Kept for backward-compat — tests assertSee the description payload content.
            'Описание и характеристики' => $this->payload->description($product),
            'Характеристики' => count($product->getAttribute('attributes') ?? []),
            'Фото (URLs)' => count($images),
            'Категория Ozon' => OzonAdminSettings::CATEGORY,
            'description_category_id' => $settings['description_category_id'] ?? 'Не задан',
            'type_id' => $settings['type_id'] ?? 'Не задан',
            'Готов к отправке' => empty($errors) ? 'READY TO SEND' : 'NOT READY TO SEND',
            'Ошибки' => $errors,
            'Предупреждения' => $warnings,
        ];
        session()->forget($this->ticketKey($product));
        if (! $errors) {
            session()->put($this->ticketKey($product), ['fingerprint' => $this->fingerprint($product), 'expires' => now()->addMinutes(15)->timestamp]);
        }

        return $this->sanitize($report);
    }

    public function canSend(Product $product): bool
    {
        $ticket = session($this->ticketKey($product));

        return config('ozon.enabled') && ! $product->ozonLink && is_array($ticket) && ($ticket['expires'] ?? 0) >= now()->timestamp;
    }

    public function send(Product $product): array
    {
        $this->authorize();
        $product = $product->fresh(['images', 'ozonLink']);
        $ticket = session()->pull($this->ticketKey($product));
        if (! is_array($ticket) || ($ticket['expires'] ?? 0) < now()->timestamp || ! hash_equals($ticket['fingerprint'] ?? '', $this->fingerprint($product))) {
            throw new \RuntimeException('ozon_check_required: товар или настройки изменились; повторите проверку');
        }
        $result = $this->exporter->export($product, $this->settings->mapping());

        return ['Результат' => $result === 'imported' ? 'Задача принята. Это ещё не созданная карточка. Проверьте статус.' : 'Уже существует: цена не отправлялась.', ...$this->status($product->fresh())];
    }

    public function status(Product $product, bool $refresh = false): array
    {
        $this->authorize();
        $link = $product->ozonLink()->first();
        if (! $link) {
            throw new \RuntimeException('ozon_not_linked');
        }
        if ($refresh) {
            $this->exporter->refresh($link);
            $link->refresh();
        }

        return $this->sanitize(['SKU' => $link->offer_id, 'Статус' => OzonProductLink::statusLabels()[$link->status] ?? $link->status,
            'Ozon status' => $link->ozon_status, 'Product ID' => $link->ozon_product_id, 'Task ID' => $link->import_task_id,
            'Сообщение Ozon / validation result' => $link->ozon_status_message, 'Ошибка' => $link->last_error,
            'Проверено' => $link->last_status_check_at?->toDateTimeString(),
            'Дальше' => 'Откройте Ozon Seller, дополните обязательные поля, проверьте цену и опубликуйте. Затем подтвердите публикацию здесь.']);
    }

    public function confirm(Product $product): array
    {
        $this->authorize();
        $link = $product->ozonLink()->firstOrFail();
        if ($link->offer_id !== $product->fresh()->sku) {
            throw new \RuntimeException('sku_changed');
        }
        $this->exporter->confirmPublished($link);

        return $this->status($product);
    }

    public function stock(Product $product): array
    {
        $this->authorize();
        $product = $product->fresh();
        $link = $product->ozonLink()->first();
        if (! $link || ! $this->exporter->stock($product, $link)) {
            throw new \RuntimeException('ozon_publication_confirmation_required');
        }

        return $this->sanitize(['SKU' => $product->sku, 'Остаток обновлён' => $this->payload->quantity($product)]);
    }

    public function images(Product $product): array
    {
        $this->authorize();
        $report = [];
        foreach ($this->payload->images($product->fresh(['images'])) as $url) {
            try {
                $response = Http::connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->head($url);
                $ok = $response->successful() && str_starts_with(strtolower($response->header('Content-Type')), 'image/');
                $report[$url] = ($ok ? '✓' : 'Ошибка').' HTTP '.$response->status();
            } catch (\Throwable) {
                $report[$url] = 'Ошибка доступности';
            }
        }

        return $this->sanitize($report ?: ['Фото' => 'Нет локальных HTTPS-фото']);
    }

    public function selected(array $ids, string $operation): array
    {
        $this->authorize();
        abort_unless(in_array($operation, ['check', 'status', 'stock'], true), 422);
        abort_if(count($ids) < 1 || count($ids) > self::MAX_SELECTED, 422, 'Выберите от 1 до 10 товаров');
        $products = Product::whereKey(array_unique($ids))->get();
        $reports = [];
        foreach ($products as $product) {
            try {
                $reports[] = $operation === 'status' ? $this->status($product, true) : $this->{$operation}($product);
            } catch (\Throwable $e) {
                $reports[] = $this->sanitize(['SKU' => $product->sku, 'Ошибка' => $this->exporter->safeError($e)]);
            }
        }

        return $reports;
    }

    private function ticketKey(Product $product): string
    {
        return 'ozon_checked.'.auth()->id().'.'.$product->id;
    }

    private function fingerprint(Product $product): string
    {
        $settings = $this->settings->read();

        return hash('sha256', json_encode([$product->getAttributes(), $this->payload->images($product),
            $settings['description_category_id'] ?? null, $settings['type_id'] ?? null,
            config('ozon.vat'), config('ozon.currency'), config('app.url')]));
    }

    public function sanitize(array $report): array
    {
        $safe = app(OzonConnectionResponsePreview::class);
        foreach ($report as $key => $value) {
            $report[$key] = is_array($value) ? $this->sanitize($value) : $safe->message($value ?? '—');
        }

        return $report;
    }
}
