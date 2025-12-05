<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\Slot;
use App\Models\PostgreSQL\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;

class SlotFactory extends Factory
{
    protected $model = Slot::class;

    public function definition()
    {
        return [
            'doctor_id'     => Doctor::factory(),
            'date'          => $this->faker->date(),
            'slot_number'   => $this->faker->numberBetween(1, 32),
            'is_booked'     => false,
        ];
    }
}
