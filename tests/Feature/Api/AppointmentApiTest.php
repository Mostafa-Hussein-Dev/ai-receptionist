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
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);
        $patient = Patient::factory()->create();

        $tomorrow = Carbon::tomorrow();
        $dayOfWeek = $tomorrow->dayOfWeek;

        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => $dayOfWeek,
            'is_available' => true,
        ]);

        // Generate slots
        app(SlotService::class)->generateSlotsForDate($doctor->id, $tomorrow);

        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => $tomorrow->format('Y-m-d'),
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
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);
        $patient = Patient::factory()->create();
        $tomorrow = Carbon::tomorrow();
        $dayOfWeek = $tomorrow->dayOfWeek;

        DoctorSchedule::factory()->create([
            'doctor_id' => $doctor->id,
            'day_of_week' => $dayOfWeek,
        ]);

        app(SlotService::class)->generateSlotsForDate($doctor->id, $tomorrow);

        $appointment = app(\App\Services\Business\AppointmentService::class)->bookAppointment(
            $patient->id,
            $doctor->id,
            $tomorrow,
            '09:00',
            2
        );

        $response = $this->putJson("/api/v1/appointments/{$appointment->id}/cancel", [
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
        $response = $this->postJson('/api/v1/appointments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['patient_id', 'doctor_id', 'date', 'start_time']);
    }
}
