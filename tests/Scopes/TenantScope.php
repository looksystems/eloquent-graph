<?php

namespace Tests\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * The tenant ID to scope to.
     */
    protected $tenantId;

    /**
     * Create a new scope instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        // In a real application, you might get this from session or auth
        $tenantId = $this->tenantId ?? 1;

        $builder->where('tenant_id', $tenantId);
    }
}
