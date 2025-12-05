<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Business\DoctorService;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\Department;
use Carbon\Carbon;

class DoctorServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DoctorService $doctorService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctorService = app(DoctorService::class);
    }

    #[Test]
    public function it_can_get_all_active_doctors()
    {
        // Arrange
        $department = Department::factory()->create();
        Doctor::factory()->count(3)->create([
            'department_id' => $department->id,
            'is_active' => true
        ]);

        // Act
        $doctors = $this->doctorService->getAllDoctors(true);

        // Assert
        $this->assertGreaterThanOrEqual(3, $doctors->count());
        $this->assertTrue($doctors->every(fn($d) => $d->is_active));
    }

    #[Test]
    public function it_can_get_doctor_by_id()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);

        // Act
        $found = $this->doctorService->getDoctor($doctor->id);

        // Assert
        $this->assertNotNull($found);
        $this->assertEquals($doctor->id, $found->id);
        $this->assertEquals($doctor->full_name, $found->full_name);
    }

    #[Test]
    public function it_can_mark_doctor_day_off()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);
        $tomorrow = Carbon::tomorrow();

        // Act
        $exception = $this->doctorService->markDayOff(
            $doctor->id,
            $tomorrow,
            'Personal day'
        );

        // Assert
        $this->assertNotNull($exception);
        $this->assertEquals($doctor->id, $exception->doctor_id);
        $this->assertEquals($tomorrow->toDateString(), $exception->date->toDateString());
        $this->assertEquals('day_off', $exception->type);
        $this->assertEquals('Personal day', $exception->reason);
    }

    #[Test]
    public function it_can_check_doctor_availability()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);

        $today = Carbon::today();
        if ($today->isWeekend()) {
            $today = $today->next(Carbon::MONDAY);
        }

        // Act - Should be available on weekday
        $isAvailable = $this->doctorService->isAvailableOnDate($doctor->id, $today);

        // Assert
        $this->assertTrue($isAvailable);
    }

    #[Test]
    public function it_returns_false_for_doctor_day_off()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);
        $tomorrow = Carbon::tomorrow();
        if ($tomorrow->isWeekend()) {
            $tomorrow = $tomorrow->next(Carbon::MONDAY);
        }

        $this->doctorService->markDayOff($doctor->id, $tomorrow, 'Off');

        // Act
        $isAvailable = $this->doctorService->isAvailableOnDate($doctor->id, $tomorrow);

        // Assert
        $this->assertFalse($isAvailable);
    }

    #[Test]
    public function it_can_set_custom_hours()
    {
        // Arrange
        $department = Department::factory()->create();
        $doctor = Doctor::factory()->create(['department_id' => $department->id]);
        $tomorrow = Carbon::tomorrow();

        // Act
        $exception = $this->doctorService->setCustomHours(
            $doctor->id,
            $tomorrow,
            '10:00',
            '16:00',
            'Extended hours'
        );

        // Assert
        $this->assertNotNull($exception);
        $this->assertEquals('custom_hours', $exception->type);
        $this->assertEquals('10:00:00', $exception->start_time);
        $this->assertEquals('16:00:00', $exception->end_time);
    }
}
