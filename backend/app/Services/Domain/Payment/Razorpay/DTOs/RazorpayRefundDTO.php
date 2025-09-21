<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\DTOs;

readonly class RazorpayRefundDTO
{
    public function __construct(
        public ?string $refundId = null,
        public ?string $paymentId = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $status = null,
        public ?string $speedRequested = null,
        public ?string $speedProcessed = null,
        public ?array $notes = null,
        public ?string $receipt = null,
        public ?int $createdAt = null,
        public ?string $batchId = null,
        public ?string $errorCode = null,
        public ?string $errorDescription = null,
    ) {
    }
}