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

class RazorpayPaymentFlowTest extends TestCase
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
                'payment_providers' => [PaymentProviders::RAZORPAY->value],
                'razorpay_key_id' => 'rzp_test_123',
                'razorpay_key_secret' => 'test_secret',
                'razorpay_webhook_secret' => 'webhook_secret',
            ]
        ]);

        $this->product = Product::factory()->for($this->event)->create();
        $this->productPrice = ProductPrice::factory()->for($this->product)->create([
            'price' => 5000, // ₹50.00
        ]);

        $this->order = Order::factory()->for($this->event)->for($this->account)->create([
            'status' => OrderStatus::AWAITING_PAYMENT->value,
            'total_gross' => 5000,
            'currency' => 'INR',
        ]);
    }

    public function testCompleteRazorpayPaymentFlowSuccess(): void
    {
        // Mock Razorpay client for the entire flow
        $mockClient = m::mock(RazorpayClient::class);
        
        // Step 1: Create Razorpay order
        $mockOrderResponse = new CreateRazorpayOrderResponseDTO(
            razorpayOrderId: 'order_test123',
            currency: 'INR',
            amount: 5000,
            receipt: 'order_' . $this->order->id,
            status: 'created'
        );

        $mockClient->shouldReceive('createOrder')
            ->once()
            ->andReturn($mockOrderResponse);

        // Step 2: Verify payment signature
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with('order_test123', 'pay_test456', 'valid_signature', 'test_secret')
            ->andReturn(true);

        // Step 3: Get payment details for verification
        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 5000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: time()
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with('pay_test456')
            ->andReturn($mockPaymentDetails);

        // Step 4: Webhook signature verification
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Step 1: Create Razorpay order
        $createOrderResponse = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $createOrderResponse->assertStatus(200);
        $createOrderData = $createOrderResponse->json();
        
        $this->assertEquals('order_test123', $createOrderData['razorpay_order_id']);
        $this->assertEquals(5000, $createOrderData['amount']);

        // Verify Razorpay payment record was created
        $razorpayPayment = RazorpayPayment::where('order_id', $this->order->id)->first();
        $this->assertNotNull($razorpayPayment);
        $this->assertEquals('order_test123', $razorpayPayment->razorpay_order_id);

        // Step 2: Verify payment (simulating frontend callback)
        $verifyPaymentResponse = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $verifyPaymentResponse->assertStatus(200);
        $verifyPaymentResponse->assertJson([
            'success' => true,
            'message' => 'Payment verified successfully',
            'order_status' => OrderStatus::COMPLETED->value,
        ]);

        // Verify order status updated
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);

        // Verify payment record updated
        $razorpayPayment->refresh();
        $this->assertEquals('pay_test456', $razorpayPayment->razorpay_payment_id);
        $this->assertEquals('valid_signature', $razorpayPayment->razorpay_signature);
        $this->assertEquals(5000, $razorpayPayment->amount_received);

        // Step 3: Process webhook (simulating Razorpay webhook)
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
                        'method' => 'card',
                        'notes' => [
                            'order_id' => $this->order->id,
                            'event_id' => $this->event->id,
                            'account_id' => $this->account->id,
                        ]
                    ]
                ]
            ],
            'created_at' => time()
        ];

        $webhookResponse = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_webhook_signature',
        ]);

        $webhookResponse->assertStatus(200);
        $webhookResponse->assertJson([
            'success' => true,
            'message' => 'Webhook processed successfully',
        ]);

        // Final verification: Order should still be completed
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);
    }

    public function testRazorpayPaymentFlowWithFailure(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        // Step 1: Create order successfully
        $mockOrderResponse = new CreateRazorpayOrderResponseDTO(
            razorpayOrderId: 'order_test123',
            currency: 'INR',
            amount: 5000,
            status: 'created'
        );

        $mockClient->shouldReceive('createOrder')
            ->once()
            ->andReturn($mockOrderResponse);

        // Step 2: Payment verification fails
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->andReturn(false);

        // Step 3: Webhook for failed payment
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Step 1: Create order
        $createOrderResponse = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $createOrderResponse->assertStatus(200);

        // Step 2: Attempt payment verification with invalid signature
        $verifyPaymentResponse = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'invalid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $verifyPaymentResponse->assertStatus(400);
        $verifyPaymentResponse->assertJson([
            'success' => false,
            'error' => 'Payment signature verification failed',
        ]);

        // Verify order status unchanged
        $this->order->refresh();
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $this->order->status);

        // Step 3: Process failed payment webhook
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
                        'error_description' => 'Payment failed',
                        'notes' => [
                            'order_id' => $this->order->id,
                            'event_id' => $this->event->id,
                            'account_id' => $this->account->id,
                        ]
                    ]
                ]
            ],
            'created_at' => time()
        ];

        $webhookResponse = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_webhook_signature',
        ]);

        $webhookResponse->assertStatus(200);

        // Verify order status updated to payment failed
        $this->order->refresh();
        $this->assertEquals(OrderStatus::PAYMENT_FAILED->value, $this->order->status);
    }

    public function testCompleteRazorpayRefundFlow(): void
    {
        // Setup completed order first
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);
        $razorpayPayment = RazorpayPayment::factory()->for($this->order)->create([
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'amount_received' => 5000,
        ]);

        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        // Mock payment details for refund eligibility check
        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 5000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: time(),
            amountRefunded: 0
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with('pay_test456')
            ->andReturn($mockPaymentDetails);

        // Mock refund processing
        $mockRefundResponse = new RazorpayRefundDTO(
            refundId: 'rfnd_test789',
            paymentId: 'pay_test456',
            amount: 5000,
            currency: 'INR',
            status: 'processed',
            createdAt: time()
        );

        $mockClient->shouldReceive('refundPayment')
            ->once()
            ->with('pay_test456', m::type(MoneyValue::class))
            ->andReturn($mockRefundResponse);

        // Mock webhook signature verification
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Authenticate user
        $this->actingAs($this->user);

        // Step 1: Process refund
        $refundResponse = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 5000,
            'reason' => 'Customer requested refund',
        ]);

        $refundResponse->assertStatus(200);
        $refundResponse->assertJson([
            'success' => true,
            'message' => 'Refund processed successfully',
            'refund_id' => 'rfnd_test789',
            'refund_amount' => 5000,
        ]);

        // Verify order refund status updated
        $this->order->refresh();
        $this->assertEquals('full_refund', $this->order->refund_status);

        // Verify payment record updated
        $razorpayPayment->refresh();
        $this->assertEquals('rfnd_test789', $razorpayPayment->refund_id);

        // Step 2: Process refund webhook
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
                        'notes' => [
                            'order_id' => $this->order->id,
                            'reason' => 'Customer requested refund',
                        ]
                    ]
                ]
            ],
            'created_at' => time()
        ];

        $webhookResponse = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_webhook_signature',
        ]);

        $webhookResponse->assertStatus(200);

        // Final verification: Refund status should remain consistent
        $this->order->refresh();
        $this->assertEquals('full_refund', $this->order->refund_status);
    }

    public function testRazorpayPaymentFlowWithWebhookBeforeVerification(): void
    {
        // This tests the scenario where webhook arrives before frontend verification
        
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        // Step 1: Create order
        $mockOrderResponse = new CreateRazorpayOrderResponseDTO(
            razorpayOrderId: 'order_test123',
            currency: 'INR',
            amount: 5000,
            status: 'created'
        );

        $mockClient->shouldReceive('createOrder')
            ->once()
            ->andReturn($mockOrderResponse);

        // Step 2: Webhook signature verification
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        // Step 3: Payment verification (after webhook)
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->andReturn(true);

        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 5000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: time()
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->andReturn($mockPaymentDetails);

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Step 1: Create order
        $createOrderResponse = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $createOrderResponse->assertStatus(200);

        // Step 2: Process webhook first (before frontend verification)
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
                        'method' => 'card',
                        'notes' => [
                            'order_id' => $this->order->id,
                            'event_id' => $this->event->id,
                            'account_id' => $this->account->id,
                        ]
                    ]
                ]
            ],
            'created_at' => time()
        ];

        $webhookResponse = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_webhook_signature',
        ]);

        $webhookResponse->assertStatus(200);

        // Verify order completed by webhook
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);

        // Step 3: Frontend verification should still work (idempotent)
        $verifyPaymentResponse = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        // Should handle already completed order gracefully
        $verifyPaymentResponse->assertStatus(200);
        $verifyPaymentResponse->assertJson([
            'success' => true,
            'order_status' => OrderStatus::COMPLETED->value,
        ]);
    }

    public function testRazorpayPaymentFlowWithPartialRefund(): void
    {
        // Setup completed order
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);
        $razorpayPayment = RazorpayPayment::factory()->for($this->order)->create([
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'amount_received' => 5000,
        ]);

        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 5000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: time(),
            amountRefunded: 0
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->andReturn($mockPaymentDetails);

        $mockRefundResponse = new RazorpayRefundDTO(
            refundId: 'rfnd_test789',
            paymentId: 'pay_test456',
            amount: 2500, // Partial refund
            currency: 'INR',
            status: 'processed',
            createdAt: time()
        );

        $mockClient->shouldReceive('refundPayment')
            ->once()
            ->andReturn($mockRefundResponse);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $this->actingAs($this->user);

        // Process partial refund
        $refundResponse = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 2500, // Half refund
            'reason' => 'Partial refund requested',
        ]);

        $refundResponse->assertStatus(200);
        $refundResponse->assertJson([
            'success' => true,
            'refund_amount' => 2500,
        ]);

        // Verify partial refund status
        $this->order->refresh();
        $this->assertEquals('partial_refund', $this->order->refund_status);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}