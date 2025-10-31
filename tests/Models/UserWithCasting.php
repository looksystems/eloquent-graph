<?php

namespace Tests\Models;

class UserWithCasting extends \Look\EloquentCypher\GraphModel
{
    protected $table = 'users_with_casting';

    protected $fillable = [
        'name', 'email', 'settings', 'metadata', 'is_active', 'age',
        'rating', 'birth_date', 'join_date',
    ];

    protected $casts = [
        'settings' => 'array',
        'metadata' => 'json',
        'is_active' => 'boolean',
        'age' => 'integer',
        'rating' => 'float',
        'birth_date' => 'datetime',
        'join_date' => 'date',
    ];

    protected $dateFormat = 'Y-m-d H:i:s';

    // Mutator: Convert name to title case
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = ucwords(strtolower($value));
    }

    // Accessor: Return email in uppercase
    public function getEmailUpperAttribute()
    {
        return strtoupper($this->email);
    }
}
