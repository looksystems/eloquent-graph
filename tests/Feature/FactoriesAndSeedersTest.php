<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase;

class FactoriesAndSeedersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearDatabase();
    }

    /**
     * Test 1: Basic factory definition and creation
     */
    public function test_user_can_create_model_with_factory()
    {
        // Create a factory instance for User model
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->id);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertTrue($user->exists);
    }

    /**
     * Test 2: Factory with automatic fake data generation
     */
    public function test_factory_generates_fake_data()
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->name);
        $this->assertNotNull($user->email);
        $this->assertStringContainsString('@', $user->email);
        $this->assertNotEmpty($user->name);
    }

    /**
     * Test 3: Factory make vs create methods
     */
    public function test_factory_make_does_not_persist()
    {
        $user = User::factory()->make([
            'name' => 'Jane Doe',
        ]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('Jane Doe', $user->name);
        $this->assertFalse($user->exists);
        $this->assertNull($user->id);
    }

    /**
     * Test 4: Factory count method for multiple models
     */
    public function test_factory_can_create_multiple_models()
    {
        $users = User::factory()->count(3)->create();

        $this->assertCount(3, $users);
        $users->each(function ($user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertNotNull($user->id);
            $this->assertTrue($user->exists);
        });

        $this->assertEquals(3, User::count());
    }

    /**
     * Test 5: Factory states for different configurations
     */
    public function test_factory_states_modify_attributes()
    {
        // Create a user with unverified state
        $unverifiedUser = User::factory()->unverified()->create();

        // Create a user with admin state
        $adminUser = User::factory()->admin()->create();

        $this->assertNull($unverifiedUser->email_verified_at);
        $this->assertEquals('admin', $adminUser->role);
    }

    /**
     * Test 6: Factory sequences for ordered data
     */
    public function test_factory_sequence_provides_ordered_values()
    {
        $users = User::factory()
            ->count(3)
            ->sequence(
                ['name' => 'First User'],
                ['name' => 'Second User'],
                ['name' => 'Third User']
            )
            ->create();

        $this->assertEquals('First User', $users[0]->name);
        $this->assertEquals('Second User', $users[1]->name);
        $this->assertEquals('Third User', $users[2]->name);
    }

    /**
     * Test 7: Factory with relationships
     */
    public function test_factory_can_create_relationships()
    {
        // Create a user with posts
        $user = User::factory()
            ->has(Post::factory()->count(3))
            ->create();

        $this->assertCount(3, $user->posts);
        $user->posts->each(function ($post) use ($user) {
            $this->assertEquals($user->id, $post->user_id);
        });
    }

    /**
     * Test 8: Factory for() method for belongsTo relationships
     */
    public function test_factory_for_method_sets_belongs_to_relationship()
    {
        $user = User::factory()->create();

        $post = Post::factory()
            ->for($user)
            ->create(['title' => 'Test Post']);

        $this->assertEquals($user->id, $post->user_id);
        $this->assertEquals('Test Post', $post->title);
        $this->assertEquals($user->id, $post->user->id);
    }

    /**
     * Test 9: Factory with many-to-many relationships
     */
    public function test_factory_can_attach_many_to_many_relationships()
    {
        $user = User::factory()
            ->hasAttached(
                Role::factory()->count(2),
                ['assigned_at' => now()]
            )
            ->create();

        $this->assertCount(2, $user->roles);
        $user->roles->each(function ($role) {
            $this->assertNotNull($role->pivot->assigned_at);
        });
    }

    /**
     * Test 10: Factory callbacks (afterMaking and afterCreating)
     */
    public function test_factory_callbacks_are_executed()
    {
        $user = User::factory()
            ->afterMaking(function (User $user) {
                $user->status = 'pending';
            })
            ->afterCreating(function (User $user) {
                $user->profile()->create([
                    'bio' => 'Auto-generated bio',
                ]);
            })
            ->create();

        $this->assertEquals('pending', $user->status);
        $this->assertNotNull($user->profile);
        $this->assertEquals('Auto-generated bio', $user->profile->bio);
    }

    /**
     * Test 11: Factory raw method for array generation
     */
    public function test_factory_raw_returns_array()
    {
        $userData = User::factory()->raw([
            'name' => 'Test User',
        ]);

        $this->assertIsArray($userData);
        $this->assertEquals('Test User', $userData['name']);
        $this->assertArrayHasKey('email', $userData);
    }

    /**
     * Test 12: Database seeder basic functionality
     */
    public function test_database_seeder_can_seed_models()
    {
        // Run the seeder
        $seeder = new \Database\Seeders\DatabaseSeeder;
        $seeder->run();

        // Verify seeded data exists
        $this->assertGreaterThan(0, User::count());
        $this->assertGreaterThan(0, Post::count());
    }

    /**
     * Test 13: Individual seeder classes
     */
    public function test_individual_seeder_can_be_called()
    {
        // Run a specific seeder
        $userSeeder = new \Database\Seeders\UserSeeder;
        $userSeeder->run();

        $users = User::all();
        $this->assertCount(10, $users); // Assuming UserSeeder creates 10 users
    }

    /**
     * Test 14: Seeder with relationships
     */
    public function test_seeder_can_create_related_data()
    {
        $seeder = new \Database\Seeders\RelationshipSeeder;
        $seeder->run();

        // Verify users have posts
        User::all()->each(function ($user) {
            $this->assertGreaterThan(0, $user->posts()->count());
        });
    }

    /**
     * Test 15: Factory definition with Faker
     */
    public function test_factory_uses_faker_for_realistic_data()
    {
        $users = User::factory()->count(10)->create();

        $emails = $users->pluck('email');
        $names = $users->pluck('name');

        // All emails should be unique
        $this->assertEquals($emails->count(), $emails->unique()->count());

        // Names should look realistic (contain spaces for full names)
        $names->each(function ($name) {
            $this->assertMatchesRegularExpression('/^[\p{L}\s\'.-]+$/u', $name);
        });
    }

    /**
     * Test 16: Factory recycle method for reusing existing models
     */
    public function test_factory_recycle_reuses_existing_models()
    {
        $existingUser = User::factory()->create();

        $posts = Post::factory()
            ->count(3)
            ->recycle($existingUser)
            ->create();

        $posts->each(function ($post) use ($existingUser) {
            $this->assertEquals($existingUser->id, $post->user_id);
        });
    }

    /**
     * Test 17: Factory state with closure
     */
    public function test_factory_state_accepts_closure()
    {
        $user = User::factory()
            ->state(function (array $attributes) {
                // State gets the factory definition attributes, not the create() override
                return [
                    'email' => 'custom.state@example.com',
                ];
            })
            ->create(['name' => 'John Doe']);

        $this->assertEquals('custom.state@example.com', $user->email);
    }

    /**
     * Test 18: Factory with graph-specific relationships
     */
    public function test_factory_handles_neo4j_relationships()
    {
        // Test creating a user with posts and comments (multi-level)
        $user = User::factory()
            ->has(
                Post::factory()
                    ->count(2)
                    ->has(Comment::factory()->count(3))
            )
            ->create();

        $this->assertCount(2, $user->posts);

        $totalComments = 0;
        foreach ($user->posts as $post) {
            $totalComments += $post->comments->count();
        }
        $this->assertEquals(6, $totalComments); // 2 posts * 3 comments each
    }

    /**
     * Test 19: Factory definition inheritance
     */
    public function test_factory_can_extend_parent_definition()
    {
        // AdminUser factory extends User factory
        $admin = \Tests\Models\AdminUser::factory()->create();

        $this->assertInstanceOf(\Tests\Models\AdminUser::class, $admin);
        $this->assertEquals('admin', $admin->role);
        $this->assertNotNull($admin->admin_since);
    }

    /**
     * Test 20: Seeder with specific count
     */
    public function test_seeder_respects_count_parameter()
    {
        $seeder = new \Database\Seeders\UserSeeder;
        $seeder->count(5)->run();

        $this->assertEquals(5, User::count());
    }
}
