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

            // Step 4: Update collected data
            if ($entities->count() > 0) {
                $this->sessionManager->updateCollectedData($sessionId, $entities->toArray());
            }

            // Step 5: Determine next state
            $nextState = $this->dialogueManager->getNextState(
                $session->conversationState,
                $intent,
                $entities,
                ['collected_data' => $session->collectedData]
            );

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
                }
            }

            //Department Resolution Logic
            if ($nextState === ConversationState::SELECT_DATE->value) {

                $doctorName = $entities->doctorName ?? $session->collectedData['doctor_name'] ?? null;
                $department = $entities->department ?? $session->department ?? null;

                // CASE 1: User provided a doctor name
                if ($doctorName) {

                    // First attempt: search by name
                    $doctors = $this->doctorService->searchDoctors($doctorName);

                    // EXACT MATCH
                    if ($doctors->count() === 1) {
                        $doctor = $doctors->first();

                        $this->sessionManager->update($sessionId, [
                            'doctor_id'  => $doctor->id,
                            'department' => $doctor->department->name ?? null,
                        ]);
                    }

                    // MULTIPLE MATCHES
                    elseif ($doctors->count() > 1) {
                        $names = $doctors->map(fn($d) => "Dr. {$d->last_name}")->implode(', ');
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I found several doctors with that name: {$names}. Which one did you mean?",
                            $session,
                            $intent,
                            $entities
                        );

                    }

                    // NO MATCH
                    else {
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I couldn't find that doctor. Could you repeat the full name or tell me the department they work in?",
                            $session,
                            $intent,
                            $entities
                        );

                    }
                }

                // CASE 2: User provided a department
                elseif ($department) {
                    $doctors = $this->doctorService->getDoctorsByDepartment($department);

                    // EXACTLY ONE DOCTOR
                    if ($doctors->count() === 1) {
                        $doctor = $doctors->first();
                        $this->sessionManager->update($sessionId, [
                            'doctor_id'  => $doctor->id,
                            'department' => $department,
                        ]);
                    }

                    // MULTIPLE DOCTORS IN DEPARTMENT
                    elseif ($doctors->count() > 1) {
                        $names = $doctors->map(fn($d) => "Dr. {$d->last_name}")->implode(', ');
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "We have several doctors in {$department}: {$names}. Which doctor would you like to see?",
                            $session,
                            $intent,
                            $entities
                        );

                    }

                    // NO DOCTORS IN THAT DEPARTMENT
                    else {
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I couldn't find any doctors in {$department}. Could you specify another department or a doctor's name?",
                            $session,
                            $intent,
                            $entities
                        );

                    }
                }

                // CASE 3: No doctor or department given
                else {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "Which doctor or department would you like to book an appointment with?",
                        $session,
                        $intent,
                        $entities
                    );

                }
            }

            //Fetch Real Time Availability
            if ($nextState === ConversationState::SHOW_AVAILABLE_SLOTS->value) {

                $doctorId = $session->collectedData['doctor_id'] ?? null;
                $date     = $session->collectedData['date'] ?? null;

                if (!$doctorId || !$date) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I’m missing some information to check availability. Could you confirm the doctor and date?",
                        $session,
                        $intent,
                        $entities
                    );

                }

                // Fetch available slots
                $availableSlots = $this->slotService->getAvailableSlots($doctorId, \Carbon\Carbon::parse($date));

                // No availability
                if ($availableSlots->isEmpty()) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm sorry, there are no available appointment times on {$date}. Would you like to try a different day?",
                        $session,
                        $intent,
                        $entities
                    );

                }

                // Format slots like: "09:00, 09:15, 09:30"
                $formatted = $availableSlots
                    ->map(fn($slot) => substr($slot->start_time, 0, 5))
                    ->implode(', ');

                // Store slot list so SELECT_SLOT state knows what's valid
                $this->sessionManager->update($sessionId, [
                    'available_slots' => $availableSlots->map(fn($s) => [
                        'slot_number' => $s->slot_number,
                        'time'        => substr($s->start_time, 0, 5),
                    ])->toArray()
                ]);

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    "Here are the available times: {$formatted}. Which time works best for you?",
                    $session,
                    $intent,
                    $entities
                );

            }

            if ($nextState === ConversationState::SELECT_SLOT->value) {

                // 1. Ensure user provided a time
                if (!$entities->has('time')) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I didn’t catch the time you prefer. Which available time works best for you?",
                        $session,
                        $intent,
                        $entities
                    );

                }

                $selectedTime = $entities->time;

                // 2. Get available slots from session
                $availableSlots = $session->context['available_slots']
                    ?? $session->collectedData['available_slots']
                    ?? [];

                if (empty($availableSlots)) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I’m missing the available time slots. Could you repeat the time you prefer?",
                        $session,
                        $intent,
                        $entities
                    );

                }

                // 3. Validate the selected time is one of the offered slots
                $matchedSlot = collect($availableSlots)->first(
                    fn($slot) => $slot['time'] === $selectedTime
                );

                if (!$matchedSlot) {
                    $validTimes = collect($availableSlots)
                        ->pluck('time')
                        ->implode(', ');
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "That time isn’t available. The available options are: {$validTimes}. Which one would you like?",
                        $session,
                        $intent,
                        $entities
                    );

                }

                // 4. Save selected slot information into session
                $this->sessionManager->updateCollectedData($sessionId, [
                    'selected_time'  => $selectedTime,
                    'slot_number'    => $matchedSlot['slot_number'],
                    'slot_count'     => 1, // assuming single-slot for now; configurable later
                ]);

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    "Great, you selected {$selectedTime}. Let me summarize everything before we confirm.",
                    $session,
                    $intent,
                    $entities
                );

            }

            if ($nextState === ConversationState::CONFIRM_BOOKING->value) {

                $doctorId    = $session->collectedData['doctor_id']      ?? null;
                $date        = $session->collectedData['date']           ?? null;
                $time        = $session->collectedData['selected_time']  ?? null;
                $slotCount   = $session->collectedData['slot_count']     ?? 1;
                $patientId   = $session->patientId                       ?? null;

                // Required fields check
                if (!$doctorId || !$date || !$time || !$patientId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm missing some details for the booking summary. Could you confirm the doctor, date, and time again?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Fetch doctor
                $doctor = $this->doctorService->getDoctor($doctorId);
                if (!$doctor) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I couldn't find that doctor in our system. Could you repeat the name?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                $doctorName   = "Dr. {$doctor->first_name} {$doctor->last_name}";
                $department   = $doctor->department->name ?? "Unknown Department";
                $specialty    = $doctor->specialization   ?? null;

                // Fetch patient
                $patient = $this->patientService->getPatient($patientId);
                if (!$patient) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm unable to confirm the patient record. Could you repeat your name?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                $patientName = "{$patient->first_name} {$patient->last_name}";

                // Compute duration based on slot count
                $slotMinutes = (int) config('hospital.slots.duration_minutes', 15);
                $duration    = $slotMinutes * $slotCount;

                // Human readable date
                $dateFormatted = \Carbon\Carbon::parse($date)->format('F j, Y');

                // Build full confirmation summary
                $summary  = "Let me confirm your appointment details:\n";
                $summary .= "• Patient: {$patientName}\n";
                $summary .= "• Doctor: {$doctorName}";
                $summary .= $specialty ? " ({$specialty})\n" : "\n";
                $summary .= "• Department: {$department}\n";
                $summary .= "• Date: {$dateFormatted}\n";
                $summary .= "• Time: {$time}\n";
                $summary .= "• Duration: {$duration} minutes\n\n";
                $summary .= "Should I go ahead and book this appointment?";

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    $summary,
                    $session,
                    $intent,
                    $entities
                );
            }

            if ($nextState === ConversationState::EXECUTE_BOOKING->value) {

                // Hard validation
                $patientId   = $session->patientId ?? null;
                $doctorId    = $session->collectedData['doctor_id']     ?? null;
                $date        = $session->collectedData['date']          ?? null;
                $time        = $session->collectedData['selected_time'] ?? null;
                $slotNumber  = $session->collectedData['slot_number']   ?? null;
                $slotCount   = $session->collectedData['slot_count']    ?? 1;
                $callId      = $session->callId                          ?? null;

                if (!$patientId || !$doctorId || !$date || !$time) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I’m missing required booking details. Let’s review the appointment again.",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    /** @var AppointmentService $appointmentService */
                    $appointmentService = app(AppointmentService::class);

                    $appointment = $appointmentService->bookAppointmentDirect(
                        patientId: $patientId,
                        doctorId: $doctorId,
                        date: \Carbon\Carbon::parse($date),
                        startTime: $time,
                        slotCount: $slotCount,
                        type: 'call_booking',
                        reason: 'Booked via phone assistant',
                        callId: $callId
                    );

                    // Persist appointment reference
                    $this->sessionManager->update($sessionId, [
                        'appointment_id' => $appointment->id,
                        'conversation_state' => ConversationState::CLOSING->value,
                        'collected_data' => array_diff_key(
                            $session->collectedData,
                            array_flip([
                                'available_slots',
                                'slot_number',
                                'slot_count',
                                'selected_time',
                                'date',
                                'doctor_id',
                            ])
                        ),
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "✅ Your appointment has been successfully booked. Is there anything else I can help you with?",
                        $session,
                        $intent,
                        $entities
                    );

                } catch (AppointmentException | SlotException $e) {

                    Log::error('[ConversationOrchestrator] Booking failed', [
                        'session' => $sessionId,
                        'error' => $e->getMessage(),
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I wasn’t able to book the appointment due to availability issues. Would you like to choose a different time or date?",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            if ($nextState === ConversationState::CANCEL_APPOINTMENT->value) {

                $patientId = $session->patientId ?? null;

                if (!$patientId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I need to verify your identity first. Could you please confirm your full name and phone number?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Fetch upcoming appointments
                $appointments = $this->appointmentService
                    ->getUpcomingAppointments($patientId);

                if ($appointments->isEmpty()) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I couldn’t find any upcoming appointments to cancel.",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Only one appointment → confirm cancellation
                if ($appointments->count() === 1) {
                    $appointment = $appointments->first();

                    $this->sessionManager->updateCollectedData($sessionId, [
                        'appointment_id' => $appointment->id
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "You have an appointment on {$appointment->date} at {$appointment->start_time}. Would you like me to cancel it?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Multiple appointments → ask which one
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

            if (
                $session->conversationState === ConversationState::CANCEL_APPOINTMENT->value &&
                $intent->intent === IntentType::CONFIRM->value
            ) {

                $appointmentId = $session->collectedData['appointment_id'] ?? null;

                if (!$appointmentId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I’m missing the appointment details. Could you repeat which appointment you want to cancel?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                // Cancel appointment (releases slots internally)
                $this->appointmentService->cancelAppointment($appointmentId);

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    "✅ Your appointment has been successfully cancelled. Is there anything else I can help you with?",
                    $session,
                    $intent,
                    $entities
                );
            }


            // Step 6: Check if we can proceed
            $canProceed = $this->dialogueManager->canProceed(
                $nextState,
                array_merge($session->collectedData, $entities->toArray())
            );

            // Step 7: Generate response
            $response = $this->generateResponse($nextState, $intent, $entities, $session, $canProceed);

            // Step 8: Update session
            $this->sessionManager->update($sessionId, [
                'conversation_state' => $nextState,
                'intent' => $intent->intent,
                'turn_count' => $turnNumber,
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
                entities: new EntityDTO([]),
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
            entities: $entities ?? new EntityDTO([]),
            conversationState: $session->conversationState ?? 'DETECT_INTENT',
            processingTimeMs: 0,
            timestamp: now()
        );
    }
}
