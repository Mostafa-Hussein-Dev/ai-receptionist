<?php
// ============================================================================
// FILE 3: AppointmentApiTest.php
// Location: tests/Feature/Api/AppointmentApiTest.php
// ============================================================================

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\PostgreSQL\{Patient, Doctor, Department, DoctorSchedule};
use App\Services\Business\SlotService;
use Carbon\Carbon;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_book_appointment()
    {
        $department = Department::factory()->create();

        $doctor = Doctor::factory()->withoutSchedule()->create([
            'department_id' => $department->id
        ]);

        $patient = Patient::factory()->create();

        // Always use a weekday to ensure deterministic behavior
        $date = Carbon::now()->next(Carbon::MONDAY);

        // Generate slots (will auto-create schedule if missing)
        app(SlotService::class)->generateSlotsForDate($doctor->id, $date);

        $response = $this->withHeaders([
            'X-API-Key' => config('api.authentication.keys')[0],
        ])->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '09:00',
            'slot_count' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Appointment booked successfully',
            ]);

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'scheduled',
        ]);
    }

    #[Test]
    public function it_can_cancel_appointment()
    {
        $department = Department::factory()->create();

        $doctor = Doctor::factory()->withoutSchedule()->create([
            'department_id' => $department->id
        ]);

        $patient = Patient::factory()->create();

        // Always use next Monday to avoid weekend logic
        $date = Carbon::now()->next(Carbon::MONDAY);

        app(SlotService::class)->generateSlotsForDate($doctor->id, $date);

        $appointment = app(\App\Services\Business\AppointmentService::class)->bookAppointment([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => $date->format('Y-m-d'),
            'start_time' => '09:00',
            'slot_count' => 2,
        ]);

        $response = $this->withHeaders([
            'X-API-Key' => config('api.authentication.keys')[0],
        ])->putJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'cancellation_reason' => 'Changed plans',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Appointment cancelled successfully',
            ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'cancelled',
        ]);
    }

    #[Test]
    public function it_validates_booking_request()
    {
        $response = $this->withHeaders([
            'X-API-Key' => config('api.authentication.keys')[0],
        ])->postJson('/api/v1/appointments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['patient_id', 'doctor_id', 'date', 'start_time']);
    }
}
