<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Profile;
use Tests\Models\Role;
use Tests\Models\User;

class RelationshipSeeder extends Seeder
{
    /**
     * Run the database seeds to create related data.
     */
    public function run(): void
    {
        // Create roles first
        $roles = Role::factory()->count(3)->create();

        // Create users with profiles, posts, and comments
        User::factory()
            ->count(5)
            ->has(Profile::factory())
            ->has(
                Post::factory()
                    ->count(2)
                    ->has(Comment::factory()->count(3))
            )
            ->create()
            ->each(function ($user) use ($roles) {
                // Attach roles to users
                $user->roles()->attach($roles->pluck('id')->toArray());
            });
    }
}
