<?php


namespace App\Orchestrators;

use App\Contracts\IntentParserServiceInterface;
use App\Contracts\EntityExtractorServiceInterface;
use App\Contracts\SessionManagerServiceInterface;
use App\Contracts\DialogueManagerServiceInterface;
use App\DTOs\ConversationTurnDTO;
use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\Enums\ConversationState;
use App\Enums\IntentType;
use Illuminate\Support\Facades\Log;
use App\Services\Business\PatientService;
use App\Services\Business\DoctorService;
use App\Services\Business\SlotService;
use App\Services\Business\AppointmentService;
use Carbon\Carbon;
use App\Exceptions\AppointmentException;
use App\Exceptions\SlotException;


/**
 * Conversation Orchestrator
 *
 * Orchestrates processing of a single conversation turn.
 * Coordinates Intent Parser, Entity Extractor, Session Manager, Dialogue Manager.
 */
class ConversationOrchestrator
{
    public function __construct(
        private IntentParserServiceInterface    $intentParser,
        private EntityExtractorServiceInterface $entityExtractor,
        private SessionManagerServiceInterface  $sessionManager,
        private DialogueManagerServiceInterface $dialogueManager,
        private PatientService                  $patientService,
        private DoctorService                   $doctorService,
        private SlotService                     $slotService,
        private AppointmentService              $appointmentService,



    )
    {
    }

    /**
     * Process one conversation turn
     */
    public function processTurn(string $sessionId, string $userMessage): ConversationTurnDTO
    {
        $startTime = microtime(true);

        try {
            // Step 1: Get session
            $session = $this->sessionManager->get($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session not found: {$sessionId}");
            }

            $turnNumber = $session->turnCount + 1;

            Log::info('[ConversationOrchestrator] Processing turn', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'state' => $session->conversationState,
            ]);

            // Step 2: Parse intent (if not already detected)
            $intent = $this->parseIntent($userMessage, $session);

            // Step 2.5: Check for patient correction patterns in non-patient-collection states
            $patientCorrection = $this->detectPatientCorrection($userMessage, $session->conversationState, $session->collectedData);
            if ($patientCorrection !== null) {
                Log::info('[ConversationOrchestrator] Patient correction detected', [
                    'correction_type' => $patientCorrection['type'],
                    'new_value' => $patientCorrection['value'],
                    'current_state' => $session->conversationState
                ]);

                // Update the patient information
                $this->sessionManager->updateCollectedData($sessionId, [
                    $patientCorrection['type'] => $patientCorrection['value']
                ]);

                // Change intent to PROVIDE_INFO and move to appropriate verification state
                $intent = new IntentDTO(
                    intent: IntentType::PROVIDE_INFO->value,
                    confidence: 0.9,
                    reasoning: 'Patient corrected their information'
                );

                // Determine which verification state to go to based on what was corrected
                $nextState = $this->getPatientVerificationState($patientCorrection['type']);

                // Update session state
                $this->sessionManager->updateState($sessionId, $nextState);

                // Generate response and return early
                $response = $this->dialogueManager->generateResponse($nextState, [
                    'collected_data' => $session->collectedData,
                    'correction_detected' => true,
                    'correction_type' => $patientCorrection['type']
                ]);

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    $response,
                    $session,
                    $intent,
                    new EntityDTO() // Empty entities since we handled them directly
                );
            }

            // Step 3: Extract entities
            $entities = $this->extractEntities($userMessage, $session);

            // Step 4: Update collected data (only save entities relevant to current state)
            if ($entities->count() > 0) {
                $relevantEntities = $this->filterEntitiesForState($entities, $session->conversationState);
                $newData = array_filter($relevantEntities, fn($value) => $value !== null);
                if (!empty($newData)) {
                    $this->sessionManager->updateCollectedData($sessionId, $newData);
                }
            }

            // Re-fetch session after updating data
            $session = $this->sessionManager->get($sessionId);

            // Step 5: Determine next state (now with updated session data)
            Log::info('[DEBUG] getNextState params', [
                'current_state' => $session->conversationState,
                'intent' => $intent->intent,
                'entities_count' => $entities->count(),
                'collected_data' => $session->collectedData
            ]);

            $nextState = $this->dialogueManager->getNextState(
                $session->conversationState,
                $intent,
                $entities,
                ['collected_data' => $session->collectedData, 'user_message' => $userMessage]
            );

            Log::info('[DEBUG] getNextState result', [
                'returned_state' => $nextState
            ]);

            // Update session state IMMEDIATELY
            $this->sessionManager->update($sessionId, [
                'conversation_state' => $nextState,
                'intent' => $intent->intent,
            ]);

            // Re-fetch session with updated state
            $session = $this->sessionManager->get($sessionId);

            // Check if we can auto-advance to the next state if we already have all required data
            if ($this->dialogueManager->canProceed($nextState, $session->collectedData)) {
                // Get the next state after current one
                $autoAdvanceState = $this->dialogueManager->getNextState(
                    $nextState,
                    $intent,
                    $entities,
                    ['collected_data' => $session->collectedData, 'user_message' => $userMessage]
                );

                // Only auto-advance if it's a different state
                if ($autoAdvanceState !== $nextState) {
                    Log::info('[ConversationOrchestrator] Auto-advancing state', [
                        'from_state' => $nextState,
                        'to_state' => $autoAdvanceState,
                        'collected_data' => $session->collectedData
                    ]);

                    $nextState = $autoAdvanceState;
                    $this->sessionManager->update($sessionId, [
                        'conversation_state' => $nextState,
                    ]);
                    $session = $this->sessionManager->get($sessionId);
                }
            }

            //Verify Patient Logic
            if ($nextState === ConversationState::VERIFY_PATIENT->value) {

                $name = $entities->patientName ?? $session->collectedData['patient_name'] ?? null;
                $dob  = $entities->dateOfBirth ?? $session->collectedData['date_of_birth'] ?? null;
                $phone = $entities->phone ?? $session->collectedData['phone'] ?? null;

                Log::info('[ConversationOrchestrator] VERIFY_PATIENT logic', [
                    'name' => $name,
                    'dob' => $dob,
                    'phone' => $phone,
                    'collected_data' => $session->collectedData,
                    'entities_count' => $entities->count()
                ]);

                if ($name && $phone) {

                    // Split name
                    $parts = explode(' ', trim($name), 2);
                    $first = $parts[0] ?? '';
                    $last  = $parts[1] ?? '';

                    try {
                        // Try verifying identity
                        $result = $this->patientService->verifyIdentity($phone, $first, $last);

                        if ($result['verified']) {
                            // Store patient_id in session
                            $this->sessionManager->update($sessionId, [
                                'patient_id' => $result['patient']->id,
                            ]);

                            Log::info('[ConversationOrchestrator] Patient verification successful', [
                                'patient_id' => $result['patient']->id,
                                'patient_name' => $result['patient']->first_name . ' ' . $result['patient']->last_name,
                                'phone_verified' => $phone
                            ]);

                            // Check if this is a correction scenario by comparing with session turn history
                            $isCorrection = $this->isCorrectionScenario($session);
                            $message = $isCorrection
                                ? "Perfect! I've updated your information and verified your record. Which doctor would you like to see, or do you have a preference for a department?"
                                : "Thank you! I've found your record. Which doctor would you like to see, or do you have a preference for a department?";

                            // Return success message and proceed to doctor selection
                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                $message,
                                $session,
                                $intent,
                                $entities
                            );

                        } else {
                            // Create new patient automatically
                            $new = $this->patientService->createPatient([
                                'first_name' => $first,
                                'last_name' => $last,
                                'date_of_birth' => $dob,
                                'phone' => $phone,
                            ]);

                            $this->sessionManager->update($sessionId, [
                                'patient_id' => $new->id,
                            ]);
                        }

                        // Return success message and proceed to doctor selection
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "Thank you! I've verified your information. Which doctor would you like to see, or do you have a preference for a department?",
                            $session,
                            $intent,
                            $entities
                        );

                    } catch (\Exception $e) {
                        Log::error('[ConversationOrchestrator] Patient verification failed', [
                            'error' => $e->getMessage()
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I'm having trouble verifying your information. Let me help you proceed with the appointment. Which doctor would you like to see?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                }
            }

            //Doctor Selection
            if ($nextState === ConversationState::SELECT_DOCTOR->value) {
                $doctorName = $entities->doctorName ?? $session->collectedData['doctor_name'] ?? null;
                $department = $entities->department ?? $session->collectedData['department'] ?? null;

                Log::info('[ConversationOrchestrator] SELECT_DOCTOR business logic starting', [
                    'doctorName' => $doctorName,
                    'department' => $department,
                    'entities_doctorName' => $entities->doctorName,
                    'collected_doctor_name' => $session->collectedData['doctor_name'] ?? null,
                    'collected_department' => $session->collectedData['department'] ?? null
                ]);

                // CASE 1: User provided a doctor name
                if ($doctorName) {
                    Log::info('[ConversationOrchestrator] Searching for doctor', ['doctorName' => $doctorName]);
                    try {
                        // First attempt: search by name
                        $doctors = $this->doctorService->searchDoctors($doctorName);
                        Log::info('[ConversationOrchestrator] Doctor search results', [
                            'search_term' => $doctorName,
                            'results_count' => $doctors->count(),
                            'results' => $doctors->map(fn($d) => "Dr. {$d->first_name} {$d->last_name}")->toArray()
                        ]);

                        // EXACT MATCH
                        if ($doctors->count() === 1) {
                            $doctor = $doctors->first();

                            $this->sessionManager->updateCollectedData($sessionId, [
                                'doctor_id' => $doctor->id,
                                'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                                'department' => $doctor->department->name ?? null,
                            ]);

                            Log::info('[ConversationOrchestrator] Doctor selected', [
                                'doctor_id' => $doctor->id,
                                'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                                'department' => $doctor->department->name ?? null,
                            ]);

                            // Continue with normal flow - doctor successfully selected
                        }

                        // MULTIPLE MATCHES
                        elseif ($doctors->count() > 1) {
                            $names = $doctors->map(fn($d) =>
                                "Dr. {$d->first_name} {$d->last_name}" .
                                ($d->department ? " ({$d->department->name})" : "")
                            )->implode(', ');

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "I found several doctors with that name: {$names}. Which one would you prefer?",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                        // NO MATCH - offer alternatives
                        else {
                            Log::info('[ConversationOrchestrator] No doctor found', [
                                'search_term' => $doctorName,
                                'search_type' => 'by_name'
                            ]);
                            // Try to suggest similar names or departments
                            $availableDepartments = $this->doctorService->getAvailableDepartments()
                                ->pluck('name')
                                ->take(5)
                                ->implode(', ');

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "I couldn't find a doctor with that name. Could you try the full name or tell me which department you prefer? We have: {$availableDepartments}.",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                    } catch (\Exception $e) {
                        Log::error('[ConversationOrchestrator] Doctor search failed', [
                            'doctor_name' => $doctorName,
                            'error' => $e->getMessage()
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I'm having trouble searching for doctors right now. Could you tell me which department you'd prefer instead?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                }

                // CASE 2: User provided department
                elseif ($department) {
                    try {
                        $doctors = $this->doctorService->getDoctorsByDepartmentName($department);

                        if ($doctors->isEmpty()) {
                            // Suggest alternative departments
                            $availableDepartments = $this->doctorService->getAvailableDepartments()
                                ->pluck('name')
                                ->take(5)
                                ->implode(', ');

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "I couldn't find any doctors in {$department}. Available departments include: {$availableDepartments}. Which would you prefer?",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                        // Single doctor in department
                        if ($doctors->count() === 1) {
                            $doctor = $doctors->first();
                            $this->sessionManager->updateCollectedData($sessionId, [
                                'doctor_id' => $doctor->id,
                                'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                                'department' => $department,
                            ]);

                            Log::info('[ConversationOrchestrator] Doctor selected by department', [
                                'doctor_id' => $doctor->id,
                                'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                                'department' => $department,
                            ]);

                            // Continue with normal flow
                        }

                        // Multiple doctors in department
                        else {
                            $names = $doctors->map(fn($d) => "Dr. {$d->first_name} {$d->last_name}")->implode(', ');
                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "We have several doctors in {$department}: {$names}. Which one would you prefer?",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                    } catch (\Exception $e) {
                        Log::error('[ConversationOrchestrator] Department search failed', [
                            'department' => $department,
                            'error' => $e->getMessage()
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I'm having trouble finding doctors in that department. Could you provide a specific doctor's name instead?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                }

                // CASE 3: No doctor or department provided yet
                else {
                    // User hasn't specified doctor or department yet - ask them to provide one
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "Which doctor would you like to see, or do you have a preference for a department?",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Show Available Slots Logic
            if ($nextState === ConversationState::SHOW_AVAILABLE_SLOTS->value) {
                $doctorId = $session->collectedData['doctor_id'] ?? null;
                $date = $session->collectedData['date'] ?? null;

                Log::info('[ConversationOrchestrator] SHOW_AVAILABLE_SLOTS called', [
                    'doctor_id' => $doctorId,
                    'date' => $date,
                    'collected_data' => $session->collectedData
                ]);

                if (!$doctorId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I need to confirm which doctor you'd like to see. Which doctor would you prefer?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                if (!$date) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "What date would you like for your appointment?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    // Convert string date to Carbon object
                    $carbonDate = is_string($date) ? Carbon::parse($date) : $date;
                    $availableSlots = $this->slotService->getAvailableSlots($doctorId, $carbonDate);

                    if ($availableSlots->isEmpty()) {
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I'm sorry, there are no available appointments on {$date}. Would you like to try a different day?",
                            $session,
                            $intent,
                            $entities
                        );
                    }

                    // Format slots like: "09:00, 10:30, 14:00"
                    $formatted = $availableSlots
                        ->map(fn($slot) => substr($slot->start_time, 0, 5))
                        ->implode(', ');

                    // Store slot list for validation with database IDs
                    $this->sessionManager->updateCollectedData($sessionId, [
                        'available_slots' => $availableSlots->map(fn($s) => [
                            'id' => $s->id,  // Database slot ID
                            'slot_number' => $s->slot_number,
                            'time' => substr($s->start_time, 0, 5),
                            'start_time' => $s->start_time,
                            'end_time' => $s->end_time,
                        ])->toArray()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "Here are the available times for {$date}: {$formatted}. Which time works best for you?",
                        $session,
                        $intent,
                        $entities
                    );

                } catch (\Exception $e) {
                    Log::error('[ConversationOrchestrator] Slot retrieval failed', [
                        'error' => $e->getMessage()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm having trouble checking available times. Would you like to try a different date?",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Slot Selection Logic
            if ($nextState === ConversationState::SELECT_SLOT->value) {
                $selectedTime = $entities->time ?? null;
                $availableSlots = $session->collectedData['available_slots'] ?? [];

                Log::info('[ConversationOrchestrator] SELECT_SLOT business logic starting', [
                    'selectedTime' => $selectedTime,
                    'entities_time' => $entities->time,
                    'available_slots_count' => count($availableSlots),
                    'session_time' => $session->collectedData['time'] ?? null
                ]);

                if (!$selectedTime) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I didn't catch the time you prefer. Which of the available times works best for you?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                if (empty($availableSlots)) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I need to refresh the available time slots. Let me check what's available for your appointment date.",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Normalize the selected time format to match slot times
                $normalizedTime = $this->normalizeTimeFormat($selectedTime);

                Log::info('[ConversationOrchestrator] Time matching', [
                    'selected_time' => $selectedTime,
                    'normalized_time' => $normalizedTime,
                    'available_slots_times' => collect($availableSlots)->pluck('time')->toArray()
                ]);

                // Validate the selected time is available
                $matchedSlot = collect($availableSlots)->first(
                    fn($slot) => $slot['time'] === $normalizedTime
                );

                if (!$matchedSlot) {
                    $validTimes = collect($availableSlots)->pluck('time')->implode(', ');
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "That time isn't available. The available times are: {$validTimes}. Which would you prefer?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Get doctor's slots_per_appointment requirement
                $doctor = $this->doctorService->getDoctor($session->collectedData['doctor_id']);
                $slotsRequired = (int) ($doctor->slots_per_appointment ?? 1); // Ensure integer

                Log::info('[ConversationOrchestrator] Slot calculation', [
                    'doctor_id' => $session->collectedData['doctor_id'],
                    'slots_required_raw' => $doctor->slots_per_appointment ?? 'default',
                    'slots_required_cast' => $slotsRequired,
                    'matched_slot' => $matchedSlot
                ]);

                // Calculate the actual appointment end time considering multiple slots
                $appointmentStartTime = \Carbon\Carbon::parse($matchedSlot['start_time']);
                $endTime = $appointmentStartTime->copy()->addMinutes($slotsRequired * 15); // Each slot is 15 minutes

                // Store selected slot information with database ID and calculated end time
                $this->sessionManager->updateCollectedData($sessionId, [
                    'selected_time' => $selectedTime,
                    'slot_id' => $matchedSlot['id'],  // Database slot ID
                    'slot_number' => $matchedSlot['slot_number'],
                    'slot_count' => $slotsRequired,
                    'start_time' => $matchedSlot['start_time'],
                    'end_time' => $endTime->format('H:i:s'),
                ]);
            }

            // Booking Confirmation Logic
            if ($nextState === ConversationState::CONFIRM_BOOKING->value) {
                $doctorName = $session->collectedData['doctor_name'] ?? 'the doctor';
                $date = $session->collectedData['date'] ?? null;
                $time = $session->collectedData['selected_time'] ?? null;
                $patientName = $session->collectedData['patient_name'] ?? 'Patient';

                if (!$date) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "What date would you like for your appointment?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                if (!$time) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "What time would you prefer for your appointment?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    "Let me confirm your appointment: {$patientName} with {$doctorName} on {$date} at {$time}. Is this correct? Please say 'yes' to confirm or 'no' to make changes.",
                    $session,
                    $intent,
                    $entities
                );
            }

            // Execute Booking Logic
            if ($nextState === ConversationState::EXECUTE_BOOKING->value) {
                $patientId = $session->patientId ?? null;
                $doctorId = $session->collectedData['doctor_id'] ?? null;
                $date = $session->collectedData['date'] ?? null;
                $time = $session->collectedData['selected_time'] ?? null;
                $slotId = $session->collectedData['slot_id'] ?? null;
                $slotCount = $session->collectedData['slot_count'] ?? 1;
                $appointmentStartTime = $session->collectedData['start_time'] ?? $time;

                Log::info('[ConversationOrchestrator] EXECUTE_BOOKING validation', [
                    'patientId' => $patientId,
                    'doctor_id' => $doctorId,
                    'date' => $date,
                    'time' => $time,
                    'slot_id' => $slotId,
                    'slot_count' => $slotCount,
                    'start_time' => $startTime,
                    'collected_data' => $session->collectedData
                ]);

                if (!$patientId || !$doctorId || !$date || !$time || !$slotId) {
                    Log::error('[ConversationOrchestrator] EXECUTE_BOOKING missing data', [
                        'missing_patientId' => !$patientId,
                        'missing_doctorId' => !$doctorId,
                        'missing_date' => !$date,
                        'missing_time' => !$time,
                        'missing_slotId' => !$slotId
                    ]);
                    // Give specific error message about what's missing
                    $missingParts = [];
                    if (!$patientId) $missingParts[] = "patient verification";
                    if (!$doctorId) $missingParts[] = "doctor selection";
                    if (!$date) $missingParts[] = "appointment date";
                    if (!$time) $missingParts[] = "appointment time";

                    $errorMessage = "I need to complete a few steps before booking: " . implode(', ', $missingParts) . ". Let me help you with this.";

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        $errorMessage,
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    // Book the appointment with proper slot data
                    $appointment = $this->appointmentService->bookAppointment([
                        'patient_id' => $patientId,
                        'doctor_id' => $doctorId,
                        'date' => $date,
                        'start_time' => $appointmentStartTime,
                        'slot_id' => $slotId,
                        'slot_count' => $slotCount,
                        'type' => 'general',
                        'reason' => 'Patient booking via AI receptionist'
                    ]);

                    // Clean up by removing the large available_slots array after successful booking
                    $this->sessionManager->removeCollectedData($sessionId, ['available_slots']);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "✅ Perfect! Your appointment has been successfully booked for {$date} at {$time}. You'll receive a confirmation. Is there anything else I can help you with?",
                        $session,
                        $intent,
                        $entities
                    );

                } catch (AppointmentException $e) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm sorry, that appointment time is no longer available. Would you like me to check for other available times?",
                        $session,
                        $intent,
                        $entities
                    );
                } catch (\Exception $e) {
                    Log::error('[ConversationOrchestrator] Booking failed', [
                        'error' => $e->getMessage()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm having trouble completing your booking right now. Would you like to try again or speak with our staff?",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Appointment Cancellation Logic
            if ($nextState === ConversationState::CANCEL_APPOINTMENT->value) {
                $patientId = $session->patientId ?? null;

                if (!$patientId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I need to verify your identity first. Could you please provide your full name and phone number?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    $appointments = $this->appointmentService->getUpcomingAppointments($patientId);

                    if ($appointments->isEmpty()) {
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I couldn't find any upcoming appointments to cancel. Is there anything else I can help you with?",
                            $session,
                            $intent,
                            $entities
                        );
                    }

                    if ($appointments->count() === 1) {
                        $appointment = $appointments->first();
                        $this->sessionManager->updateCollectedData($sessionId, [
                            'appointment_id' => $appointment->id
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "You have an appointment on {$appointment->date} at {$appointment->start_time}. Would you like me to cancel it? Please say 'yes' to confirm.",
                            $session,
                            $intent,
                            $entities
                        );
                    } else {
                        $options = $appointments
                            ->map(fn($a) => "{$a->date} at {$a->start_time}")
                            ->implode(', ');

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "You have multiple upcoming appointments: {$options}. Which one would you like to cancel?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                } catch (\Exception $e) {
                    Log::error('[ConversationOrchestrator] Appointment lookup failed', [
                        'error' => $e->getMessage()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm having trouble looking up your appointments. Could you provide more details about which appointment you'd like to cancel?",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Confirm Cancellation Logic
            if (
                $session->conversationState === ConversationState::CANCEL_APPOINTMENT->value &&
                $intent->intent === IntentType::CONFIRM->value
            ) {
                $appointmentId = $session->collectedData['appointment_id'] ?? null;

                if (!$appointmentId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm missing the appointment details. Could you repeat which appointment you want to cancel?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    $this->appointmentService->cancelAppointment($appointmentId);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "✅ Your appointment has been successfully cancelled. Is there anything else I can help you with?",
                        $session,
                        $intent,
                        $entities
                    );
                } catch (\Exception $e) {
                    Log::error('[ConversationOrchestrator] Cancellation failed', [
                        'error' => $e->getMessage()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm having trouble cancelling your appointment. Please contact our office directly for assistance.",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Re-fetch session again before final processing (in case business logic updated it)
            $session = $this->sessionManager->get($sessionId);

            // Step 6: Check if we can proceed
            $mergedData = array_merge($session->collectedData, $entities->toArray());
            $canProceed = $this->dialogueManager->canProceed($nextState, $mergedData);

            Log::info('[DEBUG] canProceed check', [
                'nextState' => $nextState,
                'merged_data' => $mergedData,
                'canProceed' => $canProceed,
                'required_entities' => $this->dialogueManager->getRequiredEntities($nextState)
            ]);

            Log::info('[DEBUG] Response Generation', [
                'nextState' => $nextState,
                'session_state' => $session->conversationState,
                'canProceed' => $canProceed,
                'collected_data' => $session->collectedData,
                'user_message' => $userMessage,
                'intent' => $intent->intent
            ]);

            // Step 7: Generate response
            $response = $this->generateResponse($nextState, $intent, $entities, $session, $canProceed);

            Log::info('[DEBUG] Generated Response', [
                'response' => $response,
                'state_used' => $nextState
            ]);

            $this->sessionManager->addMessage($sessionId, [
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => now()->toISOString(),
            ]);

            $this->sessionManager->addMessage($sessionId, [
                'role' => 'assistant',
                'content' => $response,
                'timestamp' => now()->toISOString(),
            ]);

            // Calculate processing time
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Create turn DTO
            $turn = new ConversationTurnDTO(
                turnNumber: $turnNumber,
                userMessage: $userMessage,
                systemResponse: $response,
                intent: $intent,
                entities: $entities,
                conversationState: $nextState,
                processingTimeMs: (int)$processingTime,
                timestamp: now()
            );

            Log::info('[ConversationOrchestrator] Turn processed', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'intent' => $intent->intent,
                'entities' => $entities->count(),
                'next_state' => $nextState,
                'processing_time_ms' => (int)$processingTime,
            ]);

            return $turn;

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Turn processing failed', [
                'session' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error turn
            $processingTime = (microtime(true) - $startTime) * 1000;

            return new ConversationTurnDTO(
                turnNumber: ($session->turnCount ?? 0) + 1,
                userMessage: $userMessage,
                systemResponse: "I'm sorry, I encountered an error. Could you please try again?",
                intent: new IntentDTO('UNKNOWN', 0.0, 'Error occurred'),
                entities: EntityDTO::fromArray([]),
                conversationState: $session->conversationState ?? ConversationState::DETECT_INTENT->value,
                processingTimeMs: (int)$processingTime,
                timestamp: now()
            );
        }
    }

    /**
     * Parse intent
     */
    private function parseIntent(string $userMessage, $session): IntentDTO
    {
        // If already in a specific flow, intent might be implied
        if ($session->intent && $this->isInFlow($session->conversationState)) {
            Log::debug('[ConversationOrchestrator] Using existing intent', [
                'intent' => $session->intent,
            ]);

            return new IntentDTO($session->intent, 0.95, 'Flow continuation');
        }

        // Parse intent from message
        return $this->intentParser->parseWithHistory(
            $userMessage,
            $session->conversationHistory,
            ['state' => $session->conversationState]
        );
    }

    /**
     * Extract entities
     */
    private function extractEntities(string $userMessage, $session): EntityDTO
    {
        return $this->entityExtractor->extractWithState(
            $userMessage,
            $session->conversationState,
            [
                'collected_data' => $session->collectedData,
                'conversation_state' => $session->conversationState
            ]
        );
    }

    /**
     * Check if this is a correction scenario by analyzing conversation history
     */
    private function isCorrectionScenario($session): bool
    {
        // Simple heuristic: if we have patient_id already set but we're back in VERIFY_PATIENT,
        // it's likely a correction scenario
        return !empty($session->patientId) && $session->conversationState === ConversationState::VERIFY_PATIENT->value;
    }

    /**
     * Detect patient correction patterns in user message
     */
    private function detectPatientCorrection(string $message, string $currentState, array $collectedData): ?array
    {
        // Only check for corrections in non-patient-collection states
        $nonPatientStates = ['SELECT_DOCTOR', 'SELECT_DATE', 'SHOW_AVAILABLE_SLOTS', 'SELECT_SLOT', 'CONFIRM_BOOKING'];
        if (!in_array($currentState, $nonPatientStates)) {
            return null;
        }

        $messageLower = strtolower($message);

        // Patient correction patterns
        $correctionPatterns = [
            'name' => [
                'my name is',
                'name is',
                'actually my name',
                'my real name',
                'call me',
                'wrong name',
                'correct name',
                'my name\'s'
            ],
            'dob' => [
                'my date of birth',
                'my birthday',
                'my dob',
                'actually my birthday',
                'wrong birthday',
                'correct dob'
            ],
            'phone' => [
                'my phone',
                'my number',
                'my phone number',
                'actually my phone',
                'wrong phone',
                'correct phone'
            ]
        ];

        foreach ($correctionPatterns as $field => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($messageLower, $pattern) !== false) {
                    // Extract the new value using regex
                    $newValue = $this->extractCorrectedValue($message, $field);
                    if ($newValue !== null) {
                        return [
                            'type' => "patient_{$field}" === 'patient_name' ? 'patient_name' :
                                      ("patient_{$field}" === 'patient_dob' ? 'date_of_birth' : 'phone'),
                            'field' => $field,
                            'value' => $newValue
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract corrected value from user message
     */
    private function extractCorrectedValue(string $message, string $field): ?string
    {
        // Use regex patterns to extract the corrected value
        switch ($field) {
            case 'name':
                // Look for name patterns: "My name is John Smith", "Call me John", etc.
                if (preg_match('/(?:my name is|name is|call me|actually.*?name.*?is|name\'s)\s+([A-Za-z\s]{2,50})/i', $message, $matches)) {
                    return trim($matches[1]);
                }
                break;

            case 'dob':
                // Look for DOB patterns: "My birthday is Jan 1 2001", "DOB: 2001-01-01", etc.
                if (preg_match('/(?:birthday|dob|date of birth)[^\d]*(\d{1,2}[\/\-\s]\d{1,2}[\/\-\s]\d{2,4}|\d{4}[\/\-\s]\d{1,2}[\/\-\s]\d{1,2})/i', $message, $matches)) {
                    return $this->normalizeDate($matches[1]);
                }
                break;

            case 'phone':
                // Look for phone patterns: "My phone is 123456789", "Number: +961123456"
                if (preg_match('/(?:phone|number|contact)[^\d+]*(\+?\d{8,15})/i', $message, $matches)) {
                    return $this->normalizePhone($matches[1]);
                }
                break;
        }

        return null;
    }

    /**
     * Normalize date to YYYY-MM-DD format
     */
    private function normalizeDate(string $date): string
    {
        try {
            $carbon = \Carbon\Carbon::parse($date);
            return $carbon->format('Y-m-d');
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }

    /**
     * Normalize phone number to E.164 format
     */
    private function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/[^\d]/', '', $phone);

        // Add Lebanon country code if missing and number is 8 digits
        if (strlen($phone) === 8 && !str_starts_with($phone, '961')) {
            $phone = '961' . $phone;
        }

        // Add + prefix if missing
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Normalize time format to match slot times (HH:MM format)
     */
    private function normalizeTimeFormat(string $time): string
    {
        try {
            // Parse various time formats and convert to HH:MM
            $carbon = \Carbon\Carbon::parse($time);
            return $carbon->format('H:i');
        } catch (\Exception $e) {
            // If parsing fails, try to clean the string
            $cleaned = strtolower(trim($time));

            // Remove common words and normalize
            $cleaned = str_replace(['am', 'pm', 'a.m.', 'p.m.'], '', $cleaned);
            $cleaned = trim($cleaned);

            // Try parsing again
            try {
                $carbon = \Carbon\Carbon::parse($cleaned);
                return $carbon->format('H:i');
            } catch (\Exception $e2) {
                // Return original if all parsing fails
                return $time;
            }
        }
    }

    /**
     * Get appropriate verification state for patient correction
     */
    private function getPatientVerificationState(string $correctionType): string
    {
        return match($correctionType) {
            'patient_name' => \App\Enums\ConversationState::VERIFY_PATIENT->value,
            'date_of_birth' => \App\Enums\ConversationState::VERIFY_PATIENT->value,
            'phone' => \App\Enums\ConversationState::VERIFY_PATIENT->value,
            default => \App\Enums\ConversationState::VERIFY_PATIENT->value,
        };
    }

    /**
     * Filter entities based on conversation state
     */
    private function filterEntitiesForState(EntityDTO $entities, string $state): array
    {
        $allowedEntities = $this->getAllowedEntitiesForState($state);
        $entityArray = $entities->toArray();

        $filtered = [];
        foreach ($entityArray as $key => $value) {
            if ($value !== null && in_array($key, $allowedEntities)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Get allowed entities for each state
     */
    private function getAllowedEntitiesForState(string $state): array
    {
        return match($state) {
            'GREETING' => [],
            'DETECT_INTENT' => ['patient_name', 'date', 'time', 'phone', 'date_of_birth', 'doctor_name', 'department'],
            'COLLECT_PATIENT_NAME' => ['patient_name'],
            'COLLECT_PATIENT_DOB' => ['date_of_birth'],
            'COLLECT_PATIENT_PHONE' => ['phone'],
            'VERIFY_PATIENT' => [],
            'SELECT_DOCTOR' => ['doctor_name', 'department'],
            'SELECT_DATE' => ['date'],
            'SHOW_AVAILABLE_SLOTS' => ['time'],
            'SELECT_SLOT' => ['time'],
            'CONFIRM_BOOKING' => [],
            'EXECUTE_BOOKING' => [],
            'CLOSING' => [],
            'GENERAL_INQUIRY' => ['patient_name', 'date', 'time', 'phone', 'date_of_birth', 'doctor_name', 'department'],
            'CANCEL_APPOINTMENT' => ['patient_name', 'date', 'time', 'phone', 'doctor_name'],
            'RESCHEDULE_APPOINTMENT' => ['patient_name', 'date', 'time', 'phone', 'doctor_name'],
            default => ['patient_name', 'date', 'time', 'phone', 'date_of_birth', 'doctor_name', 'department'],
        };
    }

    /**
     * Generate response
     */
    private function generateResponse(
        string    $nextState,
        IntentDTO $intent,
        EntityDTO $entities,
                  $session,
        bool      $canProceed
    ): string
    {
        // Check if we're missing required entities
        if (!$canProceed) {
            $required = $this->dialogueManager->getRequiredEntities($nextState);
            $collected = array_merge($session->collectedData, $entities->toArray());
            $missing = array_diff($required, array_keys(array_filter($collected)));

            if (!empty($missing)) {
                return $this->dialogueManager->generatePromptForMissingEntities($missing, $nextState);
            }
        }

        // Generate normal response
        return $this->dialogueManager->generateResponse($nextState, [
            'intent' => $intent,
            'entities' => $entities->toArray(),
            'collected_data' => $session->collectedData,
        ]);
    }

    /**
     * Check if in active flow
     */
    private function isInFlow(string $state): bool
    {
        $flowStates = [
            ConversationState::COLLECT_PATIENT_NAME->value,
            ConversationState::COLLECT_PATIENT_DOB->value,
            ConversationState::COLLECT_PATIENT_PHONE->value,
            ConversationState::SELECT_DOCTOR->value,
            ConversationState::SELECT_DATE->value,
            ConversationState::SELECT_SLOT->value,
            ConversationState::CONFIRM_BOOKING->value,
        ];

        return in_array($state, $flowStates);
    }

    private function earlyTurnDTO(
        string $sessionId,
        string $userMessage,
        string $assistantMessage,
               $session,
        ?IntentDTO $intent = null,
        ?EntityDTO $entities = null
    ): ConversationTurnDTO {

        $turnNumber = ($session->turnCount ?? 0) + 1;

        return new ConversationTurnDTO(
            turnNumber: $turnNumber,
            userMessage: $userMessage,
            systemResponse: $assistantMessage,
            intent: $intent ?? new IntentDTO('UNKNOWN', 0.0, 'Early return'),
            entities: $entities ?? EntityDTO::fromArray([]),
            conversationState: $session->conversationState ?? 'DETECT_INTENT',
            processingTimeMs: 0,
            timestamp: now()
        );
    }
}
