<?php

namespace Tests\Models;

class Image extends \Look\EloquentCypher\GraphModel
{
    protected $fillable = ['url', 'imageable_id', 'imageable_type'];

    public function imageable()
    {
        return $this->morphTo();
    }
}
