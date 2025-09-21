<?php

declare(strict_types=1);

namespace Tests\Feature\RegressionTests;

use HiEvents\DomainObjects\Enums\OrderStatus;
use HiEvents\DomainObjects\Enums\PaymentProvider;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentProviderRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private Event $event;
    private Order $order;
    private Product $product;
    private ProductPrice $productPrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withAccount()->create();
        $this->account = $this->user->accounts->first();
        
        $this->event = Event::factory()->for($this->account)->create([
            'settings' => [
                'payment_providers' => [
                    PaymentProvider::STRIPE->value,
                    PaymentProvider::OFFLINE->value,
                    PaymentProvider::RAZORPAY->value
                ],
                'stripe_publishable_key' => 'pk_test_123',
                'stripe_secret_key' => 'sk_test_123',
                'razorpay_key_id' => 'rzp_test_123',
                'razorpay_key_secret' => 'test_secret',
            ]
        ]);

        $this->product = Product::factory()->for($this->event)->create();
        $this->productPrice = ProductPrice::factory()->for($this->product)->create([
            'price' => 5000, // $50.00
        ]);

        $this->order = Order::factory()->for($this->event)->for($this->account)->create([
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'total_gross' => 5000,
            'currency' => 'USD',
        ]);
    }

    public function testStripePaymentFlowRemainsIntact(): void
    {
        // Test that Stripe payment creation still works
        $response = $this->postJson(route('stripe.create-payment-intent', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        // Should still work as before
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'client_secret',
            'account_id',
        ]);

        // Verify Stripe-specific fields are present
        $responseData = $response->json();
        $this->assertStringStartsWith('pi_', $responseData['client_secret']);
    }

    public function testOfflinePaymentFlowRemainsIntact(): void
    {
        // Test that offline payment transition still works
        $response = $this->postJson(route('orders.transition-to-offline-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Verify order status updated correctly
        $this->order->refresh();
        $this->assertEquals(OrderStatus::AWAITING_OFFLINE_PAYMENT->value, $this->order->status);
    }

    public function testPaymentProviderEnumStillContainsOriginalValues(): void
    {
        // Ensure original payment providers still exist
        $this->assertTrue(PaymentProvider::STRIPE->value === 'STRIPE');
        $this->assertTrue(PaymentProvider::OFFLINE->value === 'OFFLINE');
        
        // And new Razorpay provider is added
        $this->assertTrue(PaymentProvider::RAZORPAY->value === 'RAZORPAY');
        
        // Verify all cases are available
        $allCases = PaymentProvider::cases();
        $values = array_map(fn($case) => $case->value, $allCases);
        
        $this->assertContains('STRIPE', $values);
        $this->assertContains('OFFLINE', $values);
        $this->assertContains('RAZORPAY', $values);
    }

    public function testEventSettingsValidationStillWorksForStripe(): void
    {
        // Test that Stripe validation still works
        $this->actingAs($this->user);

        $response = $this->putJson(route('events.update-settings', $this->event->id), [
            'payment_providers' => ['STRIPE'],
            'stripe_publishable_key' => 'pk_test_valid',
            'stripe_secret_key' => 'sk_test_valid',
        ]);

        $response->assertStatus(200);

        // Test invalid Stripe keys still fail validation
        $response = $this->putJson(route('events.update-settings', $this->event->id), [
            'payment_providers' => ['STRIPE'],
            'stripe_publishable_key' => 'invalid_key',
            'stripe_secret_key' => 'invalid_key',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stripe_publishable_key', 'stripe_secret_key']);
    }

    public function testEventSettingsValidationStillWorksForOffline(): void
    {
        // Test that offline payment validation still works
        $this->actingAs($this->user);

        $response = $this->putJson(route('events.update-settings', $this->event->id), [
            'payment_providers' => ['OFFLINE'],
            'offline_payment_instructions' => 'Please pay by bank transfer',
        ]);

        $response->assertStatus(200);

        // Verify offline settings are saved correctly
        $this->event->refresh();
        $this->assertEquals(['OFFLINE'], $this->event->settings['payment_providers']);
        $this->assertEquals('Please pay by bank transfer', $this->event->settings['offline_payment_instructions']);
    }

    public function testMultiplePaymentProvidersCanBeEnabledTogether(): void
    {
        $this->actingAs($this->user);

        // Test enabling all payment providers together
        $response = $this->putJson(route('events.update-settings', $this->event->id), [
            'payment_providers' => ['STRIPE', 'OFFLINE', 'RAZORPAY'],
            'stripe_publishable_key' => 'pk_test_123',
            'stripe_secret_key' => 'sk_test_123',
            'razorpay_key_id' => 'rzp_test_123',
            'razorpay_key_secret' => 'test_secret',
            'offline_payment_instructions' => 'Bank transfer instructions',
        ]);

        $response->assertStatus(200);

        // Verify all providers are saved
        $this->event->refresh();
        $providers = $this->event->settings['payment_providers'];
        
        $this->assertContains('STRIPE', $providers);
        $this->assertContains('OFFLINE', $providers);
        $this->assertContains('RAZORPAY', $providers);
    }

    public function testStripeWebhookStillWorks(): void
    {
        // Test that existing Stripe webhook processing still works
        $stripePayload = [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test123',
                    'amount' => 5000,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                    'metadata' => [
                        'order_id' => $this->order->id,
                        'event_id' => $this->event->id,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/public/webhooks/stripe', $stripePayload, [
            'Stripe-Signature' => 'test_signature',
        ]);

        // Should process without interference from Razorpay code
        $response->assertStatus(200);
    }

    public function testOrderCreationStillWorksWithExistingProviders(): void
    {
        // Test creating orders with Stripe
        $stripeOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
            'status' => OrderStatus::COMPLETED->value,
        ]);

        $this->assertEquals(PaymentProvider::STRIPE->value, $stripeOrder->payment_provider);

        // Test creating orders with Offline
        $offlineOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::OFFLINE->value,
            'status' => OrderStatus::AWAITING_OFFLINE_PAYMENT->value,
        ]);

        $this->assertEquals(PaymentProvider::OFFLINE->value, $offlineOrder->payment_provider);

        // Test creating orders with Razorpay (new)
        $razorpayOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::RAZORPAY->value,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
        ]);

        $this->assertEquals(PaymentProvider::RAZORPAY->value, $razorpayOrder->payment_provider);
    }

    public function testOrderStatusTransitionsStillWork(): void
    {
        // Test Stripe order status transitions
        $stripeOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
        ]);

        $stripeOrder->update(['status' => OrderStatus::COMPLETED->value]);
        $this->assertEquals(OrderStatus::COMPLETED->value, $stripeOrder->status);

        // Test Offline order status transitions
        $offlineOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::OFFLINE->value,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
        ]);

        $offlineOrder->update(['status' => OrderStatus::AWAITING_OFFLINE_PAYMENT->value]);
        $this->assertEquals(OrderStatus::AWAITING_OFFLINE_PAYMENT->value, $offlineOrder->status);
    }

    public function testExistingOrderQueriesStillWork(): void
    {
        // Create orders with different payment providers
        $stripeOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
        ]);

        $offlineOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::OFFLINE->value,
        ]);

        $razorpayOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::RAZORPAY->value,
        ]);

        // Test querying by payment provider still works
        $stripeOrders = Order::where('payment_provider', PaymentProvider::STRIPE->value)->get();
        $this->assertCount(1, $stripeOrders);
        $this->assertEquals($stripeOrder->id, $stripeOrders->first()->id);

        $offlineOrders = Order::where('payment_provider', PaymentProvider::OFFLINE->value)->get();
        $this->assertCount(1, $offlineOrders);
        $this->assertEquals($offlineOrder->id, $offlineOrders->first()->id);

        $razorpayOrders = Order::where('payment_provider', PaymentProvider::RAZORPAY->value)->get();
        $this->assertCount(1, $razorpayOrders);
        $this->assertEquals($razorpayOrder->id, $razorpayOrders->first()->id);
    }

    public function testExistingRefundFunctionalityStillWorks(): void
    {
        // Test that existing refund functionality for Stripe still works
        $stripeOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
            'status' => OrderStatus::COMPLETED->value,
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson(route('orders.refund', [
            'event_id' => $this->event->id,
            'order_id' => $stripeOrder->id,
        ]), [
            'refund_amount' => 2500,
            'reason' => 'Customer requested refund',
        ]);

        // Should still work for Stripe orders
        $response->assertStatus(200);
    }

    public function testDatabaseMigrationsDidNotBreakExistingData(): void
    {
        // Verify that existing order data is still accessible
        $existingOrder = Order::factory()->for($this->event)->for($this->account)->create([
            'payment_provider' => PaymentProvider::STRIPE->value,
            'status' => OrderStatus::COMPLETED->value,
            'total_gross' => 10000,
        ]);

        // Should be able to retrieve and update existing orders
        $retrievedOrder = Order::find($existingOrder->id);
        $this->assertNotNull($retrievedOrder);
        $this->assertEquals(PaymentProvider::STRIPE->value, $retrievedOrder->payment_provider);
        $this->assertEquals(10000, $retrievedOrder->total_gross);

        // Should be able to update existing orders
        $retrievedOrder->update(['total_gross' => 12000]);
        $this->assertEquals(12000, $retrievedOrder->fresh()->total_gross);
    }

    public function testApiRoutesStillExistForExistingProviders(): void
    {
        // Test that Stripe routes still exist
        $this->assertTrue(route('stripe.create-payment-intent', [
            'event_id' => 1,
            'order_short_id' => 'test'
        ]) !== null);

        // Test that offline payment routes still exist
        $this->assertTrue(route('orders.transition-to-offline-payment', [
            'event_id' => 1,
            'order_short_id' => 'test'
        ]) !== null);

        // Test that new Razorpay routes exist
        $this->assertTrue(route('razorpay.create-order', [
            'event_id' => 1,
            'order_short_id' => 'test'
        ]) !== null);

        $this->assertTrue(route('razorpay.verify-payment', [
            'event_id' => 1,
            'order_short_id' => 'test'
        ]) !== null);
    }
}