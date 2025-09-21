<?php

namespace HiEvents\Services\Domain\Payment\Razorpay;

use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayPaymentDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayRefundDTO;
use HiEvents\Values\MoneyValue;

interface RazorpayServiceInterface
{
    /**
     * Create a Razorpay order for payment processing
     */
    public function createOrder(CreateRazorpayOrderRequestDTO $orderRequest): CreateRazorpayOrderResponseDTO;

    /**
     * Verify payment signature using HMAC SHA256
     */
    public function verifyPayment(string $paymentId, string $orderId, string $signature): bool;

    /**
     * Capture an authorized payment
     */
    public function capturePayment(string $paymentId, MoneyValue $amount): RazorpayPaymentDTO;

    /**
     * Process a refund for a payment
     */
    public function refundPayment(string $paymentId, MoneyValue $amount): RazorpayRefundDTO;

    /**
     * Get payment details from Razorpay
     */
    public function getPaymentDetails(string $paymentId): RazorpayPaymentDTO;

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}