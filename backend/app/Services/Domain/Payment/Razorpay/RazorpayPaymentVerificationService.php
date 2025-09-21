<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayPaymentVerificationFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayConfigurationService;
use Psr\Log\LoggerInterface;
use Throwable;

class RazorpayPaymentVerificationService
{
    public function __construct(
        private readonly RazorpayClient $razorpayClient,
        private readonly RazorpayConfigurationService $configurationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Verify payment signature using HMAC SHA256
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    public function verifyPayment(string $paymentId, string $orderId, string $signature): bool
    {
        $this->logger->info('Razorpay payment verification requested', [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
        ]);

        try {
            // Validate input parameters
            $this->validateVerificationInputs($paymentId, $orderId, $signature);

            // Verify signature using Razorpay client
            $isSignatureValid = $this->razorpayClient->verifyPaymentSignature($paymentId, $orderId, $signature);

            if (!$isSignatureValid) {
                $this->logger->warning('Payment signature verification failed', [
                    'payment_id' => $paymentId,
                    'order_id' => $orderId,
                ]);

                return false;
            }

            // Get payment details to validate status
            $paymentDetails = $this->getAndValidatePaymentDetails($paymentId);

            // Verify payment status
            $this->validatePaymentStatus($paymentDetails);

            // Verify order ID matches
            if ($paymentDetails->orderId !== $orderId) {
                $this->logger->error('Payment order ID mismatch', [
                    'payment_id' => $paymentId,
                    'expected_order_id' => $orderId,
                    'actual_order_id' => $paymentDetails->orderId,
                ]);

                throw new RazorpayPaymentVerificationFailedException(
                    'Payment order ID does not match the expected order ID'
                );
            }

            $this->logger->info('Payment verification successful', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'status' => $paymentDetails->status,
                'amount' => $paymentDetails->amount,
            ]);

            return true;

        } catch (RazorpayPaymentVerificationFailedException $exception) {
            $this->logger->error('Payment verification failed', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;

        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error during payment verification', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                'Payment verification failed due to an unexpected error'
            );
        }
    }

    /**
     * Verify webhook signature using HMAC SHA256
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $this->logger->info('Razorpay webhook signature verification requested');

        try {
            // Validate inputs
            if (empty($payload) || empty($signature)) {
                $this->logger->warning('Webhook verification failed - empty payload or signature');
                return false;
            }

            // Use Razorpay client to verify webhook signature
            $isValid = $this->razorpayClient->verifyWebhookSignature($payload, $signature);

            $this->logger->info('Webhook signature verification completed', [
                'is_valid' => $isValid,
            ]);

            return $isValid;

        } catch (Throwable $exception) {
            $this->logger->error('Webhook signature verification failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get payment details and validate they exist
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    public function getAndValidatePaymentDetails(string $paymentId): RazorpayPaymentDTO
    {
        try {
            return $this->razorpayClient->getPaymentDetails($paymentId);

        } catch (Throwable $exception) {
            $this->logger->error('Failed to fetch payment details', [
                'payment_id' => $paymentId,
                'error' => $exception->getMessage(),
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                'Unable to fetch payment details from Razorpay'
            );
        }
    }

    /**
     * Validate verification input parameters
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    private function validateVerificationInputs(string $paymentId, string $orderId, string $signature): void
    {
        if (empty($paymentId)) {
            throw new RazorpayPaymentVerificationFailedException('Payment ID is required');
        }

        if (empty($orderId)) {
            throw new RazorpayPaymentVerificationFailedException('Order ID is required');
        }

        if (empty($signature)) {
            throw new RazorpayPaymentVerificationFailedException('Payment signature is required');
        }

        // Validate payment ID format (Razorpay payment IDs start with 'pay_')
        if (!str_starts_with($paymentId, 'pay_')) {
            throw new RazorpayPaymentVerificationFailedException('Invalid payment ID format');
        }

        // Validate order ID format (Razorpay order IDs start with 'order_')
        if (!str_starts_with($orderId, 'order_')) {
            throw new RazorpayPaymentVerificationFailedException('Invalid order ID format');
        }
    }

    /**
     * Validate payment status is acceptable
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    private function validatePaymentStatus(RazorpayPaymentDTO $paymentDetails): void
    {
        $validStatuses = ['captured', 'authorized'];

        if (!in_array($paymentDetails->status, $validStatuses, true)) {
            throw new RazorpayPaymentVerificationFailedException(
                "Payment status '{$paymentDetails->status}' is not valid for verification. Expected: " . 
                implode(', ', $validStatuses)
            );
        }

        // Additional validation for captured payments
        if ($paymentDetails->status === 'captured' && !$paymentDetails->captured) {
            throw new RazorpayPaymentVerificationFailedException(
                'Payment status is captured but captured flag is false'
            );
        }
    }

    /**
     * Verify payment amount matches expected amount
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    public function verifyPaymentAmount(RazorpayPaymentDTO $paymentDetails, int $expectedAmount): bool
    {
        if ($paymentDetails->amount !== $expectedAmount) {
            $this->logger->error('Payment amount mismatch', [
                'payment_id' => $paymentDetails->paymentId,
                'expected_amount' => $expectedAmount,
                'actual_amount' => $paymentDetails->amount,
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                "Payment amount mismatch. Expected: {$expectedAmount}, Actual: {$paymentDetails->amount}"
            );
        }

        return true;
    }

    /**
     * Verify payment currency matches expected currency
     *
     * @throws RazorpayPaymentVerificationFailedException
     */
    public function verifyPaymentCurrency(RazorpayPaymentDTO $paymentDetails, string $expectedCurrency): bool
    {
        if ($paymentDetails->currency !== $expectedCurrency) {
            $this->logger->error('Payment currency mismatch', [
                'payment_id' => $paymentDetails->paymentId,
                'expected_currency' => $expectedCurrency,
                'actual_currency' => $paymentDetails->currency,
            ]);

            throw new RazorpayPaymentVerificationFailedException(
                "Payment currency mismatch. Expected: {$expectedCurrency}, Actual: {$paymentDetails->currency}"
            );
        }

        return true;
    }
}