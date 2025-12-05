<?php
// ============================================================================
// FILE 4: PatientController.php
// Location: app/Http/Controllers/Api/PatientController.php
// ============================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Business\PatientService;
use App\Http\Requests\LookupPatientRequest;
use Illuminate\Http\JsonResponse;

class PatientController extends Controller
{
    public function __construct(private PatientService $patientService) {}

    /**
     * POST /api/v1/patients/lookup
     * Lookup patient by phone and/or name
     */
    public function lookup(LookupPatientRequest $request): JsonResponse
    {
        if ($request->has('phone') && $request->has('name')) {
            $patient = $this->patientService->lookupByPhoneAndName(
                $request->phone,
                $request->name
            );
        } elseif ($request->has('phone')) {
            $patients = $this->patientService->lookupByPhone($request->phone);
            $patient = $patients->first();
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Either phone or phone+name required',
            ], 400);
        }

        if (!$patient) {
            return response()->json([
                'success' => true,
                'found' => false,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'found' => true,
            'data' => [
                'id' => $patient->id,
                'mrn' => $patient->medical_record_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'phone' => $patient->phone,
                'date_of_birth' => $patient->date_of_birth,
            ],
        ]);
    }
}
