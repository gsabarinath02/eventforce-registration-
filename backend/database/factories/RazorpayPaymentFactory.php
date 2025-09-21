<?php

declare(strict_types=1);

namespace Database\Factories;

use HiEvents\Models\RazorpayPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class RazorpayPaymentFactory extends Factory
{
    protected $model = RazorpayPayment::class;

    public function definition(): array
    {
        return [
            'razorpay_order_id' => 'order_' . $this->faker->randomNumber(8),
            'razorpay_payment_id' => 'pay_' . $this->faker->randomNumber(8),
            'razorpay_signature' => $this->faker->sha256(),
            'amount_received' => $this->faker->numberBetween(1000, 10000),
            'refund_id' => null,
            'last_error' => null,
        ];
    }
}