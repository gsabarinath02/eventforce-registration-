<?php

declare(strict_types=1);

namespace Tests\Feature\RegressionTests;

use HiEvents\DomainObjects\Enums\OrderStatus;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that all existing functionality works after Razorpay integration
     */
    public function testCompleteApplicationFunctionalityAfterRazorpayIntegration(): void
    {
        // 1. Test User and Account Creation
        $user = User::factory()->withAccount()->create();
        $account = $user->accounts->first();
        
        $this->assertNotNull($user);
        $this->assertNotNull($account);
        $this->assertTrue($user->accounts->contains($account));

        // 2. Test Event Creation with All Payment Providers
        $event = Event::factory()->for($account)->create([
            'settings' => [
                'payment_providers' => [
                    PaymentProviders::STRIPE->value,
                    PaymentProviders::OFFLINE->value,
                    PaymentProviders::RAZORPAY->value
                ],
                'stripe_publishable_key' => 'pk_test_123',
                'stripe_secret_key' => 'sk_test_123',
                'razorpay_key_id' => 'rzp_test_123',
                'razorpay_key_secret' => 'test_secret',
            ]
        ]);

        $this->assertNotNull($event);
        $this->assertEquals($account->id, $event->account_id);
        $this->assertContains(PaymentProviders::STRIPE->value, $event->settings['payment_providers']);
        $this->assertContains(PaymentProviders::OFFLINE->value, $event->settings['payment_providers']);
        $this->assertContains(PaymentProviders::RAZORPAY->value, $event->settings['payment_providers']);

        // 3. Test Product and Pricing Creation
        $product = Product::factory()->for($event)->create();
        $productPrice = ProductPrice::factory()->for($product)->create([
            'price' => 5000,
        ]);

        $this->assertNotNull($product);
        $this->assertNotNull($productPrice);
        $this->assertEquals($event->id, $product->event_id);
        $this->assertEquals($product->id, $productPrice->product_id);

        // 4. Test Order Creation for Each Payment Provider
        
        // Stripe Order
        $stripeOrder = Order::factory()->for($event)->for($account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'total_gross' => 5000,
            'currency' => 'USD',
        ]);

        $this->assertEquals(PaymentProvider::STRIPE->value, $stripeOrder->payment_provider);
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $stripeOrder->status);

        // Offline Order
        $offlineOrder = Order::factory()->for($event)->for($account)->create([
            'payment_provider' => PaymentProvider::OFFLINE->value,
            'status' => OrderStatus::AWAITING_OFFLINE_PAYMENT->value,
            'total_gross' => 5000,
            'currency' => 'USD',
        ]);

        $this->assertEquals(PaymentProvider::OFFLINE->value, $offlineOrder->payment_provider);
        $this->assertEquals(OrderStatus::AWAITING_OFFLINE_PAYMENT->value, $offlineOrder->status);

        // Razorpay Order
        $razorpayOrder = Order::factory()->for($event)->for($account)->create([
            'payment_provider' => PaymentProvider::RAZORPAY->value,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'total_gross' => 5000,
            'currency' => 'INR',
        ]);

        $this->assertEquals(PaymentProvider::RAZORPAY->value, $razorpayOrder->payment_provider);
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $razorpayOrder->status);

        // 5. Test Order Status Transitions
        
        // Complete Stripe order
        $stripeOrder->update(['status' => OrderStatus::COMPLETED->value]);
        $this->assertEquals(OrderStatus::COMPLETED->value, $stripeOrder->fresh()->status);

        // Complete offline order
        $offlineOrder->update(['status' => OrderStatus::COMPLETED->value]);
        $this->assertEquals(OrderStatus::COMPLETED->value, $offlineOrder->fresh()->status);

        // Complete Razorpay order
        $razorpayOrder->update(['status' => OrderStatus::COMPLETED->value]);
        $this->assertEquals(OrderStatus::COMPLETED->value, $razorpayOrder->fresh()->status);

        // 6. Test Database Queries and Relationships
        
        // Test event->orders relationship
        $eventOrders = $event->orders;
        $this->assertCount(3, $eventOrders);
        $this->assertTrue($eventOrders->contains($stripeOrder));
        $this->assertTrue($eventOrders->contains($offlineOrder));
        $this->assertTrue($eventOrders->contains($razorpayOrder));

        // Test account->orders relationship
        $accountOrders = $account->orders;
        $this->assertCount(3, $accountOrders);

        // Test order->event relationship
        $this->assertEquals($event->id, $stripeOrder->event->id);
        $this->assertEquals($event->id, $offlineOrder->event->id);
        $this->assertEquals($event->id, $razorpayOrder->event->id);

        // 7. Test Payment Provider Filtering
        $stripeOrders = Order::where('payment_provider', PaymentProvider::STRIPE->value)->get();
        $offlineOrders = Order::where('payment_provider', PaymentProvider::OFFLINE->value)->get();
        $razorpayOrders = Order::where('payment_provider', PaymentProvider::RAZORPAY->value)->get();

        $this->assertCount(1, $stripeOrders);
        $this->assertCount(1, $offlineOrders);
        $this->assertCount(1, $razorpayOrders);

        // 8. Test Order Status Filtering
        $completedOrders = Order::where('status', OrderStatus::COMPLETED->value)->get();
        $this->assertCount(3, $completedOrders);

        // 9. Test Event Settings Updates
        $this->actingAs($user);

        // Update to only Stripe
        $response = $this->putJson(route('events.update-settings', $event->id), [
            'payment_providers' => ['STRIPE'],
            'stripe_publishable_key' => 'pk_test_updated',
            'stripe_secret_key' => 'sk_test_updated',
        ]);

        $response->assertStatus(200);
        $event->refresh();
        $this->assertEquals(['STRIPE'], $event->settings['payment_providers']);

        // Update to only Razorpay
        $response = $this->putJson(route('events.update-settings', $event->id), [
            'payment_providers' => ['RAZORPAY'],
            'razorpay_key_id' => 'rzp_test_updated',
            'razorpay_key_secret' => 'test_secret_updated',
        ]);

        $response->assertStatus(200);
        $event->refresh();
        $this->assertEquals(['RAZORPAY'], $event->settings['payment_providers']);

        // Update to all providers
        $response = $this->putJson(route('events.update-settings', $event->id), [
            'payment_providers' => ['STRIPE', 'OFFLINE', 'RAZORPAY'],
            'stripe_publishable_key' => 'pk_test_123',
            'stripe_secret_key' => 'sk_test_123',
            'razorpay_key_id' => 'rzp_test_123',
            'razorpay_key_secret' => 'test_secret',
            'offline_payment_instructions' => 'Bank transfer instructions',
        ]);

        $response->assertStatus(200);
        $event->refresh();
        $providers = $event->settings['payment_providers'];
        $this->assertContains('STRIPE', $providers);
        $this->assertContains('OFFLINE', $providers);
        $this->assertContains('RAZORPAY', $providers);

        // 10. Test API Endpoints Accessibility
        
        // Test public order retrieval
        $response = $this->getJson(route('orders.show-public', [
            'event_id' => $event->id,
            'order_short_id' => $stripeOrder->short_id,
        ]));
        $response->assertStatus(200);

        $response = $this->getJson(route('orders.show-public', [
            'event_id' => $event->id,
            'order_short_id' => $offlineOrder->short_id,
        ]));
        $response->assertStatus(200);

        $response = $this->getJson(route('orders.show-public', [
            'event_id' => $event->id,
            'order_short_id' => $razorpayOrder->short_id,
        ]));
        $response->assertStatus(200);

        // 11. Test Enum Functionality
        $allProviders = PaymentProvider::cases();
        $this->assertCount(3, $allProviders);
        
        $providerValues = array_map(fn($case) => $case->value, $allProviders);
        $this->assertContains('STRIPE', $providerValues);
        $this->assertContains('OFFLINE', $providerValues);
        $this->assertContains('RAZORPAY', $providerValues);

        // 12. Test Model Factories Still Work
        $newStripeOrder = Order::factory()->for($event)->for($account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
        ]);
        $this->assertNotNull($newStripeOrder);

        $newOfflineOrder = Order::factory()->for($event)->for($account)->create([
            'payment_provider' => PaymentProvider::OFFLINE->value,
        ]);
        $this->assertNotNull($newOfflineOrder);

        $newRazorpayOrder = Order::factory()->for($event)->for($account)->create([
            'payment_provider' => PaymentProvider::RAZORPAY->value,
        ]);
        $this->assertNotNull($newRazorpayOrder);

        // 13. Test Data Integrity
        $totalOrders = Order::count();
        $this->assertEquals(6, $totalOrders); // 3 original + 3 new

        $totalEvents = Event::count();
        $this->assertEquals(1, $totalEvents);

        $totalUsers = User::count();
        $this->assertEquals(1, $totalUsers);

        $totalAccounts = Account::count();
        $this->assertEquals(1, $totalAccounts);

        // 14. Test Complex Queries Still Work
        $completedOrdersForEvent = Order::where('event_id', $event->id)
            ->where('status', OrderStatus::COMPLETED->value)
            ->get();
        $this->assertCount(3, $completedOrdersForEvent);

        $stripeOrdersForAccount = Order::where('account_id', $account->id)
            ->where('payment_provider', PaymentProvider::STRIPE->value)
            ->get();
        $this->assertCount(2, $stripeOrdersForAccount);

        // 15. Test Soft Deletes (if applicable)
        $orderToDelete = $newStripeOrder;
        $orderToDelete->delete();
        
        $this->assertSoftDeleted($orderToDelete);
        $this->assertEquals(5, Order::count()); // Should be 5 after soft delete
        $this->assertEquals(6, Order::withTrashed()->count()); // Should be 6 with trashed

        // All tests passed - Razorpay integration did not break existing functionality
        $this->assertTrue(true);
    }

    /**
     * Test that all existing API routes are still accessible
     */
    public function testAllExistingApiRoutesStillWork(): void
    {
        $user = User::factory()->withAccount()->create();
        $account = $user->accounts->first();
        $event = Event::factory()->for($account)->create();
        $order = Order::factory()->for($event)->for($account)->create();

        $this->actingAs($user);

        // Test event routes
        $response = $this->getJson(route('events.show', $event->id));
        $response->assertStatus(200);

        // Test order routes
        $response = $this->getJson(route('orders.index', ['event_id' => $event->id]));
        $response->assertStatus(200);

        // Test public routes
        $response = $this->getJson(route('events.show-public', $event->slug));
        $response->assertStatus(200);

        $response = $this->getJson(route('orders.show-public', [
            'event_id' => $event->id,
            'order_short_id' => $order->short_id,
        ]));
        $response->assertStatus(200);

        // All routes are accessible
        $this->assertTrue(true);
    }

    /**
     * Test that database migrations completed successfully
     */
    public function testDatabaseMigrationsCompletedSuccessfully(): void
    {
        // Test that all expected tables exist
        $this->assertTrue(\Schema::hasTable('users'));
        $this->assertTrue(\Schema::hasTable('accounts'));
        $this->assertTrue(\Schema::hasTable('events'));
        $this->assertTrue(\Schema::hasTable('orders'));
        $this->assertTrue(\Schema::hasTable('products'));
        $this->assertTrue(\Schema::hasTable('product_prices'));
        
        // Test that Razorpay table was created
        $this->assertTrue(\Schema::hasTable('razorpay_payments'));

        // Test that expected columns exist in razorpay_payments table
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'id'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'order_id'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'razorpay_order_id'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'razorpay_payment_id'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'razorpay_signature'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'amount_received'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'refund_id'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'created_at'));
        $this->assertTrue(\Schema::hasColumn('razorpay_payments', 'updated_at'));

        // Test that payment_providers enum was updated
        // This would depend on your specific database setup
        // For PostgreSQL, you might check the enum values
        // For MySQL, you might check the column definition

        $this->assertTrue(true);
    }
}