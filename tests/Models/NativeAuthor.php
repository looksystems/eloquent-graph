<?php

namespace Tests\Models;

class NativeAuthor extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Traits\NativeRelationships;

    protected $table = 'native_authors';

    protected $fillable = ['name'];

    public $timestamps = false;

    protected $useNativeRelationships = true;

    public function books()
    {
        return $this->belongsToMany(NativeBook::class, 'author_book', 'author_id', 'book_id')
            ->withPivot('role')
            ->useNativeEdges()
            ->withEdgeType('NATIVE_AUTHORS_NATIVE_BOOKS');
    }
}
