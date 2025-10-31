<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Tests\Models\User;

class UserSeeder extends Seeder
{
    /**
     * The number of users to seed.
     *
     * @var int
     */
    protected $count = 10;

    /**
     * Set the number of users to create.
     *
     * @return $this
     */
    public function count(int $count)
    {
        $this->count = $count;

        return $this;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->count($this->count)->create();
    }
}
