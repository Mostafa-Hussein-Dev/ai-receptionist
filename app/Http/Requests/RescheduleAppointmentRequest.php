<?php
// ============================================================================
// FILE 3: RescheduleAppointmentRequest.php
// Location: app/Http/Requests/RescheduleAppointmentRequest.php
// ============================================================================

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_date' => 'required|date|after_or_equal:today',
            'new_start_time' => 'required|date_format:H:i',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'new_date.required' => 'New appointment date is required',
            'new_date.after_or_equal' => 'Cannot reschedule to a past date',
            'new_start_time.required' => 'New start time is required',
            'new_start_time.date_format' => 'Start time must be in HH:MM format',
        ];
    }
}
