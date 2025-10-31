<?php

namespace Tests\Feature;

use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class ManyToManyTest extends GraphTestCase
{
    public function test_user_can_have_many_to_many_roles()
    {
        $user = User::create(['name' => 'John']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach([$role1->id, $role2->id]);

        // Reload the relationship to ensure we have fresh data
        $user->load('roles');

        $this->assertCount(2, $user->roles);
        $this->assertTrue($user->roles->contains($role1));
        $this->assertTrue($user->roles->contains($role2));
    }

    public function test_role_can_have_many_to_many_users()
    {
        $role = Role::create(['name' => 'Admin']);
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);

        $role->users()->attach([$user1->id, $user2->id]);

        // Reload the relationship to ensure we have fresh data
        $role->load('users');

        $this->assertCount(2, $role->users);
        $this->assertTrue($role->users->contains($user1));
        $this->assertTrue($role->users->contains($user2));
    }

    public function test_user_can_attach_single_role()
    {
        $user = User::create(['name' => 'John']);
        $role = Role::create(['name' => 'Admin']);

        $user->roles()->attach($role->id);

        // Reload the relationship to ensure we have fresh data
        $user->load('roles');

        $this->assertCount(1, $user->roles);
        $this->assertEquals($role->id, $user->roles->first()->id);
    }

    public function test_user_can_detach_roles()
    {
        $user = User::create(['name' => 'John']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach([$role1->id, $role2->id]);
        $user->roles()->detach($role1->id);

        $freshUser = $user->fresh();
        $this->assertCount(1, $freshUser->roles);
        $this->assertEquals($role2->id, $freshUser->roles->first()->id);
    }

    public function test_user_can_detach_all_roles()
    {
        $user = User::create(['name' => 'John']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach([$role1->id, $role2->id]);
        $user->roles()->detach();

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_user_can_sync_roles()
    {
        $user = User::create(['name' => 'John']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);
        $role3 = Role::create(['name' => 'Viewer']);

        $user->roles()->attach([$role1->id, $role2->id]);
        $user->roles()->sync([$role2->id, $role3->id]);

        $freshUser = $user->fresh();
        $this->assertCount(2, $freshUser->roles);
        $this->assertContains($role2->id, $freshUser->roles->pluck('id')->toArray());
        $this->assertContains($role3->id, $freshUser->roles->pluck('id')->toArray());
        $this->assertNotContains($role1->id, $freshUser->roles->pluck('id')->toArray());
    }

    public function test_user_can_toggle_roles()
    {
        $user = User::create(['name' => 'John']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach($role1->id);
        $user->roles()->toggle([$role1->id, $role2->id]);

        $freshUser = $user->fresh();
        $this->assertCount(1, $freshUser->roles);
        $this->assertEquals($role2->id, $freshUser->roles->first()->id);
    }

    public function test_user_can_eager_load_many_to_many()
    {
        $user = User::create(['name' => 'John']);
        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach([$role1->id, $role2->id]);

        $loaded = User::with('roles')->find($user->id);

        $this->assertTrue($loaded->relationLoaded('roles'));
        $this->assertCount(2, $loaded->roles);
    }

    public function test_many_to_many_handles_pivot_data()
    {
        $user = User::create(['name' => 'John']);
        $role = Role::create(['name' => 'Admin']);

        $user->roles()->attach($role->id, ['assigned_at' => '2025-01-01']);

        // Reload the relationship to ensure we have fresh data
        $user->load('roles');

        $userRole = $user->roles->first();
        $this->assertNotNull($userRole, 'Role should be attached to user');
        $this->assertEquals('2025-01-01', $userRole->pivot->assigned_at);
    }

    public function test_many_to_many_handles_multiple_pivot_attributes()
    {
        $user = User::create(['name' => 'John']);
        $role = Role::create(['name' => 'Admin']);

        $user->roles()->attach($role->id, [
            'assigned_at' => '2025-01-01',
            'assigned_by' => 'System',
            'expires_at' => '2025-12-31',
        ]);

        // Reload the relationship to ensure we have fresh data
        $user->load('roles');

        $userRole = $user->roles->first();
        $this->assertNotNull($userRole, 'Role should be attached to user');
        $this->assertEquals('2025-01-01', $userRole->pivot->assigned_at);
        $this->assertEquals('System', $userRole->pivot->assigned_by);
        $this->assertEquals('2025-12-31', $userRole->pivot->expires_at);
    }
}
