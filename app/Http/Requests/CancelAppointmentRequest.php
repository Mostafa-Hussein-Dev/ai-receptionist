<?php
// ============================================================================
// FILE 2: CancelAppointmentRequest.php
// Location: app/Http/Requests/CancelAppointmentRequest.php
// ============================================================================

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => 'nullable|string|max:500',
        ];
    }
}
