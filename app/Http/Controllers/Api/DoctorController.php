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
