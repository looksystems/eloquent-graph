<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Models\Role;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Tests\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Admin',
                'Editor',
                'Author',
                'Contributor',
                'Subscriber',
                'Moderator',
                'Developer',
                'Manager',
            ]),
            'permissions' => fake()->randomElements([
                'create',
                'read',
                'update',
                'delete',
                'publish',
                'moderate',
            ], fake()->numberBetween(1, 4)),
        ];
    }
}
