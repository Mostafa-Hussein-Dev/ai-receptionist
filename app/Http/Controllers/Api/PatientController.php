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
     * @OA\Post(
     *     path="/api/v1/patients/lookup",
     *     summary="Lookup patient by phone or name",
     *     description="Search for existing patient records by phone number and/or name",
     *     tags={"Patients"},
     *     security={{"ApiKeyAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"phone"},
     *             @OA\Property(property="phone", type="string", example="+1987654321", description="Patient phone number"),
     *             @OA\Property(property="name", type="string", example="Jane Doe", description="Patient full name (optional, for verification)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lookup completed (patient may or may not be found)",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="found", type="boolean", example=true, description="True if patient found, false otherwise"),
     *             @OA\Property(
     *                 property="data",
     *                 oneOf={
     *                     @OA\Schema(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=7),
     *                         @OA\Property(property="mrn", type="string", example="MRN000007"),
     *                         @OA\Property(property="first_name", type="string", example="Jane"),
     *                         @OA\Property(property="last_name", type="string", example="Doe"),
     *                         @OA\Property(property="phone", type="string", example="+1987654321"),
     *                         @OA\Property(property="date_of_birth", type="string", format="date", example="1990-05-15")
     *                     ),
     *                     @OA\Schema(type="null")
     *                 },
     *                 description="Patient data if found, null otherwise"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
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
