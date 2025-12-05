<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\Appointment;
use App\Models\PostgreSQL\Patient;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition()
    {
        return [
            'patient_id' => Patient::factory(),
            'doctor_id'  => Doctor::factory(),
            'slot_id'    => Slot::factory(),
            'status'     => $this->faker->randomElement(['scheduled', 'cancelled', 'completed']),
            'notes'      => $this->faker->optional()->sentence,
        ];
    }
}
