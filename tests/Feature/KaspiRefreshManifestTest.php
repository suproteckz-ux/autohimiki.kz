<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiRefreshManifest;
use App\Services\Kaspi\KaspiRefreshPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KaspiRefreshManifestTest extends TestCase
{
    private function payload(int $id = 1): array
    {
        return ['version' => 1, 'force_content_refresh' => true, 'product_id' => $id,
            'sku' => 'РТ-'.$id, 'storefront_url' => 'https://autohimiki.kz/product/'.$id,
            'state_fingerprint' => str_repeat('a', 64), 'kaspi_url' => 'https://kaspi.kz/shop/p/test-'.$id.'/',
            'source' => ['collector' => 'local-playwright', 'resolver_verified' => true, 'captcha' => false, 'merchant_id' => 'm', 'city_id' => 'c'],
            'content' => ['title' => 'Название', 'description' => "<p>Текст\nописания</p>",
                'images' => ['https://example.test/one', 'https://example.test/two'],
                'attributes' => [['name' => 'Бренд', 'value' => 'Марка']]]];
    }

    public function test_stream_hash_is_byte_compatible_with_old_policy_including_empty_set_and_key_order(): void
    {
        $manifest = new KaspiRefreshManifest;
        $this->assertSame(KaspiRefreshPolicy::approval([]), $manifest->approval());
        $manifest->close();
        $manifest = new KaspiRefreshManifest;
        try {
            $first = $this->payload();
            $second = array_reverse($this->payload(2), true);
            $manifest->append($first);
            $manifest->append($second);
            $this->assertSame(KaspiRefreshPolicy::approval([$second, $first]), $manifest->approval());
            $this->assertSame($manifest->approval(), $manifest->approval());
            $this->assertEquals([$first, $second], iterator_to_array($manifest->payloads()));
        } finally {
            $manifest->close();
        }
    }

    public static function changes(): array
    {
        return array_map(fn ($field) => [$field], ['product_id', 'sku', 'storefront_url', 'state_fingerprint',
            'kaspi_url', 'description', 'attributes', 'images', 'source', 'title']);
    }

    #[DataProvider('changes')]
    public function test_every_previously_bound_field_still_changes_hash(string $field): void
    {
        $original = $this->payload();
        $changed = $original;
        match ($field) {
            'product_id' => $changed['product_id'] = 2,
            'images' => $changed['content']['images'] = array_reverse($changed['content']['images']),
            'attributes' => $changed['content']['attributes'][0]['value'] = 'Другой',
            'description', 'title' => $changed['content'][$field] .= ' changed',
            'source' => $changed['source']['city_id'] = 'other',
            default => $changed[$field] .= 'changed',
        };
        $manifest = new KaspiRefreshManifest;
        try {
            $manifest->append($changed);
            $this->assertNotSame(KaspiRefreshPolicy::approval([$original]), $manifest->approval());
            $this->assertSame(KaspiRefreshPolicy::approval([$changed]), $manifest->approval());
        } finally {
            $manifest->close();
        }
    }

    public function test_large_unique_payload_set_has_bounded_memory_and_temp_is_deleted(): void
    {
        $manifest = new KaspiRefreshManifest;
        $stream = (new \ReflectionProperty($manifest, 'stream'))->getValue($manifest);
        $path = stream_get_meta_data($stream)['uri'];
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        try {
            for ($id = 1; $id <= 2000; $id++) {
                $payload = $this->payload($id);
                $payload['content']['description'] = str_repeat('x', 32768).$id;
                $manifest->append($payload);
            }
            unset($payload);
            $this->assertSame(2000, $manifest->count());
            $this->assertGreaterThan(60 * 1024 * 1024, $manifest->bytes());
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest->approval());
            $count = 0;
            foreach ($manifest->payloads() as $payload) {
                $count++;
                $this->assertSame($count, $payload['product_id']);
            }
            $this->assertSame(2000, $count);
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
            $this->assertFileExists($path);
        } finally {
            $manifest->close();
        }
        $this->assertFileDoesNotExist($path);
    }

    public function test_out_of_order_records_are_rejected_instead_of_silently_changing_hash(): void
    {
        $manifest = new KaspiRefreshManifest;
        try {
            $manifest->append($this->payload(2));
            $this->expectExceptionMessage('manifest_invalid_order');
            $manifest->append($this->payload(1));
        } finally {
            $manifest->close();
        }
    }
}
