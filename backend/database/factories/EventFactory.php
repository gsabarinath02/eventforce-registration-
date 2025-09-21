<?php

declare(strict_types=1);

namespace Database\Factories;

use HiEvents\Helper\IdHelper;
use HiEvents\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'start_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'end_date' => $this->faker->dateTimeBetween('+1 month', '+2 months'),
            'timezone' => $this->faker->timezone(),
            'currency' => 'USD',
            'status' => 'LIVE',
            'short_id' => IdHelper::shortId(IdHelper::EVENT_PREFIX),
            'user_id' => function () {
                return \HiEvents\Models\User::factory()->create()->id;
            },
            'location_details' => [
                'venue_name' => $this->faker->company(),
                'address_line_1' => $this->faker->streetAddress(),
                'city' => $this->faker->city(),
                'state_or_region' => $this->faker->state(),
                'zip_or_postal_code' => $this->faker->postcode(),
                'country' => $this->faker->countryCode(),
            ],
            'attributes' => [],
        ];
    }

    public function withRazorpaySettings(): static
    {
        return $this->afterCreating(function ($event) {
            $event->event_settings()->create([
                'payment_providers' => ['RAZORPAY'],
                'razorpay_key_id' => 'rzp_test_123',
                'razorpay_key_secret' => 'test_secret',
                'razorpay_webhook_secret' => 'webhook_secret',
            ]);
        });
    }
}