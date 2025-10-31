<?php

namespace Tests\Feature;

use Tests\Models\Role;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class PivotOperationsAdvancedTest extends GraphTestCase
{
    public function test_where_pivot_with_complex_conditions()
    {

        $user = User::create(['name' => 'John']);
        $adminRole = Role::create(['name' => 'Admin']);
        $editorRole = Role::create(['name' => 'Editor']);
        $viewerRole = Role::create(['name' => 'Viewer']);

        $user->roles()->attach($adminRole->id, ['level' => 3, 'active' => true]);
        $user->roles()->attach($editorRole->id, ['level' => 2, 'active' => true]);
        $user->roles()->attach($viewerRole->id, ['level' => 1, 'active' => false]);

        $highLevelRoles = $user->roles()
            ->wherePivot('level', '>=', 2)
            ->wherePivot('active', true)
            ->get();

        $this->assertCount(2, $highLevelRoles);
        $roleNames = $highLevelRoles->pluck('name')->toArray();
        $this->assertContains('Admin', $roleNames);
        $this->assertContains('Editor', $roleNames);
        $this->assertNotContains('Viewer', $roleNames);
    }

    public function test_pivot_where_in()
    {

        $user = User::create(['name' => 'John']);

        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);
        $role3 = Role::create(['name' => 'Viewer']);

        $user->roles()->attach($role1->id, ['department' => 'IT']);
        $user->roles()->attach($role2->id, ['department' => 'Marketing']);
        $user->roles()->attach($role3->id, ['department' => 'IT']);

        $itRoles = $user->roles()
            ->wherePivotIn('department', ['IT', 'Engineering'])
            ->get();

        $this->assertCount(2, $itRoles);
        $roleNames = $itRoles->pluck('name')->toArray();
        $this->assertContains('Admin', $roleNames);
        $this->assertContains('Viewer', $roleNames);
    }

    public function test_pivot_order_by()
    {

        $user = User::create(['name' => 'John']);

        $role1 = Role::create(['name' => 'Role A']);
        $role2 = Role::create(['name' => 'Role B']);
        $role3 = Role::create(['name' => 'Role C']);

        $user->roles()->attach($role1->id, ['priority' => 3]);
        $user->roles()->attach($role2->id, ['priority' => 1]);
        $user->roles()->attach($role3->id, ['priority' => 2]);

        $orderedRoles = $user->roles()
            ->orderByPivot('priority')
            ->get();

        $this->assertEquals('Role B', $orderedRoles[0]->name);
        $this->assertEquals('Role C', $orderedRoles[1]->name);
        $this->assertEquals('Role A', $orderedRoles[2]->name);
    }

    public function test_pivot_with_soft_deletes()
    {

        $user = User::create(['name' => 'John']);
        $role = Role::create(['name' => 'Admin']);

        $user->roles()->attach($role->id, [
            'deleted_at' => null,
            'active' => true,
        ]);

        $activeRoles = $user->roles()
            ->wherePivotNull('deleted_at')
            ->get();

        $this->assertCount(1, $activeRoles);

        $user->roles()->updateExistingPivot($role->id, [
            'deleted_at' => now(),
        ]);

        $activeRolesAfterDelete = $user->roles()
            ->wherePivotNull('deleted_at')
            ->get();

        $this->assertCount(0, $activeRolesAfterDelete);
    }

    public function test_with_pivot_value()
    {

        $user = User::create(['name' => 'John']);

        $role1 = Role::create(['name' => 'Admin']);
        $role2 = Role::create(['name' => 'Editor']);

        $user->roles()->attach($role1->id, ['level' => 3]);
        $user->roles()->attach($role2->id, ['level' => 2]);

        $rolesWithPivot = $user->roles()
            ->withPivotValue('computed_score', 100)
            ->get();

        foreach ($rolesWithPivot as $role) {
            $this->assertEquals(100, $role->pivot->computed_score);
        }
    }

    public function test_pivot_chunking()
    {

        $user = User::create(['name' => 'John']);

        for ($i = 1; $i <= 20; $i++) {
            $role = Role::create(['name' => "Role $i"]);
            $user->roles()->attach($role->id, ['level' => $i]);
        }

        $processedCount = 0;
        $user->roles()->orderBy('name')->chunk(5, function ($roles) use (&$processedCount) {
            $this->assertLessThanOrEqual(5, $roles->count());
            $processedCount += $roles->count();
        });

        $this->assertEquals(20, $processedCount);
    }

    public function test_pivot_aggregation()
    {

        $user = User::create(['name' => 'John']);

        for ($i = 1; $i <= 5; $i++) {
            $role = Role::create(['name' => "Role $i"]);
            $user->roles()->attach($role->id, ['level' => $i, 'score' => $i * 10]);
        }

        // First check if pivot data is stored correctly
        $rolesWithPivot = $user->roles()->get();
        foreach ($rolesWithPivot as $role) {
            $this->assertNotNull($role->pivot, 'Pivot should not be null');
            $this->assertNotNull($role->pivot->level, 'Pivot level should not be null');
            $this->assertNotNull($role->pivot->score, 'Pivot score should not be null');
        }

        $totalLevels = $user->roles()->sum('pivot.level');
        $avgScore = $user->roles()->avg('pivot.score');
        $maxLevel = $user->roles()->max('pivot.level');
        $minScore = $user->roles()->min('pivot.score');

        $this->assertEquals(15, $totalLevels);
        $this->assertEquals(30, $avgScore);
        $this->assertEquals(5, $maxLevel);
        $this->assertEquals(10, $minScore);
    }

    public function test_pivot_using_cursor()
    {
        $user = User::create(['name' => 'John']);

        for ($i = 1; $i <= 10; $i++) {
            $role = Role::create(['name' => "Role $i"]);
            $user->roles()->attach($role->id, ['level' => $i]);
        }

        $count = 0;
        foreach ($user->roles()->cursor() as $role) {
            $count++;
            $this->assertNotNull($role->pivot);
            $this->assertNotNull($role->pivot->level);
        }

        $this->assertEquals(10, $count);
    }

    public function test_as_method_for_pivot_accessor_name()
    {

        $user = User::create(['name' => 'John']);
        $role = Role::create(['name' => 'Admin']);

        $user->roles()->attach($role->id, ['level' => 3]);

        $roleWithCustomPivot = $user->roles()->as('membership')->first();

        $this->assertNotNull($roleWithCustomPivot->membership);
        $this->assertEquals(3, $roleWithCustomPivot->membership->level);
    }
}
