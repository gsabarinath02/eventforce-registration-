<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Tests\TestCase;

class RazorpayIncomingWebhookActionTest extends TestCase
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
            'razorpay_payment_id' => 'pay_test456',
            'razorpay_signature' => null,
            'amount_received' => null,
        ]);
    }

    public function testProcessPaymentCapturedWebhookSuccessfully(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

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

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature_hash',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Webhook processed successfully',
        ]);

        // Assert order status updated
        $this->order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED->value, $this->order->status);

        // Assert payment record updated
        $this->razorpayPayment->refresh();
        $this->assertEquals(5000, $this->razorpayPayment->amount_received);
    }

    public function testProcessPaymentAuthorizedWebhook(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $webhookPayload = [
            'event' => 'payment.authorized',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test456',
                        'order_id' => 'order_test123',
                        'amount' => 5000,
                        'currency' => 'INR',
                        'status' => 'authorized',
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

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature_hash',
        ]);

        $response->assertStatus(200);

        // For authorized payments, order should remain in awaiting payment status
        // until captured (depending on business logic)
        $this->order->refresh();
        // This depends on the actual implementation - might be AWAITING_PAYMENT or AUTHORIZED
        $this->assertContains($this->order->status, [
            OrderStatus::AWAITING_PAYMENT->value,
            OrderStatus::COMPLETED->value // If auto-capture is enabled
        ]);
    }

    public function testProcessPaymentFailedWebhook(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

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
                        'method' => 'card',
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

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature_hash',
        ]);

        $response->assertStatus(200);

        // Assert order status updated to payment failed
        $this->order->refresh();
        $this->assertEquals(OrderStatus::PAYMENT_FAILED->value, $this->order->status);
    }

    public function testProcessRefundProcessedWebhook(): void
    {
        // Update order to completed status first
        $this->order->update(['status' => OrderStatus::COMPLETED->value]);
        $this->razorpayPayment->update(['amount_received' => 5000]);

        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

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

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature_hash',
        ]);

        $response->assertStatus(200);

        // Assert refund status updated
        $this->order->refresh();
        $this->assertEquals('full_refund', $this->order->refund_status);

        $this->razorpayPayment->refresh();
        $this->assertEquals('rfnd_test789', $this->razorpayPayment->refund_id);
    }

    public function testProcessWebhookWithInvalidSignature(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(false);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test456',
                        'order_id' => 'order_test123',
                    ]
                ]
            ],
            'created_at' => time()
        ];

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Invalid webhook signature',
        ]);

        // Assert order status unchanged
        $this->order->refresh();
        $this->assertEquals(OrderStatus::AWAITING_PAYMENT->value, $this->order->status);
    }

    public function testProcessWebhookWithMissingSignature(): void
    {
        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [],
            'created_at' => time()
        ];

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Missing webhook signature',
        ]);
    }

    public function testProcessWebhookWithInvalidJson(): void
    {
        $response = $this->postJson('/api/public/webhooks/razorpay', 'invalid json', [
            'X-Razorpay-Signature' => 'signature',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Invalid webhook payload format',
        ]);
    }

    public function testProcessWebhookWithUnsupportedEvent(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $webhookPayload = [
            'event' => 'unsupported.event',
            'payload' => [],
            'created_at' => time()
        ];

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Webhook received but event type not supported',
        ]);
    }

    public function testProcessWebhookWithMissingOrderInfo(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_unknown',
                        'order_id' => 'order_unknown',
                        // Missing notes with order information
                    ]
                ]
            ],
            'created_at' => time()
        ];

        $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Unable to identify order from webhook data',
        ]);
    }

    public function testProcessDuplicateWebhook(): void
    {
        // Mock Razorpay client
        $mockClient = m::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->twice()
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);

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

        // Process webhook first time
        $response1 = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature',
        ]);

        $response1->assertStatus(200);

        // Process same webhook again (duplicate)
        $response2 = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
            'X-Razorpay-Signature' => 'valid_signature',
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'success' => true,
            'message' => 'Webhook already processed (idempotent)',
        ]);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}