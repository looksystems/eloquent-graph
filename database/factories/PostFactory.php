<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Models\Post;
use Tests\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Tests\Models\Post>
 */
class PostFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Post::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'content' => fake()->paragraphs(3, true),
            'published' => fake()->boolean(70), // 70% chance of being published
            'views' => fake()->numberBetween(0, 10000),
            'user_id' => User::factory(),
        ];
    }

    /**
     * Indicate that the post is published.
     */
    public function published(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'published' => true,
            ];
        });
    }

    /**
     * Indicate that the post is unpublished.
     */
    public function unpublished(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'published' => false,
            ];
        });
    }
}
