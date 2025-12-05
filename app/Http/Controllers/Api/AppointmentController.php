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
