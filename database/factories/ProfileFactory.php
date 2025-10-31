<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Models\Profile;
use Tests\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Tests\Models\Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Profile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bio' => fake()->paragraph(),
            'website' => fake()->url(),
            'user_id' => User::factory(),
        ];
    }
}
