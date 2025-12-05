<?php

namespace App\Services\Business;

use App\Models\PostgreSQL\Slot;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\DoctorSchedule;
use App\Models\PostgreSQL\DoctorScheduleException;
use App\Exceptions\SlotException;
use App\Exceptions\AppointmentException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SlotService
{
    /**
     * Generate slots for a specific doctor for a specific date
     */
    public function generateSlotsForDate(int $doctorId, Carbon $date): Collection
    {
        // Check if doctor exists and is active
        $doctor = Doctor::findOrFail($doctorId);

        if (!$doctor->is_active) {
            throw AppointmentException::doctorNotActive($doctorId);
        }

        // Don't generate slots for weekends
        if ($this->isWeekend($date)) {
            return collect();
        }

        // Don't generate slots for doctor's day off
        if ($this->isDoctorDayOff($doctorId, $date)) {
            return collect();
        }

        // Get working hours for this date
        $workingHours = $this->getWorkingHoursForDate($doctorId, $date);

        if (!$workingHours) {
            return collect();
        }

        // Delete existing slots for this date (if regenerating)
        Slot::where('doctor_id', $doctorId)
            ->where('date', $date->toDateString())
            ->delete();

        // Get slot duration from config - CAST TO INT
        $slotDuration = (int) config('hospital.slots.duration_minutes', 15);

        // Generate slots
        $slots = collect();
        $currentTime = Carbon::parse($workingHours['start']);
        $endTime = Carbon::parse($workingHours['end']);
        $slotNumber = 1;

        while ($currentTime->lt($endTime)) {
            $slotEndTime = $currentTime->copy()->addMinutes($slotDuration);

            // Don't create slot if it would extend past working hours
            if ($slotEndTime->gt($endTime)) {
                break;
            }

            $slot = Slot::create([
                'doctor_id' => $doctorId,
                'date' => $date->toDateString(),
                'slot_number' => $slotNumber,
                'start_time' => $currentTime->format('H:i:s'),
                'end_time' => $slotEndTime->format('H:i:s'),
                'status' => 'available',
            ]);

            $slots->push($slot);

            $currentTime->addMinutes($slotDuration);
            $slotNumber++;
        }

        return $slots;
    }

    /**
     * Generate slots for a doctor for a date range
     */
    public function generateSlotsForDateRange(int $doctorId, Carbon $startDate, Carbon $endDate): int
    {
        $totalSlots = 0;

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $slots = $this->generateSlotsForDate($doctorId, $date);
            $totalSlots += $slots->count();
        }

        return $totalSlots;
    }

    /**
     * Generate slots for all active doctors
     */
    public function generateSlotsForAllDoctors(int $days = 30): int
    {
        $doctors = Doctor::where('is_active', true)->get();
        $totalSlots = 0;

        foreach ($doctors as $doctor) {
            $startDate = Carbon::today();
            $endDate = Carbon::today()->addDays($days - 1);

            $count = $this->generateSlotsForDateRange($doctor->id, $startDate, $endDate);
            $totalSlots += $count;
        }

        return $totalSlots;
    }

    /**
     * Get available slots for a doctor on a specific date
     */
    public function getAvailableSlots(int $doctorId, Carbon $date): Collection
    {
        return Slot::where('doctor_id', $doctorId)
            ->where('date', $date->toDateString())
            ->where('status', 'available')
            ->orderBy('slot_number')
            ->get();
    }

    /**
     * Get available consecutive slots (for multi-slot appointments)
     */
    public function getAvailableConsecutiveSlots(int $doctorId, Carbon $date, int $slotCount): Collection
    {
        $allSlots = $this->getAvailableSlots($doctorId, $date);
        $consecutiveSlots = collect();

        for ($i = 0; $i <= $allSlots->count() - $slotCount; $i++) {
            $potentialSlots = $allSlots->slice($i, $slotCount);

            // Check if these slots are consecutive
            $isConsecutive = true;
            $expectedSlotNumber = $potentialSlots->first()->slot_number;

            foreach ($potentialSlots as $slot) {
                if ($slot->slot_number !== $expectedSlotNumber) {
                    $isConsecutive = false;
                    break;
                }
                $expectedSlotNumber++;
            }

            if ($isConsecutive && $potentialSlots->count() === $slotCount) {
                $consecutiveSlots->push($potentialSlots);
            }
        }

        return $consecutiveSlots;
    }

    /**
     * Book slots for an appointment (atomic operation)
     */
    public function bookSlots(int $doctorId, Carbon $date, int $startSlotNumber, int $slotCount, int $appointmentId): bool
    {
        return DB::transaction(function () use ($doctorId, $date, $startSlotNumber, $slotCount, $appointmentId) {
            // Get the slots we need to book
            $slots = Slot::where('doctor_id', $doctorId)
                ->where('date', $date->toDateString())
                ->where('slot_number', '>=', $startSlotNumber)
                ->where('slot_number', '<', $startSlotNumber + $slotCount)
                ->lockForUpdate() // Prevent concurrent booking
                ->get();

            // Verify we have the right number of slots
            if ($slots->count() !== $slotCount) {
                throw SlotException::insufficientConsecutiveSlots($slotCount);
            }

            // Verify all slots are available
            foreach ($slots as $slot) {
                if ($slot->status !== 'available') {
                    throw SlotException::alreadyBooked($slot->id);
                }
            }

            // Book all slots
            foreach ($slots as $slot) {
                $slot->update([
                    'status' => 'booked',
                    'appointment_id' => $appointmentId,
                ]);
            }

            return true;
        });
    }

    /**
     * Release slots when appointment is cancelled
     */
    public function releaseSlots(int $appointmentId): int
    {
        return Slot::where('appointment_id', $appointmentId)
            ->update([
                'status' => 'available',
                'appointment_id' => null,
            ]);
    }

    /**
     * Block a specific slot manually
     */
    public function blockSlot(int $slotId, string $reason): Slot
    {
        $slot = Slot::findOrFail($slotId);

        if ($slot->status === 'booked') {
            throw SlotException::alreadyBooked($slotId);
        }

        $slot->update([
            'status' => 'blocked',
            'blocked_reason' => $reason,
        ]);

        return $slot;
    }

    /**
     * Convert time to slot number
     */
    public function timeToSlotNumber(string $time): int
    {
        $slotDuration = (int) config('hospital.slots.duration_minutes', 15);
        $workingHoursStart = config('hospital.working_hours.start', '08:00');

        $startTime = Carbon::parse($workingHoursStart);
        $givenTime = Carbon::parse($time);

        $minutesDiff = $startTime->diffInMinutes($givenTime);

        return (int) floor($minutesDiff / $slotDuration) + 1;
    }

    /**
     * Check if date is weekend
     */
    private function isWeekend(Carbon $date): bool
    {
        $weekends = config('hospital.weekends', [6, 0]); // Saturday, Sunday
        return in_array($date->dayOfWeek, $weekends);
    }

    /**
     * Check if doctor has a day off
     */
    private function isDoctorDayOff(int $doctorId, Carbon $date): bool
    {
        return DoctorScheduleException::where('doctor_id', $doctorId)
            ->where('date', $date->toDateString())
            ->where('type', 'day_off')
            ->exists();
    }

    /**
     * Get working hours for a doctor on a specific date
     * Returns ['start' => '08:00', 'end' => '14:00'] or null
     */
    private function getWorkingHoursForDate(int $doctorId, Carbon $date): ?array
    {
        // First check for custom hours exception
        $exception = DoctorScheduleException::where('doctor_id', $doctorId)
            ->where('date', $date->toDateString())
            ->where('type', 'custom_hours')
            ->first();

        if ($exception) {
            return [
                'start' => $exception->start_time,
                'end' => $exception->end_time,
            ];
        }

        // Get regular schedule for this day of week
        $schedule = DoctorSchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_available', true)
            ->first();

        if (!$schedule) {
            return null;
        }

        return [
            'start' => $schedule->start_time,
            'end' => $schedule->end_time,
        ];
    }
}
