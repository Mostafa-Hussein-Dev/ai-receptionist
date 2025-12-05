<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition()
    {
        return [
            'name'        => $this->faker->word,
            'description' => $this->faker->sentence,
            'is_active'   => true,
        ];
    }
}
