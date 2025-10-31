<?php

use Carbon\Carbon;
use Illuminate\Support\Str;

test('set mutator transforms attribute value on set', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['name'];

        public function setNameAttribute($value)
        {
            $this->attributes['name'] = strtoupper($value);
        }
    };

    $instance = $model::create(['name' => 'john doe']);
    expect($instance->getAttributes()['name'])->toBe('JOHN DOE');

    $instance->name = 'jane smith';
    expect($instance->getAttributes()['name'])->toBe('JANE SMITH');

    $instance->save();
    $fresh = $model::find($instance->id);
    expect($fresh->getAttributes()['name'])->toBe('JANE SMITH');
});

test('get mutator transforms attribute value on access', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['price'];

        public function getPriceAttribute($value)
        {
            return $value ? '$'.number_format($value, 2) : null;
        }
    };

    $instance = $model::create(['price' => 99.5]);
    expect($instance->price)->toBe('$99.50');
    expect($instance->getAttributes()['price'])->toBe(99.5);

    $fresh = $model::find($instance->id);
    expect($fresh->price)->toBe('$99.50');
});

test('mutators work with null values', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['nullable_field'];

        public function setNullableFieldAttribute($value)
        {
            $this->attributes['nullable_field'] = $value ? strtoupper($value) : null;
        }

        public function getNullableFieldAttribute($value)
        {
            return $value ? 'Prefix: '.$value : 'No value';
        }
    };

    $instance = $model::create(['nullable_field' => null]);
    expect($instance->nullable_field)->toBe('No value');
    expect($instance->getAttributes()['nullable_field'])->toBeNull();

    $instance->nullable_field = 'test';
    expect($instance->nullable_field)->toBe('Prefix: TEST');
    expect($instance->getAttributes()['nullable_field'])->toBe('TEST');
});

test('virtual accessor creates computed attributes', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['first_name', 'last_name'];

        public function getFullNameAttribute()
        {
            return trim($this->first_name.' '.$this->last_name);
        }
    };

    $instance = $model::create(['first_name' => 'John', 'last_name' => 'Doe']);
    expect($instance->full_name)->toBe('John Doe');

    $instance->first_name = 'Jane';
    expect($instance->full_name)->toBe('Jane Doe');

    // Virtual attributes should not be in the database
    expect($instance->getAttributes())->not->toHaveKey('full_name');
});

test('mutator chains work correctly', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['code'];

        public function setCodeAttribute($value)
        {
            $cleaned = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
            $this->attributes['code'] = substr($cleaned, 0, 10);
        }

        public function getCodeAttribute($value)
        {
            return $value ? 'CODE-'.$value : null;
        }
    };

    $instance = $model::create(['code' => 'abc-123-xyz-999']);
    expect($instance->code)->toBe('CODE-ABC123XYZ9');
    expect($instance->getAttributes()['code'])->toBe('ABC123XYZ9');

    $instance->code = 'def@456#ghi!789long';
    expect($instance->code)->toBe('CODE-DEF456GHI7');
});

test('mutators interact correctly with casting', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['data'];

        protected $casts = ['data' => 'array'];

        public function setDataAttribute($value)
        {
            if (is_array($value)) {
                $value['modified'] = true;
                $value['modified_at'] = now()->toDateTimeString();
            }
            $this->attributes['data'] = is_array($value) ? json_encode($value) : $value;
        }
    };

    $instance = $model::create(['data' => ['key' => 'value']]);
    expect($instance->data)->toHaveKey('modified');
    expect($instance->data['modified'])->toBe(true);
    expect($instance->data)->toHaveKey('modified_at');
    expect($instance->data['key'])->toBe('value');
});

test('accessor with date manipulation', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['birth_date'];

        protected $casts = ['birth_date' => 'date'];

        public function getAgeAttribute()
        {
            return $this->birth_date ? $this->birth_date->age : null;
        }

        public function getBirthYearAttribute()
        {
            return $this->birth_date ? $this->birth_date->year : null;
        }
    };

    $instance = $model::create(['birth_date' => '1990-05-15']);
    expect($instance->age)->toBeGreaterThanOrEqual(33);
    expect($instance->birth_year)->toBe(1990);
});

test('mutator with validation logic', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['email'];

        public function setEmailAttribute($value)
        {
            $this->attributes['email'] = strtolower(trim($value));
        }

        public function getEmailDomainAttribute()
        {
            if (! $this->email) {
                return null;
            }
            $parts = explode('@', $this->email);

            return count($parts) === 2 ? $parts[1] : null;
        }
    };

    $instance = $model::create(['email' => '  JOHN@EXAMPLE.COM  ']);
    expect($instance->getAttributes()['email'])->toBe('john@example.com');
    expect($instance->email_domain)->toBe('example.com');

    $instance->email = 'JANE@TEST.ORG';
    expect($instance->email_domain)->toBe('test.org');
});

test('multiple mutators on same model', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['title', 'slug', 'description'];

        public function setTitleAttribute($value)
        {
            $this->attributes['title'] = ucwords(strtolower(trim($value)));
        }

        public function setSlugAttribute($value)
        {
            $this->attributes['slug'] = Str::slug($value ?: $this->title);
        }

        public function setDescriptionAttribute($value)
        {
            $this->attributes['description'] = strip_tags($value);
        }

        public function getExcerptAttribute()
        {
            return $this->description ? Str::limit($this->description, 100) : null;
        }
    };

    $instance = $model::create([
        'title' => 'THIS IS A TITLE',
        'slug' => '',
        'description' => '<p>This is a <strong>description</strong> with HTML</p>',
    ]);

    expect($instance->getAttributes()['title'])->toBe('This Is A Title');
    expect($instance->getAttributes()['slug'])->toBe('this-is-a-title');
    expect($instance->getAttributes()['description'])->toBe('This is a description with HTML');
    expect($instance->excerpt)->toBe('This is a description with HTML');
});

test('accessor performance with large datasets', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['items'];

        protected $casts = ['items' => 'array'];

        public function getItemCountAttribute()
        {
            return is_array($this->items) ? count($this->items) : 0;
        }

        public function getTotalValueAttribute()
        {
            if (! is_array($this->items)) {
                return 0;
            }

            return array_sum(array_column($this->items, 'value'));
        }
    };

    $items = [];
    for ($i = 1; $i <= 100; $i++) {
        $items[] = ['id' => $i, 'value' => $i * 10];
    }

    $instance = $model::create(['items' => $items]);
    expect($instance->item_count)->toBe(100);
    expect($instance->total_value)->toBe(50500);

    $fresh = $model::find($instance->id);
    expect($fresh->item_count)->toBe(100);
    expect($fresh->total_value)->toBe(50500);
});

test('mutator with complex calculations', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['price', 'tax_rate', 'discount_percentage'];

        public function setPriceAttribute($value)
        {
            $this->attributes['price'] = round(floatval($value), 2);
        }

        public function getTaxAmountAttribute()
        {
            $price = $this->price ?? 0;
            $taxRate = $this->tax_rate ?? 0;

            return round($price * ($taxRate / 100), 2);
        }

        public function getDiscountAmountAttribute()
        {
            $price = $this->price ?? 0;
            $discount = $this->discount_percentage ?? 0;

            return round($price * ($discount / 100), 2);
        }

        public function getFinalPriceAttribute()
        {
            $price = $this->price ?? 0;

            return round($price + $this->tax_amount - $this->discount_amount, 2);
        }
    };

    $instance = $model::create([
        'price' => 100.456,
        'tax_rate' => 8.5,
        'discount_percentage' => 10,
    ]);

    expect($instance->getAttributes()['price'])->toBe(100.46);
    expect($instance->tax_amount)->toBe(8.54);
    expect($instance->discount_amount)->toBe(10.05);
    expect($instance->final_price)->toBe(98.95);
});

test('mutator preserves null when appropriate', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['optional_field'];

        public function setOptionalFieldAttribute($value)
        {
            if ($value === null || $value === '') {
                $this->attributes['optional_field'] = null;
            } else {
                $this->attributes['optional_field'] = trim($value);
            }
        }

        public function getOptionalFieldDisplayAttribute()
        {
            return $this->optional_field ?? 'N/A';
        }
    };

    $instance = $model::create(['optional_field' => null]);
    expect($instance->getAttributes()['optional_field'])->toBeNull();
    expect($instance->optional_field_display)->toBe('N/A');

    $instance->optional_field = '  value  ';
    expect($instance->getAttributes()['optional_field'])->toBe('value');
    expect($instance->optional_field_display)->toBe('value');

    $instance->optional_field = '';
    expect($instance->getAttributes()['optional_field'])->toBeNull();
    expect($instance->optional_field_display)->toBe('N/A');
});

test('accessor with relationship data', function () {
    $userModel = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'users';

        protected $fillable = ['name', 'post_count'];

        public function getHasPostsAttribute()
        {
            return ($this->post_count ?? 0) > 0;
        }

        public function getPostCountLabelAttribute()
        {
            $count = $this->post_count ?? 0;
            if ($count === 0) {
                return 'No posts';
            }
            if ($count === 1) {
                return '1 post';
            }

            return "$count posts";
        }
    };

    $user1 = $userModel::create(['name' => 'User1', 'post_count' => 0]);
    expect($user1->has_posts)->toBe(false);
    expect($user1->post_count_label)->toBe('No posts');

    $user2 = $userModel::create(['name' => 'User2', 'post_count' => 1]);
    expect($user2->has_posts)->toBe(true);
    expect($user2->post_count_label)->toBe('1 post');

    $user3 = $userModel::create(['name' => 'User3', 'post_count' => 5]);
    expect($user3->has_posts)->toBe(true);
    expect($user3->post_count_label)->toBe('5 posts');
});

test('mutator with JSON encoding', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['config'];

        public function setConfigAttribute($value)
        {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            if (is_array($value)) {
                ksort($value);
                $this->attributes['config'] = json_encode($value);
            } else {
                $this->attributes['config'] = $value;
            }
        }

        public function getConfigAttribute($value)
        {
            if (! $value) {
                return [];
            }
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
    };

    $instance = $model::create(['config' => ['z' => 3, 'a' => 1, 'm' => 2]]);
    expect($instance->config)->toBe(['a' => 1, 'm' => 2, 'z' => 3]);

    $instance->config = '{"new": true, "test": "value"}';
    $instance->save();

    $fresh = $model::find($instance->id);
    expect($fresh->config)->toBe(['new' => true, 'test' => 'value']);
});

test('accessor returns different types based on context', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['value', 'format'];

        public function getFormattedValueAttribute()
        {
            if (! $this->value) {
                return null;
            }

            switch ($this->format) {
                case 'currency':
                    return '$'.number_format($this->value, 2);
                case 'percentage':
                    return $this->value.'%';
                case 'boolean':
                    return $this->value > 0;
                case 'raw':
                default:
                    return $this->value;
            }
        }
    };

    $instance1 = $model::create(['value' => 99.5, 'format' => 'currency']);
    expect($instance1->formatted_value)->toBe('$99.50');

    $instance2 = $model::create(['value' => 85, 'format' => 'percentage']);
    expect($instance2->formatted_value)->toBe('85%');

    $instance3 = $model::create(['value' => 1, 'format' => 'boolean']);
    expect($instance3->formatted_value)->toBe(true);

    $instance4 = $model::create(['value' => 42, 'format' => 'raw']);
    expect($instance4->formatted_value)->toBe(42);
});

test('mutator handles special characters', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['text'];

        public function setTextAttribute($value)
        {
            // Normalize whitespace and special chars
            $value = preg_replace('/\s+/', ' ', trim($value));
            // Replace smart quotes and ellipsis
            $value = str_replace(
                ["\u{2026}", "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
                ['...', '"', '"', "'", "'"],
                $value
            );
            $this->attributes['text'] = $value;
        }
    };

    $instance = $model::create(['text' => "  This   has   weird    spaces\nand\nnewlines  "]);
    expect($instance->getAttributes()['text'])->toBe('This has weird spaces and newlines');

    // Test with Unicode smart quotes
    $text = "Smart quotes \u{201C}like these\u{201D} and \u{2018}these\u{2019} become normal\u{2026}";
    $instance->text = $text;
    expect($instance->getAttributes()['text'])->toBe('Smart quotes "like these" and \'these\' become normal...');
});

test('mutators work with mass update', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['status'];

        public function setStatusAttribute($value)
        {
            $this->attributes['status'] = strtoupper($value);
        }

        public function getStatusLabelAttribute()
        {
            $labels = [
                'ACTIVE' => 'Active and Running',
                'INACTIVE' => 'Currently Inactive',
                'PENDING' => 'Awaiting Approval',
            ];

            return $labels[$this->status] ?? 'Unknown Status';
        }
    };

    $instance = $model::create(['status' => 'active']);
    expect($instance->getAttributes()['status'])->toBe('ACTIVE');
    expect($instance->status_label)->toBe('Active and Running');

    $instance->update(['status' => 'pending']);
    expect($instance->getAttributes()['status'])->toBe('PENDING');
    expect($instance->status_label)->toBe('Awaiting Approval');
});

test('accessor caching for expensive operations', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['data'];

        protected $casts = ['data' => 'array'];

        private $expensiveCache = null;

        public function getExpensiveCalculationAttribute()
        {
            if ($this->expensiveCache === null && is_array($this->data)) {
                // Simulate expensive operation
                $sum = 0;
                foreach ($this->data as $item) {
                    $sum += $item * 2;
                }
                $this->expensiveCache = $sum;
            }

            return $this->expensiveCache;
        }
    };

    $data = range(1, 100);
    $instance = $model::create(['data' => $data]);

    $expected = array_sum($data) * 2;
    expect($instance->expensive_calculation)->toBe($expected);

    // Second access should use cache (though we can't directly test this)
    expect($instance->expensive_calculation)->toBe($expected);
});

test('mutator interaction with timestamps', function () {
    $model = new class extends \Look\EloquentCypher\GraphModel
    {
        protected $table = 'test_mutators';

        protected $fillable = ['title'];

        public function setTitleAttribute($value)
        {
            $this->attributes['title'] = trim($value);
            $this->attributes['title_changed_at'] = now()->toDateTimeString();
        }

        public function getTitleChangedAtAttribute($value)
        {
            return $value ? Carbon::parse($value) : null;
        }
    };

    $instance = $model::create(['title' => 'Initial Title']);
    expect($instance->title_changed_at)->toBeInstanceOf(Carbon::class);

    $firstChange = $instance->title_changed_at;
    sleep(1);

    $instance->title = 'Updated Title';
    $instance->save();

    expect($instance->title_changed_at)->toBeInstanceOf(Carbon::class);
    expect($instance->title_changed_at->greaterThan($firstChange))->toBeTrue();
});
