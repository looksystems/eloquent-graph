<?php

namespace Tests\TestCase\Helpers;

use Laudis\Neo4j\Contracts\ClientInterface;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Profile;
use Tests\Models\Role;
use Tests\Models\User;

class Neo4jTestHelper
{
    protected ClientInterface $neo4jClient;

    public function __construct(ClientInterface $neo4jClient)
    {
        $this->neo4jClient = $neo4jClient;
    }

    /**
     * Create test users with various configurations
     */
    public function createTestUsers(int $count = 3, array $extraAttributes = []): array
    {
        $users = [];
        for ($i = 1; $i <= $count; $i++) {
            $attributes = array_merge([
                'name' => "Test User $i",
                'email' => "user{$i}@example.com",
                'age' => 20 + $i,
                'active' => $i % 2 === 0,
            ], $extraAttributes);

            $users[] = User::create($attributes);
        }

        return $users;
    }

    /**
     * Create test posts associated with users
     */
    public function createTestPosts(array $users, int $postsPerUser = 2): array
    {
        $posts = [];
        foreach ($users as $index => $user) {
            for ($i = 1; $i <= $postsPerUser; $i++) {
                $posts[] = Post::create([
                    'title' => "Post $i by User ".($index + 1),
                    'content' => "Content for post $i by user ".($index + 1),
                    'user_id' => $user->id,
                    'published' => $i % 2 === 0,
                    'views' => rand(10, 1000),
                ]);
            }
        }

        return $posts;
    }

    /**
     * Create test roles for many-to-many relationships
     */
    public function createTestRoles(int $count = 3): array
    {
        $roles = [];
        $roleNames = ['admin', 'editor', 'viewer', 'moderator', 'guest'];

        for ($i = 0; $i < $count; $i++) {
            $roles[] = Role::create([
                'name' => $roleNames[$i] ?? "role_$i",
                'description' => 'Description for '.($roleNames[$i] ?? "role_$i"),
                'level' => $i + 1,
            ]);
        }

        return $roles;
    }

    /**
     * Create test comments for posts
     */
    public function createTestComments(array $posts, array $users): array
    {
        $comments = [];
        foreach ($posts as $postIndex => $post) {
            $commentsCount = rand(1, 3);
            for ($i = 1; $i <= $commentsCount; $i++) {
                $randomUser = $users[array_rand($users)];
                $comments[] = Comment::create([
                    'content' => "Comment $i on post ".($postIndex + 1),
                    'post_id' => $post->id,
                    'user_id' => $randomUser->id,
                    'approved' => $i % 2 === 0,
                ]);
            }
        }

        return $comments;
    }

    /**
     * Create user profiles (one-to-one relationship)
     */
    public function createTestProfiles(array $users): array
    {
        $profiles = [];
        foreach ($users as $index => $user) {
            $profiles[] = Profile::create([
                'user_id' => $user->id,
                'bio' => 'Bio for user '.($index + 1),
                'website' => "https://user{$index}.example.com",
                'location' => 'City '.($index + 1),
                'birth_date' => now()->subYears(20 + $index)->format('Y-m-d'),
            ]);
        }

        return $profiles;
    }

    /**
     * Set up complex test data structure with all relationships
     */
    public function setupComplexTestData(): array
    {
        $users = $this->createTestUsers(5);
        $roles = $this->createTestRoles(3);
        $posts = $this->createTestPosts($users, 3);
        $comments = $this->createTestComments($posts, $users);
        $profiles = $this->createTestProfiles($users);

        // Attach roles to users (many-to-many)
        foreach ($users as $index => $user) {
            $rolesToAttach = array_slice($roles, 0, ($index % 3) + 1);
            $roleIds = array_map(fn ($role) => $role->id, $rolesToAttach);
            $user->roles()->attach($roleIds);
        }

        return [
            'users' => $users,
            'roles' => $roles,
            'posts' => $posts,
            'comments' => $comments,
            'profiles' => $profiles,
        ];
    }

    /**
     * Create test data for performance testing
     */
    public function createLargeTestDataset(int $userCount = 100, int $postsPerUser = 10): array
    {
        $users = [];
        $posts = [];

        // Create users in batches to avoid memory issues
        $batchSize = 20;
        for ($batch = 0; $batch < ceil($userCount / $batchSize); $batch++) {
            $batchUsers = [];
            $startIndex = $batch * $batchSize;
            $endIndex = min($startIndex + $batchSize, $userCount);

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $batchUsers[] = User::create([
                    'name' => "User $i",
                    'email' => "user{$i}@example.com",
                    'age' => 18 + ($i % 50),
                ]);
            }

            $users = array_merge($users, $batchUsers);

            // Create posts for this batch of users
            foreach ($batchUsers as $userIndex => $user) {
                for ($j = 1; $j <= $postsPerUser; $j++) {
                    $posts[] = Post::create([
                        'title' => "Post $j by User ".($startIndex + $userIndex),
                        'content' => "Content for post $j",
                        'user_id' => $user->id,
                        'views' => rand(1, 10000),
                    ]);
                }
            }

            // Garbage collection to manage memory
            if ($batch % 5 === 0) {
                gc_collect_cycles();
            }
        }

        return ['users' => $users, 'posts' => $posts];
    }

    /**
     * Clean up test data more efficiently than truncating
     */
    public function cleanupTestData(): void
    {
        // Delete in order to respect relationships
        $this->neo4jClient->run('MATCH (n:comments) DETACH DELETE n');
        $this->neo4jClient->run('MATCH (n:posts) DETACH DELETE n');
        $this->neo4jClient->run('MATCH (n:profiles) DETACH DELETE n');
        $this->neo4jClient->run('MATCH ()-[r:USER_ROLE]->() DELETE r');
        $this->neo4jClient->run('MATCH (n:roles) DELETE n');
        $this->neo4jClient->run('MATCH (n:users) DELETE n');
    }

    /**
     * Create test data with specific casting scenarios
     */
    public function createCastingTestData(): array
    {
        $userData = [
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'preferences' => ['theme' => 'dark', 'notifications' => true],
                'tags' => ['developer', 'php', 'neo4j'],
                'score' => 95.5,
                'is_premium' => true,
                'last_login' => now(),
                'metadata' => '{"source": "registration", "campaign": "spring2024"}',
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'preferences' => ['theme' => 'light', 'language' => 'en'],
                'tags' => ['designer', 'ui', 'ux'],
                'score' => 87.2,
                'is_premium' => false,
                'last_login' => now()->subHours(2),
                'metadata' => '{"source": "referral", "referrer": "john@example.com"}',
            ],
        ];

        $users = [];
        foreach ($userData as $data) {
            $users[] = User::create($data);
        }

        return $users;
    }

    /**
     * Create test data for soft delete scenarios
     */
    public function createSoftDeleteTestData(): array
    {
        $users = $this->createTestUsers(5);
        $posts = $this->createTestPosts($users, 2);

        // Soft delete some users and posts
        $users[1]->delete(); // Soft delete
        $users[3]->delete(); // Soft delete
        $posts[2]->delete(); // Soft delete
        $posts[5]->delete(); // Soft delete

        return ['users' => $users, 'posts' => $posts];
    }

    /**
     * Verify database state for testing
     */
    public function verifyDatabaseState(): array
    {
        $results = [];

        $labels = ['users', 'posts', 'comments', 'roles', 'profiles'];
        foreach ($labels as $label) {
            $result = $this->neo4jClient->run("MATCH (n:$label) RETURN count(n) as count");
            $results[$label] = $result->first()->get('count');
        }

        $relationshipResult = $this->neo4jClient->run('MATCH ()-[r]->() RETURN count(r) as count');
        $results['relationships'] = $relationshipResult->first()->get('count');

        return $results;
    }

    /**
     * Create test data with timestamps for temporal testing
     */
    public function createTimestampTestData(): array
    {
        $baseTime = now()->subDays(10);
        $users = [];

        for ($i = 0; $i < 5; $i++) {
            $user = new User([
                'name' => "User $i",
                'email' => "user{$i}@example.com",
            ]);

            // Manually set timestamps for testing
            $user->created_at = $baseTime->copy()->addDays($i);
            $user->updated_at = $baseTime->copy()->addDays($i)->addHours($i);
            $user->save();

            $users[] = $user;
        }

        return $users;
    }

    /**
     * Create test data for JSON attribute testing
     */
    public function createJsonTestData(): array
    {
        $testData = [
            [
                'name' => 'User with simple JSON',
                'email' => 'simple@example.com',
                'settings' => ['theme' => 'dark', 'language' => 'en'],
            ],
            [
                'name' => 'User with complex JSON',
                'email' => 'complex@example.com',
                'settings' => [
                    'theme' => 'light',
                    'notifications' => [
                        'email' => true,
                        'push' => false,
                        'preferences' => [
                            'marketing' => false,
                            'updates' => true,
                        ],
                    ],
                    'dashboard' => [
                        'widgets' => ['weather', 'calendar', 'tasks'],
                        'layout' => 'grid',
                    ],
                ],
            ],
            [
                'name' => 'User with array JSON',
                'email' => 'array@example.com',
                'settings' => [
                    'tags' => ['work', 'personal', 'important'],
                    'numbers' => [1, 2, 3, 4, 5],
                    'mixed' => ['string', 42, true, null],
                ],
            ],
        ];

        $users = [];
        foreach ($testData as $data) {
            $users[] = User::create($data);
        }

        return $users;
    }

    /**
     * Execute a raw Cypher query and return results
     */
    public function executeCypher(string $cypher, array $parameters = []): array
    {
        $result = $this->neo4jClient->run($cypher, $parameters);

        return $result->toArray();
    }

    /**
     * Get database statistics for analysis
     */
    public function getDatabaseStats(): array
    {
        $stats = [];

        // Node counts by label
        $labelResult = $this->neo4jClient->run('CALL db.labels() YIELD label RETURN label');
        foreach ($labelResult as $record) {
            $label = $record->get('label');
            $countResult = $this->neo4jClient->run("MATCH (n:`$label`) RETURN count(n) as count");
            $stats['nodes'][$label] = $countResult->first()->get('count');
        }

        // Relationship counts by type
        $typeResult = $this->neo4jClient->run('CALL db.relationshipTypes() YIELD relationshipType RETURN relationshipType');
        foreach ($typeResult as $record) {
            $type = $record->get('relationshipType');
            $countResult = $this->neo4jClient->run("MATCH ()-[r:`$type`]->() RETURN count(r) as count");
            $stats['relationships'][$type] = $countResult->first()->get('count');
        }

        return $stats;
    }

    /**
     * Monitor memory usage during operation
     */
    public function monitorMemoryUsage(callable $operation): array
    {
        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);

        $startTime = microtime(true);
        $result = $operation();
        $endTime = microtime(true);

        $memoryAfter = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);

        return [
            'result' => $result,
            'memory_used' => $memoryAfter - $memoryBefore,
            'peak_memory' => $peakAfter - $peakBefore,
            'execution_time' => $endTime - $startTime,
            'memory_before' => $memoryBefore,
            'memory_after' => $memoryAfter,
        ];
    }

    /**
     * Create test data for relationship testing
     */
    public function createRelationshipTestScenario(): array
    {
        // Create hierarchical data for complex relationship testing
        $users = $this->createTestUsers(3);
        $posts = $this->createTestPosts($users, 2);
        $comments = $this->createTestComments($posts, $users);

        // Create some cross-user comments (user comments on other user's posts)
        $extraComments = [];
        $extraComments[] = Comment::create([
            'content' => 'Cross-user comment 1',
            'post_id' => $posts[0]->id, // User 1's post
            'user_id' => $users[1]->id, // Comment by User 2
        ]);

        $extraComments[] = Comment::create([
            'content' => 'Cross-user comment 2',
            'post_id' => $posts[3]->id, // User 2's post
            'user_id' => $users[2]->id, // Comment by User 3
        ]);

        return [
            'users' => $users,
            'posts' => $posts,
            'comments' => array_merge($comments, $extraComments),
        ];
    }
}
