<?php

namespace Tests\Models;

class NativeImage extends Image
{
    protected $table = 'images';

    protected $useNativeRelationships = true;

    // Override the morphTo to work with native relationships
    public function imageable()
    {
        return $this->morphTo();
    }
}
