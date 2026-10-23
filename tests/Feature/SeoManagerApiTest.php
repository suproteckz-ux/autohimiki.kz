<?php

namespace Tests\Feature;

use App\Models\SeoPage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoManagerApiTest extends TestCase
{
    private const TOKEN = 'local-test-token-that-is-not-a-real-secret-123456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo_manager.write_token_hash' => hash('sha256', self::TOKEN), 'seo_manager.allow_local_http' => true]);
        foreach (glob(database_path('migrations/2025_01_0*.php')) as $file) {
            if (preg_match('/2025_01_0(0[1-9]|1[0-3])_/', basename($file))) {
                (require $file)->up();
            }
        }
        foreach (glob(database_path('migrations/2026_10_*.php')) as $file) {
            (require $file)->up();
        }
        DB::table('categories')->insert(['id' => 1, 'name' => 'Test winter fluids', 'slug' => 'test-winter-fluids', 'is_active' => true]);
        DB::table('brands')->insert(['id' => 1, 'name' => 'Test brand', 'slug' => 'test-brand', 'is_active' => true]);
        DB::table('products')->insert(['id' => 1, 'name' => 'Test fluid', 'slug' => 'test-fluid', 'sku' => 'TEST-1',
            'category_id' => 1, 'brand_id' => 1, 'price' => 1234, 'quantity' => 7, 'in_stock' => true, 'is_active' => true,
            'attributes' => json_encode(['temperature' => '-30'])]);
        DB::table('products')->insert(['id' => 2, 'name' => 'Test fluid -20', 'slug' => 'test-fluid-20', 'sku' => 'TEST-2',
            'category_id' => 1, 'brand_id' => 1, 'price' => 2345, 'quantity' => 4, 'in_stock' => true, 'is_active' => true,
            'attributes' => json_encode(['temperature' => '-20'])]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'kind' => 'page', 'title' => 'Как выбрать незамерзайку для автомобиля зимой',
            'slug' => 'kak-vybrat-nezamerzayku-zimoy', 'meta_title' => 'Как выбрать незамерзайку зимой',
            'meta_description' => 'Выбираем жидкость для стеклоомывателя по подтверждённым характеристикам.',
            'h1' => 'Как выбрать незамерзайку для автомобиля зимой',
            'intro' => 'Сравните маркировку и условия применения перед покупкой.',
            'seo_text' => '<h2>Температура применения</h2><p>Проверьте маркировку на упаковке.</p>',
            'faq' => [['question' => 'Можно ли разбавлять?', 'answer' => 'Следуйте инструкции изготовителя.']],
            'product_ids' => [1], 'category_ids' => [1],
            'canonical_url' => 'https://autohimiki.kz/kak-vybrat-nezamerzayku-zimoy',
            'target_queries' => ['как выбрать незамерзайку'],
            'internal_links' => [['url' => 'https://autohimiki.kz/catalog/test-winter-fluids', 'anchor' => 'Каталог']],
        ], $changes);
    }

    private function headers(string $key = 'test-create-12345'): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN, 'Idempotency-Key' => $key];
    }

    public function test_draft_round_trip_is_idempotent_and_does_not_change_commerce(): void
    {
        $before = (array) DB::table('products')->first();
        $response = $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), $this->headers())->assertCreated()->assertJson(['status' => 'draft']);
        $id = $response->json('id');
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), $this->headers())->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJson(['id' => $id]);
        $this->assertDatabaseCount('seo_pages', 1);
        $this->assertDatabaseHas('seo_pages', ['id' => $id, 'is_active' => false]);
        $this->assertDatabaseCount('seo_manager_audit_logs', 1);
        $this->assertSame($before, (array) DB::table('products')->first());
        $this->getJson('/api/seo-manager/v1/catalog/pages', $this->headers())->assertOk()->assertJsonPath('data.0.status', 'draft');
        $this->get('/'.$this->payload()['slug'])->assertNotFound();
    }

    public function test_reused_key_with_changed_body_is_rejected(): void
    {
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), $this->headers())->assertCreated();
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(['h1' => 'Changed']), $this->headers())->assertConflict();
    }

    public function test_auth_scopes_https_and_input_boundaries(): void
    {
        $this->getJson('/api/seo-manager/v1/catalog/products')->assertUnauthorized();
        $this->getJson('/api/seo-manager/v1/status', $this->headers())
            ->assertOk()
            ->assertJson(['status' => 'ready', 'api_version' => 1, 'drafts_write' => true]);
        $catalog = $this->getJson('/api/seo-manager/v1/catalog/products', $this->headers())->assertOk();
        $cacheControl = (string) $catalog->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        config(['seo_manager.read_token_hash' => hash('sha256', str_repeat('r', 48))]);
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), ['Authorization' => 'Bearer '.str_repeat('r', 48)])->assertForbidden();
        config(['seo_manager.allow_local_http' => false]);
        $this->getJson('http://localhost/api/seo-manager/v1/catalog/products', $this->headers())->assertForbidden();
        config(['seo_manager.allow_local_http' => true]);
        foreach (['price', 'quantity', 'sku', 'is_active', 'orders', 'is_indexed'] as $forbidden) {
            $this->postJson('/api/seo-manager/v1/drafts', $this->payload([$forbidden => 1]), $this->headers('forbidden-'.$forbidden))->assertUnprocessable();
        }
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(['product_ids' => [9999]]), $this->headers())->assertUnprocessable();
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(['canonical_url' => 'https://evil.example/']), $this->headers())->assertUnprocessable();
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), ['Authorization' => 'Bearer '.self::TOKEN])->assertUnprocessable();
        config(['seo_manager.write_token_hash' => str_repeat('z', 64), 'seo_manager.read_token_hash' => '']);
        $this->getJson('/api/seo-manager/v1/catalog/products', $this->headers())->assertUnauthorized();
    }

    public function test_update_draft_and_reject_published_record(): void
    {
        $id = $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), $this->headers())->json('id');
        $this->putJson('/api/seo-manager/v1/drafts/'.$id, $this->payload(['h1' => 'Updated title']), $this->headers('update-12345'))->assertCreated();
        SeoPage::findOrFail($id)->update(['is_active' => true]);
        $this->putJson('/api/seo-manager/v1/drafts/'.$id, $this->payload(), $this->headers('update-23456'))->assertConflict();
    }

    public function test_filter_matches_real_attributes_and_rejects_duplicate_or_empty(): void
    {
        $filter = $this->payload(['kind' => 'filter', 'filter_criteria' => ['category_id' => 1, 'attributes' => ['temperature' => '-30']]]);
        $this->postJson('/api/seo-manager/v1/drafts', $filter, $this->headers())->assertCreated()->assertJson(['kind' => 'filter']);
        $duplicate = array_replace($filter, ['title' => 'Duplicate filter', 'slug' => 'duplicate-filter']);
        $this->postJson('/api/seo-manager/v1/drafts', $duplicate, $this->headers('duplicate-123'))->assertUnprocessable();
        $empty = array_replace($duplicate, ['filter_criteria' => ['category_id' => 1, 'attributes' => ['temperature' => '-99']]]);
        $this->postJson('/api/seo-manager/v1/drafts', $empty, $this->headers('empty-12345'))->assertUnprocessable();
    }

    public function test_html_is_sanitized_and_proposals_do_not_update_products(): void
    {
        $html = '<h2>Safe</h2><script>alert(1)</script><a href="javascript:alert(1)" onclick="bad()">Link</a>';
        $this->postJson('/api/seo-manager/v1/drafts', $this->payload(['seo_text' => $html]), $this->headers())->assertCreated();
        $saved = SeoPage::firstOrFail()->seo_text;
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('javascript:', $saved);
        $this->assertStringNotContainsString('onclick', $saved);
        $before = (array) DB::table('products')->first();
        $proposal = ['target_type' => 'product', 'target_id' => 1, 'proposal' => ['meta_title' => 'Proposed title', 'reason' => 'Low CTR in GSC']];
        $this->postJson('/api/seo-manager/v1/proposals', $proposal, $this->headers('proposal-123'))->assertCreated();
        $this->postJson('/api/seo-manager/v1/proposals', $proposal, $this->headers('proposal-123'))->assertCreated();
        $this->assertDatabaseCount('seo_manager_proposals', 1);
        $this->assertSame($before, (array) DB::table('products')->first());
    }

    public function test_draft_is_visible_in_existing_filament_editor(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->rememberToken();
            $table->timestamps();
        });
        $user = User::create(['name' => 'Test admin', 'email' => 'test@example.invalid', 'password' => 'test-only-password', 'role' => 'admin']);
        $id = $this->postJson('/api/seo-manager/v1/drafts', $this->payload(), $this->headers())->json('id');
        $this->actingAs($user)->get('/admin/seo-pages/'.$id.'/edit')->assertOk()->assertSee('SEO');
        $this->actingAs($user)->get('/admin/seo-manager-proposals')->assertOk();
    }

    public function test_filter_publication_and_empty_result_indexing(): void
    {
        $filter = $this->payload(['kind' => 'filter', 'filter_criteria' => ['category_id' => 1, 'attributes' => ['temperature' => '-30']]]);
        $id = $this->postJson('/api/seo-manager/v1/drafts', $filter, $this->headers())->json('id');
        $this->get('/'.$filter['slug'])->assertNotFound();
        SeoPage::findOrFail($id)->update(['is_active' => true]); // Simulate deliberate manual publication only inside test.
        $this->get('/'.$filter['slug'].'?page=2')->assertOk()->assertSee('noindex, follow', false)->assertSee('?page=2', false);
        DB::table('products')->where('id', 1)->update(['in_stock' => false]);
        Cache::flush();
        $this->get('/'.$filter['slug'])->assertOk()->assertSee('noindex, follow', false);
        $this->get('/sitemap-seo-pages.xml')->assertOk()->assertDontSee('/'.$filter['slug']);
    }
}
