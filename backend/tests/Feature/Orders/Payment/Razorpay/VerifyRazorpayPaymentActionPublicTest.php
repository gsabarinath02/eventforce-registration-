<?php

declare(strict_types=1);

namespace Tests\Feature\Orders\Payment\Razorpay;

use HiEvents\DomainObjects\Enums\OrderStatus;
use HiEvents\DomainObjects\Enums\PaymentProvider;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\RazorpayPayment;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Tests\TestCase;

class VerifyRazorpayPaymentActionPublicTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private Event $event;
    private Order $order;
    private Product $product;
    private ProductPrice $productPrice;
    private RazorpayPayment $razorpayPayment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withAccount()->create();
        $this->account = $this->user->accounts->first();
        
        $this->event = Event::factory()->for($this->account)->create([
            'settings' => [
                'payment_providers' => [PaymentProvider::RAZORPAY->value],
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

        $this->razorpayPayment = RazorpayPayment::factory()->for($this->order)->create([
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => null,
            'razorpay_signature' => null,
            'amount_received' => null,
        ]);
    }

    public function testVerifyRazorpayPaymentSuccessfully(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        // Mock payment verification
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with('order_test123', 'pay_test456', 'valid_signature', 'test_secret')
            ->andReturn(true);

        // Mock payment details retrieval
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

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Make API request
        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        // Assert response
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Payment verified successfully',
            'order_status' => OrderStatus::COMPLETED->value,
        ]);

        // Assert database updates
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);

        $this->razorpayPayment->refresh();
        $this->assertEquals('pay_test456', $this->razorpayPayment->razorpay_payment_id);
        $this->assertEquals('valid_signature', $this->razorpayPayment->razorpay_signature);
        $this->assertEquals(5000, $this->razorpayPayment->amount_received);
    }

    public function testVerifyRazorpayPaymentWithInvalidSignature(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with('order_test123', 'pay_test456', 'invalid_signature', 'test_secret')
            ->andReturn(false);

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Make API request
        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'invalid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        // Assert response
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'Payment signature verification failed',
        ]);

        // Assert order status unchanged
        $this->order->refresh();
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $this->order->status);
    }

    public function testVerifyRazorpayPaymentWithMissingParameters(): void
    {
        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
            // Missing required payment parameters
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'razorpay_order_id',
            'razorpay_payment_id',
            'razorpay_signature',
        ]);
    }

    public function testVerifyRazorpayPaymentWithInvalidOrderId(): void
    {
        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => 'invalid_order_id',
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(404);
    }

    public function testVerifyRazorpayPaymentWithMismatchedOrderId(): void
    {
        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_different123', // Different from stored order ID
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Order ID mismatch',
        ]);
    }

    public function testVerifyRazorpayPaymentWithAlreadyCompletedOrder(): void
    {
        // Update order to completed status
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);

        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Order is not in a valid state for payment verification',
        ]);
    }

    public function testVerifyRazorpayPaymentWithAmountMismatch(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->andReturn(true);

        // Mock payment details with different amount
        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 6000, // Different amount
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: time()
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with('pay_test456')
            ->andReturn($mockPaymentDetails);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Payment amount mismatch. Expected: ₹50.00, Received: ₹60.00',
        ]);
    }

    public function testVerifyRazorpayPaymentWithFailedPaymentStatus(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->andReturn(true);

        // Mock payment details with failed status
        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 5000,
            currency: 'INR',
            status: 'failed',
            method: 'card',
            createdAt: time()
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with('pay_test456')
            ->andReturn($mockPaymentDetails);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $response = $this->postJson(route('razorpay.verify-payment', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => "Payment status 'failed' is not valid for completion",
        ]);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}