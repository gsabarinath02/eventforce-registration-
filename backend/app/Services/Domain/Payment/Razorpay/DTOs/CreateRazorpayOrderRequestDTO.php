<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\DTOs;

use HiEvents\DataTransferObjects\BaseDTO;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Values\MoneyValue;

class CreateRazorpayOrderRequestDTO extends BaseDTO
{
    public function __construct(
        public readonly MoneyValue $amount,
        public readonly string $currencyCode,
        public readonly AccountDomainObject $account,
        public readonly OrderDomainObject $order,
        public readonly ?string $receipt = null,
        public readonly ?array $notes = null,
    ) {
    }
}