<?php

namespace Database\Factories;

use App\Models\NewsletterCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NewsletterCampaign> */
class NewsletterCampaignFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['subject' => fake()->sentence(4), 'body' => fake()->paragraph()."\n\n".fake()->paragraph(), 'status' => NewsletterCampaign::DRAFT];
    }
}
