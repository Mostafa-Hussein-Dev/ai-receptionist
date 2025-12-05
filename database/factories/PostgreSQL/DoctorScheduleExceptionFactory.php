<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\DoctorScheduleException;
use App\Models\PostgreSQL\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorScheduleExceptionFactory extends Factory
{
    protected $model = DoctorScheduleException::class;

    public function definition()
    {
        return [
            'doctor_id' => Doctor::factory(),
            'date'      => $this->faker->date(),
            'is_day_off' => true,
        ];
    }
}
