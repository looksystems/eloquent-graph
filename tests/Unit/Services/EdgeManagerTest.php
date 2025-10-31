<?php

namespace Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use Tests\TestCase\UnitTestCase;

class Neo4jEdgeManagerTest extends UnitTestCase
{
    private $connection;

    private $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock connection
        $this->connection = Mockery::mock(\Look\EloquentCypher\GraphConnection::class);
        $this->manager = new \Look\EloquentCypher\Services\EdgeManager($this->connection);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_edge_between_models()
    {
        $fromModel = Mockery::mock(Model::class);
        $fromModel->shouldReceive('getKey')->andReturn(1);
        $fromModel->shouldReceive('getTable')->andReturn('users');

        $toModel = Mockery::mock(Model::class);
        $toModel->shouldReceive('getKey')->andReturn(2);
        $toModel->shouldReceive('getTable')->andReturn('posts');

        $expectedCypher = 'MATCH (a:users {id: $fromId}), (b:posts {id: $toId}) '.
                         'CREATE (a)-[r:HAS_POSTS]->(b) '.
                         'RETURN r';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
            ])
            ->andReturn([['r' => ['id' => 'edge123']]]);

        $result = $this->manager->createEdge($fromModel, $toModel, 'HAS_POSTS');

        $this->assertNotEmpty($result);
    }

    public function test_create_edge_with_properties()
    {
        $properties = ['created_at' => '2024-01-01', 'priority' => 1];

        $expectedCypher = 'MATCH (a:users {id: $fromId}), (b:posts {id: $toId}) '.
                         'CREATE (a)-[r:BELONGS_TO]->(b) '.
                         'SET r += $properties '.
                         'RETURN r';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 5,
                'toId' => 10,
                'properties' => $properties,
            ])
            ->andReturn([['r' => ['id' => 'edge456']]]);

        $result = $this->manager->createEdge(5, 10, 'BELONGS_TO', $properties, 'users', 'posts');

        $this->assertNotEmpty($result);
    }

    public function test_delete_edge_between_nodes()
    {
        $expectedCypher = 'MATCH (a:users {id: $fromId})-[r:HAS_POSTS]->(b:posts {id: $toId}) '.
                         'DELETE r '.
                         'RETURN count(r) as deleted';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
            ])
            ->andReturn([['deleted' => 1]]);

        $result = $this->manager->deleteEdge(1, 2, 'HAS_POSTS', 'users', 'posts');

        $this->assertTrue($result);
    }

    public function test_update_edge_properties()
    {
        $properties = ['updated_at' => '2024-01-02', 'status' => 'active'];

        $expectedCypher = 'MATCH (a:users {id: $fromId})-[r:HAS_POSTS]->(b:posts {id: $toId}) '.
                         'SET r += $properties '.
                         'RETURN r';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
                'properties' => $properties,
            ])
            ->andReturn([['r' => ['id' => 'edge123', 'updated_at' => '2024-01-02']]]);

        $result = $this->manager->updateEdgeProperties(1, 2, 'HAS_POSTS', $properties, 'users', 'posts');

        $this->assertNotEmpty($result);
    }

    public function test_find_edges_between_nodes()
    {
        $expectedCypher = 'MATCH (a:users {id: $fromId})-[r]->(b:posts {id: $toId}) '.
                         'RETURN r, type(r) as type';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
            ])
            ->andReturn([
                ['r' => ['id' => 'edge1'], 'type' => 'HAS_POSTS'],
                ['r' => ['id' => 'edge2'], 'type' => 'AUTHORED'],
            ]);

        $result = $this->manager->findEdgesBetween(1, 2, null, 'users', 'posts');

        $this->assertCount(2, $result);
    }

    public function test_find_edges_with_specific_type()
    {
        $expectedCypher = 'MATCH (a:users {id: $fromId})-[r:HAS_POSTS]->(b:posts {id: $toId}) '.
                         'RETURN r, type(r) as type';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
            ])
            ->andReturn([
                ['r' => ['id' => 'edge1'], 'type' => 'HAS_POSTS'],
            ]);

        $result = $this->manager->findEdgesBetween(1, 2, 'HAS_POSTS', 'users', 'posts');

        $this->assertCount(1, $result);
    }

    public function test_edge_exists_between_nodes()
    {
        $expectedCypher = 'MATCH (a:users {id: $fromId})-[r:HAS_POSTS]->(b:posts {id: $toId}) '.
                         'RETURN count(r) as count';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
            ])
            ->andReturn([['count' => 1]]);

        $result = $this->manager->edgeExists(1, 2, 'HAS_POSTS', 'users', 'posts');

        $this->assertTrue($result);
    }

    public function test_edge_does_not_exist_between_nodes()
    {
        $expectedCypher = 'MATCH (a:users {id: $fromId})-[r:DOES_NOT_EXIST]->(b:posts {id: $toId}) '.
                         'RETURN count(r) as count';

        $this->connection->shouldReceive('select')
            ->once()
            ->with($expectedCypher, [
                'fromId' => 1,
                'toId' => 2,
            ])
            ->andReturn([['count' => 0]]);

        $result = $this->manager->edgeExists(1, 2, 'DOES_NOT_EXIST', 'users', 'posts');

        $this->assertFalse($result);
    }
}
