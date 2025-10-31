<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Models\Comment;
use Tests\Models\Post;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Tests\Models\Comment>
 */
class CommentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Comment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content' => fake()->sentences(fake()->numberBetween(1, 3), true),
            'author' => fake()->name(),
            'likes' => fake()->numberBetween(0, 100),
            'post_id' => Post::factory(),
        ];
    }

    /**
     * Indicate that the comment is popular.
     */
    public function popular(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'likes' => fake()->numberBetween(100, 1000),
            ];
        });
    }
}
