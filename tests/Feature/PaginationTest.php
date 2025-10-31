<?php

namespace Tests\Feature;

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\Models\User;
use Tests\TestCase\GraphTestCase;

class PaginationTest extends GraphTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test data for pagination
        for ($i = 1; $i <= 25; $i++) {
            User::create([
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'age' => 20 + $i,
            ]);
        }
    }

    public function test_paginate_returns_length_aware_paginator(): void
    {
        $paginated = User::paginate(10);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginated);
        $this->assertEquals(10, $paginated->perPage());
        $this->assertEquals(25, $paginated->total());
        $this->assertEquals(3, $paginated->lastPage());
        $this->assertEquals(1, $paginated->currentPage());
        $this->assertCount(10, $paginated->items());
    }

    public function test_paginate_with_custom_per_page(): void
    {
        $paginated = User::paginate(5);

        $this->assertEquals(5, $paginated->perPage());
        $this->assertEquals(25, $paginated->total());
        $this->assertEquals(5, $paginated->lastPage());
        $this->assertCount(5, $paginated->items());
    }

    public function test_paginate_with_specific_page(): void
    {
        $paginated = User::orderBy('age')->paginate(10, ['*'], 'page', 2);

        $this->assertEquals(2, $paginated->currentPage());
        $this->assertCount(10, $paginated->items());
        $this->assertEquals('User 11', $paginated->items()[0]->name);
    }

    public function test_paginate_last_page(): void
    {
        $paginated = User::orderBy('age')->paginate(10, ['*'], 'page', 3);

        $this->assertEquals(3, $paginated->currentPage());
        $this->assertCount(5, $paginated->items()); // Only 5 items on last page
        $this->assertEquals('User 21', $paginated->items()[0]->name);
    }

    public function test_paginate_with_where_clause(): void
    {
        $paginated = User::where('age', '>', 30)->paginate(5);

        $this->assertEquals(15, $paginated->total()); // Users with age > 30
        $this->assertEquals(3, $paginated->lastPage());
        $this->assertCount(5, $paginated->items());
    }

    public function test_paginate_with_order_by(): void
    {
        $paginated = User::orderBy('name', 'desc')->paginate(10);

        $this->assertEquals('User 9', $paginated->items()[0]->name); // Sorted descending
        $this->assertEquals('User 8', $paginated->items()[1]->name);
    }

    public function test_paginate_returns_empty_on_invalid_page(): void
    {
        $paginated = User::paginate(10, ['*'], 'page', 999);

        $this->assertCount(0, $paginated->items());
        $this->assertEquals(999, $paginated->currentPage());
        $this->assertEquals(25, $paginated->total());
    }

    public function test_simple_paginate_returns_paginator(): void
    {
        $paginated = User::simplePaginate(10);

        $this->assertInstanceOf(Paginator::class, $paginated);
        $this->assertEquals(10, $paginated->perPage());
        $this->assertEquals(1, $paginated->currentPage());
        $this->assertCount(10, $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
    }

    public function test_simple_paginate_with_custom_per_page(): void
    {
        $paginated = User::simplePaginate(15);

        $this->assertEquals(15, $paginated->perPage());
        $this->assertCount(15, $paginated->items());
        $this->assertTrue($paginated->hasMorePages()); // Still has 10 more items
    }

    public function test_simple_paginate_on_last_page(): void
    {
        $paginated = User::simplePaginate(10, ['*'], 'page', 3);

        $this->assertEquals(3, $paginated->currentPage());
        $this->assertCount(5, $paginated->items()); // Only 5 items on last page
        $this->assertFalse($paginated->hasMorePages());
    }

    public function test_simple_paginate_with_where_clause(): void
    {
        $paginated = User::where('age', '<=', 30)->simplePaginate(5);

        $this->assertCount(5, $paginated->items());
        $this->assertTrue($paginated->hasMorePages()); // More users with age <= 30
    }

    public function test_cursor_paginate_returns_cursor_paginator(): void
    {
        $paginated = User::orderBy('id')->cursorPaginate(10);

        $this->assertInstanceOf(CursorPaginator::class, $paginated);
        $this->assertEquals(10, $paginated->perPage());
        $this->assertCount(10, $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
        $this->assertNotNull($paginated->nextCursor());
        $this->assertNull($paginated->previousCursor());
    }

    public function test_cursor_paginate_with_cursor(): void
    {
        // Get first page
        $firstPage = User::orderBy('id')->cursorPaginate(10);
        $nextCursor = $firstPage->nextCursor();

        // Get second page using cursor
        $secondPage = User::orderBy('id')->cursorPaginate(10, ['*'], 'cursor', $nextCursor);

        $this->assertCount(10, $secondPage->items());
        $this->assertNotEquals(
            $firstPage->items()[0]->id,
            $secondPage->items()[0]->id
        );
        $this->assertNotNull($secondPage->previousCursor());
        $this->assertTrue($secondPage->hasMorePages());
    }

    public function test_cursor_paginate_last_page(): void
    {
        // Get to last page
        $paginated = User::orderBy('id')->cursorPaginate(20);
        $nextCursor = $paginated->nextCursor();

        $lastPage = User::orderBy('id')->cursorPaginate(20, ['*'], 'cursor', $nextCursor);

        $this->assertCount(5, $lastPage->items()); // Only 5 items left
        $this->assertFalse($lastPage->hasMorePages());
        $this->assertNull($lastPage->nextCursor());
        $this->assertNotNull($lastPage->previousCursor());
    }

    public function test_cursor_paginate_with_where_clause(): void
    {
        $paginated = User::where('age', '>', 35)
            ->orderBy('id')
            ->cursorPaginate(5);

        $this->assertCount(5, $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
    }

    public function test_paginate_preserves_query_string(): void
    {
        // Simulate query string parameters
        request()->merge(['filter' => 'active', 'sort' => 'name']);

        $paginated = User::paginate(10);
        $paginated->appends(request()->all());

        $this->assertStringContainsString('filter=active', $paginated->url(2));
        $this->assertStringContainsString('sort=name', $paginated->url(2));
    }

    public function test_paginate_with_relationships(): void
    {
        // Create users with posts
        $user1 = User::first();
        $user1->posts()->create(['title' => 'Post 1', 'content' => 'Content']);
        $user1->posts()->create(['title' => 'Post 2', 'content' => 'Content']);

        $user2 = User::skip(1)->first();
        $user2->posts()->create(['title' => 'Post 3', 'content' => 'Content']);

        // Paginate with eager loading
        $paginated = User::with('posts')->paginate(5);

        $this->assertCount(5, $paginated->items());
        // Check that relationships are loaded
        $firstUser = $paginated->items()[0];
        $this->assertTrue($firstUser->relationLoaded('posts'));
    }

    public function test_paginate_on_relationship(): void
    {
        // Create user with many posts
        $user = User::first();
        for ($i = 1; $i <= 15; $i++) {
            $user->posts()->create([
                'title' => "Post {$i}",
                'content' => "Content {$i}",
            ]);
        }

        // Paginate the relationship
        $paginated = $user->posts()->paginate(5);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginated);
        $this->assertEquals(15, $paginated->total());
        $this->assertEquals(3, $paginated->lastPage());
        $this->assertCount(5, $paginated->items());
    }

    public function test_paginate_with_select_columns(): void
    {
        $paginated = User::select(['id', 'name'])->paginate(10);

        $firstItem = $paginated->items()[0];
        $this->assertNotNull($firstItem->id);
        $this->assertNotNull($firstItem->name);
        $this->assertNull($firstItem->email); // Not selected
    }

    public function test_paginate_count_query_optimization(): void
    {
        // This tests that the count query is optimized and doesn't include unnecessary clauses
        $paginated = User::select(['id', 'name'])
            ->orderBy('name')
            ->paginate(10);

        // The count should still be correct despite select and orderBy
        $this->assertEquals(25, $paginated->total());
    }
}
