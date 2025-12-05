<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Business\SlotService;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;
use App\Models\PostgreSQL\DoctorSchedule;
use Carbon\Carbon;

class SlotServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SlotService $slotService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slotService = app(SlotService::class);
    }

    #[Test]
    public function it_can_generate_slots_for_a_date()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);

        $today = Carbon::today();
        if ($today->isWeekend()) {
            $today = $today->next(Carbon::MONDAY);
        }

        // Update existing doctor schedule for this day to specific hours
        DoctorSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $today->dayOfWeek)
            ->update([
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);

        // Act
        $slots = $this->slotService->generateSlotsForDate($doctor->id, $today);

        // Assert
        $this->assertEquals(24, $slots->count()); // 6 hours × 4 slots per hour = 24
        $this->assertEquals('08:00:00', $slots->first()->start_time);
        $this->assertEquals('available', $slots->first()->status);
    }

    #[Test]
    public function it_does_not_generate_slots_for_weekends()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);
        $saturday = Carbon::today()->next(Carbon::SATURDAY);

        // Act
        $slots = $this->slotService->generateSlotsForDate($doctor->id, $saturday);

        // Assert
        $this->assertCount(0, $slots);
    }

    #[Test]
    public function it_can_get_available_slots()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);

        $today = Carbon::today();
        if ($today->isWeekend()) {
            $today = $today->next(Carbon::MONDAY);
        }

        // Update existing doctor schedule for this day to specific hours
        DoctorSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $today->dayOfWeek)
            ->update([
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);

        $this->slotService->generateSlotsForDate($doctor->id, $today);

        // Act
        $available = $this->slotService->getAvailableSlots($doctor->id, $today);

        // Assert
        $this->assertEquals(24, $available->count());
    }

    #[Test]
    public function it_can_get_consecutive_slots()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create([
            'department_id' => $department->id,
            'slots_per_appointment' => 2
        ]);

        $today = Carbon::today();
        if ($today->isWeekend()) {
            $today = $today->next(Carbon::MONDAY);
        }

        // Update existing doctor schedule for this day to specific hours
        DoctorSchedule::where('doctor_id', $doctor->id)
            ->where('day_of_week', $today->dayOfWeek)
            ->update([
                'start_time' => '08:00:00',
                'end_time' => '14:00:00',
                'is_available' => true,
            ]);

        $this->slotService->generateSlotsForDate($doctor->id, $today);

        // Act
        $consecutive = $this->slotService->getAvailableConsecutiveSlots($doctor->id, $today, 2);

        // Assert
        $this->assertGreaterThan(0, $consecutive->count());
        $this->assertEquals(2, $consecutive->first()->count());
    }

    #[Test]
    public function it_can_convert_time_to_slot_number()
    {
        // Act
        $slotNumber = $this->slotService->timeToSlotNumber('09:00');

        // Assert
        $this->assertEquals(5, $slotNumber); // 08:00 = slot 1, 09:00 = slot 5 (4 slots per hour)
    }
}
