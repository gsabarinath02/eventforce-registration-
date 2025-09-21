<?php

declare(strict_types=1);

namespace Database\Factories;

use HiEvents\Models\EventSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventSettingFactory extends Factory
{
    protected $model = EventSetting::class;

    public function definition(): array
    {
        return [
            'payment_providers' => ['STRIPE'],
            'stripe_publishable_key' => 'pk_test_123',
            'stripe_secret_key' => 'sk_test_123',
            'location_details' => [
                'venue_name' => $this->faker->company(),
                'address_line_1' => $this->faker->streetAddress(),
                'city' => $this->faker->city(),
                'state_or_region' => $this->faker->state(),
                'zip_or_postal_code' => $this->faker->postcode(),
                'country' => $this->faker->countryCode(),
            ],
        ];
    }
}