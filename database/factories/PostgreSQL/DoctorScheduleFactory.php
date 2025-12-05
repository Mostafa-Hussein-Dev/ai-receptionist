<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\DoctorSchedule;
use App\Models\PostgreSQL\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorScheduleFactory extends Factory
{
    protected $model = DoctorSchedule::class;

    public function definition()
    {
        return [
            'doctor_id'   => Doctor::factory(),
            'day_of_week' => $this->faker->numberBetween(1, 7),
            'start_time'  => '09:00',
            'end_time'    => '17:00',
        ];
    }
}
