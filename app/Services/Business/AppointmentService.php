<?php

namespace App\Services\Business;

use App\DTOs\AppointmentDTO;
use App\Exceptions\AppointmentException;
use App\Models\PostgreSQL\Appointment;
use App\Models\PostgreSQL\Doctor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public function __construct(
        private ValidationService $validationService,
        private SlotService $slotService
    ) {}

    /**
     * Book a new appointment
     *
     * @param AppointmentDTO|array $data
     * @throws AppointmentException
     * @throws SlotException
     */
    public function bookAppointment(AppointmentDTO|array $data): Appointment
    {
        // Convert array to DTO if needed
        $dto = is_array($data) ? $this->arrayToDTO($data) : $data;

        // Validate all business rules
        $this->validationService->validateBooking(
            $dto->patientId,
            $dto->doctorId,
            $dto->date,
            $dto->startTime,
            $dto->slotCount
        );

        return DB::transaction(function () use ($dto) {
            // Create appointment
            $appointment = Appointment::create([
                'patient_id' => $dto->patientId,
                'doctor_id' => $dto->doctorId,
                'call_id' => $dto->callId,
                'date' => $dto->date,
                'start_time' => $dto->startTime,
                'end_time' => $dto->getEndTime(),
                'slot_count' => $dto->slotCount,
                'status' => 'scheduled',
                'type' => $dto->type,
                'reason' => $dto->reason,
                'notes' => $dto->notes,
            ]);

            // Book the slots
            $startSlotNumber = $this->slotService->timeToSlotNumber($dto->startTime);
            $this->slotService->bookSlots(
                $dto->doctorId,
                $dto->date,
                $startSlotNumber,
                $dto->slotCount,
                $appointment->id
            );

            return $appointment->fresh();
        });
    }

    /**
     * Book appointment with named parameters (convenience method for API)
     *
     * @param int $patientId
     * @param int $doctorId
     * @param Carbon $date
     * @param string $startTime
     * @param int $slotCount
     * @param string $type
     * @param string|null $reason
     * @param int|null $callId
     * @return Appointment
     * @throws AppointmentException
     */
    public function bookAppointmentDirect(
        int $patientId,
        int $doctorId,
        Carbon $date,
        string $startTime,
        int $slotCount = 1,
        string $type = 'general',
        ?string $reason = null,
        ?int $callId = null
    ): Appointment {
        return $this->bookAppointment([
            'patientId' => $patientId,
            'doctorId' => $doctorId,
            'date' => $date,
            'startTime' => $startTime,
            'slotCount' => $slotCount,
            'type' => $type,
            'reason' => $reason,
            'callId' => $callId,
        ]);
    }

    /**
     * Cancel an appointment (immediate slot release)
     *
     * @throws AppointmentException
     */
    public function cancelAppointment(int $appointmentId, ?string $reason = null): Appointment
    {
        $appointment = Appointment::find($appointmentId);

        if (!$appointment) {
            throw AppointmentException::appointmentNotFound();
        }

        // Validate can be cancelled
        $this->validationService->validateCanCancel($appointment);

        return DB::transaction(function () use ($appointment, $reason) {
            // Release slots immediately
            $this->slotService->releaseSlots($appointment->id);

            // Update appointment status
            $appointment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $appointment->fresh();
        });
    }

    /**
     * Reschedule an appointment (atomic operation)
     * Preserves appointment ID, updates date/time and slots
     *
     * @throws AppointmentException
     */
    public function rescheduleAppointment(
        int $appointmentId,
        Carbon|string $newDate,
        string $newStartTime,
        ?int $newSlotCount = null,
        ?string $reason = null
    ): Appointment {
        // Convert string to Carbon if needed
        if (is_string($newDate)) {
            $newDate = Carbon::parse($newDate);
        }
        $appointment = Appointment::find($appointmentId);

        if (!$appointment) {
            throw AppointmentException::appointmentNotFound();
        }

        // Cannot reschedule if already cancelled
        if ($appointment->isCancelled()) {
            throw AppointmentException::alreadyCancelled();
        }

        // Use existing slot count if not provided
        $slotCount = $newSlotCount ?? $appointment->slot_count;

        // Validate new time slot
        $this->validationService->validateBooking(
            $appointment->patient_id,
            $appointment->doctor_id,
            $newDate,
            $newStartTime,
            $slotCount
        );

        return DB::transaction(function () use ($appointment, $newDate, $newStartTime, $slotCount, $reason) {
            // Release old slots
            $this->slotService->releaseSlots($appointment->id);

            // Calculate new end time
            $duration = $slotCount * config('hospital.slots.duration_minutes', 15);
            $newEndTime = Carbon::parse($newStartTime)->addMinutes($duration)->format('H:i:s');

            // Update appointment (atomic - preserves ID)
            $appointment->update([
                'date' => $newDate,
                'start_time' => $newStartTime,
                'end_time' => $newEndTime,
                'slot_count' => $slotCount,
                'status' => 'scheduled', // Reset to scheduled
                'notes' => $reason,
            ]);

            // Book new slots
            $startSlotNumber = $this->slotService->timeToSlotNumber($newStartTime);
            $this->slotService->bookSlots(
                $appointment->doctor_id,
                $newDate,
                $startSlotNumber,
                $slotCount,
                $appointment->id
            );

            return $appointment->fresh();
        });
    }

    /**
     * Get appointment by ID
     */
    public function getAppointment(int $appointmentId): ?Appointment
    {
        return Appointment::with(['patient', 'doctor', 'slots'])->find($appointmentId);
    }

    /**
     * Get appointment by ID (alias for getAppointment)
     *
     * @param int $id
     * @return Appointment|null
     */
    public function getById(int $id): ?Appointment
    {
        return $this->getAppointment($id);
    }

    /**
     * Get appointments for a patient
     */
    public function getPatientAppointments(int $patientId, ?string $status = null)
    {
        $query = Appointment::with(['doctor', 'slots'])
            ->where('patient_id', $patientId);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('date')->orderBy('start_time')->get();
    }

    /**
     * Get appointments for a doctor on a date
     */
    public function getDoctorAppointments(int $doctorId, Carbon $date)
    {
        return Appointment::with(['patient', 'slots'])
            ->where('doctor_id', $doctorId)
            ->where('date', $date)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Get upcoming appointments for a patient
     */
    public function getUpcomingAppointments(int $patientId)
    {
        return Appointment::with(['doctor', 'slots'])
            ->where('patient_id', $patientId)
            ->where('date', '>=', now()->toDateString())
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Mark appointment as completed
     */
    public function markAsCompleted(int $appointmentId): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);
        $appointment->update(['status' => 'completed']);
        return $appointment->fresh();
    }

    /**
     * Mark appointment as no-show
     */
    public function markAsNoShow(int $appointmentId): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);

        // Release slots for no-show
        $this->slotService->releaseSlots($appointment->id);

        $appointment->update(['status' => 'no_show']);
        return $appointment->fresh();
    }

    /**
     * Confirm appointment
     */
    public function confirmAppointment(int $appointmentId): Appointment
    {
        $appointment = Appointment::findOrFail($appointmentId);
        $appointment->update(['status' => 'confirmed']);
        return $appointment->fresh();
    }

    /**
     * Check if patient has appointment on date
     */
    public function hasAppointmentOnDate(int $patientId, Carbon $date): bool
    {
        return Appointment::where('patient_id', $patientId)
            ->where('date', $date)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->exists();
    }

    /**
     * Get available time slots for booking
     */
    public function getAvailableTimeSlots(int $doctorId, Carbon $date, int $slotCount)
    {
        $consecutiveGroups = $this->slotService->getAvailableConsecutiveSlots(
            $doctorId,
            $date,
            $slotCount
        );

        return $consecutiveGroups->map(function ($group) use ($slotCount) {
            $firstSlot = $group->first();
            $lastSlot = $group->last();
            $duration = $slotCount * (int) config('hospital.slots.duration_minutes', 15);

            return [
                'start_time' => $firstSlot->start_time,
                'end_time' => $lastSlot->end_time,
                'slot_number' => $firstSlot->slot_number,
                'slot_count' => $slotCount,
                'duration_minutes' => $duration,
            ];
        });
    }

    /**
     * Convert array to AppointmentDTO
     */
    private function arrayToDTO(array $data): AppointmentDTO
    {
        $doctor = Doctor::findOrFail($data['doctor_id']);
        $slotCount = $data['slot_count'] ?? $doctor->slots_per_appointment;

        return new AppointmentDTO(
            patientId: $data['patient_id'],
            doctorId: $data['doctor_id'],
            date: is_string($data['date']) ? Carbon::parse($data['date']) : $data['date'],
            startTime: $data['preferred_time'] ?? $data['start_time'],
            slotCount: $slotCount,
            callId: $data['call_id'] ?? null,
            type: $data['type'] ?? 'general',
            reason: $data['reason'] ?? null,
            notes: $data['notes'] ?? null
        );
    }
}
