<?php

namespace App\Services\Business;

use App\Exceptions\AppointmentException;
use App\Exceptions\SlotException;
use App\Models\PostgreSQL\Appointment;
use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\DoctorScheduleException;
use App\Models\PostgreSQL\Patient;
use Carbon\Carbon;

class ValidationService
{
    public function __construct(
        private SlotService $slotService
    ) {}

    /**
     * Validate appointment booking request
     *
     * Business Rules Enforced:
     * 1. Cannot book in the past
     * 2. Cannot book beyond 90 days advance (configurable)
     * 3. Doctor must be active
     * 4. Slot must be available
     * 5. Must be within working hours
     * 6. Must have consecutive available slots
     * 7. Cannot double-book patient at same time (different times allowed)
     * 8. Minimum 2 hours notice for booking (configurable)
     * 9. Maximum 2 appointments per patient per day (configurable)
     * 10. Block weekends (Saturdays and Sundays)
     * 11. Cannot book on doctor's day off
     */
    public function validateBooking(
        int $patientId,
        int $doctorId,
        Carbon|string $date,
        string $startTime,
        int $slotCount
    ): bool {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        // RULE 1: Cannot book in the past
        $this->validateNotInPast($date, $startTime);

        // RULE 2: Cannot book beyond advance limit (90 days default)
        $this->validateAdvanceBookingLimit($date);

        // RULE 3: Doctor must be active
        $this->validateDoctorActive($doctorId);

        // RULE 8: Minimum notice required (2 hours default)
        $this->validateMinimumNotice($date, $startTime);

        // RULE 10: Block weekends
        $this->validateNotWeekend($date);

        // RULE 11: Cannot book on doctor's day off
        $this->validateNotDoctorDayOff($doctorId, $date);

        // RULE 5: Must be within working hours
        $this->validateWithinWorkingHours($doctorId, $date, $startTime, $slotCount);

        // RULE 4 & 6: Slots must be available and consecutive
        $this->validateSlotsAvailable($doctorId, $date, $startTime, $slotCount);

        // RULE 7: Cannot double-book patient at same time
        $this->validateNoTimeConflict($patientId, $date, $startTime, $slotCount);

        // RULE 9: Maximum appointments per patient per day
        $this->validateMaxAppointmentsPerDay($patientId, $date);

        // Validate slot count is within acceptable range
        $this->validateSlotCount($slotCount);

        return true;
    }

    /**
     * RULE 1: Cannot book in the past
     */
    private function validateNotInPast(Carbon|string $date, string $startTime): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $appointmentDateTime = Carbon::parse($date->toDateString() . ' ' . $startTime);

        if ($appointmentDateTime->isPast()) {
            throw AppointmentException::cannotBookInPast();
        }
    }

    /**
     * RULE 2: Cannot book beyond advance limit
     */
    private function validateAdvanceBookingLimit(Carbon|string $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $maxDays = (int) config('hospital.appointments.booking_advance_days', 90);
        $maxDate = now()->addDays($maxDays);

        if ($date->isAfter($maxDate)) {
            throw AppointmentException::beyondAdvanceLimit($maxDays);
        }
    }

    /**
     * RULE 3: Doctor must be active
     */
    private function validateDoctorActive(int $doctorId): void
    {
        $doctor = Doctor::find($doctorId);

        if (!$doctor) {
            throw AppointmentException::doctorNotFound();
        }

        if (!$doctor->is_active) {
            throw AppointmentException::doctorNotActive();
        }
    }

    /**
     * RULE 8: Minimum notice required
     */
    private function validateMinimumNotice(Carbon|string $date, string $startTime): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $minHours = (int) config('hospital.appointments.minimum_notice_hours', 2);
        $appointmentDateTime = Carbon::parse($date->toDateString() . ' ' . $startTime);
        $minimumDateTime = now()->addHours($minHours);

        if ($appointmentDateTime->lt($minimumDateTime)) {
            throw AppointmentException::minimumNoticeRequired($minHours);
        }
    }

    /**
     * RULE 10: Block weekends
     */
    private function validateNotWeekend(Carbon|string $date): void
    {
        if (!config('hospital.appointments.block_weekends', true)) {
            return;
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $weekends = config('hospital.weekends', [6, 0]); // Saturday, Sunday

        if (in_array($date->dayOfWeek, $weekends)) {
            throw SlotException::onWeekend();
        }
    }

    /**
     * RULE 11: Cannot book on doctor's day off
     */
    private function validateNotDoctorDayOff(int $doctorId, Carbon|string $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $isDayOff = DoctorScheduleException::where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('type', 'day_off')
            ->exists();

        if ($isDayOff) {
            throw AppointmentException::doctorOnDayOff();
        }
    }

    /**
     * RULE 5: Must be within working hours
     */
    private function validateWithinWorkingHours(
        int $doctorId,
        Carbon|string $date,
        string $startTime,
        int $slotCount
    ): void {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $workingHours = $this->getWorkingHours($doctorId, $date);

        if (!$workingHours) {
            throw SlotException::outsideWorkingHours();
        }

        $start = Carbon::parse($startTime);
        $duration = $slotCount * (int) config('hospital.slots.duration_minutes', 15);
        $end = $start->copy()->addMinutes($duration);

        $workStart = Carbon::parse($workingHours['start']);
        $workEnd = Carbon::parse($workingHours['end']);

        if ($start->lt($workStart) || $end->gt($workEnd)) {
            throw SlotException::outsideWorkingHours();
        }
    }

    /**
     * RULE 4 & 6: Slots must be available and consecutive
     */
    private function validateSlotsAvailable(
        int $doctorId,
        Carbon|string $date,
        string $startTime,
        int $slotCount
    ): void {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $startSlotNumber = $this->slotService->timeToSlotNumber($startTime);

        $consecutiveGroups = $this->slotService->getAvailableConsecutiveSlots(
            $doctorId,
            $date,
            $slotCount
        );

        // Check if any group starts at the requested slot number
        $matchingGroup = $consecutiveGroups->first(function ($group) use ($startSlotNumber) {
            return $group->first()->slot_number === $startSlotNumber;
        });

        if (!$matchingGroup) {
            throw SlotException::insufficientConsecutiveSlots($slotCount);
        }
    }

    /**
     * RULE 7: Cannot double-book patient at same time (different times allowed)
     */
    private function validateNoTimeConflict(
        int $patientId,
        Carbon|string $date,
        string $startTime,
        int $slotCount
    ): void {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $duration = $slotCount * (int) config('hospital.slots.duration_minutes', 15);
        $endTime = Carbon::parse($startTime)->addMinutes($duration)->format('H:i:s');

        // Check for overlapping appointments
        $conflict = Appointment::where('patient_id', $patientId)
            ->where('date', $date)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where(function ($query) use ($startTime, $endTime) {
                // Check if new appointment overlaps with existing ones
                $query->where(function ($q) use ($startTime, $endTime) {
                    // New appointment starts during existing appointment
                    $q->where('start_time', '<=', $startTime)
                        ->where('end_time', '>', $startTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New appointment ends during existing appointment
                    $q->where('start_time', '<', $endTime)
                        ->where('end_time', '>=', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New appointment completely contains existing appointment
                    $q->where('start_time', '>=', $startTime)
                        ->where('end_time', '<=', $endTime);
                });
            })
            ->exists();

        if ($conflict) {
            throw new \Exception('Patient has a conflicting appointment at this time.');
        }
    }

    /**
     * RULE 9: Maximum appointments per patient per day
     */
    private function validateMaxAppointmentsPerDay(int $patientId, Carbon|string $date): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $maxPerDay = (int) config('hospital.appointments.max_per_patient_per_day', 2);

        $appointmentCount = Appointment::where('patient_id', $patientId)
            ->where('date', $date)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->count();

        if ($appointmentCount >= $maxPerDay) {
            throw AppointmentException::maxAppointmentsReached($maxPerDay);
        }
    }

    /**
     * Validate slot count is within acceptable range
     */
    public function validateSlotCount(int $slotCount): void
    {
        $min = (int) config('hospital.slots.min_per_appointment', 1);
        $max = (int) config('hospital.slots.max_per_appointment', 4);

        if ($slotCount < $min || $slotCount > $max) {
            throw AppointmentException::invalidSlotCount($min, $max);
        }
    }

    /**
     * Validate patient exists
     */
    private function validatePatientExists(int $patientId): void
    {
        if (!Patient::find($patientId)) {
            throw AppointmentException::patientNotFound();
        }
    }

    /**
     * Validate appointment can be cancelled
     */
    public function validateCanCancel(Appointment $appointment): void
    {
        if ($appointment->isCancelled()) {
            throw AppointmentException::alreadyCancelled();
        }
    }

    /**
     * Get working hours for doctor on a specific date
     * (considers exceptions)
     */
    private function getWorkingHours(int $doctorId, Carbon|string $date): ?array
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        // Check for custom hours exception
        $exception = DoctorScheduleException::where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('type', 'custom_hours')
            ->first();

        if ($exception) {
            return [
                'start' => $exception->start_time,
                'end' => $exception->end_time,
            ];
        }

        // Get regular schedule
        $doctor = Doctor::with('schedules')->find($doctorId);
        if (!$doctor) {
            return null;
        }

        $schedule = $doctor->schedules()
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
