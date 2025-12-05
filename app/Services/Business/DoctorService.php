<?php

namespace App\Services\Business;

use App\Models\PostgreSQL\Doctor;
use App\Models\PostgreSQL\DoctorSchedule;
use App\Models\PostgreSQL\DoctorScheduleException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DoctorService
{
    /**
     * Get all doctors
     */
    public function getAllDoctors(bool $activeOnly = false): Collection
    {
        $query = Doctor::with('department');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Get doctor by ID
     */
    public function getDoctor(int $id): ?Doctor
    {
        return Doctor::with(['department', 'schedules', 'scheduleExceptions'])->find($id);
    }

    /**
     * Create a new doctor
     */
    public function createDoctor(array $data): Doctor
    {
        return DB::transaction(function () use ($data) {
            $doctor = Doctor::create([
                'department_id' => $data['department_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'slots_per_appointment' => $data['slots_per_appointment'] ?? 2,
                'max_appointments_per_day' => $data['max_appointments_per_day'] ?? 12,
                'is_active' => $data['is_active'] ?? true,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Create default weekly schedule if provided
            if (isset($data['schedules']) && is_array($data['schedules'])) {
                foreach ($data['schedules'] as $schedule) {
                    $this->addDoctorSchedule($doctor->id, $schedule);
                }
            }

            return $doctor->fresh();
        });
    }

    /**
     * Update doctor
     */
    public function updateDoctor(int $id, array $data): Doctor
    {
        $doctor = Doctor::findOrFail($id);

        $doctor->update([
            'department_id' => $data['department_id'] ?? $doctor->department_id,
            'first_name' => $data['first_name'] ?? $doctor->first_name,
            'last_name' => $data['last_name'] ?? $doctor->last_name,
            'email' => $data['email'] ?? $doctor->email,
            'phone' => $data['phone'] ?? $doctor->phone,
            'specialization' => $data['specialization'] ?? $doctor->specialization,
            'slots_per_appointment' => $data['slots_per_appointment'] ?? $doctor->slots_per_appointment,
            'max_appointments_per_day' => $data['max_appointments_per_day'] ?? $doctor->max_appointments_per_day,
            'is_active' => $data['is_active'] ?? $doctor->is_active,
            'metadata' => $data['metadata'] ?? $doctor->metadata,
        ]);

        return $doctor->fresh();
    }

    /**
     * Deactivate doctor (soft disable)
     */
    public function deactivateDoctor(int $id): Doctor
    {
        $doctor = Doctor::findOrFail($id);
        $doctor->update(['is_active' => false]);
        return $doctor->fresh();
    }

    /**
     * Activate doctor
     */
    public function activateDoctor(int $id): Doctor
    {
        $doctor = Doctor::findOrFail($id);
        $doctor->update(['is_active' => true]);
        return $doctor->fresh();
    }

    /**
     * Add weekly schedule for a doctor
     */
    public function addDoctorSchedule(int $doctorId, array $scheduleData): DoctorSchedule
    {
        return DoctorSchedule::create([
            'doctor_id' => $doctorId,
            'day_of_week' => $scheduleData['day_of_week'],
            'start_time' => $scheduleData['start_time'],
            'end_time' => $scheduleData['end_time'],
            'is_available' => $scheduleData['is_available'] ?? true,
        ]);
    }

    /**
     * Update doctor's schedule for a specific day
     */
    public function updateDoctorSchedule(int $scheduleId, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::findOrFail($scheduleId);
        $schedule->update($data);
        return $schedule->fresh();
    }

    /**
     * Get doctor's weekly schedule
     */
    public function getDoctorSchedule(int $doctorId): Collection
    {
        return DoctorSchedule::where('doctor_id', $doctorId)
            ->orderBy('day_of_week')
            ->get();
    }

    /**
     * Add a schedule exception (day off or custom hours)
     */
    public function addScheduleException(int $doctorId, array $exceptionData): DoctorScheduleException
    {
        return DoctorScheduleException::create([
            'doctor_id' => $doctorId,
            'date' => $exceptionData['date'],
            'type' => $exceptionData['type'], // 'day_off' or 'custom_hours'
            'start_time' => $exceptionData['start_time'] ?? null,
            'end_time' => $exceptionData['end_time'] ?? null,
            'reason' => $exceptionData['reason'] ?? null,
        ]);
    }

    /**
     * Remove a schedule exception
     */
    public function removeScheduleException(int $exceptionId): bool
    {
        $exception = DoctorScheduleException::findOrFail($exceptionId);
        return $exception->delete();
    }

    /**
     * Get all schedule exceptions for a doctor
     */
    public function getDoctorExceptions(int $doctorId, ?Carbon $startDate = null, ?Carbon $endDate = null): Collection
    {
        $query = DoctorScheduleException::where('doctor_id', $doctorId);

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        return $query->orderBy('date')->get();
    }

    /**
     * Mark a specific date as day off
     */
    public function markDayOff(int $doctorId, Carbon $date, ?string $reason = null): DoctorScheduleException
    {
        return $this->addScheduleException($doctorId, [
            'date' => $date,
            'type' => 'day_off',
            'reason' => $reason,
        ]);
    }

    /**
     * Set custom working hours for a specific date
     */
    public function setCustomHours(
        int $doctorId,
        Carbon $date,
        string $startTime,
        string $endTime,
        ?string $reason = null
    ): DoctorScheduleException {
        // Ensure time format is HH:MM:SS
        if (strlen($startTime) === 5) {
            $startTime .= ':00';
        }
        if (strlen($endTime) === 5) {
            $endTime .= ':00';
        }

        return $this->addScheduleException($doctorId, [
            'date' => $date,
            'type' => 'custom_hours',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'reason' => $reason,
        ]);
    }

    /**
     * Check if doctor is available on a specific date
     */
    public function isAvailableOnDate(int $doctorId, Carbon $date): bool
    {
        // Check if it's a day off
        $isDayOff = DoctorScheduleException::where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('type', 'day_off')
            ->exists();

        if ($isDayOff) {
            return false;
        }

        // Check if there's a custom hours exception
        $customHours = DoctorScheduleException::where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('type', 'custom_hours')
            ->first();

        if ($customHours) {
            return true; // Available with custom hours
        }

        // Check regular weekly schedule
        $schedule = DoctorSchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_available', true)
            ->exists();

        return $schedule;
    }

    /**
     * Get doctors by department
     */
    public function getDoctorsByDepartment(int $departmentId, bool $activeOnly = true): Collection
    {
        $query = Doctor::where('department_id', $departmentId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Search doctors by name or specialization
     */
    public function searchDoctors(string $query, bool $activeOnly = true): Collection
    {
        $searchQuery = Doctor::where(function ($q) use ($query) {
            $q->where('first_name', 'ILIKE', "%{$query}%")
                ->orWhere('last_name', 'ILIKE', "%{$query}%")
                ->orWhere('specialization', 'ILIKE', "%{$query}%");
        });

        if ($activeOnly) {
            $searchQuery->where('is_active', true);
        }

        return $searchQuery->get();
    }
}
