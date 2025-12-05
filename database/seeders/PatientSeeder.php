<?php

namespace Database\Seeders;

use App\Models\PostgreSQL\Patient;
use Illuminate\Database\Seeder;

class PatientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $patients = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'date_of_birth' => '1985-05-15',
                'gender' => 'Male',
                'phone' => '+1234567800',
                'email' => 'john.doe@example.com',
                'address' => '123 Main Street, Springfield',
            ],
            [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'date_of_birth' => '1990-08-22',
                'gender' => 'Female',
                'phone' => '+1234567801',
                'email' => 'jane.smith@example.com',
                'address' => '456 Oak Avenue, Springfield',
            ],
            [
                'first_name' => 'Michael',
                'last_name' => 'Brown',
                'date_of_birth' => '1978-12-10',
                'gender' => 'Male',
                'phone' => '+1234567802',
                'email' => 'michael.brown@example.com',
                'address' => '789 Pine Road, Springfield',
            ],
            [
                'first_name' => 'Emily',
                'last_name' => 'Davis',
                'date_of_birth' => '1995-03-18',
                'gender' => 'Female',
                'phone' => '+1234567803',
                'email' => 'emily.davis@example.com',
                'address' => '321 Elm Street, Springfield',
            ],
            [
                'first_name' => 'Robert',
                'last_name' => 'Wilson',
                'date_of_birth' => '1982-07-25',
                'gender' => 'Male',
                'phone' => '+1234567804',
                'email' => 'robert.wilson@example.com',
                'address' => '654 Maple Drive, Springfield',
            ],
        ];

        foreach ($patients as $patientData) {
            Patient::create($patientData);
        }

        $this->command->info('Created 5 sample patients');
    }
}
