<?php

namespace Tests\Feature;

use App\Filament\Pages\OzonDashboard;
use App\Models\User;
use App\Services\Ozon\OzonAdminSettings;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class OzonCategoryPickerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->rememberToken();
            $table->timestamps();
        });
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php', '2025_01_013_create_settings_table.php',
            '2026_09_23_000001_create_ozon_export_tables.php', '2026_09_23_000002_add_ozon_status_details.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@picker.test', 'password' => 'password', 'role' => 'admin']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['app.url' => 'https://autohimiki.kz', 'ozon.enabled' => false,
            'ozon.api_key' => 'pick-secret-key', 'ozon.client_id' => 'pick-client-id',
            'ozon.warehouse_id' => 1020005000312240]);
        Http::preventStrayRequests();
    }

    private function fakeTree(array $result = []): void
    {
        Http::fake(['*/v1/description-category/tree' => Http::response(['result' => $result])]);
    }

    private function sampleTree(): array
    {
        return [
            [
                'description_category_id' => 17028752,
                'category_name' => 'Автохимия и автокосметика',
                'type' => [],
                'children' => [
                    [
                        'description_category_id' => 17028753,
                        'category_name' => 'Очистители салона',
                        'type' => [
                            ['type_id' => 97176, 'type_name' => 'Очистители салона'],
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ];
    }

    // Test 1: Dashboard показывает оба режима.
    public function test_dashboard_shows_both_picker_modes(): void
    {
        Http::fake();
        Livewire::test(OzonDashboard::class)
            ->assertSee('Выбрать из Ozon')
            ->assertSee('Ввести вручную');
    }

    // Test 2: categoryTree вызывается ровно один раз при loadCategoryOptions.
    public function test_category_tree_called_exactly_once_when_loading_options(): void
    {
        $this->fakeTree($this->sampleTree());
        Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/description-category/tree') && $r->method() === 'POST');
    }

    // Test 3: tree нигде не сохраняется в БД.
    public function test_tree_is_not_persisted_to_database(): void
    {
        $this->fakeTree($this->sampleTree());
        Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        $settingsJson = DB::table('settings')->where('key', 'ozon_admin')->value('value') ?? '{}';
        $this->assertStringNotContainsString('category_name', $settingsJson);
        $this->assertStringNotContainsString('children', $settingsJson);
        $this->assertStringNotContainsString('type_name', $settingsJson);
        $this->assertStringNotContainsString('17028752', $settingsJson);
    }

    // Test 4: leaf type с type_id > 0 превращается в option; type_id = 0 отбрасывается.
    public function test_only_leaf_types_with_positive_type_id_become_options(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 100,
                'category_name' => 'Category',
                'type' => [
                    ['type_id' => 200, 'type_name' => 'Active Type'],
                    ['type_id' => 0, 'type_name' => 'Zero Type'],
                ],
                'children' => [],
            ],
        ]);
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDispatched('ozon-category-pairs', function ($name, $params) {
                $pairs = $params['pairs'] ?? [];
                $count = $params['count'] ?? 0;
                return isset($pairs['100|200'])
                    && ! isset($pairs['100|0'])
                    && count($pairs) === 1
                    && $count === 1;
            });
    }

    // Test 5: type leaf наследует description_category_id родителя когда ключ отсутствует.
    // walkForPairs() falls back via ?? only when the key is absent (null), not when value is 0.
    public function test_type_inherits_parent_description_category_id_when_absent(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 100,
                'category_name' => 'Parent',
                'type' => [],
                'children' => [
                    [
                        // no description_category_id key → null → inherits 100 from parent
                        'category_name' => 'Child',
                        'type' => [['type_id' => 300, 'type_name' => 'Child Type']],
                        'children' => [],
                    ],
                ],
            ],
        ]);
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDispatched('ozon-category-pairs', function ($name, $params) {
                $pairs = $params['pairs'] ?? [];
                return isset($pairs['100|300']) && ! isset($pairs['0|300']);
            });
    }

    // Test 6: label формируется как «category_name — type_name».
    public function test_option_label_is_category_name_dash_type_name(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 17028752,
                'category_name' => 'Автохимия',
                'type' => [['type_id' => 97176, 'type_name' => 'Очистители салона']],
                'children' => [],
            ],
        ]);
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDispatched('ozon-category-pairs', function ($name, $params) {
                $pairs = $params['pairs'] ?? [];
                return ($pairs['17028752|97176'] ?? null) === 'Автохимия — Очистители салона';
            });
    }

    // Test 7: option value — «description_category_id|type_id».
    public function test_option_value_is_category_id_pipe_type_id(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 17028752,
                'category_name' => 'Автохимия',
                'type' => [['type_id' => 97176, 'type_name' => 'Очистители салона']],
                'children' => [],
            ],
        ]);
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDispatched('ozon-category-pairs', function ($name, $params) {
                $pairs = $params['pairs'] ?? [];
                if (! isset($pairs['17028752|97176'])) {
                    return false;
                }
                foreach (array_keys($pairs) as $key) {
                    if (! preg_match('/^\d+\|\d+$/', $key)) {
                        return false;
                    }
                }

                return true;
            });
    }

    // Test 8: Alpine picker label и кнопка загрузки отображаются.
    public function test_category_picker_label_and_load_button_are_rendered(): void
    {
        $this->fakeTree($this->sampleTree());
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertSee('Категория и тип Ozon')
            ->assertSee('Загрузить список из Ozon');
    }

    // Test 9: manual mode показывает оба поля ввода ID.
    public function test_manual_mode_shows_both_id_input_fields(): void
    {
        Http::fake();
        Livewire::test(OzonDashboard::class)
            ->set('categoryFormState.taxonomyMode', 'manual')
            ->assertSee('description_category_id')
            ->assertSee('type_id');
    }

    // Test 10: выбор пары сохраняет только description_category_id и type_id.
    public function test_selecting_option_saves_only_the_two_ids(): void
    {
        app(OzonAdminSettings::class)->saveCategory(1, 1); // ensure settings row exists
        $this->fakeTree($this->sampleTree());
        // Simulate what Alpine does: $wire.set('categoryFormState.categoryTypeKey', key)
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->set('categoryFormState.taxonomyMode', 'taxonomy')
            ->set('categoryFormState.categoryTypeKey', '17028753|97176')
            ->call('saveCategory')
            ->assertHasNoErrors();
        $settings = app(OzonAdminSettings::class)->read();
        $this->assertSame(17028753, $settings['description_category_id']);
        $this->assertSame(97176, $settings['type_id']);
        $this->assertArrayNotHasKey('category_name', $settings);
        $this->assertArrayNotHasKey('type_name', $settings);
        $this->assertArrayNotHasKey('tree', $settings);
        $this->assertArrayNotHasKey('children', $settings);
    }

    // Test 11: API error не ломает страницу — показывает уведомление об ошибке.
    public function test_api_error_shows_notification_and_does_not_crash(): void
    {
        Http::fake(['*/v1/description-category/tree' => Http::response(['message' => 'Server Error'], 500)]);
        $component = Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        $this->assertSame([], $component->get('categoryTypeOptions'));
        $this->assertSame(0, $component->get('categoryTypePairsCount'));
        $component->assertNotDispatched('ozon-category-pairs');
    }

    // Test 12: секреты не выводятся в HTML страницы.
    public function test_api_credentials_are_not_exposed_in_rendered_output(): void
    {
        $this->fakeTree($this->sampleTree());
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDontSee('pick-secret-key')
            ->assertDontSee('pick-client-id');
    }

    // Test 13: taxonomy tables не создаются; сохраняются только два поля.
    public function test_taxonomy_tables_are_not_created_and_category_mappings_stay_empty(): void
    {
        $this->fakeTree($this->sampleTree());
        Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        $this->assertFalse(Schema::hasTable('ozon_taxonomy_nodes'));
        $this->assertFalse(Schema::hasTable('ozon_taxonomy_categories'));
        $this->assertFalse(Schema::hasTable('ozon_category_tree'));
        $this->assertSame(0, DB::table('ozon_category_mappings')->count());
    }

    // Test 14: real Ozon direct leaf format — type_id/type_name on node, not in type array.
    public function test_direct_leaf_format_produces_correct_options(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 17028752,
                'category_name' => 'Автохимия и автокосметика',
                'disabled' => false,
                'type_id' => 0,
                'children' => [
                    [
                        'description_category_id' => 200001176,
                        'category_name' => 'Очистители салона',
                        'type_id' => 97176,
                        'type_name' => 'Очистители салона',
                        'disabled' => false,
                        'children' => [],
                    ],
                ],
            ],
        ]);
        $component = Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        // Livewire snapshot must stay lean — pairs go to the browser, not the snapshot.
        $this->assertSame([], $component->get('categoryTypeOptions'));
        $this->assertGreaterThan(0, $component->get('categoryTypePairsCount'));
        $component->assertDispatched('ozon-category-pairs', function ($name, $params) {
            $pairs = $params['pairs'] ?? [];
            return isset($pairs['200001176|97176'])
                && $pairs['200001176|97176'] === 'Очистители салона — Очистители салона'
                && preg_match('/^\d+\|\d+$/', '200001176|97176');
        });
    }

    // Test 15: direct leaf inherits parent description_category_id when key absent.
    public function test_direct_leaf_inherits_parent_id_when_absent(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 17028752,
                'category_name' => 'Автохимия и автокосметика',
                'disabled' => false,
                'type_id' => 0,
                'children' => [
                    [
                        // no description_category_id — inherits 17028752 from parent
                        'type_id' => 97177,
                        'type_name' => 'Другой тип',
                        'disabled' => false,
                        'children' => [],
                    ],
                ],
            ],
        ]);
        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDispatched('ozon-category-pairs', function ($name, $params) {
                $pairs = $params['pairs'] ?? [];
                return isset($pairs['17028752|97177'])
                    && str_contains($pairs['17028752|97177'], 'Автохимия и автокосметика');
            });
    }

    // Test 16: direct leaf — tree is not written to DB or settings.
    public function test_direct_leaf_tree_not_stored_in_database(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 200001176,
                'category_name' => 'Очистители салона',
                'type_id' => 97176,
                'type_name' => 'Очистители салона',
                'disabled' => false,
                'children' => [],
            ],
        ]);
        Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        $settingsJson = DB::table('settings')->where('key', 'ozon_admin')->value('value') ?? '{}';
        $this->assertStringNotContainsString('category_name', $settingsJson);
        $this->assertStringNotContainsString('type_name', $settingsJson);
        $this->assertStringNotContainsString('200001176', $settingsJson);
    }

    // Test 17: SESSION_DRIVER=file by default — pairs must never be written to session.
    public function test_session_never_stores_pairs_after_load(): void
    {
        $this->fakeTree($this->sampleTree());
        Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');
        // SESSION_DRIVER defaults to 'file' (confirmed: key absent from .env).
        // Writing pairs here would persist them to storage/framework/sessions on disk.
        $this->assertNull(session('ozon_category_pairs'),
            'Pairs must not be stored in session (SESSION_DRIVER=file writes to disk)');
    }

    // Test 18: production-scale synthetic tree — Livewire snapshot stays lean.
    public function test_large_synthetic_tree_does_not_bloat_livewire_state(): void
    {
        // 500 direct-leaf nodes → 500 pairs; simulates production-scale Ozon tree slice.
        $nodes = [];
        for ($i = 1; $i <= 500; $i++) {
            $nodes[] = [
                'description_category_id' => 1000000 + $i,
                'category_name' => "Category {$i}",
                'type_id' => $i,
                'type_name' => "Type {$i}",
                'disabled' => false,
                'children' => [],
            ];
        }
        $this->fakeTree($nodes);
        $component = Livewire::test(OzonDashboard::class)->call('loadCategoryOptions');

        // Livewire snapshot carries only the integer count, not the full array.
        $this->assertSame([], $component->get('categoryTypeOptions'));
        $this->assertSame(500, $component->get('categoryTypePairsCount'));

        // Session must remain empty — pairs go to the browser event, not disk.
        $this->assertNull(session('ozon_category_pairs'));

        // All 500 pairs arrive in the browser event.
        $component->assertDispatched('ozon-category-pairs', function ($name, $params) {
            $pairs = $params['pairs'] ?? [];
            $count = $params['count'] ?? 0;
            return $count === 500
                && isset($pairs['1000001|1'])
                && isset($pairs['1000500|500']);
        });
    }

    // Test 19: one API call; pairs dispatched to browser; no extra Ozon requests.
    public function test_pairs_dispatched_to_browser_no_extra_api_calls(): void
    {
        $this->fakeTree([
            [
                'description_category_id' => 200001176,
                'category_name' => 'Очистители салона',
                'type_id' => 97176,
                'type_name' => 'Очистители салона',
                'disabled' => false,
                'children' => [],
            ],
            [
                'description_category_id' => 200001177,
                'category_name' => 'Полироли',
                'type_id' => 97177,
                'type_name' => 'Полироли кузова',
                'disabled' => false,
                'children' => [],
            ],
        ]);

        Livewire::test(OzonDashboard::class)
            ->call('loadCategoryOptions')
            ->assertDispatched('ozon-category-pairs', function ($name, $params) {
                $pairs = $params['pairs'] ?? [];
                $count = $params['count'] ?? 0;
                return $count === 2
                    && ($pairs['200001176|97176'] ?? null) === 'Очистители салона — Очистители салона'
                    && ($pairs['200001177|97177'] ?? null) === 'Полироли — Полироли кузова';
            });

        Http::assertSentCount(1); // exactly one Ozon API call total
        $this->assertNull(session('ozon_category_pairs')); // nothing on disk
    }
}
