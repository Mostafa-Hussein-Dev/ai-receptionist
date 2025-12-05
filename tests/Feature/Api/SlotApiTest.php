<?php
// ============================================================================
// FILE 2: SlotApiTest.php
// Location: tests/Feature/Api/SlotApiTest.php
// ============================================================================

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;
use App\Models\PostgreSQL\DoctorSchedule;
use App\Services\Business\SlotService;
use Carbon\Carbon;

class SlotApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_get_available_slots()
    {
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);

        $tomorrow = Carbon::tomorrow();
        $dayOfWeek = $tomorrow->dayOfWeek;

        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => $dayOfWeek,
            'is_available' => true,
        ]);

        // Generate slots
        app(SlotService::class)->generateSlotsForDate($doctor->id, $tomorrow);

        $response = $this->getJson("/api/v1/slots?doctor_id={$doctor->id}&date={$tomorrow->format('Y-m-d')}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['doctor_id', 'date', 'total_available'],
            ]);
    }

    #[Test]
    public function it_validates_required_parameters()
    {
        $response = $this->getJson('/api/v1/slots');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id', 'date']);
    }
}
