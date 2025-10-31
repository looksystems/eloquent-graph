<?php

use Carbon\Carbon;
use Illuminate\Support\Collection;

test('casts integer attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['value'];

        protected $casts = ['value' => 'integer'];
    };

    $instance = $model::create(['value' => '42']);
    expect($instance->value)->toBe(42);
    expect($instance->value)->toBeInt();

    $instance->value = '99.99';
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->value)->toBe(99);
    expect($fresh->value)->toBeInt();
});

test('casts float attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['value'];

        protected $casts = ['value' => 'float'];
    };

    $instance = $model::create(['value' => '42.5']);
    expect($instance->value)->toBe(42.5);
    expect($instance->value)->toBeFloat();

    $instance->value = 99;
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->value)->toBe(99.0);
    expect($fresh->value)->toBeFloat();
});

test('casts double attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['value'];

        protected $casts = ['value' => 'double'];
    };

    $instance = $model::create(['value' => '3.14159']);
    expect($instance->value)->toBe(3.14159);
    expect(gettype($instance->value))->toBe('double');

    $fresh = $model::find($instance->id);
    expect($fresh->value)->toBe(3.14159);
});

test('casts decimal attribute with precision', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['price'];

        protected $casts = ['price' => 'decimal:2'];
    };

    $instance = $model::create(['price' => '99.999']);
    expect($instance->price)->toBe('100.00');

    $instance->price = 42.1;
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->price)->toBe('42.10');
});

test('casts string attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['value'];

        protected $casts = ['value' => 'string'];
    };

    $instance = $model::create(['value' => 123]);
    expect($instance->value)->toBe('123');
    expect($instance->value)->toBeString();

    $instance->value = true;
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->value)->toBe('1');
    expect($fresh->value)->toBeString();
});

test('casts boolean attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['is_active'];

        protected $casts = ['is_active' => 'boolean'];
    };

    $instance = $model::create(['is_active' => 1]);
    expect($instance->is_active)->toBe(true);
    expect($instance->is_active)->toBeBool();

    $instance->is_active = 'false';
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->is_active)->toBe(false);
    expect($fresh->is_active)->toBeBool();

    $instance->is_active = '0';
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->is_active)->toBe(false);
});

test('casts array attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['options'];

        protected $casts = ['options' => 'array'];
    };

    $data = ['key1' => 'value1', 'key2' => ['nested' => true]];
    $instance = $model::create(['options' => $data]);
    expect($instance->options)->toBe($data);
    expect($instance->options)->toBeArray();

    $instance->options = ['new' => 'data'];
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->options)->toBe(['new' => 'data']);
    expect($fresh->options)->toBeArray();
});

test('casts json attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['metadata'];

        protected $casts = ['metadata' => 'json'];
    };

    $data = ['version' => '1.0', 'features' => ['a', 'b', 'c'], 'nested' => ['deep' => 'value']];
    $instance = $model::create(['metadata' => $data]);
    expect($instance->metadata)->toBe($data);

    $instance->metadata = json_encode(['encoded' => true]);
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->metadata)->toBe(['encoded' => true]);
});

test('casts object attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['config'];

        protected $casts = ['config' => 'object'];
    };

    $data = ['setting1' => 'value1', 'setting2' => 'value2'];
    $instance = $model::create(['config' => $data]);
    expect($instance->config)->toBeObject();
    expect($instance->config->setting1)->toBe('value1');
    expect($instance->config->setting2)->toBe('value2');

    $fresh = $model::find($instance->id);
    expect($fresh->config)->toBeObject();
    expect($fresh->config->setting1)->toBe('value1');
});

test('casts collection attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['items'];

        protected $casts = ['items' => 'collection'];
    };

    $data = ['item1', 'item2', 'item3'];
    $instance = $model::create(['items' => $data]);
    expect($instance->items)->toBeInstanceOf(Collection::class);
    expect($instance->items->toArray())->toBe($data);

    $instance->items = collect(['new1', 'new2']);
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->items)->toBeInstanceOf(Collection::class);
    expect($fresh->items->toArray())->toBe(['new1', 'new2']);
});

test('casts date attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['birth_date'];

        protected $casts = ['birth_date' => 'date'];
    };

    $instance = $model::create(['birth_date' => '1990-01-15']);
    expect($instance->birth_date)->toBeInstanceOf(Carbon::class);
    expect($instance->birth_date->format('Y-m-d'))->toBe('1990-01-15');
    expect($instance->birth_date->format('H:i:s'))->toBe('00:00:00');

    $instance->birth_date = '2000-12-25';
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->birth_date)->toBeInstanceOf(Carbon::class);
    expect($fresh->birth_date->format('Y-m-d'))->toBe('2000-12-25');
});

test('casts datetime attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['scheduled_at'];

        protected $casts = ['scheduled_at' => 'datetime'];
    };

    $instance = $model::create(['scheduled_at' => '2023-06-15 14:30:00']);
    expect($instance->scheduled_at)->toBeInstanceOf(Carbon::class);
    expect($instance->scheduled_at->format('Y-m-d H:i:s'))->toBe('2023-06-15 14:30:00');

    $instance->scheduled_at = Carbon::parse('2024-01-01 12:00:00');
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->scheduled_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->scheduled_at->format('Y-m-d H:i:s'))->toBe('2024-01-01 12:00:00');
});

test('casts custom date format correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['custom_date'];

        protected $casts = ['custom_date' => 'date:Y/m/d'];
    };

    $instance = $model::create(['custom_date' => '2023-06-15']);
    expect($instance->custom_date)->toBeInstanceOf(Carbon::class);

    $serialized = $instance->toArray();
    expect($serialized['custom_date'])->toBe('2023/06/15');
});

test('casts custom datetime format correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['custom_datetime'];

        protected $casts = ['custom_datetime' => 'datetime:Y-m-d H:i'];
    };

    $instance = $model::create(['custom_datetime' => '2023-06-15 14:30:45']);
    expect($instance->custom_datetime)->toBeInstanceOf(Carbon::class);

    $serialized = $instance->toArray();
    expect($serialized['custom_datetime'])->toBe('2023-06-15 14:30');
});

test('casts timestamp attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['unix_time'];

        protected $casts = ['unix_time' => 'timestamp'];
    };

    $timestamp = 1623768000; // 2021-06-15 12:00:00 UTC
    $instance = $model::create(['unix_time' => $timestamp]);
    expect($instance->unix_time)->toBe($timestamp);
    expect($instance->unix_time)->toBeInt();

    $instance->unix_time = Carbon::parse('2024-01-01 00:00:00')->timestamp;
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->unix_time)->toBe(Carbon::parse('2024-01-01 00:00:00')->timestamp);
});

test('handles null values for casted attributes', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['nullable_int', 'nullable_array', 'nullable_date'];

        protected $casts = [
            'nullable_int' => 'integer',
            'nullable_array' => 'array',
            'nullable_date' => 'datetime',
        ];
    };

    $instance = $model::create([
        'nullable_int' => null,
        'nullable_array' => null,
        'nullable_date' => null,
    ]);

    expect($instance->nullable_int)->toBeNull();
    expect($instance->nullable_array)->toBeNull();
    expect($instance->nullable_date)->toBeNull();

    $fresh = $model::find($instance->id);
    expect($fresh->nullable_int)->toBeNull();
    expect($fresh->nullable_array)->toBeNull();
    expect($fresh->nullable_date)->toBeNull();
});

test('casts encrypted attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['secret'];

        protected $casts = ['secret' => 'encrypted'];
    };

    $secret = 'my-secret-password';
    $instance = $model::create(['secret' => $secret]);

    // The value should be decrypted when accessed
    expect($instance->secret)->toBe($secret);

    // The raw attribute should be encrypted
    $raw = $instance->getAttributes()['secret'] ?? null;
    if ($raw !== null) {
        expect($raw)->not->toBe($secret);
    }

    $fresh = $model::find($instance->id);
    expect($fresh->secret)->toBe($secret);
});

test('casts encrypted array attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['secure_data'];

        protected $casts = ['secure_data' => 'encrypted:array'];
    };

    $data = ['api_key' => 'secret123', 'credentials' => ['user' => 'admin']];
    $instance = $model::create(['secure_data' => $data]);

    expect($instance->secure_data)->toBe($data);
    expect($instance->secure_data)->toBeArray();

    $fresh = $model::find($instance->id);
    expect($fresh->secure_data)->toBe($data);
});

test('casts hashed attribute correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['password'];

        protected $casts = ['password' => 'hashed'];
    };

    $password = 'my-password';
    $instance = $model::create(['password' => $password]);

    // The value should be hashed
    expect($instance->password)->not->toBe($password);
    expect(password_verify($password, $instance->password))->toBeTrue();

    $fresh = $model::find($instance->id);
    expect(password_verify($password, $fresh->password))->toBeTrue();
});

test('handles multiple cast types on same model', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['name', 'age', 'settings', 'birth_date', 'is_active', 'score'];

        protected $casts = [
            'name' => 'string',
            'age' => 'integer',
            'settings' => 'array',
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'score' => 'float',
        ];
    };

    $instance = $model::create([
        'name' => 123,
        'age' => '25',
        'settings' => ['theme' => 'dark'],
        'birth_date' => '1990-05-15',
        'is_active' => 1,
        'score' => '95.5',
    ]);

    expect($instance->name)->toBe('123');
    expect($instance->age)->toBe(25);
    expect($instance->settings)->toBe(['theme' => 'dark']);
    expect($instance->birth_date->format('Y-m-d'))->toBe('1990-05-15');
    expect($instance->is_active)->toBe(true);
    expect($instance->score)->toBe(95.5);

    $fresh = $model::find($instance->id);
    expect($fresh->name)->toBe('123');
    expect($fresh->age)->toBe(25);
    expect($fresh->settings)->toBe(['theme' => 'dark']);
    expect($fresh->birth_date->format('Y-m-d'))->toBe('1990-05-15');
    expect($fresh->is_active)->toBe(true);
    expect($fresh->score)->toBe(95.5);
});

test('preserves type precision for decimal casts', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['price_2', 'price_4'];

        protected $casts = [
            'price_2' => 'decimal:2',
            'price_4' => 'decimal:4',
        ];
    };

    $instance = $model::create([
        'price_2' => '123.456789',
        'price_4' => '123.456789',
    ]);

    expect($instance->price_2)->toBe('123.46');
    expect($instance->price_4)->toBe('123.4568');

    $fresh = $model::find($instance->id);
    expect($fresh->price_2)->toBe('123.46');
    expect($fresh->price_4)->toBe('123.4568');
});

test('handles complex nested json structures', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['complex_data'];

        protected $casts = ['complex_data' => 'json'];
    };

    $data = [
        'level1' => [
            'level2' => [
                'level3' => [
                    'items' => [1, 2, 3],
                    'meta' => ['key' => 'value'],
                ],
            ],
            'array' => ['a', 'b', 'c'],
        ],
        'boolean' => true,
        'null' => null,
    ];

    $instance = $model::create(['complex_data' => $data]);
    expect($instance->complex_data)->toBe($data);

    $fresh = $model::find($instance->id);
    expect($fresh->complex_data)->toBe($data);
});

test('casts work with mass assignment', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['int_val', 'bool_val', 'array_val'];

        protected $casts = [
            'int_val' => 'integer',
            'bool_val' => 'boolean',
            'array_val' => 'array',
        ];
    };

    $instance = new $model;
    $instance->fill([
        'int_val' => '42',
        'bool_val' => 'true',
        'array_val' => ['test' => 'data'],
    ]);
    $instance->save();

    expect($instance->int_val)->toBe(42);
    expect($instance->bool_val)->toBe(true);
    expect($instance->array_val)->toBe(['test' => 'data']);

    $fresh = $model::find($instance->id);
    expect($fresh->int_val)->toBe(42);
    expect($fresh->bool_val)->toBe(true);
    expect($fresh->array_val)->toBe(['test' => 'data']);
});

test('casts work with update operations', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['counter'];

        protected $casts = ['counter' => 'integer'];
    };

    $instance = $model::create(['counter' => '10']);
    expect($instance->counter)->toBe(10);

    $instance->update(['counter' => '25.7']);
    expect($instance->counter)->toBe(25);

    $fresh = $model::find($instance->id);
    expect($fresh->counter)->toBe(25);
});

test('handles edge cases in boolean casting', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['flag'];

        protected $casts = ['flag' => 'boolean'];
    };

    // Test various truthy values
    $truthy = [true, 1, '1', 'true', 'yes', 'on'];
    foreach ($truthy as $value) {
        $instance = $model::create(['flag' => $value]);
        expect($instance->flag)->toBe(true, 'Failed for value: '.json_encode($value));
    }

    // Test various falsy values
    $falsy = [false, 0, '0', 'false', 'no', 'off', ''];
    foreach ($falsy as $value) {
        $instance = $model::create(['flag' => $value]);
        expect($instance->flag)->toBe(false, 'Failed for value: '.json_encode($value));
    }
});

test('handles Carbon instances in date casting', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['event_date'];

        protected $casts = ['event_date' => 'datetime'];
    };

    $carbon = Carbon::parse('2023-06-15 10:30:00');
    $instance = $model::create(['event_date' => $carbon]);

    expect($instance->event_date)->toBeInstanceOf(Carbon::class);
    expect($instance->event_date->format('Y-m-d H:i:s'))->toBe('2023-06-15 10:30:00');

    $fresh = $model::find($instance->id);
    expect($fresh->event_date)->toBeInstanceOf(Carbon::class);
    expect($fresh->event_date->format('Y-m-d H:i:s'))->toBe('2023-06-15 10:30:00');
});

test('respects timezone in datetime casting', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['meeting_time'];

        protected $casts = ['meeting_time' => 'datetime'];
    };

    // Set application timezone
    $originalTz = config('app.timezone');
    config(['app.timezone' => 'America/New_York']);

    $instance = $model::create(['meeting_time' => '2023-06-15 10:00:00']);
    expect($instance->meeting_time->timezone->getName())->toBe('America/New_York');

    // Restore original timezone
    config(['app.timezone' => $originalTz]);
});

test('handles empty strings in casting', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_casting';

        protected $fillable = ['int_val', 'float_val', 'bool_val'];

        protected $casts = [
            'int_val' => 'integer',
            'float_val' => 'float',
            'bool_val' => 'boolean',
        ];
    };

    $instance = $model::create([
        'int_val' => '',
        'float_val' => '',
        'bool_val' => '',
    ]);

    expect($instance->int_val)->toBe(0);
    expect($instance->float_val)->toBe(0.0);
    expect($instance->bool_val)->toBe(false);
});
