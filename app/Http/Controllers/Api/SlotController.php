<?php
// ============================================================================
// FILE 2: SlotController.php
// Location: app/Http/Controllers/Api/SlotController.php
// ============================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Business\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlotController extends Controller
{
    public function __construct(private SlotService $slotService) {}

    /**
     * @OA\Get(
     *     path="/api/v1/slots",
     *     summary="Get available time slots",
     *     description="Retrieve available appointment time slots for a specific doctor and date. Supports consecutive slot booking.",
     *     tags={"Slots"},
     *     @OA\Parameter(
     *         name="doctor_id",
     *         in="query",
     *         description="Doctor ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         description="Date for slot availability (YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date", example="2025-12-09")
     *     ),
     *     @OA\Parameter(
     *         name="slot_count",
     *         in="query",
     *         description="Number of consecutive slots needed (1-4)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=4, example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1105),
     *                     @OA\Property(property="date", type="string", format="date", example="2025-12-09"),
     *                     @OA\Property(property="start_time", type="string", format="time", example="08:00:00"),
     *                     @OA\Property(property="end_time", type="string", format="time", example="08:15:00"),
     *                     @OA\Property(property="slot_number", type="integer", example=1),
     *                     @OA\Property(property="status", type="string", example="available")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="doctor_id", type="string", example="3"),
     *                 @OA\Property(property="date", type="string", example="2025-12-09"),
     *                 @OA\Property(property="slot_count_requested", type="integer", example=1),
     *                 @OA\Property(property="total_available", type="integer", example=24)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     *
     * GET /api/v1/slots
     * Get available slots
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'doctor_id' => 'required|exists:doctors,id',
            'date' => 'required|date|after_or_equal:today',
            'slot_count' => 'sometimes|integer|min:1|max:4',
        ]);

        $doctorId = $request->doctor_id;
        $date = \Carbon\Carbon::parse($request->date);
        $slotCount = $request->slot_count ?? 1;

        // Get available slots
        if ($slotCount > 1) {
            $slotGroups = $this->slotService->getAvailableConsecutiveSlots($doctorId, $date, $slotCount);

            // Format consecutive slot groups
            $formattedGroups = $slotGroups->map(fn($group) => [
                'slots' => $group->map(fn($slot) => [
                    'id' => $slot->id,
                    'date' => $slot->date->format('Y-m-d'),
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'slot_number' => $slot->slot_number,
                    'status' => $slot->status,
                ]),
                'start_time' => $group->first()->start_time,
                'end_time' => $group->last()->end_time,
                'total_duration' => ($slotCount * 15) . ' minutes',
            ]);

            return response()->json([
                'success' => true,
                'data' => $formattedGroups,
                'meta' => [
                    'doctor_id' => $doctorId,
                    'date' => $date->format('Y-m-d'),
                    'slot_count_requested' => $slotCount,
                    'total_groups_available' => $formattedGroups->count(),
                ],
            ]);
        } else {
            $slots = $this->slotService->getAvailableSlots($doctorId, $date);

            return response()->json([
                'success' => true,
                'data' => $slots->map(fn($slot) => [
                    'id' => $slot->id,
                    'date' => $slot->date->format('Y-m-d'),
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'slot_number' => $slot->slot_number,
                    'status' => $slot->status,
                ]),
                'meta' => [
                    'doctor_id' => $doctorId,
                    'date' => $date->format('Y-m-d'),
                    'slot_count_requested' => $slotCount,
                    'total_available' => $slots->count(),
                ],
            ]);
        }
    }
}
