<?php

namespace Database\Factories\PostgreSQL;

use App\Models\PostgreSQL\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition()
    {
        return [
            'first_name'   => $this->faker->firstName,
            'last_name'    => $this->faker->lastName,
            'phone'        => $this->faker->phoneNumber,
            'date_of_birth'=> $this->faker->date(),
            'medical_record_number'          => $this->faker->unique()->numerify('MRN####'),
        ];
    }
}
