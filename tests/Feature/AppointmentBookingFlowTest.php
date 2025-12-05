<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Business\AppointmentService;
use App\Services\Business\SlotService;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;
use App\Models\PostgreSQL\Patient;
use App\Models\PostgreSQL\DoctorSchedule;
use Carbon\Carbon;

class AppointmentBookingFlowTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function complete_booking_flow_from_start_to_finish()
    {
        // ============================================
        // ARRANGE: Set up complete test environment
        // ============================================

        // 1. Create department
        $department = Department::factory()->create([
            'name' => 'General Medicine'
        ]);
        $this->assertDatabaseHas('departments', ['name' => 'General Medicine']);

        // 2. Create doctor
        $doctor = Doctor::factory()->create([
            'department_id' => $department->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'slots_per_appointment' => 2,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('doctors', ['first_name' => 'John', 'last_name' => 'Smith']);

        // 3. Create patient
        $patient = Patient::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '+15551234567',
            'date_of_birth' => '1990-01-01',
        ]);
        $this->assertDatabaseHas('patients', ['phone' => '+15551234567']);

        // 4. Set up doctor schedule
        $tomorrow = Carbon::tomorrow();
        if ($tomorrow->isWeekend()) {
            $tomorrow = $tomorrow->next(Carbon::MONDAY);
        }

        // Update existing doctor schedule for this day
        DoctorSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $tomorrow->dayOfWeek)
            ->update([
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);
        $this->assertDatabaseHas('doctor_schedules', ['doctor_id' => $doctor->id]);

        // 5. Generate slots
        $slotService = app(SlotService::class);
        $slots = $slotService->generateSlotsForDate($doctor->id, $tomorrow);
        $this->assertCount(24, $slots); // 6 hours * 4 slots = 24

        // ============================================
        // ACT: Book an appointment
        // ============================================

        $appointmentService = app(AppointmentService::class);
        $appointment = $appointmentService->bookAppointment([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => $tomorrow->toDateString(),
            'preferred_time' => '09:00',
            'type' => 'general',
            'reason' => 'Regular checkup',
        ]);

        // ============================================
        // ASSERT: Verify complete booking state
        // ============================================

        // 1. Appointment created correctly
        $this->assertNotNull($appointment);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'scheduled',
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'slot_count' => 2,
        ]);

        // 2. Slots marked as booked
        $bookedSlots = \App\Models\PostgreSQL\Slot::where('appointment_id', $appointment->id)
            ->where('status', 'booked')
            ->count();
        $this->assertEquals(2, $bookedSlots);

        // 3. Correct slots are booked (slot 5 & 6 for 09:00-09:30)
        $this->assertDatabaseHas('slots', [
            'doctor_id' => $doctor->id,
            'date' => $tomorrow->toDateString(),
            'slot_number' => 5,
            'status' => 'booked',
            'appointment_id' => $appointment->id,
        ]);

        // 4. Remaining slots are still available
        $availableSlots = \App\Models\PostgreSQL\Slot::where('doctor_id', $doctor->id)
            ->where('date', $tomorrow->toDateString())
            ->where('status', 'available')
            ->count();
        $this->assertEquals(22, $availableSlots); // 24 - 2 booked = 22

        // ============================================
        // ACT: Cancel the appointment
        // ============================================

        $cancelledAppointment = $appointmentService->cancelAppointment(
            $appointment->id,
            'Patient needs to reschedule'
        );

        // ============================================
        // ASSERT: Verify cancellation state
        // ============================================

        // 1. Appointment status updated
        $this->assertEquals('cancelled', $cancelledAppointment->status);
        $this->assertNotNull($cancelledAppointment->cancelled_at);
        $this->assertEquals('Patient needs to reschedule', $cancelledAppointment->cancellation_reason);

        // 2. Slots released (status = available, appointment_id = NULL)
        $releasedSlots = \App\Models\PostgreSQL\Slot::where('doctor_id', $doctor->id)
            ->where('date', $tomorrow->toDateString())
            ->where('status', 'available')
            ->whereNull('appointment_id')
            ->count();
        $this->assertEquals(24, $releasedSlots); // All slots available again

        // 3. No slots should reference this appointment anymore
        $orphanedSlots = \App\Models\PostgreSQL\Slot::where('appointment_id', $appointment->id)->count();
        $this->assertEquals(0, $orphanedSlots);

        // ============================================
        // ACT: Book another appointment (verify slots reusable)
        // ============================================

        $newAppointment = $appointmentService->bookAppointment([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => $tomorrow->toDateString(),
            'preferred_time' => '10:00', // Different time
            'type' => 'general',
        ]);

        // ============================================
        // ASSERT: Verify second booking works
        // ============================================

        $this->assertNotNull($newAppointment);
        $this->assertEquals('10:00:00', $newAppointment->start_time);

        $newBookedSlots = \App\Models\PostgreSQL\Slot::where('appointment_id', $newAppointment->id)
            ->where('status', 'booked')
            ->count();
        $this->assertEquals(2, $newBookedSlots);
    }

    #[Test]
    public function reschedule_flow_releases_old_slots_and_books_new_ones()
    {
        // ARRANGE
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create([
            'department_id' => $department->id,
            'slots_per_appointment' => 2,
        ]);
        $patient = Patient::factory()->create();

        $tomorrow = Carbon::tomorrow();
        if ($tomorrow->isWeekend()) {
            $tomorrow = $tomorrow->next(Carbon::MONDAY);
        }

        // Update existing doctor schedule for this day
        DoctorSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $tomorrow->dayOfWeek)
            ->update([
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);

        $slotService = app(SlotService::class);
        $slotService->generateSlotsForDate($doctor->id, $tomorrow);

        $appointmentService = app(AppointmentService::class);

        // Book original appointment at 09:00
        $original = $appointmentService->bookAppointment([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => $tomorrow->toDateString(),
            'preferred_time' => '09:00',
        ]);

        // ACT - Reschedule to 11:00
        $rescheduled = $appointmentService->rescheduleAppointment(
            $original->id,
            $tomorrow->toDateString(),
            '11:00'
        );

        // ASSERT

        // 1. Appointment time changed
        $this->assertEquals('11:00:00', $rescheduled->start_time);
        $this->assertEquals('11:30:00', $rescheduled->end_time);

        // 2. Old slots (09:00-09:30) are available
        $oldSlotsAvailable = \App\Models\PostgreSQL\Slot::where('doctor_id', $doctor->id)
            ->where('date', $tomorrow->toDateString())
            ->whereBetween('slot_number', [5, 6]) // 09:00 slots
            ->where('status', 'available')
            ->whereNull('appointment_id')
            ->count();
        $this->assertEquals(2, $oldSlotsAvailable);

        // 3. New slots (11:00-11:30) are booked
        $newSlotsBooked = \App\Models\PostgreSQL\Slot::where('doctor_id', $doctor->id)
            ->where('date', $tomorrow->toDateString())
            ->whereBetween('slot_number', [13, 14]) // 11:00 slots
            ->where('status', 'booked')
            ->where('appointment_id', $rescheduled->id)
            ->count();
        $this->assertEquals(2, $newSlotsBooked);

        // 4. Total available slots correct (24 - 2 booked = 22)
        $totalAvailable = \App\Models\PostgreSQL\Slot::where('doctor_id', $doctor->id)
            ->where('date', $tomorrow->toDateString())
            ->where('status', 'available')
            ->count();
        $this->assertEquals(22, $totalAvailable);
    }
}
