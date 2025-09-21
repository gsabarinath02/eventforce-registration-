<?php

namespace Tests\Unit\Services\Domain\Payment\Razorpay;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Values\MoneyValue;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class RazorpayServiceTest extends TestCase
{
    private RazorpayClient $razorpayClient;
    private LoggerInterface $logger;
    private RazorpayService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->razorpayClient = m::mock(RazorpayClient::class);
        $this->logger = m::mock(LoggerInterface::class);

        $this->service = new RazorpayService(
            razorpayClient: $this->razorpayClient,
            logger: $this->logger,
        );
    }

    public function testCreateOrder(): void
    {
        // Arrange
        $amount = new MoneyValue(50000);
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: 'INR',
            account: $account,
            order: $order,
            receipt: 'order_123',
            notes: ['order_id' => '123']
        );

        $expectedResponse = new CreateRazorpayOrderResponseDTO(
            razorpayOrderId: 'order_test123',
            currency: 'INR',
            amount: 50000,
            receipt: 'order_123',
            status: 'created'
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('createOrder')
            ->once()
            ->with($orderRequest)
            ->andReturn($expectedResponse);

        // Act
        $result = $this->service->createOrder($orderRequest);

        // Assert
        $this->assertEquals($expectedResponse, $result);
        $this->assertEquals('order_test123', $result->razorpayOrderId);
    }

    public function testVerifyPayment(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $orderId = 'order_test456';
        $signature = 'valid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with($paymentId, $orderId, $signature)
            ->andReturn(true);

        // Act
        $result = $this->service->verifyPayment($paymentId, $orderId, $signature);

        // Assert
        $this->assertTrue($result);
    }

    public function testVerifyPaymentWithInvalidSignature(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $orderId = 'order_test456';
        $signature = 'invalid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with($paymentId, $orderId, $signature)
            ->andReturn(false);

        // Act
        $result = $this->service->verifyPayment($paymentId, $orderId, $signature);

        // Assert
        $this->assertFalse($result);
    }

    public function testCapturePayment(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $amount = new MoneyValue(50000);

        $expectedPayment = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test456',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('capturePayment')
            ->once()
            ->with($paymentId, $amount)
            ->andReturn($expectedPayment);

        // Act
        $result = $this->service->capturePayment($paymentId, $amount);

        // Assert
        $this->assertEquals($expectedPayment, $result);
        $this->assertEquals('captured', $result->status);
    }

    public function testRefundPayment(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $amount = new MoneyValue(30000);

        $expectedRefund = new RazorpayRefundDTO(
            refundId: 'rfnd_test456',
            paymentId: $paymentId,
            amount: 30000,
            currency: 'INR',
            status: 'processed',
            createdAt: 1234567890
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('refundPayment')
            ->once()
            ->with($paymentId, $amount)
            ->andReturn($expectedRefund);

        // Act
        $result = $this->service->refundPayment($paymentId, $amount);

        // Assert
        $this->assertEquals($expectedRefund, $result);
        $this->assertEquals('rfnd_test456', $result->refundId);
    }

    public function testGetPaymentDetails(): void
    {
        // Arrange
        $paymentId = 'pay_test123';

        $expectedPayment = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test456',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($expectedPayment);

        // Act
        $result = $this->service->getPaymentDetails($paymentId);

        // Assert
        $this->assertEquals($expectedPayment, $result);
        $this->assertEquals($paymentId, $result->paymentId);
    }

    public function testVerifyWebhookSignature(): void
    {
        // Arrange
        $payload = 'webhook_payload';
        $signature = 'webhook_signature';

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

    public function testVerifyWebhookSignatureWithInvalidSignature(): void
    {
        // Arrange
        $payload = 'webhook_payload';
        $signature = 'invalid_signature';

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyWebhookSignature')
            ->once()
            ->with($payload, $signature)
            ->andReturn(false);

        // Act
        $result = $this->service->verifyWebhookSignature($payload, $signature);

        // Assert
        $this->assertFalse($result);
    }
}