<?php

namespace Tests\TestCase\Helpers;

use Laudis\Neo4j\Contracts\ClientInterface;
use Tests\Models\Comment;
use Tests\Models\Post;
use Tests\Models\Role;
use Tests\Models\User;

class GraphDataFactory
{
    protected ClientInterface $neo4jClient;

    protected array $createdNodes = [];

    protected array $createdRelationships = [];

    public function __construct(ClientInterface $neo4jClient)
    {
        $this->neo4jClient = $neo4jClient;
    }

    /**
     * Create a complex blog scenario with multiple relationship types
     */
    public function createBlogScenario(): array
    {
        // Authors (Users with special role)
        $authors = [];
        for ($i = 1; $i <= 3; $i++) {
            $authors[] = User::create([
                'name' => "Author $i",
                'email' => "author{$i}@blog.com",
                'type' => 'author',
                'experience_years' => rand(1, 10),
            ]);
        }

        // Readers (Regular users)
        $readers = [];
        for ($i = 1; $i <= 5; $i++) {
            $readers[] = User::create([
                'name' => "Reader $i",
                'email' => "reader{$i}@example.com",
                'type' => 'reader',
                'subscription_level' => ['free', 'premium', 'vip'][rand(0, 2)],
            ]);
        }

        // Categories (implemented as roles for many-to-many)
        $categories = [];
        $categoryNames = ['Technology', 'Science', 'Health', 'Travel', 'Food'];
        foreach ($categoryNames as $name) {
            $categories[] = Role::create([
                'name' => strtolower($name),
                'description' => "$name category",
                'type' => 'category',
            ]);
        }

        // Blog posts by authors
        $posts = [];
        foreach ($authors as $author) {
            for ($i = 1; $i <= 4; $i++) {
                $posts[] = Post::create([
                    'title' => "Article $i by {$author->name}",
                    'content' => "Detailed content for article $i by {$author->name}",
                    'user_id' => $author->id,
                    'status' => ['draft', 'published', 'archived'][rand(0, 2)],
                    'word_count' => rand(500, 3000),
                    'reading_time' => rand(3, 15),
                ]);
            }
        }

        // Assign categories to posts
        foreach ($posts as $post) {
            $assignedCategories = array_rand($categories, rand(1, 3));
            if (! is_array($assignedCategories)) {
                $assignedCategories = [$assignedCategories];
            }
            $categoryIds = array_map(fn ($idx) => $categories[$idx]->id, $assignedCategories);
            $post->categories()->attach($categoryIds);
        }

        // Comments by readers on posts
        $comments = [];
        foreach ($posts as $post) {
            $numComments = rand(2, 6);
            for ($i = 0; $i < $numComments; $i++) {
                $commenter = $readers[array_rand($readers)];
                $comments[] = Comment::create([
                    'content' => "Comment by {$commenter->name} on {$post->title}",
                    'post_id' => $post->id,
                    'user_id' => $commenter->id,
                    'rating' => rand(1, 5),
                    'is_featured' => rand(0, 1),
                ]);
            }
        }

        return [
            'authors' => $authors,
            'readers' => $readers,
            'categories' => $categories,
            'posts' => $posts,
            'comments' => $comments,
        ];
    }

    /**
     * Create a social network scenario
     */
    public function createSocialNetworkScenario(): array
    {
        // Create users
        $users = [];
        for ($i = 1; $i <= 10; $i++) {
            $users[] = User::create([
                'name' => "User $i",
                'email' => "user{$i}@social.com",
                'username' => "user$i",
                'follower_count' => rand(10, 1000),
                'following_count' => rand(5, 500),
                'verified' => rand(0, 1),
            ]);
        }

        // Create posts (content sharing)
        $posts = [];
        foreach ($users as $user) {
            $numPosts = rand(3, 8);
            for ($i = 1; $i <= $numPosts; $i++) {
                $posts[] = Post::create([
                    'title' => "Post $i by {$user->username}",
                    'content' => "Social media content from {$user->username}",
                    'user_id' => $user->id,
                    'likes_count' => rand(0, 500),
                    'shares_count' => rand(0, 50),
                    'visibility' => ['public', 'friends', 'private'][rand(0, 2)],
                ]);
            }
        }

        // Create friendship relationships via roles
        $friendships = [];
        for ($i = 0; $i < 15; $i++) {
            $user1 = $users[array_rand($users)];
            $user2 = $users[array_rand($users)];

            if ($user1->id !== $user2->id) {
                // Check if friendship already exists
                $existing = $this->neo4jClient->run(
                    'MATCH (u1:users {id: $id1})-[r:USER_ROLE]-(u2:users {id: $id2}) RETURN count(r) as count',
                    ['id1' => $user1->id, 'id2' => $user2->id]
                )->first()->get('count');

                if ($existing == 0) {
                    $friendship = Role::create([
                        'name' => 'friend',
                        'description' => 'Friendship connection',
                        'type' => 'friendship',
                        'created_at' => now()->subDays(rand(1, 365)),
                    ]);
                    $friendships[] = $friendship;

                    $user1->roles()->attach($friendship->id);
                    $user2->roles()->attach($friendship->id);
                }
            }
        }

        return [
            'users' => $users,
            'posts' => $posts,
            'friendships' => $friendships,
        ];
    }

    /**
     * Create an e-commerce scenario
     */
    public function createEcommerceScenario(): array
    {
        // Customers
        $customers = [];
        for ($i = 1; $i <= 8; $i++) {
            $customers[] = User::create([
                'name' => "Customer $i",
                'email' => "customer{$i}@shop.com",
                'type' => 'customer',
                'membership_level' => ['bronze', 'silver', 'gold', 'platinum'][rand(0, 3)],
                'total_spent' => rand(100, 5000),
                'registration_date' => now()->subDays(rand(30, 1000)),
            ]);
        }

        // Products (using Post model)
        $products = [];
        $productCategories = ['Electronics', 'Clothing', 'Books', 'Home', 'Sports'];
        for ($i = 1; $i <= 20; $i++) {
            $products[] = Post::create([
                'title' => "Product $i",
                'content' => "Description for product $i",
                'user_id' => $customers[0]->id, // Shop owner
                'price' => rand(10, 500),
                'stock_quantity' => rand(0, 100),
                'category' => $productCategories[array_rand($productCategories)],
                'rating' => rand(1, 5),
                'is_featured' => rand(0, 1),
            ]);
        }

        // Orders (Comments represent order items)
        $orders = [];
        foreach ($customers as $customer) {
            $numOrders = rand(1, 5);
            for ($i = 1; $i <= $numOrders; $i++) {
                $product = $products[array_rand($products)];
                $orders[] = Comment::create([
                    'content' => "Order item: {$product->title}",
                    'post_id' => $product->id,
                    'user_id' => $customer->id,
                    'quantity' => rand(1, 5),
                    'order_date' => now()->subDays(rand(1, 180)),
                    'status' => ['pending', 'shipped', 'delivered', 'returned'][rand(0, 3)],
                ]);
            }
        }

        return [
            'customers' => $customers,
            'products' => $products,
            'orders' => $orders,
        ];
    }

    /**
     * Create a hierarchical organization scenario
     */
    public function createOrganizationScenario(): array
    {
        // Create organization hierarchy
        $ceo = User::create([
            'name' => 'CEO',
            'email' => 'ceo@company.com',
            'position' => 'Chief Executive Officer',
            'level' => 1,
            'department' => 'Executive',
        ]);

        $managers = [];
        $departments = ['Engineering', 'Marketing', 'Sales', 'HR'];
        foreach ($departments as $dept) {
            $managers[] = User::create([
                'name' => "Manager of $dept",
                'email' => strtolower($dept).'.manager@company.com',
                'position' => "$dept Manager",
                'level' => 2,
                'department' => $dept,
                'reports_to' => $ceo->id,
            ]);
        }

        $employees = [];
        foreach ($managers as $manager) {
            $numEmployees = rand(3, 7);
            for ($i = 1; $i <= $numEmployees; $i++) {
                $employees[] = User::create([
                    'name' => "{$manager->department} Employee $i",
                    'email' => strtolower($manager->department).".emp{$i}@company.com",
                    'position' => "{$manager->department} Specialist",
                    'level' => 3,
                    'department' => $manager->department,
                    'reports_to' => $manager->id,
                    'salary_range' => rand(40000, 80000),
                ]);
            }
        }

        // Projects (using Post model)
        $projects = [];
        foreach ($managers as $manager) {
            $numProjects = rand(2, 4);
            for ($i = 1; $i <= $numProjects; $i++) {
                $projects[] = Post::create([
                    'title' => "{$manager->department} Project $i",
                    'content' => "Project description for {$manager->department}",
                    'user_id' => $manager->id,
                    'status' => ['planning', 'active', 'completed', 'on-hold'][rand(0, 3)],
                    'budget' => rand(10000, 500000),
                    'start_date' => now()->subDays(rand(30, 365)),
                    'deadline' => now()->addDays(rand(30, 180)),
                ]);
            }
        }

        return [
            'ceo' => $ceo,
            'managers' => $managers,
            'employees' => $employees,
            'projects' => $projects,
        ];
    }

    /**
     * Create performance testing data with controlled complexity
     */
    public function createPerformanceTestData(int $scale = 100): array
    {
        $users = [];
        $posts = [];
        $comments = [];

        // Create users in batches
        $batchSize = 50;
        for ($batch = 0; $batch < ceil($scale / $batchSize); $batch++) {
            $batchStart = $batch * $batchSize;
            $batchEnd = min($batchStart + $batchSize, $scale);

            for ($i = $batchStart; $i < $batchEnd; $i++) {
                $users[] = User::create([
                    'name' => "PerfUser $i",
                    'email' => "perf{$i}@test.com",
                    'batch_number' => $batch,
                    'user_index' => $i,
                ]);
            }

            // Create posts for this batch
            foreach (array_slice($users, $batchStart, $batchEnd - $batchStart) as $user) {
                $numPosts = rand(1, 5);
                for ($j = 1; $j <= $numPosts; $j++) {
                    $posts[] = Post::create([
                        'title' => "Post $j by User {$user->user_index}",
                        'content' => 'Performance test content',
                        'user_id' => $user->id,
                        'batch_number' => $batch,
                    ]);
                }
            }

            // Cleanup memory periodically
            if ($batch % 10 === 0) {
                gc_collect_cycles();
            }
        }

        // Create a subset of comments to test relationships
        $commentCount = min(count($posts) * 2, 1000); // Limit comments for performance
        for ($i = 0; $i < $commentCount; $i++) {
            $post = $posts[array_rand($posts)];
            $commenter = $users[array_rand($users)];

            $comments[] = Comment::create([
                'content' => "Performance comment $i",
                'post_id' => $post->id,
                'user_id' => $commenter->id,
            ]);
        }

        return [
            'users' => $users,
            'posts' => $posts,
            'comments' => $comments,
            'scale' => $scale,
        ];
    }

    /**
     * Create graph with specific relationship patterns for testing
     */
    public function createRelationshipPatterns(): array
    {
        // Star pattern - one central node connected to many
        $centralUser = User::create([
            'name' => 'Central Hub User',
            'email' => 'hub@test.com',
            'pattern_type' => 'star_center',
        ]);

        $starNodes = [];
        for ($i = 1; $i <= 8; $i++) {
            $starNodes[] = User::create([
                'name' => "Star Node $i",
                'email' => "star{$i}@test.com",
                'pattern_type' => 'star_node',
                'connected_to_hub' => $centralUser->id,
            ]);
        }

        // Chain pattern - linear connections
        $chainNodes = [];
        for ($i = 1; $i <= 6; $i++) {
            $chainNodes[] = User::create([
                'name' => "Chain Node $i",
                'email' => "chain{$i}@test.com",
                'pattern_type' => 'chain',
                'chain_position' => $i,
            ]);
        }

        // Cluster pattern - densely connected subgraph
        $clusterNodes = [];
        for ($i = 1; $i <= 5; $i++) {
            $clusterNodes[] = User::create([
                'name' => "Cluster Node $i",
                'email' => "cluster{$i}@test.com",
                'pattern_type' => 'cluster',
            ]);
        }

        return [
            'star' => ['center' => $centralUser, 'nodes' => $starNodes],
            'chain' => $chainNodes,
            'cluster' => $clusterNodes,
        ];
    }

    /**
     * Create temporal data for time-based testing
     */
    public function createTemporalData(): array
    {
        $baseDate = now()->subMonths(6);
        $users = [];

        // Create users across different time periods
        for ($month = 0; $month < 6; $month++) {
            $monthDate = $baseDate->copy()->addMonths($month);
            $usersInMonth = rand(5, 15);

            for ($i = 1; $i <= $usersInMonth; $i++) {
                $user = User::create([
                    'name' => "User M{$month}U{$i}",
                    'email' => "m{$month}u{$i}@temporal.com",
                    'signup_month' => $month,
                    'created_at' => $monthDate->copy()->addDays(rand(0, 29)),
                ]);

                // Create posts throughout the period
                $postsCount = rand(2, 8);
                for ($j = 1; $j <= $postsCount; $j++) {
                    Post::create([
                        'title' => "Post $j by User M{$month}U{$i}",
                        'content' => 'Temporal content',
                        'user_id' => $user->id,
                        'created_at' => $monthDate->copy()->addDays(rand(0, 29)),
                        'month_created' => $month,
                    ]);
                }

                $users[] = $user;
            }
        }

        return ['users' => $users];
    }

    /**
     * Get summary of created test data
     */
    public function getDataSummary(): array
    {
        $summary = [];

        $labels = ['users', 'posts', 'comments', 'roles', 'profiles'];
        foreach ($labels as $label) {
            $result = $this->neo4jClient->run("MATCH (n:`$label`) RETURN count(n) as count");
            $summary['nodes'][$label] = $result->first()->get('count');
        }

        $result = $this->neo4jClient->run('MATCH ()-[r]->() RETURN count(r) as count');
        $summary['total_relationships'] = $result->first()->get('count');

        return $summary;
    }

    /**
     * Clean up all created test data
     */
    public function cleanup(): void
    {
        // Order matters for relationship dependencies
        // First delete all native edges that might have been created
        $deleteOrder = [
            // Delete all native edge types first
            'MATCH ()-[r:HAS_POSTS]->() DELETE r',
            'MATCH ()-[r:HAS_COMMENTS]->() DELETE r',
            'MATCH ()-[r:BELONGS_TO]->() DELETE r',
            'MATCH ()-[r:HAS_PROFILE]->() DELETE r',
            'MATCH ()-[r:USER_ROLE]->() DELETE r',
            'MATCH ()-[r:USERS_ROLES]->() DELETE r',
            // Then use DETACH DELETE to remove nodes and any remaining relationships
            'MATCH (n:comments) DETACH DELETE n',
            'MATCH (n:posts) DETACH DELETE n',
            'MATCH (n:profiles) DETACH DELETE n',
            'MATCH (n:roles) DETACH DELETE n',
            'MATCH (n:users) DETACH DELETE n',
        ];

        foreach ($deleteOrder as $cypher) {
            $this->neo4jClient->run($cypher);
        }

        $this->createdNodes = [];
        $this->createdRelationships = [];
    }

    /**
     * Create minimal test data for quick tests
     */
    public function createMinimalData(): array
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test content',
            'user_id' => $user->id,
        ]);

        return ['user' => $user, 'post' => $post];
    }

    /**
     * Create data with all supported cast types for testing
     */
    public function createCastingTestData(): array
    {
        return [
            User::create([
                'name' => 'Cast Test User',
                'email' => 'cast@test.com',
                'age' => 30,                                    // integer
                'score' => 95.5,                               // float
                'is_active' => true,                           // boolean
                'preferences' => ['theme' => 'dark'],          // array/json
                'metadata' => '{"key": "value"}',              // json string
                'tags' => ['php', 'neo4j', 'testing'],        // array
                'created_at' => now(),                         // datetime
                'last_login_date' => now()->toDateString(),    // date
            ]),
        ];
    }
}
