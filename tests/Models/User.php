<?php

namespace Tests\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends \Look\EloquentCypher\GraphModel
{
    use \Look\EloquentCypher\Concerns\GraphSoftDeletes, SoftDeletes {
        \Look\EloquentCypher\Concerns\GraphSoftDeletes::forceDelete insteadof SoftDeletes;
    }

    protected $fillable = [
        'id', 'name', 'email', 'age', 'status', 'salary', 'created_at', 'email_verified_at', 'role',
        'active', 'secret_key', 'internal_id', 'type', 'experience_years', 'subscription_level',
        'username', 'follower_count', 'following_count', 'verified', 'membership_level',
        'total_spent', 'registration_date', 'position', 'level', 'department', 'reports_to',
        'salary_range', 'batch_number', 'user_index', 'pattern_type', 'connected_to_hub',
        'chain_position', 'signup_month', 'preferences', 'tags', 'score', 'is_premium',
        'last_login', 'metadata', 'is_active', 'settings', 'optional_field', 'another_field',
        'deleted_at', 'first_name', 'last_name', 'lives', 'health', 'mana', 'stamina', 'points',
        'skills', 'data', 'custom_id', 'bio', 'parent_id',
    ];

    protected $casts = [
        'preferences' => 'array',
        'tags' => 'array',
        'metadata' => 'json',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_premium' => 'boolean',
        'active' => 'boolean',
        'verified' => 'boolean',
        'age' => 'integer',
        'health' => 'integer',
        'mana' => 'integer',
        'stamina' => 'integer',
        'points' => 'integer',
        'lives' => 'integer',
        'score' => 'float',
        'last_login' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function comments()
    {
        return $this->hasManyThrough(Comment::class, Post::class);
    }

    public function latestComment()
    {
        return $this->hasOneThrough(Comment::class, Post::class);
    }

    public function customComment()
    {
        return $this->hasOneThrough(
            Comment::class,
            Post::class,
            'custom_user_id', // Foreign key on the posts table
            'custom_post_id', // Foreign key on the comments table
            'custom_id', // Local key on the users table
            'id' // Local key on the posts table
        );
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function avatar()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }
}
