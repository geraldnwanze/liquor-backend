<?php

namespace Database\Factories;

use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NewsletterSubscriber> */
class NewsletterSubscriberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token' => Str::random(48),
            'consented_at' => now(),
            'consent_text' => 'I agree to receive news and offers by email.',
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['confirmed_at' => now()]);
    }
}
