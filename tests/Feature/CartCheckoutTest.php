<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CacheService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    private Product $product;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2025_01_001_create_categories_table.php', '2025_01_002_create_brands_table.php',
            '2025_01_003_create_products_table.php', '2025_01_004_create_product_images_table.php',
            '2025_01_012_create_redirects_table.php', '2025_01_013_create_settings_table.php',
            '2026_09_23_000001_create_orders_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->rememberToken();
            $table->timestamps();
        });
        $category = Category::create(['name' => 'Автохимия', 'slug' => 'care']);
        $this->product = Product::create(['category_id' => $category->id, 'name' => 'Шампунь', 'slug' => 'shampoo',
            'sku' => '00123', 'price' => '1234.56', 'quantity' => 5, 'is_active' => true]);
        $this->token = (string) Str::uuid();
        Cache::put(CacheService::KEY_SETTINGS, ['whatsapp' => '77001234567']);
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        parent::tearDown();
    }

    private function basket(int $quantity = 2): static
    {
        return $this->withSession(['cart' => [$this->product->id => $quantity], 'checkout_token' => $this->token]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Антон', 'phone' => '+7 (700) 123-45-67', 'city' => 'Алматы',
            'delivery_method' => 'pickup', 'consent' => '1', 'checkout_token' => $this->token], $changes);
    }

    public function test_add_and_repeat_add_without_reserving_stock(): void
    {
        $this->from($this->product->url)->post('/cart', ['product_id' => $this->product->id])
            ->assertRedirect($this->product->url)->assertSessionHas('cart.'.$this->product->id, 1);
        $this->post('/cart', ['product_id' => $this->product->id])->assertSessionHas('cart.'.$this->product->id, 2);
        $this->assertEquals(5, $this->product->fresh()->quantity);
        $this->get($this->product->url)->assertOk()->assertSee('(2)');
    }

    public function test_cannot_add_more_than_stock(): void
    {
        $this->basket(5)->post('/cart', ['product_id' => $this->product->id])->assertSessionHasErrors('cart');
        $this->assertEquals(5, session('cart.'.$this->product->id));
    }

    #[DataProvider('unavailableProducts')]
    public function test_unavailable_product_cannot_be_added(array $changes): void
    {
        Product::whereKey($this->product->id)->update($changes);
        $this->post('/cart', ['product_id' => $this->product->id])->assertSessionHasErrors('cart');
        $this->assertEmpty(session('cart'));
    }

    public static function unavailableProducts(): array
    {
        return [['changes' => ['quantity' => 0]], ['changes' => ['is_active' => false]]];
    }

    public function test_quantity_update_and_remove(): void
    {
        $this->basket()->patch('/cart/'.$this->product->id, ['quantity' => 3])->assertRedirect('/cart')->assertSessionHas('cart.'.$this->product->id, 3);
        $this->delete('/cart/'.$this->product->id)->assertRedirect('/cart')->assertSessionMissing('cart.'.$this->product->id);
        $this->get('/cart')->assertOk()->assertSee('Ваша корзина пока пуста.');
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantity_validation($quantity): void
    {
        $this->basket()->patch('/cart/'.$this->product->id, ['quantity' => $quantity])->assertSessionHasErrors();
        $this->assertSame(2, session('cart.'.$this->product->id));
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [6], ['1.5'], ['abc']];
    }

    public function test_cart_subtotals_and_total_use_current_database_price(): void
    {
        $other = Product::create(['category_id' => $this->product->category_id, 'name' => 'Воск', 'slug' => 'wax', 'sku' => 'WAX', 'price' => '10.01', 'quantity' => 10]);
        $this->withSession(['cart' => [$this->product->id => 2, $other->id => 3]])->get('/cart')
            ->assertOk()->assertViewHas('total', 249915)->assertViewHas('items', fn ($items) => $items[0]['subtotal'] === 246912 && $items[1]['subtotal'] === 3003)
            ->assertSee('2 499.15')->assertSee('00123');
    }

    public function test_guest_checkout_stores_snapshots_clears_cart_and_confirms(): void
    {
        $this->basket()->get('/checkout')->assertOk()->assertSee('Цены указаны с НДС.');
        $this->post('/checkout', $this->payload(['price' => 1, 'total' => 1, 'status' => 'completed']))
            ->assertRedirect('/checkout/success')->assertSessionMissing('cart');
        $order = Order::firstOrFail();
        $this->assertSame('2469.12', $order->total);
        $this->assertSame('new', $order->status);
        $this->assertSame('+77001234567', $order->phone);
        $this->assertNotNull($order->consented_at);
        $item = $order->items()->firstOrFail();
        $this->assertSame('00123', $item->sku);
        $this->assertSame('Шампунь', $item->name);
        $this->assertSame('1234.56', $item->price);
        $this->assertSame('2469.12', $item->subtotal);
        $this->assertEquals(5, $this->product->fresh()->quantity);
        Product::whereKey($this->product->id)->update(['price' => '999.99', 'name' => 'Новое название']);
        $this->assertSame('1234.56', $item->fresh()->price);
        $this->get('/checkout/success')->assertOk()->assertSee('Заказ №'.$order->id.' принят.');
        $this->post('/checkout', $this->payload())->assertRedirect('/checkout/success');
        $this->assertSame(1, Order::count());
    }

    #[DataProvider('invalidCheckout')]
    public function test_checkout_validation(string $field, $value): void
    {
        $this->basket()->post('/checkout', $this->payload([$field => $value]))->assertSessionHasErrors($field);
        $this->assertSame(0, Order::count());
        $this->assertNotEmpty(session('cart'));
    }

    public static function invalidCheckout(): array
    {
        return [['name', ''], ['name', 'А'], ['phone', ''], ['phone', 'hello'], ['phone', '123'], ['phone', '0000000000'],
            ['city', ''], ['delivery_method', ''], ['delivery_method', 'invalid'], ['consent', '0']];
    }

    #[DataProvider('deliveryMethods')]
    public function test_delivery_requires_address(string $method): void
    {
        $this->basket()->post('/checkout', $this->payload(['delivery_method' => $method]))->assertSessionHasErrors('address');
        $this->post('/checkout', $this->payload(['delivery_method' => $method, 'address' => 'ул. Тестовая, 12']))->assertRedirect('/checkout/success');
        $this->assertDatabaseHas('orders', ['delivery_method' => $method, 'address' => 'ул. Тестовая, 12', 'total' => '2469.12']);
    }

    public static function deliveryMethods(): array
    {
        return [['yandex'], ['kazpost']];
    }

    #[DataProvider('stockChanges')]
    public function test_checkout_rechecks_stock_and_active_state(array $changes): void
    {
        $this->basket()->get('/checkout')->assertOk();
        Product::whereKey($this->product->id)->update($changes);
        $this->post('/checkout', $this->payload())->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
        $this->get('/cart')->assertOk()->assertSee('остаток изменился')->assertViewHas('available', false);
    }

    public static function stockChanges(): array
    {
        return [[['quantity' => 1]], [['quantity' => 0]], [['is_active' => false]]];
    }

    public function test_deleted_product_blocks_checkout_and_can_be_removed(): void
    {
        $this->basket();
        $this->product->delete();
        $this->post('/checkout', $this->payload())->assertSessionHasErrors('cart');
        $this->get('/cart')->assertOk()->assertSee('Товар удалён');
        $this->delete('/cart/'.$this->product->id)->assertRedirect('/cart');
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_uses_price_changed_after_form_was_opened(): void
    {
        $this->basket()->get('/checkout')->assertOk();
        Product::whereKey($this->product->id)->update(['price' => '10.25']);
        $this->post('/checkout', $this->payload(['price' => '0.01']))->assertRedirect('/checkout/success');
        $this->assertDatabaseHas('order_items', ['price' => '10.25', 'subtotal' => '20.50']);
        $this->assertDatabaseHas('orders', ['total' => '20.50']);
    }

    public function test_empty_cart_and_foreign_confirmation_are_not_accessible(): void
    {
        $this->get('/checkout')->assertRedirect('/cart');
        $this->withSession(['checkout_token' => $this->token])->post('/checkout', $this->payload())->assertSessionHasErrors('cart');
        $this->get('/checkout/success')->assertNotFound();
    }

    public function test_checkout_token_is_required_and_bound_to_session(): void
    {
        $this->basket()->post('/checkout', $this->payload(['checkout_token' => (string) Str::uuid()]))->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_product_keeps_whatsapp_and_replaces_callback(): void
    {
        $this->get($this->product->url)->assertOk()->assertSee('Купить через WhatsApp')->assertSee('Добавить в корзину')->assertDontSee('Заказать звонок')
            ->assertSee('https://wa.me/77001234567?text='.urlencode('Хочу заказать: '.$this->product->name.' - '.$this->product->url), false);
        Product::whereKey($this->product->id)->update(['quantity' => 0]);
        $this->get($this->product->url)->assertOk()->assertSee('Нет в наличии')->assertSee('disabled', false);
    }

    public function test_admin_can_see_order_and_change_only_status(): void
    {
        $this->basket()->post('/checkout', $this->payload())->assertRedirect('/checkout/success');
        $order = Order::firstOrFail();
        $this->get('/admin/orders')->assertRedirect('/admin/login');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'admin']);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/orders')->assertOk()->assertSee('Заказы');
        $this->get('/admin/orders/'.$order->id.'/edit')->assertOk()->assertSee('00123')->assertSee('Шампунь');
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order]);
        Livewire::test(EditOrder::class, ['record' => $order->id])->fillForm(['status' => 'confirmed'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('2469.12', $order->fresh()->total);
    }

    public function test_order_migration_can_rollback_and_run_again(): void
    {
        $migration = require database_path('migrations/2026_09_23_000001_create_orders_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('order_items'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_items'));
    }
}
