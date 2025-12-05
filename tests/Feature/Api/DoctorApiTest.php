<?php
// ============================================================================
// FILE 1: DoctorApiTest.php
// Location: tests/Feature/Api/DoctorApiTest.php
// ============================================================================

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;

class DoctorApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_list_all_active_doctors()
    {
        $department = Department::factory()->create();
        Doctor::factory()->count(3)->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/doctors');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'specialization'],
                ],
            ])
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_can_get_doctor_details()
    {
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);

        $response = $this->getJson("/api/v1/doctors/{$doctor->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $doctor->id,
                    'first_name' => $doctor->first_name,
                    'last_name' => $doctor->last_name,
                ],
            ]);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_doctor()
    {
        $response = $this->getJson('/api/v1/doctors/99999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => 'Doctor not found',
            ]);
    }
}
