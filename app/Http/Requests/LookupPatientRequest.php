<?php
// ============================================================================
// FILE 4: LookupPatientRequest.php
// Location: app/Http/Requests/LookupPatientRequest.php
// ============================================================================

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LookupPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => 'required_without:name|string|regex:/^\+?[1-9]\d{1,14}$/',
            'name' => 'sometimes|string|min:2|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required_without' => 'Phone number is required',
            'phone.regex' => 'Invalid phone number format',
            'name.min' => 'Name must be at least 2 characters',
        ];
    }
}
