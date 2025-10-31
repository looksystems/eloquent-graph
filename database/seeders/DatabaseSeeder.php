<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Role;
use Tests\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create some roles
        $roles = Role::factory()->count(5)->create();

        // Create users with posts and relationships
        User::factory()
            ->count(10)
            ->has(Post::factory()->count(3))
            ->create()
            ->each(function ($user) use ($roles) {
                // Attach random roles to each user
                $user->roles()->attach(
                    $roles->random(rand(1, 3))->pluck('id')->toArray(),
                    ['assigned_at' => now()]
                );
            });

        // Add comments to posts
        Post::all()->each(function ($post) {
            Comment::factory()
                ->count(rand(0, 5))
                ->for($post)
                ->create();
        });
    }
}
