<?php

namespace Tests\Unit\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayWebhookVerificationFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\PaymentAuthorizedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\PaymentCapturedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\PaymentFailedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\EventHandlers\RefundProcessedHandler;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayWebhookService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class RazorpayWebhookServiceTest extends TestCase
{
    private RazorpayClient $razorpayClient;
    private PaymentAuthorizedHandler $paymentAuthorizedHandler;
    private PaymentCapturedHandler $paymentCapturedHandler;
    private PaymentFailedHandler $paymentFailedHandler;
    private RefundProcessedHandler $refundProcessedHandler;
    private LoggerInterface $logger;
    private RazorpayWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->razorpayClient = m::mock(RazorpayClient::class);
        $this->paymentAuthorizedHandler = m::mock(PaymentAuthorizedHandler::class);
        $this->paymentCapturedHandler = m::mock(PaymentCapturedHandler::class);
        $this->paymentFailedHandler = m::mock(PaymentFailedHandler::class);
        $this->refundProcessedHandler = m::mock(RefundProcessedHandler::class);
        $this->logger = m::mock(LoggerInterface::class);

        $this->service = new RazorpayWebhookService(
            razorpayClient: $this->razorpayClient,
            paymentAuthorizedHandler: $this->paymentAuthorizedHandler,
            paymentCapturedHandler: $this->paymentCapturedHandler,
            paymentFailedHandler: $this->paymentFailedHandler,
            refundProcessedHandler: $this->refundProcessedHandler,
            logger: $this->logger,
        );
    }

    public function testVerifyAndParseWebhookSuccessfully(): void
    {
        // Arrange
        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                        'amount' => 50000,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ]);
        $signature = 'valid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->with($payload, $signature)
            ->andReturn(true);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay webhook verified and parsed successfully', [
                'event' => 'payment.captured',
                'payment_id' => 'pay_test123',
                'order_id' => 'order_test456',
                'created_at' => 1234567890,
            ]);

        // Act
        $result = $this->service->verifyAndParseWebhook($payload, $signature);

        // Assert
        $this->assertInstanceOf(RazorpayWebhookEventDTO::class, $result);
        $this->assertEquals('payment.captured', $result->event);
        $this->assertEquals('pay_test123', $result->getPaymentId());
        $this->assertEquals('order_test456', $result->getOrderId());
    }

    public function testVerifyAndParseWebhookWithInvalidSignature(): void
    {
        // Arrange
        $payload = json_encode(['event' => 'payment.captured']);
        $signature = 'invalid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->with($payload, $signature)
            ->andReturn(false);

        // Mock logger calls
        $this->logger->shouldReceive('warning')
            ->once()
            ->with('Razorpay webhook signature verification failed', [
                'payload_length' => strlen($payload),
            ]);

        // Act & Assert
        $this->expectException(RazorpayWebhookVerificationFailedException::class);
        $this->expectExceptionMessage('Invalid webhook signature');

        $this->service->verifyAndParseWebhook($payload, $signature);
    }

    public function testVerifyAndParseWebhookWithInvalidJson(): void
    {
        // Arrange
        $payload = 'invalid json';
        $signature = 'valid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->with($payload, $signature)
            ->andReturn(true);

        // Mock logger calls
        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay webhook payload parsing failed', [
                'json_error' => 'Syntax error',
                'payload_length' => strlen($payload),
            ]);

        // Act & Assert
        $this->expectException(RazorpayWebhookVerificationFailedException::class);
        $this->expectExceptionMessage('Invalid webhook payload format');

        $this->service->verifyAndParseWebhook($payload, $signature);
    }

    public function testVerifyWebhookSignatureSuccessfully(): void
    {
        // Arrange
        $payload = 'test payload';
        $signature = 'valid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->with($payload, $signature)
            ->andReturn(true);

        // Act
        $result = $this->service->verifyWebhookSignature($payload, $signature);

        // Assert
        $this->assertTrue($result);
    }

    public function testVerifyWebhookSignatureWithException(): void
    {
        // Arrange
        $payload = 'test payload';
        $signature = 'signature';

        // Mock Razorpay client to throw exception
        $this->razorpayClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->with($payload, $signature)
            ->andThrow(new \Exception('Verification error'));

        // Mock logger calls
        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay webhook signature verification error', [
                'error' => 'Verification error',
            ]);

        // Act
        $result = $this->service->verifyWebhookSignature($payload, $signature);

        // Assert
        $this->assertFalse($result);
    }

    public function testValidateWebhookEventWithValidPaymentEvent(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Act
        $result = $this->service->validateWebhookEvent($event);

        // Assert
        $this->assertTrue($result);
    }

    public function testValidateWebhookEventWithUnsupportedEvent(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'unsupported.event',
            'payload' => [],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Unsupported Razorpay webhook event type', [
                'event' => 'unsupported.event',
            ]);

        // Act
        $result = $this->service->validateWebhookEvent($event);

        // Assert
        $this->assertFalse($result);
    }

    public function testValidateWebhookEventWithMissingPaymentEntity(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [] // Missing payment ID
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('warning')
            ->once()
            ->with('Invalid payment event: missing payment entity', [
                'event' => 'payment.captured',
            ]);

        // Act
        $result = $this->service->validateWebhookEvent($event);

        // Assert
        $this->assertFalse($result);
    }

    public function testIsSupportedEventType(): void
    {
        // Test supported events
        $supportedEvents = [
            'payment.authorized',
            'payment.captured',
            'payment.failed',
            'refund.processed',
            'refund.failed',
        ];

        foreach ($supportedEvents as $event) {
            $this->assertTrue($this->service->isSupportedEventType($event));
        }

        // Test unsupported event
        $this->assertFalse($this->service->isSupportedEventType('unsupported.event'));
    }

    public function testExtractOrderInfoSuccessfully(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                        'notes' => [
                            'order_id' => '123',
                            'event_id' => '456',
                            'order_short_id' => 'ABC123',
                            'account_id' => '789',
                        ]
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Act
        $result = $this->service->extractOrderInfo($event);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('order_test456', $result['razorpay_order_id']);
        $this->assertEquals('123', $result['internal_order_id']);
        $this->assertEquals('456', $result['event_id']);
        $this->assertEquals('ABC123', $result['order_short_id']);
        $this->assertEquals('789', $result['account_id']);
    }

    public function testExtractOrderInfoWithNoOrderId(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        // No order_id
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Act
        $result = $this->service->extractOrderInfo($event);

        // Assert
        $this->assertNull($result);
    }

    public function testProcessWebhookEventPaymentAuthorized(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.authorized',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing Razorpay webhook event', [
                'event' => 'payment.authorized',
                'payment_id' => 'pay_test123',
                'order_id' => 'order_test456',
            ]);

        // Mock handler
        $this->paymentAuthorizedHandler->shouldReceive('handleEvent')
            ->once()
            ->with($event);

        // Act
        $this->service->processWebhookEvent($event);

        // Assert - No exception thrown
        $this->assertTrue(true);
    }

    public function testProcessWebhookEventPaymentCaptured(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing Razorpay webhook event', [
                'event' => 'payment.captured',
                'payment_id' => 'pay_test123',
                'order_id' => 'order_test456',
            ]);

        // Mock handler
        $this->paymentCapturedHandler->shouldReceive('handleEvent')
            ->once()
            ->with($event);

        // Act
        $this->service->processWebhookEvent($event);

        // Assert - No exception thrown
        $this->assertTrue(true);
    }

    public function testProcessWebhookEventPaymentFailed(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing Razorpay webhook event', [
                'event' => 'payment.failed',
                'payment_id' => 'pay_test123',
                'order_id' => 'order_test456',
            ]);

        // Mock handler
        $this->paymentFailedHandler->shouldReceive('handleEvent')
            ->once()
            ->with($event);

        // Act
        $this->service->processWebhookEvent($event);

        // Assert - No exception thrown
        $this->assertTrue(true);
    }

    public function testProcessWebhookEventRefundProcessed(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'refund.processed',
            'payload' => [
                'refund' => [
                    'entity' => [
                        'id' => 'rfnd_test123',
                        'payment_id' => 'pay_test456',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing Razorpay webhook event', [
                'event' => 'refund.processed',
                'payment_id' => 'pay_test456',
                'order_id' => null,
            ]);

        // Mock handler
        $this->refundProcessedHandler->shouldReceive('handleEvent')
            ->once()
            ->with($event);

        // Act
        $this->service->processWebhookEvent($event);

        // Assert - No exception thrown
        $this->assertTrue(true);
    }

    public function testProcessWebhookEventUnsupportedEvent(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'unsupported.event',
            'payload' => [],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing Razorpay webhook event', [
                'event' => 'unsupported.event',
                'payment_id' => null,
                'order_id' => null,
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Unsupported Razorpay webhook event type', [
                'event' => 'unsupported.event',
            ]);

        // Act
        $this->service->processWebhookEvent($event);

        // Assert - No exception thrown
        $this->assertTrue(true);
    }

    public function testProcessWebhookEventWithHandlerException(): void
    {
        // Arrange
        $webhookData = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test456',
                    ]
                ]
            ],
            'created_at' => 1234567890
        ];
        $event = RazorpayWebhookEventDTO::fromWebhookData($webhookData);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing Razorpay webhook event', [
                'event' => 'payment.captured',
                'payment_id' => 'pay_test123',
                'order_id' => 'order_test456',
            ]);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Failed to process Razorpay webhook event', [
                'error' => 'Handler error',
                'event' => 'payment.captured',
                'payment_id' => 'pay_test123',
                'order_id' => 'order_test456',
            ]);

        // Mock handler to throw exception
        $this->paymentCapturedHandler->shouldReceive('handleEvent')
            ->once()
            ->with($event)
            ->andThrow(new \Exception('Handler error'));

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Handler error');

        $this->service->processWebhookEvent($event);
    }
}