<?php

namespace Database\Seeders;

use App\Models\PostgreSQL\Department;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\DoctorSchedule;
use Illuminate\Database\Seeder;

class DoctorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $department = Department::where('name', 'General Medicine')->first();

        // Doctor 1: Dr. Smith (30-minute appointments - 2 slots)
        $drSmith = Doctor::create([
            'department_id' => $department->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'dr.smith@hospital.com',
            'phone' => '+1234567890',
            'specialization' => 'General Practitioner',
            'slots_per_appointment' => 2, // 30 minutes
            'max_appointments_per_day' => 12,
            'is_active' => true,
        ]);

        // Create weekly schedule for Dr. Smith (Monday-Friday, 8 AM - 2 PM)
        for ($dayOfWeek = 1; $dayOfWeek <= 5; $dayOfWeek++) {
            DoctorSchedule::create([
                'doctor_id' => $drSmith->id,
                'day_of_week' => $dayOfWeek,
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);
        }

        // Doctor 2: Dr. Johnson (60-minute appointments - 4 slots)
        $drJohnson = Doctor::create([
            'department_id' => $department->id,
            'first_name' => 'Sarah',
            'last_name' => 'Johnson',
            'email' => 'dr.johnson@hospital.com',
            'phone' => '+1234567891',
            'specialization' => 'Internal Medicine',
            'slots_per_appointment' => 4, // 60 minutes
            'max_appointments_per_day' => 6,
            'is_active' => true,
        ]);

        // Create weekly schedule for Dr. Johnson (Monday-Friday, 8 AM - 2 PM)
        for ($dayOfWeek = 1; $dayOfWeek <= 5; $dayOfWeek++) {
            DoctorSchedule::create([
                'doctor_id' => $drJohnson->id,
                'day_of_week' => $dayOfWeek,
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);
        }

        $this->command->info('Created 2 doctors with weekly schedules');
    }
}
