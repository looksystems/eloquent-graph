<?php

namespace Database\Factories;

use Tests\Models\AdminUser;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Tests\Models\AdminUser>
 */
class AdminUserFactory extends UserFactory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AdminUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_merge(parent::definition(), [
            'role' => 'admin',
            'admin_since' => fake()->dateTimeBetween('-5 years', 'now'),
        ]);
    }
}
