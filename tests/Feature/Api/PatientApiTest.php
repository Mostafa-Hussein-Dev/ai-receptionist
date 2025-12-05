<?php
// ============================================================================
// FILE 4: PatientApiTest.php
// Location: tests/Feature/Api/PatientApiTest.php
// ============================================================================

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\PostgreSQL\Patient;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_lookup_patient_by_phone()
    {
        $patient = Patient::factory()->create([
            'phone' => '+15551234567',
        ]);

        $response = $this->postJson('/api/v1/patients/lookup', [
            'phone' => '+15551234567',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'found' => true,
                'data' => [
                    'id' => $patient->id,
                    'phone' => '+15551234567',
                ],
            ]);
    }

    #[Test]
    public function it_returns_not_found_for_nonexistent_patient()
    {
        $response = $this->postJson('/api/v1/patients/lookup', [
            'phone' => '+15559999999',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'found' => false,
                'data' => null,
            ]);
    }

    #[Test]
    public function it_validates_lookup_request()
    {
        $response = $this->postJson('/api/v1/patients/lookup', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }
}
