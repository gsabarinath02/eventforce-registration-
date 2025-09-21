<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;

class RefundRazorpayOrderRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'refund_amount' => 'required|numeric|gt:0',
            'currency' => 'sometimes|string|in:INR,USD,EUR,GBP',
        ];
    }

    public function messages(): array
    {
        return [
            'refund_amount.required' => 'Refund amount is required',
            'refund_amount.numeric' => 'Refund amount must be a number',
            'refund_amount.gt' => 'Refund amount must be greater than 0',
            'currency.in' => 'Currency must be one of: INR, USD, EUR, GBP',
        ];
    }
}