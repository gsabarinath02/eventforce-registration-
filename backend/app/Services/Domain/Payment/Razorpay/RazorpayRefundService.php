<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayRefundFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Values\MoneyValue;
use Psr\Log\LoggerInterface;
use Throwable;

class RazorpayRefundService
{
    private const REFUNDABLE_STATUSES = ['captured', 'authorized'];
    private const MIN_REFUND_AMOUNT = 100; // ₹1.00 in paise for INR

    public function __construct(
        private readonly RazorpayClient $razorpayClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process a full refund for a payment
     *
     * @throws RazorpayRefundFailedException
     */
    public function refundPayment(string $paymentId, MoneyValue $amount): RazorpayRefundDTO
    {
        $this->logger->info('Razorpay refund requested', [
            'payment_id' => $paymentId,
            'amount' => $amount->toMinorUnit(),
        ]);

        try {
            // Validate payment ID format
            $this->validatePaymentId($paymentId);

            // Get payment details to validate refund eligibility
            $paymentDetails = $this->getPaymentDetails($paymentId);

            // Validate refund eligibility
            $this->validateRefundEligibility($paymentDetails, $amount);

            // Process the refund
            $refundResult = $this->razorpayClient->refundPayment($paymentId, $amount);

            $this->logger->info('Razorpay refund processed successfully', [
                'payment_id' => $paymentId,
                'refund_id' => $refundResult->refundId,
                'amount' => $refundResult->amount,
                'status' => $refundResult->status,
            ]);

            return $refundResult;

        } catch (RazorpayRefundFailedException $exception) {
            $this->logger->error('Razorpay refund failed', [
                'payment_id' => $paymentId,
                'amount' => $amount->toMinorUnit(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;

        } catch (Throwable $exception) {
            $this->logger->error('Unexpected error during refund processing', [
                'payment_id' => $paymentId,
                'amount' => $amount->toMinorUnit(),
                'error' => $exception->getMessage(),
            ]);

            throw new RazorpayRefundFailedException(
                'Refund processing failed due to an unexpected error'
            );
        }
    }

    /**
     * Process a partial refund for a payment
     *
     * @throws RazorpayRefundFailedException
     */
    public function partialRefund(string $paymentId, MoneyValue $refundAmount): RazorpayRefundDTO
    {
        $this->logger->info('Razorpay partial refund requested', [
            'payment_id' => $paymentId,
            'refund_amount' => $refundAmount->toMinorUnit(),
        ]);

        // Get payment details to validate partial refund
        $paymentDetails = $this->getPaymentDetails($paymentId);

        // Validate partial refund amount
        $this->validatePartialRefundAmount($paymentDetails, $refundAmount);

        return $this->refundPayment($paymentId, $refundAmount);
    }

    /**
     * Get refund status for a specific refund
     *
     * @throws RazorpayRefundFailedException
     */
    public function getRefundStatus(string $refundId): array
    {
        try {
            $this->logger->info('Fetching refund status', [
                'refund_id' => $refundId,
            ]);

            // Note: This would require additional Razorpay API implementation
            // For now, we'll return a placeholder response
            return [
                'refund_id' => $refundId,
                'status' => 'processed', // This would come from actual API call
            ];

        } catch (Throwable $exception) {
            $this->logger->error('Failed to fetch refund status', [
                'refund_id' => $refundId,
                'error' => $exception->getMessage(),
            ]);

            throw new RazorpayRefundFailedException(
                'Failed to fetch refund status'
            );
        }
    }

    /**
     * Check if a payment is eligible for refund
     */
    public function isRefundEligible(string $paymentId): bool
    {
        try {
            $paymentDetails = $this->getPaymentDetails($paymentId);
            
            // Check if payment status allows refunds
            if (!in_array($paymentDetails->status, self::REFUNDABLE_STATUSES, true)) {
                return false;
            }

            // Check if payment has already been fully refunded
            if ($paymentDetails->amountRefunded && $paymentDetails->amountRefunded >= $paymentDetails->amount) {
                return false;
            }

            return true;

        } catch (Throwable $exception) {
            $this->logger->error('Error checking refund eligibility', [
                'payment_id' => $paymentId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Calculate maximum refundable amount for a payment
     */
    public function getMaxRefundableAmount(string $paymentId): int
    {
        try {
            $paymentDetails = $this->getPaymentDetails($paymentId);
            
            $refundedAmount = $paymentDetails->amountRefunded ?? 0;
            $maxRefundable = $paymentDetails->amount - $refundedAmount;

            return max(0, $maxRefundable);

        } catch (Throwable $exception) {
            $this->logger->error('Error calculating max refundable amount', [
                'payment_id' => $paymentId,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Get payment details from Razorpay
     *
     * @throws RazorpayRefundFailedException
     */
    private function getPaymentDetails(string $paymentId): RazorpayPaymentDTO
    {
        try {
            return $this->razorpayClient->getPaymentDetails($paymentId);

        } catch (Throwable $exception) {
            throw new RazorpayRefundFailedException(
                'Unable to fetch payment details for refund validation'
            );
        }
    }

    /**
     * Validate payment ID format
     *
     * @throws RazorpayRefundFailedException
     */
    private function validatePaymentId(string $paymentId): void
    {
        if (empty($paymentId)) {
            throw new RazorpayRefundFailedException('Payment ID is required');
        }

        if (!str_starts_with($paymentId, 'pay_')) {
            throw new RazorpayRefundFailedException('Invalid payment ID format');
        }
    }

    /**
     * Validate refund eligibility
     *
     * @throws RazorpayRefundFailedException
     */
    private function validateRefundEligibility(RazorpayPaymentDTO $paymentDetails, MoneyValue $refundAmount): void
    {
        // Check payment status
        if (!in_array($paymentDetails->status, self::REFUNDABLE_STATUSES, true)) {
            throw new RazorpayRefundFailedException(
                "Payment with status '{$paymentDetails->status}' is not eligible for refund. " .
                "Eligible statuses: " . implode(', ', self::REFUNDABLE_STATUSES)
            );
        }

        // Check refund amount
        $refundAmountMinor = $refundAmount->toMinorUnit();
        
        if ($refundAmountMinor <= 0) {
            throw new RazorpayRefundFailedException('Refund amount must be greater than zero');
        }

        if ($refundAmountMinor < self::MIN_REFUND_AMOUNT) {
            throw new RazorpayRefundFailedException(
                'Refund amount is below minimum threshold of ₹1.00'
            );
        }

        // Check if refund amount exceeds payment amount
        if ($refundAmountMinor > $paymentDetails->amount) {
            throw new RazorpayRefundFailedException(
                'Refund amount cannot exceed the original payment amount'
            );
        }

        // Check if refund would exceed available refundable amount
        $alreadyRefunded = $paymentDetails->amountRefunded ?? 0;
        $availableForRefund = $paymentDetails->amount - $alreadyRefunded;

        if ($refundAmountMinor > $availableForRefund) {
            throw new RazorpayRefundFailedException(
                "Refund amount exceeds available refundable amount of ₹" . 
                number_format($availableForRefund / 100, 2)
            );
        }
    }

    /**
     * Validate partial refund amount
     *
     * @throws RazorpayRefundFailedException
     */
    private function validatePartialRefundAmount(RazorpayPaymentDTO $paymentDetails, MoneyValue $refundAmount): void
    {
        $refundAmountMinor = $refundAmount->toMinorUnit();
        
        // Ensure it's not a full refund
        if ($refundAmountMinor >= $paymentDetails->amount) {
            throw new RazorpayRefundFailedException(
                'Use full refund method for refunding the entire payment amount'
            );
        }

        // Standard refund validation
        $this->validateRefundEligibility($paymentDetails, $refundAmount);
    }
}