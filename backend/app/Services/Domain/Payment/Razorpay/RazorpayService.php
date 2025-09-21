<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Values\MoneyValue;
use Psr\Log\LoggerInterface;

class RazorpayService implements RazorpayServiceInterface
{
    public function __construct(
        private readonly RazorpayClient $razorpayClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a Razorpay order for payment processing
     */
    public function createOrder(CreateRazorpayOrderRequestDTO $orderRequest): CreateRazorpayOrderResponseDTO
    {
        return $this->razorpayClient->createOrder($orderRequest);
    }

    /**
     * Verify payment signature using HMAC SHA256
     */
    public function verifyPayment(string $paymentId, string $orderId, string $signature): bool
    {
        return $this->razorpayClient->verifyPaymentSignature($paymentId, $orderId, $signature);
    }

    /**
     * Capture an authorized payment
     */
    public function capturePayment(string $paymentId, MoneyValue $amount): RazorpayPaymentDTO
    {
        return $this->razorpayClient->capturePayment($paymentId, $amount);
    }

    /**
     * Process a refund for a payment
     */
    public function refundPayment(string $paymentId, MoneyValue $amount): RazorpayRefundDTO
    {
        return $this->razorpayClient->refundPayment($paymentId, $amount);
    }

    /**
     * Get payment details from Razorpay
     */
    public function getPaymentDetails(string $paymentId): RazorpayPaymentDTO
    {
        return $this->razorpayClient->getPaymentDetails($paymentId);
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->razorpayClient->verifyWebhookSignature($payload, $signature);
    }
}