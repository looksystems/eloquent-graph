<?php

namespace Tests\Models;

class MultiLabelUser extends \Look\EloquentCypher\GraphModel
{
    protected $connection = 'graph';

    protected $table = 'users';

    protected $labels = ['Person', 'Individual'];

    protected $fillable = ['id', 'name', 'email', 'age'];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
