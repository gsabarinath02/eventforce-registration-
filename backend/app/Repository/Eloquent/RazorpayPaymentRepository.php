<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\Models\RazorpayPayment;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;

class RazorpayPaymentRepository extends BaseRepository implements RazorpayPaymentRepositoryInterface
{
    protected function getModel(): string
    {
        return RazorpayPayment::class;
    }

    public function getDomainObject(): string
    {
        return RazorpayPaymentDomainObject::class;
    }
}