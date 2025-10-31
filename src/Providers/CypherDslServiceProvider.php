<?php

namespace Look\EloquentCypher\Providers;

use Illuminate\Support\ServiceProvider;
use Look\EloquentCypher\Support\CypherDslFactory;

class CypherDslServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('neo4j.cypher.dsl', function ($app) {
            return new CypherDslFactory($app['db']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Service provider is ready
    }
}
