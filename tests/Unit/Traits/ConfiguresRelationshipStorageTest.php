<?php

namespace Tests\Unit\Traits;

use Look\EloquentCypher\Traits\ConfiguresRelationshipStorage;
use Tests\TestCase\UnitTestCase;

class ConfiguresRelationshipStorageTest extends UnitTestCase
{
    private $mockClass;

    protected function setUp(): void
    {
        parent::setUp();

        // Create anonymous class using the trait
        $this->mockClass = new class
        {
            use ConfiguresRelationshipStorage;

            public $storageStrategy = null;

            public $defaultRelationshipStorage = null;

            // Expose protected method for testing
            public function test_get_default_storage_strategy(): string
            {
                return $this->getDefaultStorageStrategy();
            }
        };
    }

    public function test_default_storage_strategy_uses_global_config()
    {
        // Set global config
        config(['database.connections.neo4j.default_relationship_storage' => 'edge']);

        $this->assertEquals('edge', $this->mockClass->test_get_default_storage_strategy());
    }

    public function test_model_level_default_overrides_global_config()
    {
        // Set global config
        config(['database.connections.neo4j.default_relationship_storage' => 'edge']);

        // Set model-level default
        $this->mockClass->defaultRelationshipStorage = 'foreign_key';

        $this->assertEquals('foreign_key', $this->mockClass->test_get_default_storage_strategy());
    }

    public function test_relationship_specific_setting_has_highest_priority()
    {
        // Set all levels
        config(['database.connections.neo4j.default_relationship_storage' => 'edge']);
        $this->mockClass->defaultRelationshipStorage = 'hybrid';
        $this->mockClass->storageStrategy = 'foreign_key';

        $this->assertEquals('foreign_key', $this->mockClass->test_get_default_storage_strategy());
    }

    public function test_defaults_to_foreign_key_when_no_config()
    {
        // Clear config
        config(['database.connections.neo4j.default_relationship_storage' => null]);

        $this->assertEquals('foreign_key', $this->mockClass->test_get_default_storage_strategy());
    }

    public function test_use_foreign_keys_method_sets_strategy()
    {
        $result = $this->mockClass->useForeignKeys();

        $this->assertEquals('foreign_key', $this->mockClass->storageStrategy);
        $this->assertSame($this->mockClass, $result); // Fluent interface
    }

    public function test_use_native_edges_method_sets_strategy()
    {
        $result = $this->mockClass->useNativeEdges();

        $this->assertEquals('edge', $this->mockClass->storageStrategy);
        $this->assertSame($this->mockClass, $result); // Fluent interface
    }

    public function test_use_hybrid_storage_method_sets_strategy()
    {
        $result = $this->mockClass->useHybridStorage();

        $this->assertEquals('hybrid', $this->mockClass->storageStrategy);
        $this->assertSame($this->mockClass, $result); // Fluent interface
    }
}
