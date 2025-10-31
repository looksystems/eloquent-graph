# Schema Introspection

Discover and explore your Neo4j database schema programmatically.

## Introduction

Schema introspection lets you programmatically query your Neo4j database structure—labels, relationships, properties, constraints, and indexes. Unlike traditional SQL databases with fixed schemas, Neo4j schemas evolve dynamically. Introspection helps you understand what's actually in your database.

**Common Use Cases:**

- Building admin panels with dynamic forms
- Generating API documentation
- Creating schema validation tests
- Building GraphQL schemas from Neo4j
- Tracking schema changes over time
- Migration tools and compatibility checks

## Programmatic API

The `Neo4jSchema` facade provides complete access to your database schema.

### Get All Labels

Retrieve all node labels in your database:

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

$labels = Neo4jSchema::getAllLabels();
// ['User', 'Post', 'Comment', 'Tag']
```

Labels persist in Neo4j metadata even after all nodes are deleted. This is by design.

### Get All Relationship Types

Retrieve all relationship types:

```php
$types = Neo4jSchema::getAllRelationshipTypes();
// ['WROTE', 'COMMENTED_ON', 'TAGGED', 'FOLLOWS']
```

Relationship types are always uppercase by convention.

### Get All Property Keys

Retrieve all property keys used across nodes and relationships:

```php
$keys = Neo4jSchema::getAllPropertyKeys();
// ['id', 'name', 'email', 'created_at', 'title', 'body']
```

Property keys persist in metadata, providing a complete view of all fields ever used.

### Get Constraints

Retrieve all constraints with full details:

```php
$constraints = Neo4jSchema::getConstraints();

// Returns array of:
[
    [
        'name' => 'user_email_unique',
        'type' => 'UNIQUENESS',
        'entityType' => 'NODE',
        'labelsOrTypes' => ['User'],
        'properties' => ['email'],
    ],
    [
        'name' => 'post_slug_unique',
        'type' => 'UNIQUENESS',
        'entityType' => 'NODE',
        'labelsOrTypes' => ['Post'],
        'properties' => ['slug'],
    ],
]
```

**Constraint Types:**
- `UNIQUENESS` - Unique property constraint
- `NODE_KEY` - Composite unique constraint
- `NODE_PROPERTY_EXISTENCE` - Required property (Enterprise)
- `RELATIONSHIP_PROPERTY_EXISTENCE` - Required relationship property (Enterprise)

### Get Indexes

Retrieve all indexes with details:

```php
$indexes = Neo4jSchema::getIndexes();

// Returns array of:
[
    [
        'name' => 'user_name_index',
        'type' => 'RANGE',
        'entityType' => 'NODE',
        'labelsOrTypes' => ['User'],
        'properties' => ['name'],
        'state' => 'ONLINE',
    ],
    [
        'name' => 'post_created_index',
        'type' => 'RANGE',
        'entityType' => 'NODE',
        'labelsOrTypes' => ['Post'],
        'properties' => ['created_at'],
        'state' => 'ONLINE',
    ],
]
```

**Index States:**
- `ONLINE` - Ready for use
- `POPULATING` - Being built
- `FAILED` - Creation failed

Filter out system indexes (those starting with `__`):

```php
$userIndexes = array_filter($indexes, fn($idx) =>
    !str_starts_with($idx['name'] ?? '', '__')
);
```

### Complete Introspection

Get everything in one call:

```php
$schema = Neo4jSchema::introspect();

// Returns:
[
    'labels' => [...],
    'relationshipTypes' => [...],
    'propertyKeys' => [...],
    'constraints' => [...],
    'indexes' => [...],
]
```

Perfect for comprehensive schema analysis or exports.

## Check Methods

Verify existence before performing operations.

### Check Label Exists

```php
if (Neo4jSchema::hasLabel('User')) {
    // Label exists in schema (has nodes, indexes, or constraints)
}
```

**Note:** Returns `true` if:
- Nodes with this label exist
- Constraints reference this label
- Indexes reference this label

### Check Constraint Exists

```php
if (Neo4jSchema::hasConstraint('user_email_unique')) {
    // Constraint exists
} else {
    // Safe to create
    Neo4jSchema::label('User', function($label) {
        $label->property('email')->unique('user_email_unique');
    });
}
```

### Check Index Exists

```php
if (Neo4jSchema::hasIndex('user_name_index')) {
    // Index exists
} else {
    // Safe to create
    Neo4jSchema::label('User', function($label) {
        $label->property('name')->index('user_name_index');
    });
}
```

**Best Practice:** Always check before creating to avoid errors:

```php
// Conditional schema creation
$requiredIndexes = ['user_email_index', 'post_slug_index'];

foreach ($requiredIndexes as $indexName) {
    if (!Neo4jSchema::hasIndex($indexName)) {
        // Create missing index
    }
}
```

## Artisan Commands

Full suite of CLI commands for schema inspection.

### Complete Overview

Display entire schema at a glance:

```bash
php artisan neo4j:schema
```

Output:
```
Neo4j Schema Overview

Labels:
  - User
  - Post
  - Comment

Relationship Types:
  - WROTE
  - COMMENTED_ON

Property Keys:
  id, name, email, title, body, created_at

Constraints:
  - user_email_unique (UNIQUENESS) on User
  - post_slug_unique (UNIQUENESS) on Post

Indexes:
  - user_name_index (RANGE) on User [ONLINE]
  - post_created_index (RANGE) on Post [ONLINE]
```

**Options:**
- `--json` - Output as JSON
- `--compact` - Minimal output (skip property keys)

```bash
php artisan neo4j:schema --json
php artisan neo4j:schema --compact
```

### List Labels

Show all node labels:

```bash
php artisan neo4j:schema:labels
```

Output:
```
Neo4j Node Labels

  - User
  - Post
  - Comment
  - Tag
```

**Count nodes per label:**

```bash
php artisan neo4j:schema:labels --count
```

Output:
```
+----------+-------+
| Label    | Count |
+----------+-------+
| User     | 1,234 |
| Post     | 5,678 |
| Comment  | 12,456|
| Tag      | 89    |
+----------+-------+
```

### List Relationship Types

Show all relationship types:

```bash
php artisan neo4j:schema:relationships
```

**Count relationships:**

```bash
php artisan neo4j:schema:relationships --count
```

Output:
```
+------------------+-------+
| Relationship Type| Count |
+------------------+-------+
| WROTE            | 5,678 |
| COMMENTED_ON     | 12,456|
| TAGGED           | 8,234 |
| FOLLOWS          | 2,345 |
+------------------+-------+
```

### List Property Keys

Show all property keys:

```bash
php artisan neo4j:schema:properties
```

Output:
```
Neo4j Property Keys

  id, name, email, created_at, updated_at, title, body, slug
```

### List Constraints

Show all constraints:

```bash
php artisan neo4j:schema:constraints
```

**Filter by type:**

```bash
php artisan neo4j:schema:constraints --type=UNIQUENESS
```

### List Indexes

Show all indexes:

```bash
php artisan neo4j:schema:indexes
```

**Filter by type:**

```bash
php artisan neo4j:schema:indexes --type=RANGE
```

### Export Schema

Export complete schema to file:

```bash
# Export as JSON (default)
php artisan neo4j:schema:export storage/schema/production.json

# Export as YAML
php artisan neo4j:schema:export storage/schema/production.yaml --format=yaml
```

Creates directory automatically if it doesn't exist.

## Building Dynamic UIs

Generate forms and interfaces directly from schema.

### Dynamic Form Generator

Build forms based on available properties:

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

class FormBuilder
{
    public function buildForm(string $label): array
    {
        $schema = Neo4jSchema::introspect();

        if (!in_array($label, $schema['labels'])) {
            throw new \InvalidArgumentException("Label {$label} not found");
        }

        // Get constraints for this label
        $constraints = array_filter($schema['constraints'],
            fn($c) => in_array($label, $c['labelsOrTypes'])
        );

        // Build field list
        $fields = [];
        foreach ($constraints as $constraint) {
            foreach ($constraint['properties'] as $property) {
                $fields[$property] = [
                    'type' => $this->guessFieldType($property),
                    'required' => $constraint['type'] === 'NODE_PROPERTY_EXISTENCE',
                    'unique' => $constraint['type'] === 'UNIQUENESS',
                ];
            }
        }

        return $fields;
    }

    protected function guessFieldType(string $property): string
    {
        return match(true) {
            str_contains($property, 'email') => 'email',
            str_ends_with($property, '_at') => 'datetime',
            str_ends_with($property, '_date') => 'date',
            $property === 'password' => 'password',
            default => 'text',
        };
    }
}

// Usage
$builder = new FormBuilder();
$fields = $builder->buildForm('User');
// ['email' => ['type' => 'email', 'required' => false, 'unique' => true]]
```

### Admin Panel Example

Create a dynamic admin panel:

```php
class AdminController
{
    public function index()
    {
        $schema = Neo4jSchema::introspect();

        return view('admin.schema', [
            'labels' => $schema['labels'],
            'relationshipTypes' => $schema['relationshipTypes'],
        ]);
    }

    public function showLabel(string $label)
    {
        // Validate label exists
        if (!Neo4jSchema::hasLabel($label)) {
            abort(404);
        }

        $schema = Neo4jSchema::introspect();

        // Get metadata for this label
        $constraints = array_filter($schema['constraints'],
            fn($c) => in_array($label, $c['labelsOrTypes'])
        );

        $indexes = array_filter($schema['indexes'],
            fn($i) => in_array($label, $i['labelsOrTypes'])
        );

        return view('admin.label', compact('label', 'constraints', 'indexes'));
    }
}
```

## Schema Versioning

Track schema changes over time.

### Snapshot System

Create snapshots for comparison:

```php
class SchemaVersioning
{
    protected string $storageDir = 'storage/schema/versions';

    public function createSnapshot(string $version): void
    {
        $schema = Neo4jSchema::introspect();

        $filename = "{$this->storageDir}/{$version}.json";

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }

        file_put_contents($filename, json_encode([
            'version' => $version,
            'created_at' => now()->toIso8601String(),
            'schema' => $schema,
        ], JSON_PRETTY_PRINT));
    }

    public function compareVersions(string $v1, string $v2): array
    {
        $schema1 = $this->loadSnapshot($v1);
        $schema2 = $this->loadSnapshot($v2);

        return [
            'added_labels' => array_diff($schema2['labels'], $schema1['labels']),
            'removed_labels' => array_diff($schema1['labels'], $schema2['labels']),
            'added_relationships' => array_diff(
                $schema2['relationshipTypes'],
                $schema1['relationshipTypes']
            ),
            'removed_relationships' => array_diff(
                $schema1['relationshipTypes'],
                $schema2['relationshipTypes']
            ),
            'added_constraints' => $this->diffConstraints($schema1, $schema2),
            'removed_constraints' => $this->diffConstraints($schema2, $schema1),
        ];
    }

    protected function loadSnapshot(string $version): array
    {
        $filename = "{$this->storageDir}/{$version}.json";
        return json_decode(file_get_contents($filename), true)['schema'];
    }

    protected function diffConstraints(array $old, array $new): array
    {
        $oldNames = array_column($old['constraints'], 'name');
        $newNames = array_column($new['constraints'], 'name');
        return array_diff($newNames, $oldNames);
    }
}

// Usage
$versioning = new SchemaVersioning();
$versioning->createSnapshot('v1.0.0');

// Later...
$versioning->createSnapshot('v1.1.0');
$diff = $versioning->compareVersions('v1.0.0', 'v1.1.0');
```

### Artisan Command

Create a version snapshot command:

```php
// app/Console/Commands/SchemaSnapshot.php
namespace App\Console\Commands;

use Illuminate\Console\Command;

class SchemaSnapshot extends Command
{
    protected $signature = 'schema:snapshot {version}';
    protected $description = 'Create schema version snapshot';

    public function handle()
    {
        $version = $this->argument('version');

        app(SchemaVersioning::class)->createSnapshot($version);

        $this->info("Schema snapshot created: {$version}");
    }
}
```

Usage:
```bash
php artisan schema:snapshot v1.0.0
```

## Migration Tools

Use introspection to build smarter migrations.

### Conditional Migrations

Only create what doesn't exist:

```php
use Look\EloquentCypher\Facades\Neo4jSchema;

class CreateUserSchema
{
    public function up()
    {
        // Only create if it doesn't exist
        if (!Neo4jSchema::hasConstraint('user_email_unique')) {
            Neo4jSchema::label('User', function($label) {
                $label->property('email')->unique('user_email_unique');
            });
        }

        if (!Neo4jSchema::hasIndex('user_name_index')) {
            Neo4jSchema::label('User', function($label) {
                $label->property('name')->index('user_name_index');
            });
        }
    }

    public function down()
    {
        // Only drop if it exists
        if (Neo4jSchema::hasConstraint('user_email_unique')) {
            Neo4jSchema::dropConstraint('user_email_unique');
        }

        if (Neo4jSchema::hasIndex('user_name_index')) {
            Neo4jSchema::dropIndex('user_name_index');
        }
    }
}
```

### Schema Sync Utility

Sync expected schema with current:

```php
class SchemaSync
{
    protected array $expectedSchema = [
        'User' => [
            'constraints' => [
                ['property' => 'email', 'type' => 'unique', 'name' => 'user_email_unique'],
            ],
            'indexes' => [
                ['property' => 'name', 'name' => 'user_name_index'],
            ],
        ],
        'Post' => [
            'constraints' => [
                ['property' => 'slug', 'type' => 'unique', 'name' => 'post_slug_unique'],
            ],
        ],
    ];

    public function sync(): array
    {
        $changes = [];

        foreach ($this->expectedSchema as $label => $definition) {
            // Check constraints
            foreach ($definition['constraints'] ?? [] as $constraint) {
                if (!Neo4jSchema::hasConstraint($constraint['name'])) {
                    $this->createConstraint($label, $constraint);
                    $changes[] = "Created constraint: {$constraint['name']}";
                }
            }

            // Check indexes
            foreach ($definition['indexes'] ?? [] as $index) {
                if (!Neo4jSchema::hasIndex($index['name'])) {
                    $this->createIndex($label, $index);
                    $changes[] = "Created index: {$index['name']}";
                }
            }
        }

        return $changes;
    }

    protected function createConstraint(string $label, array $constraint): void
    {
        Neo4jSchema::label($label, function($l) use ($constraint) {
            $l->property($constraint['property'])->unique($constraint['name']);
        });
    }

    protected function createIndex(string $label, array $index): void
    {
        Neo4jSchema::label($label, function($l) use ($index) {
            $l->property($index['property'])->index($index['name']);
        });
    }
}

// Usage
$sync = new SchemaSync();
$changes = $sync->sync();

foreach ($changes as $change) {
    echo "✓ {$change}\n";
}
```

## Schema Validation

Automated testing for schema integrity.

### Test Schema Structure

```php
use Look\EloquentCypher\Facades\Neo4jSchema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    /** @test */
    public function user_model_has_required_schema()
    {
        $this->assertTrue(Neo4jSchema::hasConstraint('user_email_unique'));
        $this->assertTrue(Neo4jSchema::hasIndex('user_name_index'));
    }

    /** @test */
    public function all_expected_labels_exist()
    {
        $labels = Neo4jSchema::getAllLabels();

        $expected = ['User', 'Post', 'Comment'];

        foreach ($expected as $label) {
            $this->assertContains($label, $labels,
                "Expected label '{$label}' not found"
            );
        }
    }

    /** @test */
    public function all_expected_relationships_exist()
    {
        $types = Neo4jSchema::getAllRelationshipTypes();

        $expected = ['WROTE', 'COMMENTED_ON'];

        foreach ($expected as $type) {
            $this->assertContains($type, $types,
                "Expected relationship '{$type}' not found"
            );
        }
    }

    /** @test */
    public function all_indexes_are_online()
    {
        $indexes = Neo4jSchema::getIndexes();

        // Filter user indexes
        $userIndexes = array_filter($indexes,
            fn($idx) => !str_starts_with($idx['name'] ?? '', '__')
        );

        foreach ($userIndexes as $index) {
            $this->assertEquals('ONLINE', $index['state'],
                "Index '{$index['name']}' is not online: {$index['state']}"
            );
        }
    }
}
```

### Environment Validation

Check production matches staging:

```php
class SchemaValidator
{
    public function validateEnvironment(string $environment): array
    {
        $current = Neo4jSchema::introspect();
        $expected = $this->loadExpectedSchema($environment);

        $errors = [];

        // Check labels
        $missingLabels = array_diff($expected['labels'], $current['labels']);
        if (!empty($missingLabels)) {
            $errors[] = "Missing labels: " . implode(', ', $missingLabels);
        }

        // Check constraints
        $expectedConstraints = array_column($expected['constraints'], 'name');
        $currentConstraints = array_column($current['constraints'], 'name');
        $missingConstraints = array_diff($expectedConstraints, $currentConstraints);

        if (!empty($missingConstraints)) {
            $errors[] = "Missing constraints: " . implode(', ', $missingConstraints);
        }

        return $errors;
    }

    protected function loadExpectedSchema(string $environment): array
    {
        $file = storage_path("schema/{$environment}.json");
        return json_decode(file_get_contents($file), true);
    }
}
```

## Documentation Generation

Auto-generate schema documentation.

### Markdown Generator

Create documentation from live schema:

```php
class SchemaDocGenerator
{
    public function generateMarkdown(): string
    {
        $schema = Neo4jSchema::introspect();

        $md = "# Database Schema\n\n";
        $md .= "_Generated: " . now()->toDateTimeString() . "_\n\n";

        // Labels section
        $md .= "## Node Labels\n\n";
        foreach ($schema['labels'] as $label) {
            $md .= "### {$label}\n\n";
            $md .= $this->labelDetails($label, $schema);
            $md .= "\n";
        }

        // Relationships section
        $md .= "## Relationships\n\n";
        foreach ($schema['relationshipTypes'] as $type) {
            $md .= "- `{$type}`\n";
        }

        return $md;
    }

    protected function labelDetails(string $label, array $schema): string
    {
        $md = "";

        // Find constraints for this label
        $constraints = array_filter($schema['constraints'],
            fn($c) => in_array($label, $c['labelsOrTypes'])
        );

        if (!empty($constraints)) {
            $md .= "**Constraints:**\n\n";
            foreach ($constraints as $constraint) {
                $props = implode(', ', $constraint['properties']);
                $md .= "- `{$constraint['name']}` - {$constraint['type']} on `{$props}`\n";
            }
            $md .= "\n";
        }

        // Find indexes
        $indexes = array_filter($schema['indexes'],
            fn($i) => in_array($label, $i['labelsOrTypes'])
                && !str_starts_with($i['name'] ?? '', '__')
        );

        if (!empty($indexes)) {
            $md .= "**Indexes:**\n\n";
            foreach ($indexes as $index) {
                $props = implode(', ', $index['properties']);
                $md .= "- `{$index['name']}` - {$index['type']} on `{$props}`\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    public function saveToFile(string $path): void
    {
        file_put_contents($path, $this->generateMarkdown());
    }
}

// Usage
$generator = new SchemaDocGenerator();
$generator->saveToFile('docs/DATABASE_SCHEMA.md');
```

### GraphQL Schema Generator

Generate GraphQL types from Neo4j schema:

```php
class GraphQLSchemaGenerator
{
    public function generateTypes(): string
    {
        $labels = Neo4jSchema::getAllLabels();
        $graphql = "";

        foreach ($labels as $label) {
            $graphql .= "type {$label} {\n";
            $graphql .= "  id: ID!\n";

            // Get properties from constraints and indexes
            $schema = Neo4jSchema::introspect();
            $constraints = array_filter($schema['constraints'],
                fn($c) => in_array($label, $c['labelsOrTypes'])
            );

            $properties = [];
            foreach ($constraints as $constraint) {
                foreach ($constraint['properties'] as $prop) {
                    if ($prop !== 'id') {
                        $properties[$prop] = $this->guessGraphQLType($prop);
                    }
                }
            }

            foreach ($properties as $prop => $type) {
                $graphql .= "  {$prop}: {$type}\n";
            }

            $graphql .= "}\n\n";
        }

        return $graphql;
    }

    protected function guessGraphQLType(string $property): string
    {
        return match(true) {
            str_ends_with($property, '_at') => 'DateTime',
            str_ends_with($property, '_date') => 'Date',
            str_contains($property, 'email') => 'String!',
            $property === 'count' => 'Int',
            default => 'String',
        };
    }
}
```

## JSON/YAML Export

Export formats and usage.

### JSON Format

```json
{
    "labels": ["User", "Post"],
    "relationshipTypes": ["WROTE"],
    "propertyKeys": ["id", "name", "email", "title"],
    "constraints": [
        {
            "name": "user_email_unique",
            "type": "UNIQUENESS",
            "entityType": "NODE",
            "labelsOrTypes": ["User"],
            "properties": ["email"]
        }
    ],
    "indexes": [
        {
            "name": "user_name_index",
            "type": "RANGE",
            "entityType": "NODE",
            "labelsOrTypes": ["User"],
            "properties": ["name"],
            "state": "ONLINE"
        }
    ]
}
```

### YAML Format

```yaml
labels:
  - User
  - Post
relationshipTypes:
  - WROTE
propertyKeys:
  - id
  - name
  - email
  - title
constraints:
  - name: user_email_unique
    type: UNIQUENESS
    entityType: NODE
    labelsOrTypes:
      - User
    properties:
      - email
indexes:
  - name: user_name_index
    type: RANGE
    entityType: NODE
    labelsOrTypes:
      - User
    properties:
      - name
    state: ONLINE
```

### Load and Compare

```php
class SchemaLoader
{
    public function loadFromJson(string $file): array
    {
        return json_decode(file_get_contents($file), true);
    }

    public function loadFromYaml(string $file): array
    {
        // Simple YAML parser for schema files
        $yaml = file_get_contents($file);
        $lines = explode("\n", $yaml);

        // Parse logic here...
        // For production, use symfony/yaml package

        return $parsed;
    }

    public function compareWithCurrent(array $expected): array
    {
        $current = Neo4jSchema::introspect();

        return [
            'labels' => [
                'missing' => array_diff($expected['labels'], $current['labels']),
                'extra' => array_diff($current['labels'], $expected['labels']),
            ],
            'constraints' => [
                'missing' => $this->missingConstraints($expected, $current),
                'extra' => $this->missingConstraints($current, $expected),
            ],
        ];
    }

    protected function missingConstraints(array $expected, array $current): array
    {
        $expectedNames = array_column($expected['constraints'], 'name');
        $currentNames = array_column($current['constraints'], 'name');
        return array_diff($expectedNames, $currentNames);
    }
}
```

## Real-World Examples

Complete applications using introspection.

### Database Explorer API

REST API for schema exploration:

```php
// routes/api.php
Route::prefix('schema')->group(function() {
    Route::get('/', [SchemaApiController::class, 'overview']);
    Route::get('/labels', [SchemaApiController::class, 'labels']);
    Route::get('/labels/{label}', [SchemaApiController::class, 'labelDetail']);
    Route::get('/relationships', [SchemaApiController::class, 'relationships']);
});

// app/Http/Controllers/SchemaApiController.php
class SchemaApiController extends Controller
{
    public function overview()
    {
        return response()->json(Neo4jSchema::introspect());
    }

    public function labels()
    {
        $labels = Neo4jSchema::getAllLabels();
        $connection = app('db')->connection('neo4j');

        $data = [];
        foreach ($labels as $label) {
            $result = $connection->select("MATCH (n:`{$label}`) RETURN count(n) as count");
            $count = is_array($result[0]) ? $result[0]['count'] : $result[0]->count;

            $data[] = [
                'label' => $label,
                'count' => $count,
            ];
        }

        return response()->json($data);
    }

    public function labelDetail(string $label)
    {
        if (!Neo4jSchema::hasLabel($label)) {
            return response()->json(['error' => 'Label not found'], 404);
        }

        $schema = Neo4jSchema::introspect();

        return response()->json([
            'label' => $label,
            'constraints' => array_filter($schema['constraints'],
                fn($c) => in_array($label, $c['labelsOrTypes'])
            ),
            'indexes' => array_filter($schema['indexes'],
                fn($i) => in_array($label, $i['labelsOrTypes'])
            ),
        ]);
    }

    public function relationships()
    {
        $types = Neo4jSchema::getAllRelationshipTypes();
        $connection = app('db')->connection('neo4j');

        $data = [];
        foreach ($types as $type) {
            $result = $connection->select("MATCH ()-[r:`{$type}`]->() RETURN count(r) as count");
            $count = is_array($result[0]) ? $result[0]['count'] : $result[0]->count;

            $data[] = [
                'type' => $type,
                'count' => $count,
            ];
        }

        return response()->json($data);
    }
}
```

### Vue.js Admin Panel

Dynamic frontend:

```vue
<template>
  <div class="schema-explorer">
    <h1>Database Schema</h1>

    <section>
      <h2>Labels ({{ labels.length }})</h2>
      <ul>
        <li v-for="label in labels" :key="label.label" @click="viewLabel(label.label)">
          {{ label.label }} ({{ label.count }} nodes)
        </li>
      </ul>
    </section>

    <section v-if="selectedLabel">
      <h2>{{ selectedLabel.label }}</h2>

      <div v-if="selectedLabel.constraints.length">
        <h3>Constraints</h3>
        <ul>
          <li v-for="c in selectedLabel.constraints" :key="c.name">
            {{ c.name }} - {{ c.type }}
          </li>
        </ul>
      </div>

      <div v-if="selectedLabel.indexes.length">
        <h3>Indexes</h3>
        <ul>
          <li v-for="i in selectedLabel.indexes" :key="i.name">
            {{ i.name }} - {{ i.state }}
          </li>
        </ul>
      </div>
    </section>
  </div>
</template>

<script>
export default {
  data() {
    return {
      labels: [],
      selectedLabel: null,
    }
  },

  async mounted() {
    const response = await fetch('/api/schema/labels');
    this.labels = await response.json();
  },

  methods: {
    async viewLabel(label) {
      const response = await fetch(`/api/schema/labels/${label}`);
      this.selectedLabel = await response.json();
    }
  }
}
</script>
```

## Integration Patterns

Use in CI/CD pipelines.

### GitHub Actions

Validate schema in CI:

```yaml
# .github/workflows/schema-validation.yml
name: Schema Validation

on: [push, pull_request]

jobs:
  validate:
    runs-on: ubuntu-latest

    services:
      neo4j:
        image: neo4j:5
        env:
          NEO4J_AUTH: neo4j/password
        ports:
          - 7687:7687

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install Dependencies
        run: composer install

      - name: Run Migrations
        run: php artisan migrate

      - name: Validate Schema
        run: php artisan test --filter=SchemaTest

      - name: Export Schema
        run: php artisan neo4j:schema:export storage/schema/ci.json

      - name: Upload Schema
        uses: actions/upload-artifact@v2
        with:
          name: schema
          path: storage/schema/ci.json
```

### Pre-Deployment Check

Verify schema before deploy:

```bash
#!/bin/bash
# scripts/check-schema.sh

echo "Checking production schema..."

# Export current production schema
php artisan neo4j:schema:export storage/schema/production-current.json

# Compare with expected
php artisan schema:validate production

if [ $? -ne 0 ]; then
  echo "❌ Schema validation failed!"
  exit 1
fi

echo "✅ Schema validation passed"
```

### Deployment Hook

Auto-create snapshots on deploy:

```php
// app/Console/Commands/DeploymentSnapshot.php
class DeploymentSnapshot extends Command
{
    protected $signature = 'deploy:snapshot';

    public function handle()
    {
        $version = config('app.version', 'unknown');
        $timestamp = now()->format('Y-m-d_H-i-s');

        $filename = "deployment_{$version}_{$timestamp}";

        $this->info("Creating deployment snapshot: {$filename}");

        Neo4jSchema::introspect();
        $this->call('neo4j:schema:export', [
            'file' => "storage/schema/deployments/{$filename}.json"
        ]);

        $this->info("✓ Snapshot created successfully");
    }
}
```

Add to deployment script:

```bash
php artisan deploy:snapshot
```

## Performance Considerations

Optimize schema introspection.

### Cache Schema Data

Schema changes infrequently—cache it:

```php
use Illuminate\Support\Facades\Cache;

class CachedSchemaService
{
    protected int $ttl = 3600; // 1 hour

    public function getSchema(): array
    {
        return Cache::remember('neo4j:schema', $this->ttl, function() {
            return Neo4jSchema::introspect();
        });
    }

    public function getLabels(): array
    {
        return Cache::remember('neo4j:labels', $this->ttl, function() {
            return Neo4jSchema::getAllLabels();
        });
    }

    public function clearCache(): void
    {
        Cache::forget('neo4j:schema');
        Cache::forget('neo4j:labels');
        Cache::forget('neo4j:relationships');
    }
}
```

**Clear cache after schema changes:**

```php
Neo4jSchema::label('User', function($label) {
    $label->property('email')->unique('user_email_unique');
});

// Clear cache
app(CachedSchemaService::class)->clearCache();
```

### Lazy Loading

Only fetch what you need:

```php
class LazySchemaLoader
{
    protected ?array $schema = null;

    public function getLabels(): array
    {
        if ($this->schema === null) {
            $this->schema = Neo4jSchema::introspect();
        }

        return $this->schema['labels'];
    }

    public function getConstraints(): array
    {
        if ($this->schema === null) {
            $this->schema = Neo4jSchema::introspect();
        }

        return $this->schema['constraints'];
    }
}
```

### Best Practices

**DO:**
- ✅ Cache schema data in production
- ✅ Use check methods before creating indexes/constraints
- ✅ Export schema as part of deployment process
- ✅ Validate schema in CI/CD
- ✅ Use `--compact` flag when you don't need all details

**DON'T:**
- ⚠️ Query schema on every request
- ⚠️ Create indexes without checking existence
- ⚠️ Ignore index state (`POPULATING`, `FAILED`)
- ⚠️ Assume labels exist without checking

## Next Steps

- [Multi-Label Nodes](multi-label-nodes.md) - Working with multiple labels
- [Performance Optimization](performance.md) - Query optimization techniques
- [Migration Guide](migration-guide.md) - Migrating and managing schema changes
- [Quick Reference](quick-reference.md) - Command cheat sheet
