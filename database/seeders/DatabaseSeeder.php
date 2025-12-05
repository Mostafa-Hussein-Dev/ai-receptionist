<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌱 Starting database seeding...');

        $this->call([
            DepartmentSeeder::class,
            DoctorSeeder::class,
            PatientSeeder::class,
        ]);

        $this->command->info('✅ Database seeding completed!');
        $this->command->info('');
        $this->command->info('Summary:');
        $this->command->info('  - 1 Department (General Medicine)');
        $this->command->info('  - 2 Doctors (Dr. Smith, Dr. Johnson)');
        $this->command->info('  - 5 Patients');
        $this->command->info('');
        $this->command->info('Next steps:');
        $this->command->info('  1. Generate slots: php artisan slots:generate');
        $this->command->info('  2. Test in tinker: php artisan tinker');
    }
}
