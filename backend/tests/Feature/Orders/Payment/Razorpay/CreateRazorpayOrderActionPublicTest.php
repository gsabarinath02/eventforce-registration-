<?php

declare(strict_types=1);

namespace Tests\Feature\Orders\Payment\Razorpay;

use HiEvents\DomainObjects\Enums\OrderStatus;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Models\Account;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Tests\TestCase;

class CreateRazorpayOrderActionPublicTest extends TestCase
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
    }

    public function testCreateRazorpayOrderSuccessfully(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        $mockResponse = new CreateRazorpayOrderResponseDTO(
            razorpayOrderId: 'order_test123',
            currency: 'INR',
            amount: 5000,
            receipt: 'order_' . $this->order->id,
            status: 'created'
        );

        $mockClient->shouldReceive('createOrder')
            ->once()
            ->andReturn($mockResponse);

        $this->app->instance(RazorpayClient::class, $mockClient);

        // Make API request
        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        // Assert response
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'razorpay_order_id',
            'currency',
            'amount',
            'key_id',
            'order_id',
            'name',
            'description',
            'prefill' => [
                'name',
                'email',
            ],
            'theme' => [
                'color',
            ],
        ]);

        $responseData = $response->json();
        $this->assertEquals('order_test123', $responseData['razorpay_order_id']);
        $this->assertEquals('INR', $responseData['currency']);
        $this->assertEquals(5000, $responseData['amount']);
        $this->assertEquals('rzp_test_123', $responseData['key_id']);
    }

    public function testCreateRazorpayOrderWithInvalidOrderId(): void
    {
        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => 'invalid_order_id',
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(404);
    }

    public function testCreateRazorpayOrderWithInvalidEventId(): void
    {
        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => 99999,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(404);
    }

    public function testCreateRazorpayOrderWithMissingSessionIdentifier(): void
    {
        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['session_identifier']);
    }

    public function testCreateRazorpayOrderWithCompletedOrder(): void
    {
        // Update order to completed status
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);

        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Order is not in a valid state for payment processing',
        ]);
    }

    public function testCreateRazorpayOrderWithRazorpayNotEnabled(): void
    {
        // Update event to not have Razorpay enabled
        $this->event->update([
            'settings' => [
                'payment_providers' => [PaymentProvider::STRIPE->value],
            ]
        ]);

        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Razorpay payment provider is not enabled for this event',
        ]);
    }

    public function testCreateRazorpayOrderWithMissingCredentials(): void
    {
        // Update event to have missing Razorpay credentials
        $this->event->update([
            'settings' => [
                'payment_providers' => [PaymentProvider::RAZORPAY->value],
                // Missing razorpay_key_id, razorpay_key_secret
            ]
        ]);

        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Razorpay credentials are not properly configured',
        ]);
    }

    public function testCreateRazorpayOrderWithZeroAmount(): void
    {
        // Update order to have zero amount
        $this->order->update(['total_gross' => 0]);

        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Order amount must be greater than zero',
        ]);
    }

    public function testCreateRazorpayOrderWithUnsupportedCurrency(): void
    {
        // Update order to have unsupported currency
        $this->order->update(['currency' => 'JPY']);

        $response = $this->postJson(route('razorpay.create-order', [
            'event_id' => $this->event->id,
            'order_short_id' => $this->order->short_id,
        ]), [
            'session_identifier' => 'test_session_123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => "Currency 'JPY' is not supported by Razorpay",
        ]);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}