<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;
use App\Models\PostgreSQL\DoctorSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition()
    {
        return [
            'department_id' => Department::factory(),
            'first_name'    => $this->faker->firstName,
            'last_name'     => $this->faker->lastName,
            'is_active'     => true,
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function (Doctor $doctor) {
            // Create default weekly schedule for weekdays (Monday-Friday)
            // Only if not explicitly disabled
            if (!isset($this->activeStates['withoutSchedule'])) {
                for ($day = 1; $day <= 5; $day++) {
                    DoctorSchedule::create([
                        'doctor_id' => $doctor->id,
                        'day_of_week' => $day,
                        'start_time' => '08:00:00',
                        'end_time' => '17:00:00',
                        'is_available' => true,
                    ]);
                }
            }
        });
    }

    /**
     * Create a doctor without default schedule
     */
    public function withoutSchedule()
    {
        return $this->state(fn () => [])->afterMaking(function () {
            $this->activeStates['withoutSchedule'] = true;
        });
    }
}
