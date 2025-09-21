<?php

namespace Tests\Unit\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayPaymentVerificationFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayPaymentVerificationService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayConfigurationService;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class RazorpayPaymentVerificationServiceTest extends TestCase
{
    private RazorpayClient $razorpayClient;
    private RazorpayConfigurationService $configurationService;
    private LoggerInterface $logger;
    private RazorpayPaymentVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->razorpayClient = m::mock(RazorpayClient::class);
        $this->configurationService = m::mock(RazorpayConfigurationService::class);
        $this->logger = m::mock(LoggerInterface::class);

        $this->service = new RazorpayPaymentVerificationService(
            razorpayClient: $this->razorpayClient,
            configurationService: $this->configurationService,
            logger: $this->logger,
        );
    }

    public function testVerifyPaymentSignatureSuccessfully(): void
    {
        // Arrange
        $orderId = 'order_test123';
        $paymentId = 'pay_test456';
        $signature = 'valid_signature_hash';
        $keySecret = 'test_key_secret';

        // Mock configuration service
        $this->configurationService->shouldReceive('getKeySecret')
            ->once()
            ->andReturn($keySecret);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay payment verification requested', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay payment signature verified successfully', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with($orderId, $paymentId, $signature, $keySecret)
            ->andReturn(true);

        // Act
        $result = $this->service->verifyPaymentSignature($orderId, $paymentId, $signature);

        // Assert
        $this->assertTrue($result);
    }

    public function testVerifyPaymentSignatureWithInvalidSignature(): void
    {
        // Arrange
        $orderId = 'order_test123';
        $paymentId = 'pay_test456';
        $signature = 'invalid_signature_hash';
        $keySecret = 'test_key_secret';

        // Mock configuration service
        $this->configurationService->shouldReceive('getKeySecret')
            ->once()
            ->andReturn($keySecret);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay payment verification requested', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ]);

        $this->logger->shouldReceive('warning')
            ->once()
            ->with('Razorpay payment signature verification failed', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('verifyPaymentSignature')
            ->once()
            ->with($orderId, $paymentId, $signature, $keySecret)
            ->andReturn(false);

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage('Payment signature verification failed');

        $this->service->verifyPaymentSignature($orderId, $paymentId, $signature);
    }

    public function testVerifyPaymentSignatureWithEmptyOrderId(): void
    {
        // Arrange
        $orderId = '';
        $paymentId = 'pay_test456';
        $signature = 'signature_hash';

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage('Order ID is required for payment verification');

        $this->service->verifyPaymentSignature($orderId, $paymentId, $signature);
    }

    public function testVerifyPaymentSignatureWithEmptyPaymentId(): void
    {
        // Arrange
        $orderId = 'order_test123';
        $paymentId = '';
        $signature = 'signature_hash';

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage('Payment ID is required for payment verification');

        $this->service->verifyPaymentSignature($orderId, $paymentId, $signature);
    }

    public function testVerifyPaymentSignatureWithEmptySignature(): void
    {
        // Arrange
        $orderId = 'order_test123';
        $paymentId = 'pay_test456';
        $signature = '';

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage('Payment signature is required for verification');

        $this->service->verifyPaymentSignature($orderId, $paymentId, $signature);
    }

    public function testGetPaymentDetailsSuccessfully(): void
    {
        // Arrange
        $paymentId = 'pay_test456';
        $expectedPayment = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Fetching Razorpay payment details', [
                'payment_id' => $paymentId,
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay payment details retrieved successfully', [
                'payment_id' => $paymentId,
                'status' => 'captured',
                'amount' => 50000,
            ]);

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
        $this->assertEquals('captured', $result->status);
    }

    public function testGetPaymentDetailsWithInvalidPaymentId(): void
    {
        // Arrange
        $paymentId = '';

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage('Payment ID is required');

        $this->service->getPaymentDetails($paymentId);
    }

    public function testValidatePaymentStatusWithValidStatus(): void
    {
        // Arrange
        $payment = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890
        );

        // Act
        $result = $this->service->validatePaymentStatus($payment);

        // Assert
        $this->assertTrue($result);
    }

    public function testValidatePaymentStatusWithInvalidStatus(): void
    {
        // Arrange
        $payment = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'failed',
            method: 'card',
            createdAt: 1234567890
        );

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage("Payment status 'failed' is not valid for completion");

        $this->service->validatePaymentStatus($payment);
    }

    public function testValidatePaymentAmountWithMatchingAmount(): void
    {
        // Arrange
        $payment = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890
        );
        $expectedAmount = 50000;

        // Act
        $result = $this->service->validatePaymentAmount($payment, $expectedAmount);

        // Assert
        $this->assertTrue($result);
    }

    public function testValidatePaymentAmountWithMismatchedAmount(): void
    {
        // Arrange
        $payment = new RazorpayPaymentDTO(
            paymentId: 'pay_test456',
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890
        );
        $expectedAmount = 60000;

        // Act & Assert
        $this->expectException(RazorpayPaymentVerificationFailedException::class);
        $this->expectExceptionMessage('Payment amount mismatch. Expected: ₹600.00, Received: ₹500.00');

        $this->service->validatePaymentAmount($payment, $expectedAmount);
    }
}