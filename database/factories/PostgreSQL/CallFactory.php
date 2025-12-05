<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\Call;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition()
    {
        return [
            'from_number' => $this->faker->phoneNumber(),
            'to_number'   => $this->faker->phoneNumber(),
            'status'      => $this->faker->randomElement(['ringing', 'answered', 'ended']),
            'duration'    => $this->faker->numberBetween(0, 600),
        ];
    }
}
