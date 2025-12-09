<?php
// ============================================================================
// FILE 1: DoctorController.php
// Location: app/Http/Controllers/Api/DoctorController.php
// ============================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Business\DoctorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function __construct(private DoctorService $doctorService) {}

    /**
     * @OA\Get(
     *     path="/api/v1/doctors",
     *     summary="Get all active doctors",
     *     description="Retrieve a list of all active doctors in the system",
     *     tags={"Doctors"},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=3),
     *                     @OA\Property(property="name", type="string", example="John Smith"),
     *                     @OA\Property(property="specialization", type="string", example="General Practitioner"),
     *                     @OA\Property(property="department", type="string", example="General Medicine"),
     *                     @OA\Property(property="slots_per_appointment", type="integer", example=2)
     *                 )
     *             )
     *         )
     *     )
     * )
     *
     * GET /api/v1/doctors
     * Get all active doctors
     */
    public function index(): JsonResponse
    {
        $doctors = $this->doctorService->getAllDoctors(true);

        return response()->json([
            'success' => true,
            'data' => $doctors->map(fn($doctor) => [
                'id' => $doctor->id,
                'name' => $doctor->first_name . ' ' . $doctor->last_name,
                'specialization' => $doctor->specialization,
                'department' => $doctor->department->name ?? null,
                'slots_per_appointment' => $doctor->slots_per_appointment,
            ]),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/doctors/{id}",
     *     summary="Get doctor details",
     *     description="Retrieve detailed information about a specific doctor",
     *     tags={"Doctors"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Doctor ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=3),
     *                 @OA\Property(property="first_name", type="string", example="John"),
     *                 @OA\Property(property="last_name", type="string", example="Smith"),
     *                 @OA\Property(property="email", type="string", example="dr.smith@hospital.com"),
     *                 @OA\Property(property="phone", type="string", example="+1234567890"),
     *                 @OA\Property(property="specialization", type="string", example="General Practitioner"),
     *                 @OA\Property(property="department", type="string", example="General Medicine"),
     *                 @OA\Property(property="slots_per_appointment", type="integer", example=2),
     *                 @OA\Property(property="max_appointments_per_day", type="integer", example=12),
     *                 @OA\Property(property="is_active", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Doctor not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * GET /api/v1/doctors/{id}
     * Get doctor details
     */
    public function show(int $id): JsonResponse
    {
        $doctor = $this->doctorService->getById($id);

        if (!$doctor) {
            return response()->json([
                'success' => false,
                'error' => 'Doctor not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $doctor->id,
                'first_name' => $doctor->first_name,
                'last_name' => $doctor->last_name,
                'email' => $doctor->email,
                'phone' => $doctor->phone,
                'specialization' => $doctor->specialization,
                'department' => $doctor->department->name ?? null,
                'slots_per_appointment' => $doctor->slots_per_appointment,
                'max_appointments_per_day' => $doctor->max_appointments_per_day,
                'is_active' => $doctor->is_active,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/doctors/{id}/availability",
     *     summary="Check doctor availability",
     *     description="Check if a doctor is available on a specific date",
     *     tags={"Doctors"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Doctor ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         description="Date to check availability (YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date", example="2025-12-09")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Availability checked successfully",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Schema(
     *                     @OA\Property(property="success", type="boolean", example=true),
     *                     @OA\Property(property="available", type="boolean", example=true)
     *                 ),
     *                 @OA\Schema(
     *                     @OA\Property(property="success", type="boolean", example=true),
     *                     @OA\Property(property="available", type="boolean", example=false),
     *                     @OA\Property(property="message", type="string", example="Doctor is not available on this date")
     *                 )
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * GET /api/v1/doctors/{id}/availability
     * Check doctor availability for a date
     */
    public function availability(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date|after_or_equal:today',
        ]);

        $date = \Carbon\Carbon::parse($request->date);

        if (!$this->doctorService->isAvailable($id, $date)) {
            return response()->json([
                'success' => true,
                'available' => false,
                'message' => 'Doctor is not available on this date',
            ]);
        }

        return response()->json([
            'success' => true,
            'available' => true,
        ]);
    }
}
