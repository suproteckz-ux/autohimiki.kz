<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProductsTest extends TestCase
{
    protected User $admin;

    protected Category $firstCategory;

    protected Category $secondCategory;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('manager');
            $table->rememberToken();
            $table->timestamps();
        });

        $this->runMigration('2025_01_001_create_categories_table.php');
        $this->runMigration('2025_01_002_create_brands_table.php');
        $this->runMigration('2025_01_003_create_products_table.php');
        $this->runMigration('2025_01_004_create_product_images_table.php');

        $this->admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $this->firstCategory = Category::query()->create(['name' => 'First', 'slug' => 'first']);
        $this->secondCategory = Category::query()->create(['name' => 'Second', 'slug' => 'second']);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_admin_products_page_loads(): void
    {
        $product = $this->product();

        $this->get('/admin/products')->assertOk()->assertSee('Товары');
        $this->get('/admin/products/create')->assertOk()->assertSee('Создать');
        $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee($product->name);
    }

    public function test_reference_layout_columns_actions_and_bulk_actions_are_present(): void
    {
        $product = $this->product();

        Livewire::test(ListProducts::class)
            ->assertTableColumnExists('product_summary', fn ($column): bool => $column instanceof ViewColumn)
            ->assertSeeHtml('class="fi-product-summary"')
            ->assertTableColumnExists('category_id', fn ($column): bool => $column instanceof SelectColumn)
            ->assertTableColumnExists('price', fn ($column): bool => $column instanceof TextInputColumn)
            ->assertTableColumnExists('quantity', fn ($column): bool => $column instanceof TextInputColumn)
            ->assertTableColumnExists('in_stock', fn ($column): bool => $column instanceof ToggleColumn)
            ->assertTableColumnExists('is_active', fn ($column): bool => $column instanceof ToggleColumn)
            ->assertTableColumnExists('is_hit', fn ($column): bool => $column instanceof ToggleColumn)
            ->assertTableActionExists('edit')
            ->assertTableActionExists('storefront')
            ->assertTableActionExists('delete')
            ->assertTableBulkActionExists('changeCategory')
            ->assertTableBulkActionExists('activate')
            ->assertTableBulkActionExists('deactivate')
            ->assertTableBulkActionExists('delete')
            ->assertActionExists('create')
            ->assertSee($product->sku);
    }

    public function test_inline_category_change_updates_only_category(): void
    {
        $product = $this->product();
        $before = $product->only($this->protectedFields(['category_id']));

        Livewire::test(ListProducts::class)
            ->call('updateTableColumnState', 'category_id', (string) $product->id, $this->secondCategory->id)
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame($this->secondCategory->id, $product->category_id);
        $this->assertSame($before, $product->only($this->protectedFields(['category_id'])));
    }

    public function test_bulk_category_changes_selected_products_only_and_preserves_commercial_fields(): void
    {
        [$first, $second, $unrelated] = [$this->product('one'), $this->product('two'), $this->product('three')];
        $snapshots = $this->snapshots([$first, $second, $unrelated], ['category_id']);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('changeCategory', [$first, $second], ['category_id' => $this->secondCategory->id])
            ->assertHasNoErrors();

        $this->assertSame($this->secondCategory->id, $first->fresh()->category_id);
        $this->assertSame($this->secondCategory->id, $second->fresh()->category_id);
        $this->assertSame($this->firstCategory->id, $unrelated->fresh()->category_id);
        $this->assertProtectedFieldsUnchanged($snapshots, ['category_id']);
    }

    public function test_bulk_activate_and_deactivate_touch_only_selected_products(): void
    {
        $first = $this->product('one', false);
        $second = $this->product('two', false);
        $unrelated = $this->product('three', false);
        $snapshots = $this->snapshots([$first, $second, $unrelated], ['is_active']);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('activate', [$first, $second])
            ->assertHasNoErrors();

        $this->assertTrue($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertFalse($unrelated->fresh()->is_active);
        $this->assertProtectedFieldsUnchanged($snapshots, ['is_active']);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('deactivate', [$first])
            ->assertHasNoErrors();

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertFalse($unrelated->fresh()->is_active);
    }

    public function test_bulk_delete_requires_confirmation_and_deletes_only_selected_records(): void
    {
        $selected = $this->product('selected');
        $unrelated = $this->product('unrelated');
        $selected->images()->create(['path' => 'products/selected.jpg']);

        $component = Livewire::test(ListProducts::class);
        $deleteAction = $component->instance()->getTable()->getBulkAction('delete');
        $this->assertTrue($deleteAction->isConfirmationRequired());
        $this->assertSame('Удалить выбранные товары?', $deleteAction->getModalHeading());
        $this->assertDatabaseHas('products', ['id' => $selected->id]);
        $component->callTableBulkAction('delete', [$selected])
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('products', ['id' => $selected->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $selected->id]);
        $this->assertDatabaseHas('products', ['id' => $unrelated->id]);
    }

    public function test_manager_can_manage_products_but_cannot_bulk_delete(): void
    {
        $product = $this->product();
        $manager = User::query()->create([
            'name' => 'Manager',
            'email' => 'manager@example.test',
            'password' => 'password',
            'role' => 'manager',
        ]);

        $this->actingAs($manager);

        $this->get('/admin/products')->assertOk();
        Livewire::test(ListProducts::class)
            ->assertTableBulkActionExists('changeCategory')
            ->assertTableBulkActionHidden('delete')
            ->assertTableActionVisible('edit', $product)
            ->assertTableActionHidden('delete', $product);
    }

    private function runMigration(string $file): void
    {
        (require database_path('migrations/'.$file))->up();
    }

    private function product(string $sku = 'sku', bool $active = true): Product
    {
        return Product::query()->create([
            'category_id' => $this->firstCategory->id,
            'name' => 'Product '.$sku,
            'slug' => 'product-'.$sku,
            'sku' => $sku,
            'price' => 3000,
            'quantity' => 2,
            'in_stock' => true,
            'is_active' => $active,
            'is_hit' => true,
            'description' => 'Manual content',
            'main_image' => 'products/manual.jpg',
            'meta_title' => 'Manual SEO',
        ]);
    }

    private function protectedFields(array $except = []): array
    {
        return array_values(array_diff([
            'price', 'quantity', 'in_stock', 'category_id', 'is_active', 'is_hit',
            'description', 'main_image', 'meta_title',
        ], $except));
    }

    private function snapshots(array $products, array $except = []): array
    {
        return collect($products)->mapWithKeys(fn (Product $product): array => [
            $product->id => $product->only($this->protectedFields($except)),
        ])->all();
    }

    private function assertProtectedFieldsUnchanged(array $snapshots, array $except = []): void
    {
        foreach ($snapshots as $id => $snapshot) {
            $this->assertSame($snapshot, Product::query()->findOrFail($id)->only($this->protectedFields($except)));
        }
    }
}
