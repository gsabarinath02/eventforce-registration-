<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\DTOs;

readonly class CreateRazorpayOrderResponseDTO
{
    public function __construct(
        public ?string $razorpayOrderId = null,
        public ?string $currency = null,
        public ?int $amount = null,
        public ?string $receipt = null,
        public ?string $status = null,
        public ?array $notes = null,
        public ?string $error = null,
    ) {
    }
}