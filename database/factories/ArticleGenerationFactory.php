<?php

namespace Database\Factories;

use App\Models\ArticleGeneration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ArticleGenerationFactory extends Factory
{
    protected $model = ArticleGeneration::class;

    public function definition(): array
    {
        return [
            'job_id' => (string) Str::ulid(),
            'scenario' => fake()->randomElement([
                'three_tier_both',
                'three_tier_gpt',
                'three_tier_gemini',
                'simple_gpt',
                'simple_gemini'
            ]),
            'query' => fake()->sentence(3),
            'keywords' => [fake()->word(), fake()->word()],
            'required_keywords' => [fake()->word()],
            'language' => 'English',
            'country' => 'USA',
            'word_count' => fake()->numberBetween(1000, 5000),
            'page_type' => 'blog article',
            'status' => fake()->randomElement(['queued', 'processing', 'completed', 'failed']),
            'result' => null,
            'tokens_used' => null,
            'error_message' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'result' => [
                'title' => fake()->sentence(),
                'content' => fake()->paragraphs(10, true),
                'meta_titles' => [fake()->sentence(), fake()->sentence()],
                'meta_descriptions' => [fake()->sentence(), fake()->sentence()],
                'word_count' => $attributes['word_count'],
                'tokens_used' => fake()->numberBetween(1000, 5000),
            ],
            'tokens_used' => [
                'total' => fake()->numberBetween(1000, 5000)
            ],
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Test error: ' . fake()->sentence(),
        ]);
    }
}
