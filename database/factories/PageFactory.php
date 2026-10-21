<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Page> */
class PageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return ['title' => Str::title($title), 'slug' => Str::slug($title), 'body' => fake()->paragraph()."\n\n".fake()->paragraph(), 'status' => Page::PUBLISHED, 'show_in_footer' => true];
    }

    public function draft(): static
    {
        return $this->state(['status' => Page::DRAFT]);
    }
}
