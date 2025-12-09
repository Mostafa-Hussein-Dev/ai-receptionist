<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Business\AppointmentService;
use App\Services\Business\SlotService;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;
use App\Models\PostgreSQL\Patient;
use App\Models\PostgreSQL\Appointment;
use App\Models\PostgreSQL\DoctorSchedule;
use App\Models\PostgreSQL\Slot;
use Carbon\Carbon;
use App\Exceptions\SlotException;

class AppointmentServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AppointmentService $appointmentService;
    private SlotService $slotService;
    private Doctor $doctor;
    private Patient $patient;
    private Carbon $testDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appointmentService = app(AppointmentService::class);
        $this->slotService        = app(SlotService::class);

        // Create test data
        $department = Department::factory()->create();

        // Let factory create the normal weekly schedule (Mon–Fri)
        $this->doctor = Doctor::factory()->create([
            'department_id'         => $department->id,
            'slots_per_appointment' => 2,
        ]);

        $this->patient = Patient::factory()->create();

        // 🔑 IMPORTANT:
        // Use *tomorrow* as the test date so we never violate the
        // "at least 2 hours notice" rule. If tomorrow is weekend,
        // push to next Monday.
        $this->testDate = Carbon::tomorrow();
        if ($this->testDate->isWeekend()) {
            $this->testDate = $this->testDate->next(Carbon::MONDAY);
        }

        // Make sure the doctor's schedule for this weekday is 08:00–14:00
        DoctorSchedule::where('doctor_id', $this->doctor->id)
            ->where('day_of_week', $this->testDate->dayOfWeek)
            ->update([
                'start_time'   => '08:00:00',
                'end_time'     => '14:00:00',
                'is_available' => true,
            ]);

        // Generate slots for that date based on schedule
        $this->slotService->generateSlotsForDate($this->doctor->id, $this->testDate);
    }

    #[Test]
    public function it_can_book_appointment()
    {
        // Arrange
        $data = [
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',    // inside 08:00–14:00 window
            'type'           => 'general',
        ];

        // Act
        $appointment = $this->appointmentService->bookAppointment($data);

        // Assert
        $this->assertNotNull($appointment);
        $this->assertEquals('scheduled', $appointment->status);
        $this->assertEquals('09:00:00', $appointment->start_time);
        $this->assertEquals('09:30:00', $appointment->end_time);
        $this->assertEquals(2, $appointment->slot_count);
    }

    #[Test]
    public function it_marks_slots_as_booked_after_appointment()
    {
        // Arrange
        $data = [
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ];

        // Act
        $appointment = $this->appointmentService->bookAppointment($data);

        // Assert - Check slots are marked as booked
        $bookedSlots = $appointment->slots()
            ->where('status', 'booked')
            ->where('appointment_id', $appointment->id)
            ->count();

        $this->assertEquals(2, $bookedSlots);
    }

    #[Test]
    public function it_can_cancel_appointment()
    {
        // Arrange - Book first
        $appointment = $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // Act
        $cancelled = $this->appointmentService->cancelAppointment(
            $appointment->id,
            'Patient request'
        );

        // Assert
        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertEquals('Patient request', $cancelled->cancellation_reason);
    }

    #[Test]
    public function it_releases_slots_after_cancellation()
    {
        // Arrange - Book first
        $appointment = $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // Act
        $this->appointmentService->cancelAppointment($appointment->id, 'Test');

        // Assert - Slots should be available again with NULL appointment_id
        // Assuming slots 5 & 6 correspond to 09:00–09:30 in your slot mapping.
        $releasedSlots = Slot::where('doctor_id', $this->doctor->id)
            ->where('date', $this->testDate->toDateString())
            ->where('status', 'available')
            ->whereNull('appointment_id')
            ->whereBetween('slot_number', [5, 6]) // 09:00 slots
            ->count();

        $this->assertEquals(2, $releasedSlots);
    }

    #[Test]
    public function cancelled_appointment_status_is_stored_correctly()
    {
        // Arrange - Book first
        $appointment = $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // Act
        $this->appointmentService->cancelAppointment($appointment->id, 'Test');

        // Assert - Check appointment record directly
        $cancelled = Appointment::find($appointment->id);
        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    #[Test]
    public function it_can_reschedule_appointment()
    {
        // Arrange - Book first
        $original = $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // Act - Reschedule to 10:00
        $rescheduled = $this->appointmentService->rescheduleAppointment(
            $original->id,
            $this->testDate->toDateString(),
            '10:00'
        );

        // Assert
        $this->assertEquals('scheduled', $rescheduled->status);
        $this->assertEquals('10:00:00', $rescheduled->start_time);
        $this->assertEquals('10:30:00', $rescheduled->end_time);
    }

    #[Test]
    public function it_releases_old_slots_when_rescheduling()
    {
        // Arrange - Book at 09:00
        $original = $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // Act - Reschedule to 10:00
        $this->appointmentService->rescheduleAppointment(
            $original->id,
            $this->testDate->toDateString(),
            '10:00'
        );

        // Assert - Old slots (09:00) should be available
        $releasedSlots = Slot::where('doctor_id', $this->doctor->id)
            ->where('date', $this->testDate->toDateString())
            ->where('status', 'available')
            ->whereNull('appointment_id')
            ->whereBetween('slot_number', [5, 6]) // 09:00 slots
            ->count();

        $this->assertEquals(2, $releasedSlots);
    }

    #[Test]
    public function it_throws_exception_when_no_consecutive_slots_available()
    {
        // Arrange - Book all slots except one so that no 2 consecutive slots remain
        for ($i = 1; $i <= 23; $i++) {
            Slot::where('doctor_id', $this->doctor->id)
                ->where('date', $this->testDate->toDateString())
                ->where('slot_number', $i)
                ->update(['status' => 'booked']);
        }

        // Act & Assert - Booking should now fail due to no consecutive slots
        $this->expectException(SlotException::class);

        $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);
    }

    #[Test]
    public function it_can_get_upcoming_appointments_for_patient()
    {
        // Arrange - Book 1st appointment on testDate
        $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $this->testDate->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // Get next weekday after testDate
        $nextDate = $this->testDate->copy()->addDay();
        if ($nextDate->isWeekend()) {
            $nextDate = $nextDate->next(Carbon::MONDAY);
        }

        // Ensure schedule & slots for the next day
        DoctorSchedule::where('doctor_id', $this->doctor->id)
            ->where('day_of_week', $nextDate->dayOfWeek)
            ->update([
                'start_time'   => '08:00:00',
                'end_time'     => '14:00:00',
                'is_available' => true,
            ]);

        $this->slotService->generateSlotsForDate($this->doctor->id, $nextDate);

        // Book 2nd appointment on the next day
        $this->appointmentService->bookAppointment([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'date'           => $nextDate->toDateString(),
            'preferred_time' => '10:00',
        ]);

        // Act
        $upcoming = $this->appointmentService->getUpcomingAppointments($this->patient->id);

        // Assert
        $this->assertCount(2, $upcoming);
    }
}
