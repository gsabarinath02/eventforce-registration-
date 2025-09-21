<?php

declare(strict_types=1);

namespace Database\Factories;

use HiEvents\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition(): array
    {
        return [
            'label' => $this->faker->words(2, true),
            'price' => $this->faker->numberBetween(1000, 10000), // Price in cents
            'is_default' => true,
            'sale_start_date' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'sale_end_date' => $this->faker->dateTimeBetween('+1 week', '+1 month'),
            'initial_quantity_available' => $this->faker->numberBetween(50, 500),
            'quantity_sold' => 0,
        ];
    }
}