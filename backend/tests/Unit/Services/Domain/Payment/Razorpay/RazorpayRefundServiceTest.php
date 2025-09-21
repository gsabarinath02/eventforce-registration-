<?php

namespace Tests\Unit\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayRefundFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayRefundService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Values\MoneyValue;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class RazorpayRefundServiceTest extends TestCase
{
    private RazorpayClient $razorpayClient;
    private LoggerInterface $logger;
    private RazorpayRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->razorpayClient = m::mock(RazorpayClient::class);
        $this->logger = m::mock(LoggerInterface::class);

        $this->service = new RazorpayRefundService(
            razorpayClient: $this->razorpayClient,
            logger: $this->logger,
        );
    }

    public function testRefundPaymentSuccessfully(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(30000); // ₹300.00
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890,
            amountRefunded: 0
        );

        $expectedRefund = new RazorpayRefundDTO(
            refundId: 'rfnd_test456',
            paymentId: $paymentId,
            amount: 30000,
            currency: 'INR',
            status: 'processed',
            createdAt: 1234567890
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 30000,
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund processed successfully', [
                'payment_id' => $paymentId,
                'refund_id' => 'rfnd_test456',
                'amount' => 30000,
                'status' => 'processed',
            ]);

        // Mock Razorpay client calls
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        $this->razorpayClient->shouldReceive('refundPayment')
            ->once()
            ->with($paymentId, $refundAmount)
            ->andReturn($expectedRefund);

        // Act
        $result = $this->service->refundPayment($paymentId, $refundAmount);

        // Assert
        $this->assertEquals($expectedRefund, $result);
        $this->assertEquals('rfnd_test456', $result->refundId);
        $this->assertEquals(30000, $result->amount);
    }

    public function testRefundPaymentWithInvalidPaymentId(): void
    {
        // Arrange
        $paymentId = 'invalid_id';
        $refundAmount = new MoneyValue(30000);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 30000,
            ]);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage('Invalid payment ID format');

        $this->service->refundPayment($paymentId, $refundAmount);
    }

    public function testRefundPaymentWithEmptyPaymentId(): void
    {
        // Arrange
        $paymentId = '';
        $refundAmount = new MoneyValue(30000);

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 30000,
            ]);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage('Payment ID is required');

        $this->service->refundPayment($paymentId, $refundAmount);
    }

    public function testRefundPaymentWithIneligibleStatus(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(30000);
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'failed', // Ineligible status
            method: 'card',
            createdAt: 1234567890
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 30000,
            ]);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay refund failed', [
                'payment_id' => $paymentId,
                'amount' => 30000,
                'error' => "Payment with status 'failed' is not eligible for refund. Eligible statuses: captured, authorized",
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage("Payment with status 'failed' is not eligible for refund");

        $this->service->refundPayment($paymentId, $refundAmount);
    }

    public function testRefundPaymentWithZeroAmount(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(0);
        
        $paymentDetails = new RazorpayPaymentDTO(
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
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 0,
            ]);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay refund failed', [
                'payment_id' => $paymentId,
                'amount' => 0,
                'error' => 'Refund amount must be greater than zero',
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage('Refund amount must be greater than zero');

        $this->service->refundPayment($paymentId, $refundAmount);
    }

    public function testRefundPaymentWithAmountTooSmall(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(50); // Below minimum ₹1.00
        
        $paymentDetails = new RazorpayPaymentDTO(
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
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 50,
            ]);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay refund failed', [
                'payment_id' => $paymentId,
                'amount' => 50,
                'error' => 'Refund amount is below minimum threshold of ₹1.00',
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage('Refund amount is below minimum threshold of ₹1.00');

        $this->service->refundPayment($paymentId, $refundAmount);
    }

    public function testRefundPaymentWithAmountExceedingPayment(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(60000); // More than payment amount
        
        $paymentDetails = new RazorpayPaymentDTO(
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
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 60000,
            ]);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay refund failed', [
                'payment_id' => $paymentId,
                'amount' => 60000,
                'error' => 'Refund amount cannot exceed the original payment amount',
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage('Refund amount cannot exceed the original payment amount');

        $this->service->refundPayment($paymentId, $refundAmount);
    }

    public function testPartialRefundSuccessfully(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(20000); // ₹200.00 partial refund
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890,
            amountRefunded: 0
        );

        $expectedRefund = new RazorpayRefundDTO(
            refundId: 'rfnd_test456',
            paymentId: $paymentId,
            amount: 20000,
            currency: 'INR',
            status: 'processed',
            createdAt: 1234567890
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay partial refund requested', [
                'payment_id' => $paymentId,
                'refund_amount' => 20000,
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund requested', [
                'payment_id' => $paymentId,
                'amount' => 20000,
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay refund processed successfully', [
                'payment_id' => $paymentId,
                'refund_id' => 'rfnd_test456',
                'amount' => 20000,
                'status' => 'processed',
            ]);

        // Mock Razorpay client calls
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->twice()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        $this->razorpayClient->shouldReceive('refundPayment')
            ->once()
            ->with($paymentId, $refundAmount)
            ->andReturn($expectedRefund);

        // Act
        $result = $this->service->partialRefund($paymentId, $refundAmount);

        // Assert
        $this->assertEquals($expectedRefund, $result);
        $this->assertEquals(20000, $result->amount);
    }

    public function testPartialRefundWithFullAmount(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        $refundAmount = new MoneyValue(50000); // Full amount
        
        $paymentDetails = new RazorpayPaymentDTO(
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
            ->with('Razorpay partial refund requested', [
                'payment_id' => $paymentId,
                'refund_amount' => 50000,
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act & Assert
        $this->expectException(RazorpayRefundFailedException::class);
        $this->expectExceptionMessage('Use full refund method for refunding the entire payment amount');

        $this->service->partialRefund($paymentId, $refundAmount);
    }

    public function testIsRefundEligibleWithEligiblePayment(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890,
            amountRefunded: 0
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act
        $result = $this->service->isRefundEligible($paymentId);

        // Assert
        $this->assertTrue($result);
    }

    public function testIsRefundEligibleWithIneligibleStatus(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'failed',
            method: 'card',
            createdAt: 1234567890
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act
        $result = $this->service->isRefundEligible($paymentId);

        // Assert
        $this->assertFalse($result);
    }

    public function testIsRefundEligibleWithFullyRefundedPayment(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890,
            amountRefunded: 50000 // Fully refunded
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act
        $result = $this->service->isRefundEligible($paymentId);

        // Assert
        $this->assertFalse($result);
    }

    public function testGetMaxRefundableAmount(): void
    {
        // Arrange
        $paymentId = 'pay_test123';
        
        $paymentDetails = new RazorpayPaymentDTO(
            paymentId: $paymentId,
            orderId: 'order_test123',
            amount: 50000,
            currency: 'INR',
            status: 'captured',
            method: 'card',
            createdAt: 1234567890,
            amountRefunded: 20000 // ₹200 already refunded
        );

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andReturn($paymentDetails);

        // Act
        $result = $this->service->getMaxRefundableAmount($paymentId);

        // Assert
        $this->assertEquals(30000, $result); // ₹300 remaining
    }

    public function testGetMaxRefundableAmountWithError(): void
    {
        // Arrange
        $paymentId = 'pay_test123';

        // Mock logger calls
        $this->logger->shouldReceive('error')
            ->once()
            ->with('Error calculating max refundable amount', [
                'payment_id' => $paymentId,
                'error' => 'API Error',
            ]);

        // Mock Razorpay client to throw exception
        $this->razorpayClient->shouldReceive('getPaymentDetails')
            ->once()
            ->with($paymentId)
            ->andThrow(new \Exception('API Error'));

        // Act
        $result = $this->service->getMaxRefundableAmount($paymentId);

        // Assert
        $this->assertEquals(0, $result);
    }
}