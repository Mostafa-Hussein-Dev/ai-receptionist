<?php
// ============================================================================
// FILE 1: StoreAppointmentRequest.php
// Location: app/Http/Requests/StoreAppointmentRequest.php
// ============================================================================

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'doctor_id' => 'required|exists:doctors,id',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'slot_count' => 'sometimes|integer|min:1|max:4',
            'type' => 'sometimes|string|max:50',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'patient_id.required' => 'Patient ID is required',
            'patient_id.exists' => 'Patient not found',
            'doctor_id.required' => 'Doctor ID is required',
            'doctor_id.exists' => 'Doctor not found',
            'date.required' => 'Appointment date is required',
            'date.after_or_equal' => 'Cannot book appointments in the past',
            'start_time.required' => 'Start time is required',
            'start_time.date_format' => 'Start time must be in HH:MM format',
            'slot_count.min' => 'Slot count must be at least 1',
            'slot_count.max' => 'Slot count cannot exceed 4',
        ];
    }
}
