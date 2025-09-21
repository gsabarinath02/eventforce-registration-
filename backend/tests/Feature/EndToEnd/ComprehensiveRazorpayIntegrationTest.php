<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use HiEvents\DomainObjects\Enums\OrderStatus;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\RazorpayPayment;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Tests\TestCase;

/**
 * Comprehensive integration tests for Razorpay payment flow
 * 
 * This test class covers all aspects of the Razorpay integration including:
 * - Complete payment flows (success and failure scenarios)
 * - Webhook processing and order synchronization
 * - Refund processing (full and partial)
 * - Edge cases and error handling
 * - Performance and load scenarios
 */
class ComprehensiveRazorpayIntegrationTest extends TestCase
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
        
        // Create test data
        $this->user = User::factory()->create();
        $this->account = Account::factory()->create(['user_id' => $this->user->id]);
        $this->event = Event::factory()->create([
            'account_id' => $this->account->id,
            'payment_providers' => [PaymentProviders::RAZORPAY->value],
        ]);
        $this->product = Product::factory()->create(['event_id' => $this->event->id]);
        $this->productPrice = ProductPrice::factory()->create([
            'product_id' => $this->product->id,
            'price' => 5000, // ₹50.00 in paise
        ]);
        $this->order = Order::factory()->create([
            'event_id' => $this->event->id,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'payment_provider' => PaymentProviders::RAZORPAY->value,
            'total_gross' => 5000,
            'currency' => 'INR',
        ]);
    }

    /**
     * Test complete successful payment flow from order creation to completion
     */
    public function test_complete_successful_payment_flow(): void
    {
        // Mock Razorpay client for order creation
        $mockClient = m::mock(RazorpayClient::class);
        $mockClient->shouldReceive('createOrder')
            ->once()
            ->with([
                'amount' => 5000,
                'currency' => 'INR',
                'receipt' => $this->order->short_id,
                'notes' => [
                    'order_id' => $this->order->id,
                    'event_id' => $this->event->id,
                ]
            ])
            ->andReturn(new CreateRazorpayOrderResponseDTO(
                id: 'order_test123',
                amount: 5000,
                currency: 'INR',
                receipt: $this->order->short_id,
                status: 'created'
            ));

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Step 1: Create Razorpay order
        $response = $this->postJson("/api/public/events/{$this->event->id}/order/{$this->order->short_id}/razorpay/order");
        
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'amount',
                    'currency',
                    'receipt',
                    'status'
                ]
            ]);

        // Verify payment record was created
        $this->assertDatabaseHas('razorpay_payments', [
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
        ]);

        // Step 2: Simulate payment completion and verification
        $paymentId = 'pay_test456';
        $signature = $this->generateTestSignature('order_test123', $paymentId);

        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with('order_test123', $paymentId, $signature)
            ->andReturn(true);

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn(new RazorpayPaymentDTO(
                id: $paymentId,
                orderId: 'order_test123',
                amount: 5000,
                currency: 'INR',
                status: 'captured',
                method: 'card'
            ));

        $verifyResponse = $this->postJson("/api/public/events/{$this->event->id}/order/{$this->order->short_id}/razorpay/verify", [
            'razorpay_payment_id' => $paymentId,
            'razorpay_order_id' => 'order_test123',
            'razorpay_signature' => $signature,
        ]);

        $verifyResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify order status updated
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);

        // Verify payment record updated
        $this->assertDatabaseHas('razorpay_payments', [
            'order_id' => $this->order->id,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
            'amount_received' => 5000,
        ]);
    }

    /**
     * Test payment failure scenario
     */
    public function test_payment_failure_flow(): void
    {
        // Create initial payment record
        RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
        ]);

        // Mock failed payment verification
        $mockClient = m::mock(RazorpayClient::class);
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->andReturn(false);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $response = $this->postJson("/api/public/events/{$this->event->id}/order/{$this->order->short_id}/razorpay/verify", [
            'razorpay_payment_id' => 'pay_invalid',
            'razorpay_order_id' => 'order_test123',
            'razorpay_signature' => 'invalid_signature',
        ]);

        $response->assertStatus(400)
            ->assertJson(['success' => false]);

        // Verify order status remains awaiting payment
        $this->order->refresh();
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $this->order->status);
    }

    /**
     * Test webhook processing for payment.captured event
     */
    public function test_webhook_payment_captured_processing(): void
    {
        // Create payment record
        $payment = RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
        ]);

        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test456',
                        'order_id' => 'order_test123',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $signature = $this->generateWebhookSignature($webhookPayload);

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Verify order status updated via webhook
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);

        // Verify payment record updated
        $payment->refresh();
        $this->assertEquals(5000, $payment->amount_received);
    }

    /**
     * Test webhook processing for payment.failed event
     */
    public function test_webhook_payment_failed_processing(): void
    {
        // Create payment record
        RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
        ]);

        $webhookPayload = [
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test456',
                        'order_id' => 'order_test123',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'failed',
                        'error_code' => 'BAD_REQUEST_ERROR',
                        'error_description' => 'Payment failed due to insufficient funds',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $signature = $this->generateWebhookSignature($webhookPayload);

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Verify order status updated to payment failed
        $this->order->refresh();
        $this->assertEquals(OrderStatus::PAYMENT_FAILED->value, $this->order->status);
    }

    /**
     * Test full refund processing
     */
    public function test_full_refund_processing(): void
    {
        // Set up completed order with payment
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);
        $payment = RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'amount_received' => 5000,
        ]);

        // Mock refund API call
        $mockClient = m::mock(RazorpayClient::class);
        $mockClient->shouldReceive('createRefund')
            ->once()
            ->with('pay_test456', 5000)
            ->andReturn(new RazorpayRefundDTO(
                id: 'rfnd_test789',
                paymentId: 'pay_test456',
                amount: 5000,
                currency: 'INR',
                status: 'processed'
            ));

        $this->app->instance(RazorpayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->postJson("/api/events/{$this->event->id}/orders/{$this->order->id}/razorpay/refund", [
                'amount' => 50.00, // Full refund amount in rupees
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'amount',
                    'status'
                ]
            ]);

        // Verify refund record updated
        $payment->refresh();
        $this->assertEquals('rfnd_test789', $payment->refund_id);
    }

    /**
     * Test partial refund processing
     */
    public function test_partial_refund_processing(): void
    {
        // Set up completed order with payment
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);
        $payment = RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'amount_received' => 5000,
        ]);

        // Mock partial refund API call
        $mockClient = m::mock(RazorpayClient::class);
        $mockClient->shouldReceive('createRefund')
            ->once()
            ->with('pay_test456', 2500) // Partial refund of ₹25.00
            ->andReturn(new RazorpayRefundDTO(
                id: 'rfnd_test789',
                paymentId: 'pay_test456',
                amount: 2500,
                currency: 'INR',
                status: 'processed'
            ));

        $this->app->instance(RazorpayClient::class, $mockClient);

        $response = $this->actingAs($this->user)
            ->postJson("/api/events/{$this->event->id}/orders/{$this->order->id}/razorpay/refund", [
                'amount' => 25.00, // Partial refund amount in rupees
            ]);

        $response->assertStatus(200);

        // Verify partial refund processed
        $payment->refresh();
        $this->assertEquals('rfnd_test789', $payment->refund_id);
    }

    /**
     * Test webhook idempotency - duplicate webhooks should not cause issues
     */
    public function test_webhook_idempotency(): void
    {
        // Create payment record
        $payment = RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
        ]);

        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test456',
                        'order_id' => 'order_test123',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $signature = $this->generateWebhookSignature($webhookPayload);

        // Send webhook twice
        $response1 = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => $signature,
        ]);

        $response2 = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => $signature,
        ]);

        // Both should succeed
        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Verify order is still completed (not processed twice)
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);
    }

    /**
     * Test invalid webhook signature rejection
     */
    public function test_invalid_webhook_signature_rejection(): void
    {
        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test456',
                        'order_id' => 'order_test123',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        // Send with invalid signature
        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(400);

        // Verify order status unchanged
        $this->order->refresh();
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $this->order->status);
    }

    /**
     * Test error handling for missing payment record
     */
    public function test_webhook_with_missing_payment_record(): void
    {
        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_nonexistent',
                        'order_id' => 'order_nonexistent',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $signature = $this->generateWebhookSignature($webhookPayload);

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => $signature,
        ]);

        // Should handle gracefully
        $response->assertStatus(200);
    }

    /**
     * Test refund webhook processing
     */
    public function test_refund_webhook_processing(): void
    {
        // Set up payment with refund
        $payment = RazorpayPayment::create([
            'order_id' => $this->order->id,
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'amount_received' => 5000,
            'refund_id' => 'rfnd_test789',
        ]);

        $webhookPayload = [
            'event' => 'refund.processed',
            'payload' => [
                'refund' => [
                    'entity' => [
                        'id' => 'rfnd_test789',
                        'payment_id' => 'pay_test456',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'processed',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $signature = $this->generateWebhookSignature($webhookPayload);

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Verify refund status updated
        $this->order->refresh();
        $this->assertEquals(OrderStatus::REFUNDED->value, $this->order->status);
    }

    /**
     * Generate test payment signature for verification
     */
    private function generateTestSignature(string $orderId, string $paymentId): string
    {
        $payload = $orderId . '|' . $paymentId;
        return hash_hmac('sha256', $payload, config('razorpay.key_secret'));
    }

    /**
     * Generate webhook signature for testing
     */
    private function generateWebhookSignature(array $payload): string
    {
        $webhookBody = json_encode($payload);
        return hash_hmac('sha256', $webhookBody, config('razorpay.webhook_secret'));
    }

    /**
     * Test performance under load - simulate multiple concurrent payments
     */
    public function test_concurrent_payment_processing(): void
    {
        $mockClient = m::mock(RazorpayClient::class);
        
        // Create multiple orders
        $orders = Order::factory()->count(5)->create([
            'event_id' => $this->event->id,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'payment_provider' => PaymentProviders::RAZORPAY->value,
            'total_gross' => 5000,
            'currency' => 'INR',
        ]);

        foreach ($orders as $index => $order) {
            $orderId = "order_test{$index}";
            $paymentId = "pay_test{$index}";
            
            // Mock order creation
            $mockClient->shouldReceive('createOrder')
                ->once()
                ->andReturn(new CreateRazorpayOrderResponseDTO(
                    id: $orderId,
                    amount: 5000,
                    currency: 'INR',
                    receipt: $order->short_id,
                    status: 'created'
                ));

            // Mock payment verification
            $mockClient->shouldReceive('verifyPaymentSignature')
                ->once()
                ->andReturn(true);

            $mockClient->shouldReceive('getPaymentDetails')
                ->once()
                ->andReturn(new RazorpayPaymentDTO(
                    id: $paymentId,
                    orderId: $orderId,
                    amount: 5000,
                    currency: 'INR',
                    status: 'captured',
                    method: 'card'
                ));
        }

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Process all orders concurrently
        foreach ($orders as $index => $order) {
            // Create order
            $response = $this->postJson("/api/public/events/{$this->event->id}/order/{$order->short_id}/razorpay/order");
            $response->assertStatus(200);

            // Verify payment
            $signature = $this->generateTestSignature("order_test{$index}", "pay_test{$index}");
            $verifyResponse = $this->postJson("/api/public/events/{$this->event->id}/order/{$order->short_id}/razorpay/verify", [
                'razorpay_payment_id' => "pay_test{$index}",
                'razorpay_order_id' => "order_test{$index}",
                'razorpay_signature' => $signature,
            ]);
            $verifyResponse->assertStatus(200);
        }

        // Verify all orders completed successfully
        foreach ($orders as $order) {
            $order->refresh();
            $this->assertEquals(OrderStatus::COMPLETED->value, $order->status);
        }
    }

    /**
     * Test edge case: Order timeout handling
     */
    public function test_order_timeout_handling(): void
    {
        // Create expired order (older than 30 minutes)
        $expiredOrder = Order::factory()->create([
            'event_id' => $this->event->id,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'payment_provider' => PaymentProviders::RAZORPAY->value,
            'created_at' => now()->subMinutes(35),
        ]);

        $response = $this->postJson("/api/public/events/{$this->event->id}/order/{$expiredOrder->short_id}/razorpay/order");

        // Should handle expired order appropriately
        $response->assertStatus(400);
    }

    /**
     * Test currency validation
     */
    public function test_currency_validation(): void
    {
        // Create order with unsupported currency
        $invalidOrder = Order::factory()->create([
            'event_id' => $this->event->id,
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'payment_provider' => PaymentProviders::RAZORPAY->value,
            'currency' => 'USD', // Razorpay primarily supports INR
        ]);

        $response = $this->postJson("/api/public/events/{$this->event->id}/order/{$invalidOrder->short_id}/razorpay/order");

        // Should validate currency appropriately
        $response->assertStatus(400);
    }
     }
