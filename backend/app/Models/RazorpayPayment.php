<?php

namespace HiEvents\Models;

use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RazorpayPayment extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'last_error' => 'array',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            RazorpayPaymentDomainObjectAbstract::ORDER_ID,
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_ORDER_ID,
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_PAYMENT_ID,
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_SIGNATURE,
            RazorpayPaymentDomainObjectAbstract::AMOUNT_RECEIVED,
            RazorpayPaymentDomainObjectAbstract::REFUND_ID,
            RazorpayPaymentDomainObjectAbstract::LAST_ERROR,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}