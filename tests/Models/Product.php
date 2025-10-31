<?php

namespace Tests\Models;

class Product extends \Look\EloquentCypher\GraphModel
{
    protected $label = 'Product';

    protected $fillable = [
        'name',
        'price',
        'cost',
        'discount_price',
        'category',
        'is_active',
        'stock_quantity',
        'rating',
        'tenant_id',
        'created_at',
        'updated_at',
        'features',
    ];

    protected $casts = [
        'price' => 'float',
        'is_active' => 'boolean',
        'stock_quantity' => 'integer',
        'rating' => 'float',
        'tenant_id' => 'integer',
    ];

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive products.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope a query to only include products in a given price range.
     */
    public function scopePriceRange($query, $min, $max)
    {
        return $query->whereBetween('price', [$min, $max]);
    }

    /**
     * Scope a query to only include expensive products.
     */
    public function scopeExpensive($query, $threshold = 100)
    {
        return $query->where('price', '>', $threshold);
    }

    /**
     * Scope a query to only include products in a specific category.
     */
    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include products in stock.
     */
    public function scopeInStock($query, $minimumStock = 1)
    {
        return $query->where('stock_quantity', '>=', $minimumStock);
    }

    /**
     * Scope a query to only include out of stock products.
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '<=', 0);
    }

    /**
     * Scope a query to only include highly rated products.
     */
    public function scopeHighlyRated($query, $minRating = 4.0)
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Scope a query to include popular products (highly rated and in stock).
     * This demonstrates chaining conditions within a scope.
     */
    public function scopePopular($query)
    {
        return $query->where('rating', '>=', 4.0)
            ->where('stock_quantity', '>', 10)
            ->where('is_active', true);
    }

    /**
     * Scope with ordering - gets featured products.
     */
    public function scopeFeatured($query, $limit = 5)
    {
        return $query->where('is_active', true)
            ->where('rating', '>=', 4.5)
            ->orderByDesc('rating')
            ->limit($limit);
    }

    /**
     * Scope for a specific tenant (for multi-tenant testing).
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope that selects specific columns.
     */
    public function scopeBasicInfo($query)
    {
        return $query->select(['name', 'price', 'category']);
    }

    /**
     * Complex scope with multiple optional parameters.
     */
    public function scopeFilter($query, array $filters = [])
    {
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $query->where('stock_quantity', '>', 0);
        }

        return $query;
    }
}
