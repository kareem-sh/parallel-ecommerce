<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    /** Possible order statuses used throughout the app. */
    private const STATUSES = ['created', 'processing', 'shipped', 'delivered', 'cancelled'];

    public function definition(): array
    {
        return [
            'customer_email' => fake()->safeEmail(),
            'status'         => fake()->randomElement(self::STATUSES),
            'total'          => 0.00,   // recalculated after items are attached
            'created_at'     => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function created(): static
    {
        return $this->state(['status' => 'created']);
    }

    public function delivered(): static
    {
        return $this->state(['status' => 'delivered']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    /** Pin the order to a specific date (for daily-sales seeding). */
    public function onDate(string $date): static
    {
        return $this->state([
            'created_at' => $date.' '.fake()->time(),
            'updated_at' => $date.' '.fake()->time(),
        ]);
    }
}
