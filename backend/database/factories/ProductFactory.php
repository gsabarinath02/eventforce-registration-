<?php

declare(strict_types=1);

namespace Database\Factories;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),
            'type' => 'TICKET',
            'status' => 'ACTIVE',
            'short_id' => IdHelper::shortId(IdHelper::PRODUCT_PREFIX),
            'max_per_order' => $this->faker->numberBetween(1, 10),
            'min_per_order' => 1,
            'quantity_available' => $this->faker->numberBetween(50, 500),
            'quantity_sold' => 0,
            'sale_start_date' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'sale_end_date' => $this->faker->dateTimeBetween('+1 week', '+1 month'),
            'show_quantity_remaining' => false,
            'order' => 1,
        ];
    }
}