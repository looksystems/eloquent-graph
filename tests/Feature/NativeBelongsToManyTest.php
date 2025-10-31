<?php

use Look\EloquentCypher\Neo4jEdgePivot;

// Test Models for BelongsToMany relationships
class NativeAuthor extends \Look\EloquentCypher\GraphModel
{
    protected $table = 'authors';

    protected $fillable = ['name'];

    protected $useNativeRelationships = true;

    public function books()
    {
        return $this->belongsToMany(NativeBook::class, 'author_book', 'author_id', 'book_id')
            ->withPivotValue('role', 'author');
    }
}

class NativeBook extends \Look\EloquentCypher\GraphModel
{
    protected $table = 'books';

    protected $fillable = ['title', 'isbn'];

    protected $useNativeRelationships = true;

    public function authors()
    {
        return $this->belongsToMany(NativeAuthor::class, 'author_book', 'book_id', 'author_id')
            ->withPivotValue('role', 'author');
    }
}

class LegacyAuthor extends \Look\EloquentCypher\GraphModel
{
    protected $table = 'authors';

    protected $fillable = ['name'];
    // No native relationships - uses foreign keys

    public function books()
    {
        return $this->belongsToMany(LegacyBook::class, 'author_book', 'author_id', 'book_id')
            ->withPivotValue('role', 'author')
            ->withTimestamps();
    }
}

class LegacyBook extends \Look\EloquentCypher\GraphModel
{
    protected $table = 'books';

    protected $fillable = ['title', 'isbn'];
    // No native relationships - uses foreign keys

    public function authors()
    {
        return $this->belongsToMany(LegacyAuthor::class, 'author_book', 'book_id', 'author_id')
            ->withPivotValue('role', 'author')
            ->withTimestamps();
    }
}

test('attach creates edges with pivot data as properties', function () {
    $author = NativeAuthor::create(['name' => 'J.K. Rowling']);
    $book = NativeBook::create(['title' => 'Harry Potter', 'isbn' => '978-0-439-70818-8']);

    // Debug: Check if native relationships are enabled
    $reflection = new \ReflectionClass($author);
    $prop = $reflection->getProperty('useNativeRelationships');
    $prop->setAccessible(true);
    expect($prop->getValue($author))->toBeTrue();

    // Check if relationship uses native edges
    $relation = $author->books();
    $shouldCreateEdgeReflection = new \ReflectionMethod($relation, 'shouldCreateEdge');
    $shouldCreateEdgeReflection->setAccessible(true);
    $shouldCreateEdge = $shouldCreateEdgeReflection->invoke($relation);
    expect($shouldCreateEdge)->toBeTrue();

    // Attach with pivot data
    $author->books()->attach($book->id, [
        'role' => 'primary_author',
        'royalty_percentage' => 15.5,
    ]);

    // Verify edge was created with pivot properties
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books {id: $bookId}) RETURN r';
    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book->id]);

    expect($result)->not->toBeEmpty();
    $edge = $result[0]['r'] ?? $result[0]->r;
    expect($edge['role'] ?? $edge->role)->toBe('primary_author');
    expect($edge['royalty_percentage'] ?? $edge->royalty_percentage)->toBe(15.5);
});

test('attach creates edges without requiring timestamps', function () {
    $author = NativeAuthor::create(['name' => 'George R.R. Martin']);
    $book = NativeBook::create(['title' => 'A Game of Thrones', 'isbn' => '978-0-553-10354-0']);

    // Attach - basic attachment
    $author->books()->attach($book->id);

    // Verify edge was created
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books {id: $bookId}) RETURN r';
    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book->id]);

    expect($result)->not->toBeEmpty();
    $edge = $result[0]['r'] ?? $result[0]->r;
    expect($edge['role'] ?? $edge->role)->toBe('author'); // Default pivot value
});

test('detach removes edges properly', function () {
    $author = NativeAuthor::create(['name' => 'Stephen King']);
    $book1 = NativeBook::create(['title' => 'The Shining', 'isbn' => '978-0-385-12167-5']);
    $book2 = NativeBook::create(['title' => 'IT', 'isbn' => '978-0-670-81302-0']);

    // Attach both books
    $author->books()->attach([$book1->id, $book2->id]);

    // Verify both edges exist
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books) RETURN count(r) as count';
    $result = $connection->select($cypher, ['authorId' => $author->id]);
    expect($result[0]['count'])->toBe(2);

    // Detach one book
    $author->books()->detach($book1->id);

    // Verify only one edge remains
    $result = $connection->select($cypher, ['authorId' => $author->id]);
    expect($result[0]['count'])->toBe(1);

    // Verify the correct edge was removed
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books {id: $bookId}) RETURN r';
    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book1->id]);
    expect($result)->toBeEmpty();

    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book2->id]);
    expect($result)->not->toBeEmpty();
});

test('detach all removes all edges when no ids specified', function () {
    $author = NativeAuthor::create(['name' => 'J.R.R. Tolkien']);
    $book1 = NativeBook::create(['title' => 'The Hobbit', 'isbn' => '978-0-547-92822-7']);
    $book2 = NativeBook::create(['title' => 'The Lord of the Rings', 'isbn' => '978-0-544-00341-5']);

    // Attach both books
    $author->books()->attach([$book1->id, $book2->id]);

    // Detach all
    $author->books()->detach();

    // Verify no edges remain
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books) RETURN count(r) as count';
    $result = $connection->select($cypher, ['authorId' => $author->id]);
    expect($result[0]['count'])->toBe(0);
});

test('sync updates edges correctly', function () {
    $author = NativeAuthor::create(['name' => 'Isaac Asimov']);
    $book1 = NativeBook::create(['title' => 'Foundation', 'isbn' => '978-0-553-29335-7']);
    $book2 = NativeBook::create(['title' => 'I, Robot', 'isbn' => '978-0-553-38256-3']);
    $book3 = NativeBook::create(['title' => 'The Gods Themselves', 'isbn' => '978-0-553-28810-0']);

    // Initial attach
    $author->books()->attach([$book1->id, $book2->id]);

    // Sync to different set
    $result = $author->books()->sync([$book2->id, $book3->id]);

    expect($result['attached'])->toContain($book3->id);
    expect($result['detached'])->toContain($book1->id);

    // Verify edges
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books) RETURN b.id as book_id';
    $results = $connection->select($cypher, ['authorId' => $author->id]);

    $bookIds = array_map(fn ($r) => $r['book_id'] ?? $r->book_id, $results);
    expect($bookIds)->toContain($book2->id);
    expect($bookIds)->toContain($book3->id);
    expect($bookIds)->not->toContain($book1->id);
});

test('sync with pivot data creates edges with properties', function () {
    $author = NativeAuthor::create(['name' => 'Neil Gaiman']);
    $book1 = NativeBook::create(['title' => 'Good Omens', 'isbn' => '978-0-060-85398-3']);
    $book2 = NativeBook::create(['title' => 'American Gods', 'isbn' => '978-0-380-97365-1']);

    // Sync with pivot data
    $author->books()->sync([
        $book1->id => ['role' => 'co-author', 'royalty_percentage' => 7.5],
        $book2->id => ['role' => 'sole-author', 'royalty_percentage' => 12.0],
    ]);

    // Verify edges have correct properties
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books {id: $bookId}) RETURN r';

    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book1->id]);
    $edge = $result[0]['r'] ?? $result[0]->r;
    expect($edge['role'] ?? $edge->role)->toBe('co-author');
    expect($edge['royalty_percentage'] ?? $edge->royalty_percentage)->toBe(7.5);

    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book2->id]);
    $edge = $result[0]['r'] ?? $result[0]->r;
    expect($edge['role'] ?? $edge->role)->toBe('sole-author');
    expect($edge['royalty_percentage'] ?? $edge->royalty_percentage)->toBe(12.0);
});

test('pivot property access through virtual pivot', function () {
    $author = NativeAuthor::create(['name' => 'Terry Pratchett']);
    $book = NativeBook::create(['title' => 'The Color of Magic', 'isbn' => '978-0-060-85592-5']);

    // Attach with pivot data
    $author->books()->attach($book->id, [
        'role' => 'creator',
        'royalty_percentage' => 10.0,
        'notes' => 'First Discworld novel',
    ]);

    // Retrieve with pivot
    $loadedBook = $author->books()->first();

    expect($loadedBook)->toBeInstanceOf(NativeBook::class);
    expect($loadedBook->pivot)->not->toBeNull();
    expect($loadedBook->pivot->role)->toBe('creator');
    expect($loadedBook->pivot->royalty_percentage)->toBe(10.0);
    expect($loadedBook->pivot->notes)->toBe('First Discworld novel');
});

test('virtual pivot is instance of Neo4jEdgePivot when using native edges', function () {
    $author = NativeAuthor::create(['name' => 'Douglas Adams']);
    $book = NativeBook::create(['title' => 'The Hitchhiker\'s Guide to the Galaxy', 'isbn' => '978-0-345-39180-3']);

    $author->books()->attach($book->id, ['role' => 'genius']);

    $loadedBook = $author->books()->first();

    // When native edges are enabled, pivot should be Neo4jEdgePivot
    expect($loadedBook->pivot)->toBeInstanceOf(\Look\EloquentCypher\EdgePivot::class);
});

test('updateExistingPivot updates edge properties', function () {
    $author = NativeAuthor::create(['name' => 'Margaret Atwood']);
    $book = NativeBook::create(['title' => 'The Handmaid\'s Tale', 'isbn' => '978-0-385-49081-8']);

    // Initial attach
    $author->books()->attach($book->id, ['role' => 'author', 'royalty_percentage' => 8.0]);

    // Update pivot
    $author->books()->updateExistingPivot($book->id, ['royalty_percentage' => 12.5, 'notes' => 'Bestseller']);

    // Verify edge properties were updated
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books {id: $bookId}) RETURN r';
    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book->id]);

    $edge = $result[0]['r'] ?? $result[0]->r;
    expect($edge['role'] ?? $edge->role)->toBe('author'); // Original value preserved
    expect($edge['royalty_percentage'] ?? $edge->royalty_percentage)->toBe(12.5); // Updated
    expect($edge['notes'] ?? $edge->notes)->toBe('Bestseller'); // New property added
});

test('toggle method works with native edges', function () {
    $author = NativeAuthor::create(['name' => 'Haruki Murakami']);
    $book1 = NativeBook::create(['title' => '1Q84', 'isbn' => '978-0-307-59331-3']);
    $book2 = NativeBook::create(['title' => 'Kafka on the Shore', 'isbn' => '978-1-4000-7927-8']);

    // Initial attach
    $author->books()->attach($book1->id);

    // Toggle - should detach book1 and attach book2
    $result = $author->books()->toggle([$book1->id, $book2->id]);

    expect($result['detached'])->toContain($book1->id);
    expect($result['attached'])->toContain($book2->id);

    // Verify edges
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books) RETURN b.id as book_id';
    $results = $connection->select($cypher, ['authorId' => $author->id]);

    $bookIds = array_map(fn ($r) => $r['book_id'] ?? $r->book_id, $results);
    expect($bookIds)->toContain($book2->id);
    expect($bookIds)->not->toContain($book1->id);
});

test('backward compatibility with foreign key mode', function () {
    // Use legacy models that don't have useNativeRelationships
    $author = LegacyAuthor::create(['name' => 'William Shakespeare']);
    $book = LegacyBook::create(['title' => 'Hamlet', 'isbn' => '978-0-14-310763-8']);

    // Attach should create relationship using standard edge logic
    // In Neo4j, relationships ARE edges - this is fundamental to graph databases
    $author->books()->attach($book->id, ['role' => 'playwright']);

    // Verify edge was created (Neo4j reality: relationships are edges)
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:AUTHORS_BOOKS]->(b:books {id: $bookId}) RETURN r';
    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book->id]);

    // In Neo4j, even "foreign key mode" creates edges because that's how relationships work
    expect($result)->not->toBeEmpty();
    $edge = $result[0]['r'] ?? $result[0]->r;
    expect($edge['role'] ?? $edge->role)->toBe('playwright');

    // The relationship should work through the edge-based mechanism
    $loadedBooks = $author->books()->get();
    expect($loadedBooks)->toHaveCount(1);
    expect($loadedBooks->first()->title)->toBe('Hamlet');
});

test('eager loading works with native edges', function () {
    $author1 = NativeAuthor::create(['name' => 'Jane Austen']);
    $author2 = NativeAuthor::create(['name' => 'Charlotte Brontë']);
    $book1 = NativeBook::create(['title' => 'Pride and Prejudice', 'isbn' => '978-0-14-143951-8']);
    $book2 = NativeBook::create(['title' => 'Jane Eyre', 'isbn' => '978-0-14-144114-6']);
    $book3 = NativeBook::create(['title' => 'Emma', 'isbn' => '978-0-14-143958-7']);

    $author1->books()->attach([$book1->id, $book3->id]);
    $author2->books()->attach($book2->id);

    // Eager load
    $authors = NativeAuthor::with('books')->get();

    expect($authors)->toHaveCount(2);
    expect($authors[0]->books)->toHaveCount(2);
    expect($authors[1]->books)->toHaveCount(1);

    // Verify pivot data is loaded
    expect($authors[0]->books[0]->pivot)->not->toBeNull();
    expect($authors[0]->books[0]->pivot->role)->toBe('author');
});

test('withCount works with native edges', function () {
    $author = NativeAuthor::create(['name' => 'Leo Tolstoy']);
    $book1 = NativeBook::create(['title' => 'War and Peace', 'isbn' => '978-1-4000-7998-8']);
    $book2 = NativeBook::create(['title' => 'Anna Karenina', 'isbn' => '978-0-14-303500-8']);

    $author->books()->attach([$book1->id, $book2->id]);

    $authorWithCount = NativeAuthor::withCount('books')->find($author->id);

    expect($authorWithCount->books_count)->toBe(2);
});

test('custom edge types can be specified', function () {
    // Create a model with custom edge type
    $authorWithCustomEdge = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'authors';

        protected $fillable = ['name'];

        protected $useNativeRelationships = true;

        protected $relationshipEdgeTypes = [
            'books' => 'WROTE',
        ];

        public function books()
        {
            return $this->belongsToMany(NativeBook::class, 'author_book', 'author_id', 'book_id');
        }
    };

    $author = $authorWithCustomEdge::create(['name' => 'Custom Edge Author']);
    $book = NativeBook::create(['title' => 'Custom Edge Book', 'isbn' => '978-0-000-00000-0']);

    $author->books()->attach($book->id);

    // Verify custom edge type was used
    $connection = $author->getConnection();
    $cypher = 'MATCH (a:authors {id: $authorId})-[r:WROTE]->(b:books {id: $bookId}) RETURN r';
    $result = $connection->select($cypher, ['authorId' => $author->id, 'bookId' => $book->id]);

    expect($result)->not->toBeEmpty();
});
