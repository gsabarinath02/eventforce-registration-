<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\DTOs;

readonly class RazorpayPaymentDTO
{
    public function __construct(
        public ?string $paymentId = null,
        public ?string $orderId = null,
        public ?string $status = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $method = null,
        public ?int $amountRefunded = null,
        public ?string $refundStatus = null,
        public ?bool $captured = null,
        public ?string $description = null,
        public ?array $notes = null,
        public ?int $fee = null,
        public ?int $tax = null,
        public ?string $errorCode = null,
        public ?string $errorDescription = null,
        public ?int $createdAt = null,
    ) {
    }
}