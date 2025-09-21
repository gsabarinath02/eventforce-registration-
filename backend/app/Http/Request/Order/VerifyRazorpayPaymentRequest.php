<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;

class VerifyRazorpayPaymentRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'razorpay_payment_id' => 'required|string',
            'razorpay_order_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'razorpay_payment_id.required' => 'Razorpay payment ID is required',
            'razorpay_order_id.required' => 'Razorpay order ID is required',
            'razorpay_signature.required' => 'Razorpay signature is required',
        ];
    }
}