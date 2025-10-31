# Neo4j Aggregate Functions

Master statistical analysis and data aggregation in Neo4j. Beyond standard SQL aggregates, Neo4j provides powerful functions for percentiles, standard deviation, and array collection—perfect for analytics dashboards, performance monitoring, and quality control.

---

## Introduction

Neo4j supports all standard SQL aggregate functions plus specialized statistical functions for advanced analytics.

**Standard aggregates**: `count()`, `sum()`, `avg()`, `min()`, `max()`
**Neo4j aggregates**: `percentileDisc()`, `percentileCont()`, `stdev()`, `stdevp()`, `collect()`

### Neo4j vs SQL Aggregates

**Similarities**:
- All standard SQL aggregates work identically
- NULL values are ignored in calculations
- Can be combined with WHERE, GROUP BY, HAVING

**Neo4j advantages**:
- Built-in percentile functions (no window functions needed)
- Native array collection with `collect()`
- Statistical functions optimized for graph queries
- Work seamlessly in relationship traversals

---

## Standard Aggregates

**✅ Same as Eloquent**: All standard aggregate methods work identically.

### Quick Reference

```php
use App\Models\User;
use App\Models\Product;

// count() - Count records
$total = User::count();                         // 1,523

// sum() - Sum numeric values
$revenue = Order::sum('amount');                // 125,430.50

// avg() - Average value
$avgAge = User::avg('age');                     // 34.5

// min() - Minimum value
$lowestPrice = Product::min('price');           // 9.99

// max() - Maximum value
$highestSalary = Employee::max('salary');       // 185,000
```

### Aggregates with Conditions

```php
// Average salary for active employees
$avgSalary = Employee::where('active', true)
    ->avg('salary');

// Max views for posts this year
$maxViews = Post::whereYear('created_at', 2024)
    ->max('views');

// Sum of completed order amounts
$totalRevenue = Order::where('status', 'completed')
    ->sum('amount');
```

### Aggregates on Relationships

```php
$user = User::find($id);

// Total views across user's posts
$totalViews = $user->posts()->sum('views');

// Average rating of user's products
$avgRating = $user->products()->avg('rating');

// Count of user's comments
$commentCount = $user->comments()->count();
```

**✅ Works everywhere**: Use aggregates on models, query builders, and relationships.

---

## Percentile Functions Deep Dive

Percentile functions calculate values at specific points in your distribution—essential for SLA monitoring, performance analysis, and outlier detection.

### percentileDisc() - Discrete Percentile

Returns the **actual value** from your dataset at the specified percentile. No interpolation—always returns a value that exists in your data.

```php
use App\Models\Request;

// Get 95th percentile response time
$p95 = Request::percentileDisc('response_time', 0.95);
// Returns: 245 (actual value from dataset)

// Get 99th percentile for API latency
$p99 = ApiLog::where('endpoint', '/api/users')
    ->percentileDisc('latency_ms', 0.99);
// Returns: 892
```

**When to use**:
- SLA monitoring (p95, p99 response times)
- Performance benchmarking
- When you need actual observed values
- Outlier detection thresholds

### percentileCont() - Continuous Percentile

Returns an **interpolated value** at the specified percentile. May return a value that doesn't exist in your dataset.

```php
use App\Models\Sale;

// Get interpolated median
$median = Sale::percentileCont('amount', 0.5);
// Returns: 127.50 (may be interpolated)

// Calculate quartiles for box plot
$q1 = Product::percentileCont('price', 0.25);  // 29.75
$q2 = Product::percentileCont('price', 0.50);  // 49.50 (median)
$q3 = Product::percentileCont('price', 0.75);  // 79.25
```

**When to use**:
- Statistical analysis requiring smooth distributions
- Quartile calculations for box plots
- When interpolated values are acceptable
- Scientific/academic reporting

### Discrete vs Continuous: Practical Example

```php
// Dataset: [10, 20, 30, 40, 50]

// 75th percentile
$disc = Product::percentileDisc('price', 0.75);
// Returns: 40 (actual value at 75th position)

$cont = Product::percentileCont('price', 0.75);
// Returns: 42.5 (interpolated between 40 and 50)
```

### Percentiles in Relationship Queries

```php
$user = User::find($id);

// 95th percentile view count for user's posts
$p95Views = $user->posts()
    ->percentileDisc('views', 0.95);

// Median order amount for this customer
$medianOrder = $customer->orders()
    ->where('status', 'completed')
    ->percentileCont('amount', 0.5);
```

### Common Percentiles

```php
// P50 (Median) - Middle value
$p50 = Request::percentileCont('response_time', 0.50);

// P95 - 95% of requests faster than this
$p95 = Request::percentileDisc('response_time', 0.95);

// P99 - 99% of requests faster than this
$p99 = Request::percentileDisc('response_time', 0.99);

// P999 (3-nines) - Used for strict SLAs
$p999 = Request::percentileDisc('response_time', 0.999);
```

**⚠️ Percentile Range**: Values must be between 0.0 and 1.0
✅ Valid: `0.95`, `0.99`, `0.5`
❌ Invalid: `95`, `99`, `50`

---

## Standard Deviation Functions

Measure variability and spread in your data. Essential for quality control, risk assessment, and consistency analysis.

### stdev() - Sample Standard Deviation

Calculates standard deviation for a **sample** of the population (uses n-1 denominator).

```php
use App\Models\Product;
use App\Models\Employee;

// Price variability in product category
$priceStdDev = Product::where('category', 'electronics')
    ->stdev('price');
// Returns: 24.57 (higher = more price variation)

// Salary spread among employees
$salaryStdDev = Employee::where('department', 'engineering')
    ->stdev('salary');
// Returns: 15,432.50
```

**Use sample stdev when**:
- You're analyzing a subset of data
- Making statistical inferences
- Default choice for most use cases

### stdevp() - Population Standard Deviation

Calculates standard deviation for the **entire population** (uses n denominator).

```php
// Manufacturing: Part dimension consistency
$dimensionStdDev = Part::where('batch_id', 'B-2024-001')
    ->stdevp('dimension_mm');
// Returns: 0.012 (tight tolerance)

// Complete dataset analysis
$allSalaries = Employee::stdevp('salary');
```

**Use population stdev when**:
- You have the complete dataset
- Quality control measurements
- When n-1 adjustment isn't appropriate

### Sample vs Population: Practical Example

```php
// Dataset: [10, 20, 30, 40, 50]

$sample = Order::stdev('amount');
// Uses n-1: More conservative estimate

$population = Order::stdevp('amount');
// Uses n: Assumes complete data
```

### Standard Deviation in Quality Control

```php
// Detect products with inconsistent quality
$products = Product::selectRaw('
    n.name,
    AVG(n.rating) as avg_rating,
    stdev(n.rating) as rating_consistency
')
->groupBy('n.name')
->get();

foreach ($products as $product) {
    if ($product->rating_consistency > 1.5) {
        // High variability - inconsistent quality
        echo "⚠️ {$product->name}: Inconsistent ratings\n";
    }
}
```

### Coefficient of Variation

Combine standard deviation with mean for relative variability:

```php
$stats = Product::selectRaw('
    AVG(n.price) as avg_price,
    stdev(n.price) as std_dev_price
')->first();

$coefficientOfVariation = ($stats->std_dev_price / $stats->avg_price) * 100;
// Returns: 42.5% (coefficient of variation)

if ($coefficientOfVariation > 50) {
    echo "High price variability across products";
}
```

---

## collect() Function

Aggregate values into arrays—perfect for tag clouds, gathering IDs, and building nested data structures.

### Basic Collection

```php
use App\Models\User;
use App\Models\Post;

// Collect all user names
$names = User::where('active', true)
    ->collect('name');
// Returns: ['Alice', 'Bob', 'Charlie', 'Diana']

// Collect post IDs for bulk operation
$postIds = Post::where('published', true)
    ->collect('id');
// Returns: [1, 2, 3, 4, 5]
```

### Collecting from Relationships

```php
$user = User::find($id);

// Collect all tags from user's posts
$tags = $user->posts()->collect('tags');
// Returns: ['php', 'laravel', 'neo4j', 'graphdb', 'api']

// Collect product IDs from orders
$purchasedProductIds = $user->orders()
    ->where('status', 'completed')
    ->collect('product_id');
// Returns: [101, 105, 107, 112]
```

### Building Tag Clouds

```php
// Get all unique tags with counts
$tagStats = Post::selectRaw('
    collect(DISTINCT n.tags) as all_tags,
    COUNT(*) as post_count
')->first();

// Flatten nested arrays
$allTags = collect($tagStats->all_tags)
    ->flatten()
    ->unique()
    ->values()
    ->all();
// Returns: ['php', 'laravel', 'neo4j', 'javascript', 'api']
```

### Grouping with collect()

```php
// Collect post titles by user
$userPosts = User::join('posts', function($join) {
    $join->on('posts.user_id', '=', 'users.id');
})
->selectRaw('
    users.name,
    collect(posts.title) as post_titles
')
->groupBy('users.name')
->get();

foreach ($userPosts as $user) {
    echo "{$user->name}: " . implode(', ', $user->post_titles) . "\n";
}
// Output:
// Alice: Getting Started, Advanced Tips, FAQ
// Bob: Tutorial Part 1, Tutorial Part 2
```

### Collecting Nested Properties

```php
// Collect multiple fields as structured data
$productData = Order::selectRaw('
    n.order_id,
    collect({
        product: n.product_name,
        quantity: n.quantity,
        price: n.unit_price
    }) as items
')
->groupBy('n.order_id')
->get();
```

---

## Statistical Analysis Guide

Complete workflow for building analytics dashboards and statistical reports.

### Distribution Analysis

Analyze the full distribution of your data:

```php
use App\Models\Order;

$distribution = Order::where('status', 'completed')
    ->selectRaw('
        COUNT(*) as total_orders,
        MIN(n.amount) as min_amount,
        percentileCont(n.amount, 0.25) as q1,
        percentileCont(n.amount, 0.50) as median,
        percentileCont(n.amount, 0.75) as q3,
        MAX(n.amount) as max_amount,
        AVG(n.amount) as mean,
        stdev(n.amount) as std_dev
    ')
    ->first();

// Five-number summary
echo "Distribution Analysis:\n";
echo "Min:    ${$distribution->min_amount}\n";
echo "Q1:     ${$distribution->q1}\n";
echo "Median: ${$distribution->median}\n";
echo "Q3:     ${$distribution->q3}\n";
echo "Max:    ${$distribution->max_amount}\n";
echo "Mean:   ${$distribution->mean}\n";
echo "StdDev: ${$distribution->std_dev}\n";
```

### Outlier Detection

Identify statistical outliers using IQR method:

```php
$stats = Product::selectRaw('
    percentileCont(n.price, 0.25) as q1,
    percentileCont(n.price, 0.75) as q3
')->first();

$iqr = $stats->q3 - $stats->q1;
$lowerBound = $stats->q1 - (1.5 * $iqr);
$upperBound = $stats->q3 + (1.5 * $iqr);

// Find outliers
$outliers = Product::where('price', '<', $lowerBound)
    ->orWhere('price', '>', $upperBound)
    ->get();

foreach ($outliers as $product) {
    echo "⚠️ Outlier: {$product->name} - ${$product->price}\n";
}
```

### Cohort Analysis

Analyze metrics across different cohorts:

```php
$cohortStats = User::selectRaw('
    DATE(n.created_at) as signup_date,
    COUNT(*) as user_count,
    AVG(n.lifetime_value) as avg_ltv,
    percentileDisc(n.lifetime_value, 0.95) as p95_ltv,
    stdev(n.lifetime_value) as ltv_std_dev
')
->groupBy('DATE(n.created_at)')
->orderBy('signup_date', 'desc')
->limit(30)
->get();

foreach ($cohortStats as $cohort) {
    echo "{$cohort->signup_date}: ";
    echo "{$cohort->user_count} users, ";
    echo "LTV: \${$cohort->avg_ltv} ± \${$cohort->ltv_std_dev}\n";
}
```

### Z-Score Calculation

Identify how many standard deviations a value is from the mean:

```php
$stats = Product::selectRaw('
    AVG(n.price) as mean,
    stdev(n.price) as std_dev
')->first();

$products = Product::all();

foreach ($products as $product) {
    $zScore = ($product->price - $stats->mean) / $stats->std_dev;

    if (abs($zScore) > 3) {
        echo "Extreme: {$product->name} (z={$zScore})\n";
    }
}
```

---

## Performance Monitoring

Real-time metrics and SLA tracking using Neo4j aggregates.

### SLA Dashboard

Monitor service level agreements with percentile tracking:

```php
use App\Models\ApiRequest;

// Calculate SLA metrics for last hour
$slaMetrics = ApiRequest::where('created_at', '>', now()->subHour())
    ->selectRaw('
        COUNT(*) as total_requests,
        AVG(n.response_time) as avg_response,
        percentileDisc(n.response_time, 0.50) as p50,
        percentileDisc(n.response_time, 0.95) as p95,
        percentileDisc(n.response_time, 0.99) as p99,
        percentileDisc(n.response_time, 0.999) as p999,
        MAX(n.response_time) as max_response
    ')
    ->first();

// Display dashboard
echo "📊 API Performance (Last Hour)\n";
echo "Total Requests: {$slaMetrics->total_requests}\n";
echo "Average:        {$slaMetrics->avg_response}ms\n";
echo "P50 (median):   {$slaMetrics->p50}ms\n";
echo "P95:            {$slaMetrics->p95}ms ";
echo ($slaMetrics->p95 < 200 ? "✅" : "⚠️") . "\n";
echo "P99:            {$slaMetrics->p99}ms ";
echo ($slaMetrics->p99 < 500 ? "✅" : "⚠️") . "\n";
echo "P999:           {$slaMetrics->p999}ms\n";
echo "Max:            {$slaMetrics->max_response}ms\n";
```

### Endpoint Performance Comparison

```php
$endpointStats = ApiRequest::selectRaw('
    n.endpoint,
    COUNT(*) as request_count,
    AVG(n.response_time) as avg_time,
    percentileDisc(n.response_time, 0.95) as p95,
    stdev(n.response_time) as consistency
')
->groupBy('n.endpoint')
->orderBy('request_count', 'desc')
->limit(10)
->get();

foreach ($endpointStats as $stats) {
    $indicator = $stats->p95 < 200 ? "✅" : "⚠️";
    echo "{$indicator} {$stats->endpoint}\n";
    echo "   Requests: {$stats->request_count}\n";
    echo "   Avg: {$stats->avg_time}ms, P95: {$stats->p95}ms\n";
    echo "   Consistency: ±{$stats->consistency}ms\n\n";
}
```

### Real-Time Alerting

```php
// Check if current performance exceeds thresholds
$currentP95 = ApiRequest::where('created_at', '>', now()->subMinutes(5))
    ->percentileDisc('response_time', 0.95);

if ($currentP95 > 500) {
    // Send alert
    $message = "🚨 P95 latency: {$currentP95}ms (threshold: 500ms)";
    notify_ops_team($message);
}
```

### Error Rate Analysis

```php
$errorStats = ApiRequest::where('created_at', '>', now()->subDay())
    ->selectRaw('
        COUNT(*) as total,
        SUM(CASE WHEN n.status >= 500 THEN 1 ELSE 0 END) as errors,
        AVG(CASE WHEN n.status >= 500 THEN n.response_time ELSE null END) as error_response_time
    ')
    ->first();

$errorRate = ($errorStats->errors / $errorStats->total) * 100;

echo "Error Rate: " . number_format($errorRate, 2) . "%\n";
echo "Error Response Time: {$errorStats->error_response_time}ms\n";
```

---

## Time-Series Analysis

Aggregate data over time periods for trend analysis and forecasting.

### Hourly Metrics

```php
use App\Models\Metric;

// Aggregate by hour
$hourlyStats = Metric::selectRaw('
    datetime({epochSeconds: toInteger(n.timestamp / 1000)}) as hour,
    AVG(n.value) as avg_value,
    MIN(n.value) as min_value,
    MAX(n.value) as max_value,
    percentileDisc(n.value, 0.95) as p95_value,
    stdev(n.value) as volatility
')
->where('created_at', '>', now()->subDay())
->groupBy('hour')
->orderBy('hour', 'asc')
->get();

foreach ($hourlyStats as $hour) {
    echo "{$hour->hour}: ";
    echo "avg={$hour->avg_value}, ";
    echo "p95={$hour->p95_value}, ";
    echo "volatility={$hour->volatility}\n";
}
```

### Daily Rollups

```php
// Daily aggregated statistics
$dailyStats = Order::selectRaw('
    DATE(n.created_at) as order_date,
    COUNT(*) as order_count,
    SUM(n.amount) as revenue,
    AVG(n.amount) as avg_order_value,
    percentileCont(n.amount, 0.50) as median_order,
    stdev(n.amount) as order_variance
')
->where('status', 'completed')
->where('created_at', '>', now()->subDays(30))
->groupBy('DATE(n.created_at)')
->orderBy('order_date', 'desc')
->get();
```

### Moving Averages

Calculate rolling statistics for trend analysis:

```php
// Get last 7 days of data
$dailyMetrics = Metric::selectRaw('
    DATE(n.created_at) as metric_date,
    AVG(n.value) as daily_avg
')
->where('created_at', '>', now()->subDays(7))
->groupBy('DATE(n.created_at)')
->orderBy('metric_date', 'asc')
->get();

// Calculate 3-day moving average
$movingAvgs = collect($dailyMetrics)
    ->sliding(3)
    ->map(function ($window) {
        return [
            'date' => $window->last()->metric_date,
            'value' => $window->avg('daily_avg'),
        ];
    });
```

### Seasonal Patterns

```php
// Analyze by day of week
$weekdayStats = Order::selectRaw('
    dayOfWeek(n.created_at) as weekday,
    COUNT(*) as order_count,
    AVG(n.amount) as avg_amount,
    percentileDisc(n.amount, 0.95) as p95_amount
')
->where('created_at', '>', now()->subMonths(3))
->groupBy('dayOfWeek(n.created_at)')
->orderBy('weekday', 'asc')
->get();

$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
foreach ($weekdayStats as $stat) {
    echo "{$days[$stat->weekday]}: ";
    echo "{$stat->order_count} orders, ";
    echo "avg=\${$stat->avg_amount}\n";
}
```

---

## Combining Aggregates

Build comprehensive dashboards with multiple metrics in a single query.

### Multi-Metric Dashboard

```php
use App\Models\Order;

$dashboard = Order::where('created_at', '>', now()->subMonth())
    ->selectRaw('
        COUNT(*) as total_orders,
        SUM(n.amount) as total_revenue,
        AVG(n.amount) as avg_order_value,
        percentileCont(n.amount, 0.50) as median_order,
        percentileDisc(n.amount, 0.95) as p95_order,
        MIN(n.amount) as min_order,
        MAX(n.amount) as max_order,
        stdev(n.amount) as order_std_dev,
        collect(DISTINCT n.customer_id) as unique_customers
    ')
    ->first();

// Display comprehensive dashboard
echo "📊 Monthly Business Dashboard\n\n";
echo "Orders\n";
echo "  Total:     {$dashboard->total_orders}\n";
echo "  Customers: " . count($dashboard->unique_customers) . "\n\n";

echo "Revenue\n";
echo "  Total:     \${$dashboard->total_revenue}\n";
echo "  Average:   \${$dashboard->avg_order_value}\n";
echo "  Median:    \${$dashboard->median_order}\n";
echo "  StdDev:    \${$dashboard->order_std_dev}\n\n";

echo "Order Value Range\n";
echo "  Min:       \${$dashboard->min_order}\n";
echo "  Max:       \${$dashboard->max_order}\n";
echo "  P95:       \${$dashboard->p95_order}\n";
```

### Segmented Analysis

```php
// Compare metrics across segments
$segments = Product::selectRaw('
    n.category,
    COUNT(*) as product_count,
    AVG(n.price) as avg_price,
    percentileCont(n.price, 0.50) as median_price,
    stdev(n.price) as price_variance,
    percentileDisc(n.rating, 0.95) as p95_rating,
    collect(n.id) as product_ids
')
->groupBy('n.category')
->orderBy('product_count', 'desc')
->get();

foreach ($segments as $segment) {
    echo "\n{$segment->category}\n";
    echo "  Products:  {$segment->product_count}\n";
    echo "  Avg Price: \${$segment->avg_price}\n";
    echo "  Variance:  \${$segment->price_variance}\n";
    echo "  P95 Rating: {$segment->p95_rating} ⭐\n";
}
```

### Correlation Analysis

```php
// Analyze relationship between two metrics
$correlation = Post::selectRaw('
    AVG(n.word_count) as avg_words,
    AVG(n.view_count) as avg_views,
    stdev(n.word_count) as words_std,
    stdev(n.view_count) as views_std,
    percentileCont(n.word_count, 0.50) as median_words,
    percentileCont(n.view_count, 0.50) as median_views
')->first();

echo "Content Length vs Views\n";
echo "Words:  {$correlation->median_words} (±{$correlation->words_std})\n";
echo "Views:  {$correlation->median_views} (±{$correlation->views_std})\n";
```

---

## Comparison Tables

### Neo4j vs SQL Aggregate Functions

| Function | Neo4j | SQL Equivalent | Notes |
|----------|-------|----------------|-------|
| **count()** | `COUNT(*)` | `COUNT(*)` | Identical |
| **sum()** | `SUM(n.col)` | `SUM(col)` | Identical |
| **avg()** | `AVG(n.col)` | `AVG(col)` | Identical |
| **min()** | `MIN(n.col)` | `MIN(col)` | Identical |
| **max()** | `MAX(n.col)` | `MAX(col)` | Identical |
| **percentileDisc()** | `percentileDisc(n.col, 0.95)` | `PERCENTILE_DISC(0.95)` | Neo4j simpler syntax |
| **percentileCont()** | `percentileCont(n.col, 0.95)` | `PERCENTILE_CONT(0.95)` | Neo4j simpler syntax |
| **stdev()** | `stdev(n.col)` | `STDDEV_SAMP(col)` | Sample standard deviation |
| **stdevp()** | `stdevp(n.col)` | `STDDEV_POP(col)` | Population standard deviation |
| **collect()** | `collect(n.col)` | `ARRAY_AGG(col)` | Native array aggregation |

### When to Use Each Aggregate

| Use Case | Recommended Function | Why |
|----------|---------------------|-----|
| Total count | `count()` | Standard, fast |
| Sum of values | `sum()` | Direct aggregation |
| Average value | `avg()` | Central tendency |
| SLA monitoring | `percentileDisc()` | Real observed values |
| Statistical analysis | `percentileCont()` | Smooth distribution |
| Quality control | `stdev()` or `stdevp()` | Measure consistency |
| Tag collection | `collect()` | Array aggregation |
| Performance monitoring | `percentileDisc(*, 0.95)` | Industry standard |
| Outlier detection | `stdev()` + `avg()` | Z-score calculation |

---

## Dashboard Examples

### Executive Dashboard

Complete business metrics in one query:

```php
use App\Models\Order;
use App\Models\User;

// Key business metrics
$metrics = Order::where('created_at', '>', now()->subMonth())
    ->where('status', 'completed')
    ->selectRaw('
        COUNT(*) as total_orders,
        SUM(n.amount) as revenue,
        AVG(n.amount) as aov,
        percentileCont(n.amount, 0.50) as median_order,
        percentileDisc(n.amount, 0.95) as high_value_threshold,
        stdev(n.amount) as revenue_volatility,
        collect(DISTINCT n.user_id) as active_customers
    ')
    ->first();

$newCustomers = User::where('created_at', '>', now()->subMonth())
    ->count();

// Display executive dashboard
echo "📈 Executive Dashboard - " . now()->format('F Y') . "\n";
echo str_repeat("=", 50) . "\n\n";

echo "Revenue\n";
echo "  Total:        \$" . number_format($metrics->revenue, 2) . "\n";
echo "  Orders:       " . number_format($metrics->total_orders) . "\n";
echo "  AOV:          \$" . number_format($metrics->aov, 2) . "\n";
echo "  Median:       \$" . number_format($metrics->median_order, 2) . "\n";
echo "  Volatility:   ±\$" . number_format($metrics->revenue_volatility, 2) . "\n\n";

echo "Customers\n";
echo "  Active:       " . count($metrics->active_customers) . "\n";
echo "  New:          {$newCustomers}\n";
echo "  Retention:    " . number_format((count($metrics->active_customers) - $newCustomers) / count($metrics->active_customers) * 100, 1) . "%\n\n";

echo "High-Value Orders (P95)\n";
echo "  Threshold:    \$" . number_format($metrics->high_value_threshold, 2) . "\n";
```

### Operations Dashboard

Real-time system health monitoring:

```php
use App\Models\ApiRequest;
use App\Models\Job;

$performance = ApiRequest::where('created_at', '>', now()->subHour())
    ->selectRaw('
        COUNT(*) as total_requests,
        AVG(n.response_time) as avg_latency,
        percentileDisc(n.response_time, 0.50) as p50,
        percentileDisc(n.response_time, 0.95) as p95,
        percentileDisc(n.response_time, 0.99) as p99,
        MAX(n.response_time) as max_latency,
        SUM(CASE WHEN n.status >= 500 THEN 1 ELSE 0 END) as errors
    ')
    ->first();

$errorRate = ($performance->errors / $performance->total_requests) * 100;

$queueStats = Job::where('status', 'pending')
    ->selectRaw('
        COUNT(*) as pending_jobs,
        AVG(n.attempts) as avg_attempts,
        MAX(n.attempts) as max_attempts
    ')
    ->first();

echo "🖥️  Operations Dashboard - " . now()->format('H:i:s') . "\n";
echo str_repeat("=", 50) . "\n\n";

echo "API Performance\n";
$p95Status = $performance->p95 < 200 ? "✅" : "⚠️";
$p99Status = $performance->p99 < 500 ? "✅" : "🚨";
echo "  P50:          {$performance->p50}ms\n";
echo "  P95:          {$performance->p95}ms {$p95Status}\n";
echo "  P99:          {$performance->p99}ms {$p99Status}\n";
echo "  Max:          {$performance->max_latency}ms\n";
echo "  Avg:          {$performance->avg_latency}ms\n\n";

echo "Reliability\n";
$errorStatus = $errorRate < 1 ? "✅" : "⚠️";
echo "  Error Rate:   " . number_format($errorRate, 2) . "% {$errorStatus}\n";
echo "  Total Errors: {$performance->errors}\n";
echo "  Requests:     {$performance->total_requests}\n\n";

echo "Queue\n";
$queueStatus = $queueStats->pending_jobs < 100 ? "✅" : "⚠️";
echo "  Pending:      {$queueStats->pending_jobs} {$queueStatus}\n";
echo "  Avg Attempts: {$queueStats->avg_attempts}\n";
```

### Product Analytics Dashboard

```php
use App\Models\Product;

$productStats = Product::selectRaw('
    n.category,
    COUNT(*) as product_count,
    AVG(n.price) as avg_price,
    percentileCont(n.price, 0.50) as median_price,
    stdev(n.price) as price_std_dev,
    AVG(n.rating) as avg_rating,
    percentileDisc(n.rating, 0.95) as p95_rating,
    stdev(n.rating) as rating_consistency,
    collect(n.id) as product_ids
')
->groupBy('n.category')
->orderBy('product_count', 'desc')
->get();

echo "🛍️  Product Analytics\n";
echo str_repeat("=", 70) . "\n\n";

foreach ($productStats as $cat) {
    echo strtoupper($cat->category) . "\n";
    echo "  Products:         {$cat->product_count}\n";
    echo "  Price Range:      \$" . number_format($cat->median_price, 2);
    echo " ±\$" . number_format($cat->price_std_dev, 2) . "\n";
    echo "  Average Rating:   " . number_format($cat->avg_rating, 1) . "/5.0";
    echo " (±" . number_format($cat->rating_consistency, 2) . ")\n";
    echo "  P95 Rating:       " . number_format($cat->p95_rating, 1) . "/5.0\n";
    echo "\n";
}
```

---

## Best Practices

### Performance Tips

**✅ Use aggregates instead of loading all data**:
```php
// ❌ Slow: Load everything then calculate
$products = Product::all();
$avgPrice = $products->avg('price');

// ✅ Fast: Calculate in database
$avgPrice = Product::avg('price');
```

**✅ Combine multiple aggregates in one query**:
```php
// ❌ Slow: Multiple queries
$avg = Order::avg('amount');
$p95 = Order::percentileDisc('amount', 0.95);
$stddev = Order::stdev('amount');

// ✅ Fast: Single query
$stats = Order::selectRaw('
    AVG(n.amount) as avg,
    percentileDisc(n.amount, 0.95) as p95,
    stdev(n.amount) as stddev
')->first();
```

**✅ Index columns used in aggregates**:
```php
// Create index for frequent aggregate queries
Schema::neo4j('products', function($label) {
    $label->index('price');    // Speeds up price aggregates
    $label->index('rating');   // Speeds up rating aggregates
});
```

### When to Pre-Aggregate

For frequently accessed metrics, consider pre-aggregating:

```php
// Real-time calculation (slower for frequent access)
$dailyStats = Order::whereDate('created_at', today())
    ->selectRaw('
        COUNT(*) as count,
        SUM(n.amount) as revenue,
        AVG(n.amount) as aov
    ')
    ->first();

// Pre-aggregated (faster, eventual consistency)
DailyStats::updateOrCreate(
    ['date' => today()],
    [
        'order_count' => $dailyStats->count,
        'revenue' => $dailyStats->revenue,
        'aov' => $dailyStats->aov,
    ]
);
```

**Pre-aggregate when**:
- Metrics accessed hundreds/thousands of times
- Data doesn't change frequently
- Dashboard loading time is critical
- Historical data is immutable

**Don't pre-aggregate when**:
- Data changes constantly
- Real-time accuracy required
- Storage costs are a concern

### Memory Considerations

**⚠️ collect() can use significant memory**:
```php
// ❌ Dangerous: Collect millions of IDs
$allIds = Product::collect('id');  // Could use GBs of memory

// ✅ Better: Use count() if you just need the number
$count = Product::count();

// ✅ Better: Use pagination if you need to process all
Product::chunk(1000, function($products) {
    // Process in batches
});
```

### NULL Handling

Aggregates automatically ignore NULL values:

```php
User::create(['name' => 'Alice', 'age' => 25]);
User::create(['name' => 'Bob', 'age' => null]);
User::create(['name' => 'Carol', 'age' => 30]);

$avgAge = User::avg('age');
// Returns: 27.5 (only counts Alice and Carol)

$count = User::count();
// Returns: 3 (counts all users)
```

### Aggregate Precision

Floating-point aggregates may have rounding:

```php
// Store precise values for financial calculations
$totalRevenue = Order::sum('amount');
$precise = round($totalRevenue, 2);  // Round to 2 decimals

// Or use integer cents
Order::sum('amount_cents') / 100;  // Convert cents to dollars
```

---

## Next Steps

**Query Building**:
- Master WHERE clauses, joins, and subqueries
- Read: [querying.md](querying.md)

**Performance Optimization**:
- Learn indexing, batching, and caching strategies
- Read: [performance.md](performance.md)

**Relationships**:
- Use aggregates in relationship queries
- Read: [relationships.md](relationships.md)

**Quick Reference**:
- All aggregate methods at a glance
- Read: [quick-reference.md](quick-reference.md)
