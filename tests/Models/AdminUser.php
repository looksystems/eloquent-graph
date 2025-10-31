<?php

namespace Tests\Models;

use Database\Factories\AdminUserFactory;

class AdminUser extends User
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'age',
        'status',
        'salary',
        'created_at',
        'email_verified_at',
        'role',
        'admin_since',
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return AdminUserFactory::new();
    }
}
