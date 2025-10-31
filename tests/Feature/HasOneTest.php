<?php

namespace Tests\Feature;

use Tests\Models\Profile;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class HasOneTest extends GraphTestCase
{
    public function test_user_can_define_has_one_relationship()
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $profile = $user->profile()->create(['bio' => 'Software Developer']);

        $found = $user->profile;

        $this->assertNotNull($found);
        $this->assertInstanceOf(Profile::class, $found);
        $this->assertEquals('Software Developer', $found->bio);
        $this->assertEquals($user->id, $found->user_id);
    }

    public function test_has_one_returns_null_when_no_related_model()
    {
        $user = User::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $profile = $user->profile;

        $this->assertNull($profile);
    }

    public function test_has_one_returns_only_first_model_even_with_multiple()
    {
        $user = User::create(['name' => 'Bob Smith', 'email' => 'bob@example.com']);

        // Create multiple profiles (though this shouldn't happen in real app)
        $profile1 = Profile::create(['bio' => 'First Profile', 'user_id' => $user->id]);
        $profile2 = Profile::create(['bio' => 'Second Profile', 'user_id' => $user->id]);

        $found = $user->profile;

        $this->assertNotNull($found);
        $this->assertEquals('First Profile', $found->bio);
        // Should only return one, not a collection
        $this->assertInstanceOf(Profile::class, $found);
    }

    public function test_user_can_eager_load_has_one()
    {
        $user = User::create(['name' => 'Alice Brown', 'email' => 'alice@example.com']);
        $profile = $user->profile()->create(['bio' => 'Designer']);

        $loaded = User::with('profile')->find($user->id);

        $this->assertTrue($loaded->relationLoaded('profile'));
        $this->assertNotNull($loaded->profile);
        $this->assertEquals('Designer', $loaded->profile->bio);
    }

    public function test_can_eager_load_has_one_with_multiple_users()
    {
        $user1 = User::create(['name' => 'User One', 'email' => 'user1@example.com']);
        $user2 = User::create(['name' => 'User Two', 'email' => 'user2@example.com']);

        $profile1 = $user1->profile()->create(['bio' => 'Profile One']);
        $profile2 = $user2->profile()->create(['bio' => 'Profile Two']);

        $users = User::with('profile')->whereIn('id', [$user1->id, $user2->id])->get();

        $this->assertCount(2, $users);
        $this->assertTrue($users[0]->relationLoaded('profile'));
        $this->assertTrue($users[1]->relationLoaded('profile'));
        $this->assertNotNull($users->firstWhere('id', $user1->id)->profile);
        $this->assertEquals('Profile One', $users->firstWhere('id', $user1->id)->profile->bio);
        $this->assertEquals('Profile Two', $users->firstWhere('id', $user2->id)->profile->bio);
    }

    public function test_can_query_has_one_relationship()
    {
        $user = User::create(['name' => 'Query User', 'email' => 'query@example.com']);
        $profile = $user->profile()->create(['bio' => 'Senior Developer', 'location' => 'NYC']);

        $found = $user->profile()->where('location', 'NYC')->first();

        $this->assertNotNull($found);
        $this->assertEquals('Senior Developer', $found->bio);
        $this->assertEquals('NYC', $found->location);
    }

    public function test_profile_belongs_to_user()
    {
        // Test inverse relationship
        $user = User::create(['name' => 'Parent User', 'email' => 'parent@example.com']);
        $profile = $user->profile()->create(['bio' => 'Has User']);

        $parent = $profile->user;

        $this->assertNotNull($parent);
        $this->assertEquals($user->id, $parent->id);
        $this->assertEquals('Parent User', $parent->name);
    }

    public function test_with_count_adds_relationship_count_for_has_one()
    {
        $user1 = User::create(['name' => 'User With Profile', 'email' => 'with@example.com']);
        $user2 = User::create(['name' => 'User Without Profile', 'email' => 'without@example.com']);

        $user1->profile()->create(['bio' => 'Has a profile']);

        $users = User::withCount('profile')->whereIn('id', [$user1->id, $user2->id])->get();

        $withProfile = $users->firstWhere('id', $user1->id);
        $withoutProfile = $users->firstWhere('id', $user2->id);

        $this->assertEquals(1, $withProfile->profile_count);
        $this->assertEquals(0, $withoutProfile->profile_count);
        $this->assertIsInt($withProfile->profile_count);
    }

    public function test_has_filters_models_that_have_the_relationship()
    {
        $user1 = User::create(['name' => 'User With Profile', 'email' => 'with@example.com']);
        $user2 = User::create(['name' => 'User Without Profile', 'email' => 'without@example.com']);
        $user3 = User::create(['name' => 'Another Without', 'email' => 'another@example.com']);

        $user1->profile()->create(['bio' => 'Has a profile']);

        // Users with profiles
        $usersWithProfiles = User::has('profile')->get();

        $this->assertCount(1, $usersWithProfiles);
        $this->assertEquals($user1->id, $usersWithProfiles->first()->id);
    }

    public function test_doesnt_have_filters_models_without_relationship()
    {
        $user1 = User::create(['name' => 'User With Profile', 'email' => 'with@example.com']);
        $user2 = User::create(['name' => 'User Without Profile', 'email' => 'without@example.com']);
        $user3 = User::create(['name' => 'Another Without', 'email' => 'another@example.com']);

        $user1->profile()->create(['bio' => 'Has a profile']);

        // Users without profiles
        $usersWithoutProfiles = User::doesntHave('profile')->get();

        $this->assertCount(2, $usersWithoutProfiles);
        $userIds = $usersWithoutProfiles->pluck('id');
        $this->assertContains($user2->id, $userIds);
        $this->assertContains($user3->id, $userIds);
        $this->assertNotContains($user1->id, $userIds);
    }

    public function test_where_has_filters_by_relationship_constraints()
    {
        $user1 = User::create(['name' => 'NYC User', 'email' => 'nyc@example.com']);
        $user2 = User::create(['name' => 'LA User', 'email' => 'la@example.com']);
        $user3 = User::create(['name' => 'SF User', 'email' => 'sf@example.com']);

        $user1->profile()->create(['bio' => 'Developer', 'location' => 'NYC']);
        $user2->profile()->create(['bio' => 'Designer', 'location' => 'LA']);
        $user3->profile()->create(['bio' => 'Manager', 'location' => 'SF']);

        // Users with NYC profiles
        $nycUsers = User::whereHas('profile', function ($query) {
            $query->where('location', 'NYC');
        })->get();

        $this->assertCount(1, $nycUsers);
        $this->assertEquals($user1->id, $nycUsers->first()->id);

        // Users with Designer bio
        $designers = User::whereHas('profile', function ($query) {
            $query->where('bio', 'CONTAINS', 'Designer');
        })->get();

        $this->assertCount(1, $designers);
        $this->assertEquals($user2->id, $designers->first()->id);
    }

    public function test_update_through_relationship_updates_related_model()
    {
        $user = User::create(['name' => 'Update User', 'email' => 'update@example.com']);
        $profile = $user->profile()->create(['bio' => 'Original Bio', 'location' => 'NYC']);

        // Update profile through relationship
        $updated = $user->profile()->update(['bio' => 'Updated Bio']);

        $this->assertEquals(1, $updated);

        // Verify update
        $freshProfile = Profile::find($profile->id);
        $this->assertEquals('Updated Bio', $freshProfile->bio);
        $this->assertEquals('NYC', $freshProfile->location); // Location unchanged
    }

    public function test_delete_through_relationship_removes_related_model()
    {
        $user = User::create(['name' => 'Delete User', 'email' => 'delete@example.com']);
        $profile = $user->profile()->create(['bio' => 'To Be Deleted']);
        $profileId = $profile->id;

        // Delete profile through relationship
        $deleted = $user->profile()->delete();

        $this->assertEquals(1, $deleted);

        // Verify deletion
        $this->assertNull(Profile::find($profileId));

        // User should still exist
        $this->assertNotNull(User::find($user->id));
    }

    public function test_create_sets_foreign_key_and_returns_single_model()
    {
        $user = User::create(['name' => 'Create User', 'email' => 'create@example.com']);

        // Create profile through relationship (no need to specify user_id)
        $profile = $user->profile()->create(['bio' => 'Auto FK Profile']);

        // Should return a single model, not a collection
        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertNotNull($profile->user_id);
        $this->assertEquals($user->id, $profile->user_id);

        // Verify relationship works
        $this->assertEquals($user->id, $profile->user->id);
        $this->assertNotNull($user->fresh()->profile);
    }
}
