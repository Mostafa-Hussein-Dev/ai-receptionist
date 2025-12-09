<?php
// ============================================================================
// FILE 3: AppointmentController.php
// Location: app/Http/Controllers/Api/AppointmentController.php
// ============================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Business\AppointmentService;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\CancelAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use Illuminate\Http\JsonResponse;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointmentService) {}

    /**
     * @OA\Post(
     *     path="/api/v1/appointments",
     *     summary="Book new appointment",
     *     description="Create a new appointment for a patient with a doctor",
     *     tags={"Appointments"},
     *     security={{"ApiKeyAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"patient_id", "doctor_id", "date", "start_time"},
     *             @OA\Property(property="patient_id", type="integer", example=7, description="Patient ID"),
     *             @OA\Property(property="doctor_id", type="integer", example=3, description="Doctor ID"),
     *             @OA\Property(property="date", type="string", format="date", example="2025-12-09", description="Appointment date"),
     *             @OA\Property(property="start_time", type="string", format="time", example="08:00", description="Start time in HH:MM format"),
     *             @OA\Property(property="slot_count", type="integer", example=2, description="Number of consecutive slots (default: 1)"),
     *             @OA\Property(property="type", type="string", example="general", description="Appointment type (default: general)"),
     *             @OA\Property(property="reason", type="string", example="Annual checkup", description="Reason for appointment")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Appointment created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Appointment booked successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=8),
     *                 @OA\Property(property="patient_id", type="integer", example=7),
     *                 @OA\Property(property="doctor_id", type="integer", example=3),
     *                 @OA\Property(property="date", type="string", format="date", example="2025-12-09"),
     *                 @OA\Property(property="start_time", type="string", format="time", example="08:00:00"),
     *                 @OA\Property(property="end_time", type="string", format="time", example="08:30:00"),
     *                 @OA\Property(property="status", type="string", example="scheduled"),
     *                 @OA\Property(property="type", type="string", example="general")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Booking failed",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * POST /api/v1/appointments
     * Book new appointment
     */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        try {
            $appointment = $this->appointmentService->bookAppointment([
                'patient_id' => $request->patient_id,
                'doctor_id' => $request->doctor_id,
                'date' => $request->date,
                'start_time' => $request->start_time,
                'slot_count' => $request->slot_count ?? 1,
                'type' => $request->type ?? 'general',
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment booked successfully',
                'data' => [
                    'id' => $appointment->id,
                    'patient_id' => $appointment->patient_id,
                    'doctor_id' => $appointment->doctor_id,
                    'date' => $appointment->date->format('Y-m-d'),
                    'start_time' => $appointment->start_time,
                    'end_time' => $appointment->end_time,
                    'status' => $appointment->status,
                    'type' => $appointment->type,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/appointments/{id}",
     *     summary="Get appointment details",
     *     description="Retrieve detailed information about a specific appointment",
     *     tags={"Appointments"},
     *     security={{"ApiKeyAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Appointment ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=8)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=8),
     *                 @OA\Property(
     *                     property="patient",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=7),
     *                     @OA\Property(property="name", type="string", example="Jane Doe")
     *                 ),
     *                 @OA\Property(
     *                     property="doctor",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=3),
     *                     @OA\Property(property="name", type="string", example="John Smith")
     *                 ),
     *                 @OA\Property(property="date", type="string", format="date", example="2025-12-09"),
     *                 @OA\Property(property="start_time", type="string", format="time", example="08:00:00"),
     *                 @OA\Property(property="end_time", type="string", format="time", example="08:30:00"),
     *                 @OA\Property(property="slot_count", type="integer", example=2),
     *                 @OA\Property(property="status", type="string", example="scheduled"),
     *                 @OA\Property(property="type", type="string", example="general"),
     *                 @OA\Property(property="reason", type="string", example="Annual checkup")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Appointment not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * GET /api/v1/appointments/{id}
     * Get appointment details
     */
    public function show(int $id): JsonResponse
    {
        $appointment = $this->appointmentService->getById($id);

        if (!$appointment) {
            return response()->json([
                'success' => false,
                'error' => 'Appointment not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $appointment->id,
                'patient' => [
                    'id' => $appointment->patient->id,
                    'name' => $appointment->patient->first_name . ' ' . $appointment->patient->last_name,
                ],
                'doctor' => [
                    'id' => $appointment->doctor->id,
                    'name' => $appointment->doctor->first_name . ' ' . $appointment->doctor->last_name,
                ],
                'date' => $appointment->date->format('Y-m-d'),
                'start_time' => $appointment->start_time,
                'end_time' => $appointment->end_time,
                'slot_count' => $appointment->slot_count,
                'status' => $appointment->status,
                'type' => $appointment->type,
                'reason' => $appointment->reason,
            ],
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/appointments/{id}/cancel",
     *     summary="Cancel appointment",
     *     description="Cancel an existing appointment",
     *     tags={"Appointments"},
     *     security={{"ApiKeyAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Appointment ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=8)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"cancellation_reason"},
     *             @OA\Property(property="cancellation_reason", type="string", example="Patient requested cancellation", description="Reason for cancellation")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Appointment cancelled successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Appointment cancelled successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=8),
     *                 @OA\Property(property="status", type="string", example="cancelled"),
     *                 @OA\Property(property="cancelled_at", type="string", format="date-time", example="2025-12-05T10:24:12.000000Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Cancellation failed",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * PUT /api/v1/appointments/{id}/cancel
     * Cancel appointment
     */
    public function cancel(int $id, CancelAppointmentRequest $request): JsonResponse
    {
        try {
            $appointment = $this->appointmentService->cancelAppointment(
                $id,
                $request->cancellation_reason
            );

            return response()->json([
                'success' => true,
                'message' => 'Appointment cancelled successfully',
                'data' => [
                    'id' => $appointment->id,
                    'status' => $appointment->status,
                    'cancelled_at' => $appointment->cancelled_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/appointments/{id}/reschedule",
     *     summary="Reschedule appointment",
     *     description="Change the date and time of an existing appointment",
     *     tags={"Appointments"},
     *     security={{"ApiKeyAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Appointment ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=8)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"new_date", "new_start_time"},
     *             @OA\Property(property="new_date", type="string", format="date", example="2025-12-09", description="New appointment date"),
     *             @OA\Property(property="new_start_time", type="string", format="time", example="10:00", description="New start time in HH:MM format")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Appointment rescheduled successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Appointment rescheduled successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=8),
     *                 @OA\Property(property="date", type="string", format="date", example="2025-12-09"),
     *                 @OA\Property(property="start_time", type="string", format="time", example="10:00:00"),
     *                 @OA\Property(property="end_time", type="string", format="time", example="10:30:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Rescheduling failed",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * PUT /api/v1/appointments/{id}/reschedule
     * Reschedule appointment
     */
    public function reschedule(int $id, RescheduleAppointmentRequest $request): JsonResponse
    {
        try {
            $appointment = $this->appointmentService->rescheduleAppointment(
                $id,
                \Carbon\Carbon::parse($request->new_date),
                $request->new_start_time
            );

            return response()->json([
                'success' => true,
                'message' => 'Appointment rescheduled successfully',
                'data' => [
                    'id' => $appointment->id,
                    'date' => $appointment->date->format('Y-m-d'),
                    'start_time' => $appointment->start_time,
                    'end_time' => $appointment->end_time,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
