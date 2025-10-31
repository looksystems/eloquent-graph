<?php

namespace Tests\Models;

use Database\Factories\PostFactory;

class Post extends \Look\EloquentCypher\GraphModel
{
    protected $fillable = [
        'id', 'title', 'content', 'body', 'user_id', 'views', 'published', 'published_at',
        'status', 'word_count', 'reading_time', 'price', 'stock_quantity',
        'category', 'rating', 'is_featured', 'featured', 'likes', 'likes_count', 'shares_count',
        'visibility', 'batch_number', 'month_created', 'budget', 'start_date',
        'deadline', 'created_at', 'updated_at', 'deleted_at', 'metadata', 'tags',
        'custom_user_id', 'custom_post_id', 'reviewer_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tags' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    // Polymorphic relationships
    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function featuredImage()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function categories()
    {
        return $this->belongsToMany(Role::class, null, 'post_id', 'role_id');
    }

    /**
     * Get all attached tags for the post (polymorphic many-to-many).
     */
    public function attachedTags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Alias for attachedTags() for test compatibility.
     */
    public function tags()
    {
        return $this->attachedTags();
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return PostFactory::new();
    }
}
