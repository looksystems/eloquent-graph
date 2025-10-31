<?php

namespace Tests\Models;

class NativeBook extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Traits\NativeRelationships;

    protected $table = 'native_books';

    protected $fillable = ['title'];

    public $timestamps = false;

    protected $useNativeRelationships = true;

    public function authors()
    {
        return $this->belongsToMany(NativeAuthor::class, 'author_book', 'book_id', 'author_id')
            ->withPivot('role')
            ->useNativeEdges()
            ->withEdgeType('NATIVE_BOOKS_NATIVE_AUTHORS');
    }
}
