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
            $slots = $this->slotService->getAvailableConsecutiveSlots($doctorId, $date, $slotCount);
        } else {
            $slots = $this->slotService->getAvailableSlots($doctorId, $date);
        }

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
