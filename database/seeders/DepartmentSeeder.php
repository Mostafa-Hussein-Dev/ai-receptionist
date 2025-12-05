<?php

namespace Database\Seeders;

use App\Models\PostgreSQL\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create single department for testing
        Department::create([
            'name' => 'General Medicine',
            'description' => 'General medical consultations and checkups',
            'is_active' => true,
        ]);
    }
}
