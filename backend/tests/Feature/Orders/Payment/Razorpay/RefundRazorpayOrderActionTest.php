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
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Tests\TestCase;

class RefundRazorpayOrderActionTest extends TestCase
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
            'status' => OrderStatus::COMPLETED->value,
            'total_gross' => 5000,
            'currency' => 'INR',
            'refund_status' => null,
        ]);

        $this->razorpayPayment = RazorpayPayment::factory()->for($this->order)->create([
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => 'valid_signature',
            'amount_received' => 5000,
        ]);
    }

    public function testRefundRazorpayOrderSuccessfully(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        // Mock payment details retrieval
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

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Authenticate user
        $this->actingAs($this->user);

        // Make API request
        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 5000, // Full refund
            'reason' => 'Customer requested refund',
        ]);

        // Assert response
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Refund processed successfully',
            'refund_id' => 'rfnd_test789',
            'refund_amount' => 5000,
            'refund_status' => 'processed',
        ]);

        // Assert database updates
        $this->order->refresh();
        $this->assertEquals('full_refund', $this->order->refund_status);

        $this->razorpayPayment->refresh();
        $this->assertEquals('rfnd_test789', $this->razorpayPayment->refund_id);
    }

    public function testRefundRazorpayOrderPartialRefund(): void
    {
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
            ->with('pay_test456')
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

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 2500, // Partial refund
            'reason' => 'Partial refund requested',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'refund_amount' => 2500,
        ]);

        $this->order->refresh();
        $this->assertEquals('partial_refund', $this->order->refund_status);
    }

    public function testRefundRazorpayOrderWithInvalidOrderId(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => 99999, // Invalid order ID
        ]), [
            'refund_amount' => 5000,
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(404);
    }

    public function testRefundRazorpayOrderWithUnauthorizedUser(): void
    {
        // Create different user
        $otherUser = User::factory()->withAccount()->create();
        $this->actingAs($otherUser);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 5000,
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(403);
    }

    public function testRefundRazorpayOrderWithMissingParameters(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            // Missing refund_amount and reason
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'refund_amount',
            'reason',
        ]);
    }

    public function testRefundRazorpayOrderWithInvalidAmount(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 0, // Invalid amount
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['refund_amount']);
    }

    public function testRefundRazorpayOrderWithAmountExceedingPayment(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 10000, // More than payment amount
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Refund amount cannot exceed the original payment amount',
        ]);
    }

    public function testRefundRazorpayOrderWithNonRazorpayPayment(): void
    {
        // Update order to use different payment provider
        $this->order->update(['payment_provider' => PaymentProvider::STRIPE->value]);

        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 5000,
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Order was not paid using Razorpay',
        ]);
    }

    public function testRefundRazorpayOrderWithAlreadyRefundedOrder(): void
    {
        // Update order to already refunded status
        $this->order->update(['refund_status' => 'full_refund']);

        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 5000,
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Order has already been fully refunded',
        ]);
    }

    public function testRefundRazorpayOrderWithIneligiblePaymentStatus(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockPaymentDetails = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 5000,
            currency: 'INR',
            status: 'failed', // Ineligible status
            method: 'card',
            createdAt: time()
        );

        $mockClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with('pay_test456')
            ->andReturn($mockPaymentDetails);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $this->actingAs($this->user);

        $response = $this->postJson(route('razorpay.refund-order', [
            'event_id' => $this->event->id,
            'order_id' => $this->order->id,
        ]), [
            'refund_amount' => 5000,
            'reason' => 'Test refund',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => "Payment with status 'failed' is not eligible for refund",
        ]);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}