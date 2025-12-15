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
use Illuminate\Support\Facades\Log;
use App\Services\Business\PatientService;
use App\Services\Business\DoctorService;
use App\Services\Business\SlotService;
use App\Services\Business\AppointmentService;
use App\Exceptions\AppointmentException;
use App\Exceptions\SlotException;
use App\Enums\IntentType;


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

            // Step 3: Extract entities
            $entities = $this->extractEntities($userMessage, $session);

            // Step 4: Update collected data (filter out null values to preserve existing data)
            if ($entities->count() > 0) {
                $newData = array_filter($entities->toArray(), fn($value) => $value !== null);
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
                ['collected_data' => $session->collectedData]
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

            //Verify Patient Logic
            if ($nextState === ConversationState::VERIFY_PATIENT->value) {

                $name = $entities->patientName ?? $session->collectedData['patient_name'] ?? null;
                $dob  = $entities->dateOfBirth ?? $session->collectedData['date_of_birth'] ?? null;
                $phone = $entities->phone ?? $session->collectedData['phone'] ?? null;

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

                // CASE 1: User provided a doctor name
                if ($doctorName) {
                    try {
                        // First attempt: search by name
                        $doctors = $this->doctorService->searchDoctors($doctorName);

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

                if (!$doctorId || !$date) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm missing some appointment details. Let's start over - which doctor and date would you prefer?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    $availableSlots = $this->slotService->getAvailableSlots($doctorId, $date);

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

                    // Store slot list for validation
                    $this->sessionManager->updateCollectedData($sessionId, [
                        'available_slots' => $availableSlots->map(fn($s) => [
                            'slot_number' => $s->slot_number,
                            'time' => substr($s->start_time, 0, 5),
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
                        "I'm missing the available time slots. Let me check again - what date would you prefer?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Validate the selected time is available
                $matchedSlot = collect($availableSlots)->first(
                    fn($slot) => $slot['time'] === $selectedTime
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

                // Store selected slot information
                $this->sessionManager->updateCollectedData($sessionId, [
                    'selected_time' => $selectedTime,
                    'slot_number' => $matchedSlot['slot_number'],
                    'slot_count' => 1,
                ]);
            }

            // Booking Confirmation Logic
            if ($nextState === ConversationState::CONFIRM_BOOKING->value) {
                $doctorName = $session->collectedData['doctor_name'] ?? 'the doctor';
                $date = $session->collectedData['date'] ?? null;
                $time = $session->collectedData['selected_time'] ?? null;
                $patientName = $session->collectedData['patient_name'] ?? 'Patient';

                if (!$date || !$time) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm missing some appointment details. Let me gather the information again. Which doctor, date, and time would you prefer?",
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
                $slotNumber = $session->collectedData['slot_number'] ?? null;

                if (!$patientId || !$doctorId || !$date || !$time) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm missing some required information to book the appointment. Let's start over. How can I help you?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    // Book the appointment
                    $appointment = $this->appointmentService->bookAppointment([
                        'patient_id' => $patientId,
                        'doctor_id' => $doctorId,
                        'date' => $date,
                        'start_time' => $time,
                        'slot_number' => $slotNumber,
                        'type' => 'general',
                        'reason' => 'Patient booking via AI receptionist'
                    ]);

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
            $canProceed = $this->dialogueManager->canProceed(
                $nextState,
                array_merge($session->collectedData, $entities->toArray())
            );

            Log::info('[DEBUG] Response Generation', [
                'nextState' => $nextState,
                'session_state' => $session->conversationState,
                'canProceed' => $canProceed,
                'collected_data' => $session->collectedData
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
            ['collected_data' => $session->collectedData]
        );
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
