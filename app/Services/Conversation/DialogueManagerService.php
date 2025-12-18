<?php


namespace App\Services\Conversation;

use App\Contracts\DialogueManagerServiceInterface;
use App\Contracts\LLMServiceInterface;
use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\DTOs\SessionDTO;
use App\DTOs\StructuredAIResponseDTO;
use App\Enums\ConversationState;
use App\Enums\IntentType;
use Illuminate\Support\Facades\Log;
use App\Services\Business\DoctorService;
use App\Services\Business\AppointmentService;
use App\Services\Business\SlotService;
use App\Services\Conversation\SessionManagerServiceInterface;
use Carbon\Carbon;


/**
 * Dialogue Manager Service
 *
 * Manages conversation flow and state transitions.
 * Determines what to ask next and generates responses.
 */
class DialogueManagerService implements DialogueManagerServiceInterface
{
    private ?LLMServiceInterface $llm;
    private bool $useLLM;
    private string $hospitalName;

    public function __construct(
        private DoctorService $doctorService,
        private SlotService $slotService,
        private AppointmentService $appointmentService,
        ?LLMServiceInterface $llm = null,
    ) {
        $this->llm = $llm;
        $this->useLLM = config('ai.response.use_llm', true) && $llm !== null;
        $this->hospitalName = config('hospital.name', 'Our Hospital');
    }

    /**
     * Get next conversation state
     */
    public function getNextState(
        string    $currentState,
        IntentDTO $intent,
        EntityDTO $entities,
        array     $context = []
    ): string
    {
        $state = ConversationState::from($currentState);

        // State machine logic
        switch ($state) {
            case ConversationState::GREETING:
                return ConversationState::DETECT_INTENT->value;

            case ConversationState::DETECT_INTENT:
                // Handle PROVIDE_INFO intent by checking what data we've collected
                if ($intent->intent === 'PROVIDE_INFO') {
                    $collectedData = $context['collected_data'] ?? [];

                    // No data yet - start booking flow
                    if (empty($collectedData)) {
                        return ConversationState::BOOK_APPOINTMENT->value;
                    }

                    // Check what's missing and go to appropriate collection state
                    if (!isset($collectedData['patient_name'])) {
                        return ConversationState::COLLECT_PATIENT_NAME->value;
                    }
                    if (!isset($collectedData['date_of_birth'])) {
                        return ConversationState::COLLECT_PATIENT_DOB->value;
                    }
                    if (!isset($collectedData['phone'])) {
                        return ConversationState::COLLECT_PATIENT_PHONE->value;
                    }

                    // All basic info collected - move to verification
                    return ConversationState::VERIFY_PATIENT->value;
                }

                // For other intents, use the routing logic
                return $this->routeFromIntent($intent);

            case ConversationState::BOOK_APPOINTMENT:
                return ConversationState::COLLECT_PATIENT_NAME->value;

            case ConversationState::COLLECT_PATIENT_NAME:
                $collectedData = $context['collected_data'] ?? [];
                if ($entities->has('patient_name') || isset($collectedData['patient_name'])) {
                    return ConversationState::COLLECT_PATIENT_DOB->value;
                }
                return ConversationState::COLLECT_PATIENT_NAME->value;

            case ConversationState::COLLECT_PATIENT_DOB:
                $collectedData = $context['collected_data'] ?? [];
                if ($entities->has('date_of_birth') || isset($collectedData['date_of_birth'])) {
                    return ConversationState::COLLECT_PATIENT_PHONE->value;
                }
                return ConversationState::COLLECT_PATIENT_DOB->value;

            case ConversationState::COLLECT_PATIENT_PHONE:
                $collectedData = $context['collected_data'] ?? [];
                if ($entities->has('phone') || isset($collectedData['phone'])) {
                    return ConversationState::VERIFY_PATIENT->value;
                }
                return ConversationState::COLLECT_PATIENT_PHONE->value;

            case ConversationState::VERIFY_PATIENT:
                return ConversationState::SELECT_DOCTOR->value;

            case ConversationState::SELECT_DOCTOR:
                $collectedData = $context['collected_data'] ?? [];

                // If we have doctor_id, proceed to SELECT_DATE
                if (isset($collectedData['doctor_id']) && !empty($collectedData['doctor_id'])) {
                    return ConversationState::SELECT_DATE->value;
                }

                // If we have doctor_name, validate it and store doctor_id
                if (isset($collectedData['doctor_name']) && !empty($collectedData['doctor_name'])) {
                    try {
                        $doctors = $this->doctorService->searchDoctors($collectedData['doctor_name']);

                        if ($doctors->count() === 1) {
                            // Exact match - store doctor_id in session and proceed
                            $doctor = $doctors->first();

                            if (isset($context['session_id'])) {
                                $sessionManager = app(\App\Services\Conversation\SessionManagerServiceInterface::class);
                                $sessionManager->updateCollectedData($context['session_id'], [
                                    'doctor_id' => $doctor->id
                                ]);
                            }

                            Log::info('[DialogueManager] Doctor validated and doctor_id stored', [
                                'doctor_name' => $collectedData['doctor_name'],
                                'doctor_id' => $doctor->id
                            ]);

                            return ConversationState::SELECT_DATE->value;
                        } elseif ($doctors->count() > 1) {
                            // Multiple matches - stay in SELECT_DOCTOR to disambiguate
                            Log::info('[DialogueManager] Multiple doctor matches found - staying in SELECT_DOCTOR', [
                                'doctor_name' => $collectedData['doctor_name'],
                                'match_count' => $doctors->count()
                            ]);
                            return ConversationState::SELECT_DOCTOR->value;
                        } else {
                            // No matches - stay in SELECT_DOCTOR
                            Log::info('[DialogueManager] No doctor found - staying in SELECT_DOCTOR', [
                                'doctor_name' => $collectedData['doctor_name']
                            ]);
                            return ConversationState::SELECT_DOCTOR->value;
                        }
                    } catch (\Exception $e) {
                        Log::error('[DialogueManager] Doctor validation failed', [
                            'doctor_name' => $collectedData['doctor_name'],
                            'error' => $e->getMessage()
                        ]);
                        return ConversationState::SELECT_DOCTOR->value;
                    }
                }

                // Stay in SELECT_DOCTOR if no doctor selected
                return ConversationState::SELECT_DOCTOR->value;

            case ConversationState::SELECT_DATE:
                $collectedData = $context['collected_data'] ?? [];
                if ($entities->has('date') || isset($collectedData['date'])) {
                    return ConversationState::SHOW_AVAILABLE_SLOTS->value;
                }
                return ConversationState::SELECT_DATE->value;

            case ConversationState::SHOW_AVAILABLE_SLOTS:
                $collectedData = $context['collected_data'] ?? [];
                $userMessage = $context['user_message'] ?? '';

                // Check if user is asking to see slots (not selecting a time)
                $askingForSlots = stripos($userMessage, 'show') !== false ||
                                 stripos($userMessage, 'available') !== false ||
                                 stripos($userMessage, 'slots') !== false ||
                                 stripos($userMessage, 'what times') !== false ||
                                 stripos($userMessage, 'what time') !== false ||
                                 stripos($userMessage, 'what are') !== false;

                // If user is asking for slots, stay in SHOW_AVAILABLE_SLOTS to display them
                if ($askingForSlots) {
                    return ConversationState::SHOW_AVAILABLE_SLOTS->value;
                }

                // If user provided a specific time, move to SELECT_SLOT
                // Check multiple sources for the time value
                $time = $entities->time ??
                       $context['entities']['time'] ??
                       $collectedData['time'] ??
                       $this->extractTimeFromMessage($userMessage);

                if ($time) {
                    Log::info('[DialogueManager] Time detected, moving to SELECT_SLOT', [
                        'time' => $time,
                        'source' => 'entities_or_message'
                    ]);
                    return ConversationState::SELECT_SLOT->value;
                }

                // Default: stay in SHOW_AVAILABLE_SLOTS to show available options
                return ConversationState::SHOW_AVAILABLE_SLOTS->value;

            case ConversationState::SELECT_SLOT:
                $collectedData = $context['collected_data'] ?? [];
                $userMessage = $context['user_message'] ?? '';
                $time = $entities->time ?? $collectedData['time'] ?? null;
                $doctorId = $collectedData['doctor_id'] ?? null;
                $date = $collectedData['date'] ?? null;

                // Check if user is asking for available slots (not selecting a specific time)
                $askingForSlots = stripos($userMessage, 'available slots') !== false ||
                                 stripos($userMessage, 'what times') !== false ||
                                 stripos($userMessage, 'what time') !== false ||
                                 stripos($userMessage, 'what are') !== false;

                if ($askingForSlots && $doctorId && $date) {
                    Log::info('[DialogueManager] User asking for available slots - transitioning to SHOW_AVAILABLE_SLOTS', [
                        'doctor_id' => $doctorId,
                        'date' => $date
                    ]);
                    return ConversationState::SHOW_AVAILABLE_SLOTS->value;
                }

                if ($time && $doctorId && $date) {
                    try {
                        Log::info('[DialogueManager] Validating slot availability', [
                            'time' => $time,
                            'doctor_id' => $doctorId,
                            'date' => $date,
                            'existing_time_in_collected' => isset($collectedData['time'])
                        ]);

                        // Check if user is confirming an existing time (common pattern)
                        $isConfirmation = isset($collectedData['time']) &&
                                       $collectedData['time'] === $time &&
                                       $this->isConfirmationMessage($userMessage);

                        if ($isConfirmation) {
                            Log::info('[DialogueManager] User confirmed existing time - proceeding to confirmation', [
                                'confirmed_time' => $time
                            ]);
                            return ConversationState::CONFIRM_BOOKING->value;
                        }

                        // Check if the requested time slot is actually available
                        $availableSlots = $this->slotService->getAvailableSlots($doctorId, Carbon::parse($date));
                        $requestedTime = $this->normalizeTime($time);

                        Log::info('[DialogueManager] Checking slot availability', [
                            'requested_time' => $time,
                            'normalized_time' => $requestedTime,
                            'available_slots_count' => $availableSlots->count(),
                            'sample_slots' => $availableSlots->take(5)->map(fn($s) => [
                                'start_time' => $s->start_time,
                                'normalized' => $this->normalizeTime($s->start_time)
                            ])->toArray()
                        ]);

                        $isSlotAvailable = $availableSlots->contains(function ($slot) use ($requestedTime) {
                            $slotTime = $this->normalizeTime($slot->start_time);
                            return $slotTime === $requestedTime;
                        });

                        if ($isSlotAvailable) {
                            Log::info('[DialogueManager] Slot is available - proceeding to confirmation', [
                                'time' => $time,
                                'normalized_time' => $requestedTime,
                                'available_slots_count' => $availableSlots->count()
                            ]);
                            return ConversationState::CONFIRM_BOOKING->value;
                        } else {
                            Log::info('[DialogueManager] Requested slot not available - staying in SELECT_SLOT', [
                                'requested_time' => $time,
                                'normalized_time' => $requestedTime,
                                'available_slots' => $availableSlots->take(3)->map(fn($s) => $s->start_time)->toArray()
                            ]);
                            return ConversationState::SELECT_SLOT->value;
                        }
                    } catch (\Exception $e) {
                        Log::error('[DialogueManager] Slot validation failed', [
                            'time' => $time,
                            'doctor_id' => $doctorId,
                            'date' => $date,
                            'error' => $e->getMessage()
                        ]);
                        return ConversationState::SELECT_SLOT->value;
                    }
                }

                return ConversationState::SELECT_SLOT->value;

            case ConversationState::CONFIRM_BOOKING:
                // Check for explicit confirm intent OR common confirmation words
                $userMessage = $context['user_message'] ?? '';
                $confirmationWords = ['yes', 'yeah', 'yep', 'confirm', 'confirmed', 'okay', 'ok', 'sure', 'that\'s correct', 'correct', 'that works', 'sounds good', 'definitely'];

                $isConfirmIntent = $intent->intent === IntentType::CONFIRM->value;
                $hasConfirmWord = false;

                foreach ($confirmationWords as $word) {
                    if (stripos($userMessage, $word) !== false) {
                        $hasConfirmWord = true;
                        break;
                    }
                }

                if ($isConfirmIntent || $hasConfirmWord) {
                    return ConversationState::EXECUTE_BOOKING->value;
                }
                return ConversationState::CONFIRM_BOOKING->value;

            case ConversationState::EXECUTE_BOOKING:
                return ConversationState::CLOSING->value;

            case ConversationState::CLOSING:
                if ($intent->intent === IntentType::GOODBYE->value) {
                    return ConversationState::END->value;
                }
                return ConversationState::DETECT_INTENT->value;

            default:
                return ConversationState::DETECT_INTENT->value;
        }
    }

    /**
     * Get required entities for current state
     */
    public function getRequiredEntities(string $state): array
    {
        return match (ConversationState::from($state)) {
            ConversationState::COLLECT_PATIENT_NAME => ['patient_name'],
            ConversationState::COLLECT_PATIENT_DOB => ['date_of_birth'],
            ConversationState::COLLECT_PATIENT_PHONE => ['phone'],
            ConversationState::SELECT_DOCTOR => ['doctor_id', 'doctor_name'], // Accept either doctor_id or doctor_name
            ConversationState::SELECT_DATE => ['date'],
            ConversationState::SELECT_SLOT => ['time'],
            default => [],
        };
    }

    /**
     * Check if we can proceed
     */
    public function canProceed(string $state, array $collectedData): bool
    {
        $required = $this->getRequiredEntities($state);

        foreach ($required as $entity) {
            // Special handling for doctor selection - accept either doctor_id OR doctor_name
            if ($entity === 'doctor_id' && isset($collectedData['doctor_name'])) {
                continue; // Skip doctor_id check if we have doctor_name
            }
            if ($entity === 'doctor_name' && isset($collectedData['doctor_id'])) {
                continue; // Skip doctor_name check if we have doctor_id
            }

            if (!isset($collectedData[$entity]) || $collectedData[$entity] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate response
     */
    public function generateResponse(string $state, array $context = []): string
    {
        if ($this->useLLM && $this->llm) {
            return $this->generateLLMResponse($state, $context);
        }

        return $this->generateTemplateResponse($state, $context);
    }

    /**
     * Generate prompt for missing entities
     */
    public function generatePromptForMissingEntities(array $missingEntities, string $state): string
    {
        if (empty($missingEntities)) {
            return $this->generateResponse($state);
        }

        $first = $missingEntities[0];

        return match ($first) {
            'patient_name' => "May I have your full name please?",
            'date_of_birth' => "What's your date of birth?",
            'phone' => "What's the best phone number to reach you?",
            'doctor_id' => "Which doctor would you like to see, or do you have a preference for a department?",
            'date' => "What date would you like for your appointment?",
            'time' => "What time works best for you?",
            default => "Could you please provide more information?",
        };
    }

    /**
     * Get greeting
     */
    public function getGreeting(): string
    {
        return str_replace('{hospital_name}', $this->hospitalName, config('conversation.prompts.greeting'));
    }

    /**
     * Get closing
     */
    public function getClosing(): string
    {
        return config('conversation.prompts.closing');
    }

    /**
     * Get clarification
     */
    public function getClarification(string $reason = ''): string
    {
        return config('conversation.prompts.clarification');
    }

    /**
     * Enhanced dialogue management with clarification and fallback logic
     * Processes structured AI responses and handles conversation flow
     */
    public function processStructuredResponse(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context = []
    ): array {
        Log::info('[DialogueManager] Processing structured AI response', [
            'session_id' => $session->sessionId,
            'current_state' => $session->conversationState,
            'next_action' => $aiResponse->next_action,
            'confidence' => $aiResponse->confidence,
            'requires_clarification' => $aiResponse->requires_clarification,
            'task_switch_detected' => $aiResponse->task_switch_detected
        ]);

        // Handle task switching
        if ($aiResponse->task_switch_detected) {
            return $this->handleTaskSwitch($session, $aiResponse, $context);
        }

        // Handle clarification requests
        if ($aiResponse->requires_clarification) {
            return $this->handleClarification($session, $aiResponse, $context);
        }

        // Handle low confidence scenarios
        if ($aiResponse->confidence < 0.5 && $aiResponse->next_action === 'CONFIDENCE_LOW') {
            return $this->handleLowConfidence($session, $aiResponse, $context);
        }

        // Handle successful processing
        if ($aiResponse->isSuccessful()) {
            return $this->handleSuccessfulResponse($session, $aiResponse, $context);
        }

        // Handle errors
        if ($aiResponse->next_action === 'ERROR') {
            return $this->handleError($session, $aiResponse, $context);
        }

        // Default handling
        return $this->handleDefault($session, $aiResponse, $context);
    }

    /**
     * Handle task switching scenarios
     */
    private function handleTaskSwitch(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context
    ): array {
        Log::info('[DialogueManager] Handling task switch', [
            'from_intent' => $aiResponse->previous_intent,
            'preserved_slots' => $aiResponse->slots,
            'updated_state' => $aiResponse->updated_state
        ]);

        // Determine new state based on the task switch
        $newState = $aiResponse->updated_state ?? $this->determineStateFromTaskSwitch($aiResponse, $session);

        // Preserve relevant data from slots
        $preservedData = $this->preserveRelevantData($session->collectedData, $aiResponse->slots);

        return [
            'state' => $newState,
            'response' => $aiResponse->response_text,
            'metadata' => [
                'task_switch' => true,
                'previous_intent' => $aiResponse->previous_intent,
                'preserved_data' => $preservedData,
                'action' => 'task_switch_handled'
            ],
            'preserved_data' => $preservedData,
            'auto_advance' => false // Don't auto-advance after task switch
        ];
    }

    /**
     * Handle clarification requests
     */
    private function handleClarification(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context
    ): array {
        Log::info('[DialogueManager] Handling clarification request', [
            'clarification_question' => $aiResponse->clarification_question,
            'confidence' => $aiResponse->confidence,
            'slots' => $aiResponse->slots
        ]);

        // Use AI-provided clarification question or generate fallback
        $clarificationQuestion = $aiResponse->clarification_question
            ?? $this->generateFallbackClarification($session, $aiResponse, $context);

        return [
            'state' => $session->conversationState, // Stay in current state
            'response' => $clarificationQuestion,
            'metadata' => [
                'clarification_requested' => true,
                'confidence' => $aiResponse->confidence,
                'extracted_slots' => $aiResponse->slots,
                'action' => 'clarification_needed'
            ],
            'partial_slots' => $aiResponse->slots, // Partial data extracted
            'auto_advance' => false // Wait for user clarification
        ];
    }

    /**
     * Handle low confidence scenarios
     */
    private function handleLowConfidence(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context
    ): array {
        Log::info('[DialogueManager] Handling low confidence', [
            'confidence' => $aiResponse->confidence,
            'response_text' => $aiResponse->response_text
        ]);

        return [
            'state' => $session->conversationState, // Stay in current state
            'response' => $aiResponse->response_text,
            'metadata' => [
                'low_confidence' => true,
                'confidence' => $aiResponse->confidence,
                'action' => 'confidence_low'
            ],
            'auto_advance' => false
        ];
    }

    /**
     * Handle successful AI response
     */
    private function handleSuccessfulResponse(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context
    ): array {
        Log::info('[DialogueManager] Handling successful response', [
            'next_action' => $aiResponse->next_action,
            'updated_state' => $aiResponse->updated_state,
            'slots' => $aiResponse->slots
        ]);

        $newState = $aiResponse->updated_state ?? $session->conversationState;
        $autoAdvance = $this->shouldAutoAdvance($aiResponse, $session);

        return [
            'state' => $newState,
            'response' => $aiResponse->response_text,
            'metadata' => [
                'success' => true,
                'confidence' => $aiResponse->confidence,
                'extracted_slots' => $aiResponse->slots,
                'action' => $aiResponse->next_action
            ],
            'extracted_slots' => $aiResponse->slots,
            'auto_advance' => $autoAdvance
        ];
    }

    /**
     * Handle error scenarios
     */
    private function handleError(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context
    ): array {
        Log::error('[DialogueManager] Handling AI error', [
            'response_text' => $aiResponse->response_text,
            'confidence' => $aiResponse->confidence
        ]);

        return [
            'state' => $session->conversationState,
            'response' => $aiResponse->response_text,
            'metadata' => [
                'error' => true,
                'fallback_used' => true,
                'action' => 'error_handled'
            ],
            'auto_advance' => false
        ];
    }

    /**
     * Handle default scenarios
     */
    private function handleDefault(
        SessionDTO $session,
        StructuredAIResponseDTO $aiResponse,
        array $context
    ): array {
        Log::info('[DialogueManager] Handling default response', [
            'next_action' => $aiResponse->next_action
        ]);

        // Determine next state based on current state and AI response
        $newState = $aiResponse->updated_state ?? $this->determineNextStateDefault($session, $aiResponse);

        return [
            'state' => $newState,
            'response' => $aiResponse->response_text,
            'metadata' => [
                'default_handling' => true,
                'action' => $aiResponse->next_action
            ],
            'auto_advance' => false
        ];
    }

    /**
     * Determine state from task switch
     */
    private function determineStateFromTaskSwitch(StructuredAIResponseDTO $aiResponse, SessionDTO $session): string
    {
        // Extract intent from the AI response or context
        $intent = $this->extractIntentFromResponse($aiResponse);

        return match($intent) {
            'BOOK_APPOINTMENT' => ConversationState::BOOK_APPOINTMENT->value,
            'CANCEL_APPOINTMENT' => ConversationState::CANCEL_APPOINTMENT->value,
            'RESCHEDULE_APPOINTMENT' => ConversationState::RESCHEDULE_APPOINTMENT->value,
            'GENERAL_INQUIRY' => ConversationState::GENERAL_INQUIRY->value,
            default => ConversationState::DETECT_INTENT->value
        };
    }

    /**
     * Extract intent from AI response
     */
    private function extractIntentFromResponse(StructuredAIResponseDTO $aiResponse): string
    {
        // Try to extract intent from reasoning or metadata
        if ($aiResponse->reasoning) {
            $reasoning = strtolower($aiResponse->reasoning);

            foreach (IntentType::cases() as $intent) {
                if (strpos($reasoning, strtolower($intent->value)) !== false) {
                    return $intent->value;
                }
            }
        }

        // Fallback to next action
        return match($aiResponse->next_action) {
            'BOOK_APPOINTMENT' => 'BOOK_APPOINTMENT',
            'CANCEL_APPOINTMENT' => 'CANCEL_APPOINTMENT',
            'RESCHEDULE_APPOINTMENT' => 'RESCHEDULE_APPOINTMENT',
            default => 'UNKNOWN'
        };
    }

    /**
     * Preserve relevant data during task switch
     */
    private function preserveRelevantData(array $existingData, array $newSlots): array
    {
        $preserved = [];

        // Always preserve patient basic info
        $patientFields = ['patient_name', 'date_of_birth', 'phone'];
        foreach ($patientFields as $field) {
            if (isset($existingData[$field])) {
                $preserved[$field] = $existingData[$field];
            }
        }

        // Preserve data from new slots if it's more recent
        foreach ($newSlots as $key => $value) {
            if ($value !== null) {
                $preserved[$key] = $value;
            }
        }

        return $preserved;
    }

    /**
     * Generate fallback clarification question
     */
    private function generateFallbackClarification(SessionDTO $session, StructuredAIResponseDTO $aiResponse, array $context): string
    {
        $state = $session->conversationState;

        // State-specific clarification questions
        return match($state) {
            'COLLECT_PATIENT_NAME' => 'Could you please spell your full name for me?',
            'COLLECT_PATIENT_DOB' => 'Could you provide your date of birth in MM/DD/YYYY format?',
            'COLLECT_PATIENT_PHONE' => 'Could you provide your phone number with area code?',
            'SELECT_DOCTOR' => 'Could you please specify which doctor or department you need?',
            'SELECT_DATE' => 'Could you please provide a specific date for your appointment?',
            'SELECT_SLOT' => 'Could you please specify what time would work best for you?',
            default => 'Could you please provide more details so I can help you better?'
        };
    }

    /**
     * Determine if conversation should auto-advance
     */
    private function shouldAutoAdvance(StructuredAIResponseDTO $aiResponse, SessionDTO $session): bool
    {
        // Don't auto-advance if clarification was needed
        if ($aiResponse->requires_clarification) {
            return false;
        }

        // Don't auto-advance after task switch
        if ($aiResponse->task_switch_detected) {
            return false;
        }

        // Don't auto-advance with low confidence
        if ($aiResponse->confidence < 0.7) {
            return false;
        }

        // Auto-advance if we have all required entities for current state
        return $this->hasAllRequiredEntities($session, $aiResponse->slots);
    }

    /**
     * Check if all required entities are present
     */
    private function hasAllRequiredEntities(SessionDTO $session, array $slots): bool
    {
        $state = $session->conversationState;
        $collectedData = array_merge($session->collectedData, $slots);

        $requiredEntities = match($state) {
            'BOOK_APPOINTMENT' => ['patient_name', 'date_of_birth', 'phone', 'doctor_name', 'date', 'time'],
            'CANCEL_APPOINTMENT' => ['patient_name', 'date'],
            'RESCHEDULE_APPOINTMENT' => ['patient_name', 'date', 'time'],
            'COLLECT_PATIENT_NAME' => ['patient_name'],
            'COLLECT_PATIENT_DOB' => ['date_of_birth'],
            'COLLECT_PATIENT_PHONE' => ['phone'],
            'SELECT_DOCTOR' => ['doctor_name'],
            'SELECT_DATE' => ['date'],
            'SELECT_SLOT' => ['time'],
            default => []
        };

        foreach ($requiredEntities as $entity) {
            if (!isset($collectedData[$entity]) || empty($collectedData[$entity])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine next state for default handling
     */
    private function determineNextStateDefault(SessionDTO $session, StructuredAIResponseDTO $aiResponse): string
    {
        // Use updated state if provided
        if ($aiResponse->updated_state) {
            return $aiResponse->updated_state;
        }

        // Stay in current state by default
        return $session->conversationState;
    }

    /**
     * Handle state transition
     */
    public function handleStateTransition(SessionDTO $session, string $newState): array
    {
        $response = $this->generateResponse($newState, [
            'collected_data' => $session->collectedData,
            'conversation_state' => $newState,
        ]);

        return [
            'state' => $newState,
            'response' => $response,
            'metadata' => [
                'from_state' => $session->conversationState,
                'to_state' => $newState,
            ],
        ];
    }

    /**
     * Check if state is valid
     */
    public function isValidState(string $state): bool
    {
        try {
            ConversationState::from($state);
            return true;
        } catch (\ValueError $e) {
            return false;
        }
    }

    /**
     * Get possible next states
     */
    public function getPossibleNextStates(string $currentState): array
    {
        // Simplified - return common next states
        return match (ConversationState::from($currentState)) {
            ConversationState::GREETING => [ConversationState::DETECT_INTENT->value],
            ConversationState::DETECT_INTENT => [
                ConversationState::BOOK_APPOINTMENT->value,
                ConversationState::CANCEL_APPOINTMENT->value,
                ConversationState::GENERAL_INQUIRY->value,
            ],
            default => [ConversationState::DETECT_INTENT->value],
        };
    }

    /**
     * Route from detected intent
     */
    private function routeFromIntent(IntentDTO $intent): string
    {
        return match ($intent->intent) {
            IntentType::BOOK_APPOINTMENT->value => ConversationState::BOOK_APPOINTMENT->value,
            IntentType::CANCEL_APPOINTMENT->value => ConversationState::CANCEL_APPOINTMENT->value,
            IntentType::RESCHEDULE_APPOINTMENT->value => ConversationState::RESCHEDULE_APPOINTMENT->value,
            IntentType::CHECK_APPOINTMENT->value => ConversationState::CHECK_APPOINTMENT->value,
            IntentType::GENERAL_INQUIRY->value => ConversationState::GENERAL_INQUIRY->value,
            IntentType::GOODBYE->value => ConversationState::END->value,
            default => ConversationState::DETECT_INTENT->value,
        };
    }

    /**
     * Generate response using LLM
     */
    private function generateLLMResponse(string $state, array $context): string
    {
        // Performance optimization: Use LLM only for complex states
        $simpleStates = [
            ConversationState::GREETING->value,
            ConversationState::COLLECT_PATIENT_NAME->value,
            ConversationState::COLLECT_PATIENT_DOB->value,
            ConversationState::COLLECT_PATIENT_PHONE->value,
            ConversationState::SELECT_DOCTOR->value,
            ConversationState::SELECT_DATE->value,
            ConversationState::END->value,
        ];

        if (in_array($state, $simpleStates)) {
            // Use template responses for simple states to improve performance
            return $this->generateTemplateResponse($state, $context);
        }

        // Use optimized prompts for complex states
        $systemPrompt = "You are a helpful AI hospital receptionist. Respond naturally and briefly.";
        $userPrompt = $this->buildOptimizedUserPrompt($state, $context);

        try {
            $response = $this->llm->chat($systemPrompt, [
                ['role' => 'user', 'content' => $userPrompt],
            ]);

            // If LLM returns empty response, fall back to template
            if (empty(trim($response))) {
                Log::warning('[DialogueManager] LLM returned empty response, using template', ['state' => $state]);
                return $this->generateTemplateResponse($state, $context);
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('[DialogueManager] LLM response generation failed', ['error' => $e->getMessage()]);
            return $this->generateTemplateResponse($state, $context);
        }
    }

    /**
     * Generate response using templates
     */
    private function generateTemplateResponse(string $state, array $context): string
    {
        // Check for patient correction scenario
        if (isset($context['correction_detected']) && $context['correction_detected']) {
            $correctionType = $context['correction_type'] ?? 'information';
            return match($correctionType) {
                'patient_name' => "Thank you for the correction. I've updated your name. Let me verify your information with the new details.",
                'date_of_birth' => "Thank you for the correction. I've updated your date of birth. Let me verify your information with the new details.",
                'phone' => "Thank you for the correction. I've updated your phone number. Let me verify your information with the new details.",
                default => "Thank you for the correction. I've updated your information. Let me verify the details.",
            };
        }

        $response = match (ConversationState::from($state)) {
            ConversationState::GREETING => $this->getGreeting(),
            ConversationState::BOOK_APPOINTMENT => "I'd be happy to help you book an appointment. May I have your full name?",
            ConversationState::COLLECT_PATIENT_NAME => "May I have your full name please?",
            ConversationState::COLLECT_PATIENT_DOB => "What's your date of birth?",
            ConversationState::COLLECT_PATIENT_PHONE => "What's the best phone number to reach you?",
            ConversationState::VERIFY_PATIENT => "Thank you. Which doctor would you like to see, or do you have a preference for a department?",
            ConversationState::SELECT_DOCTOR => "Which doctor would you like to see, or do you have a preference for a department?",
            ConversationState::SELECT_DATE => "What date would you like for your appointment? Please provide a specific date.",
            ConversationState::SHOW_AVAILABLE_SLOTS => $this->buildAvailableSlotsResponse($context),
            ConversationState::SELECT_SLOT => function($context) {
                $collectedData = $context['collected_data'] ?? [];
                $userMessage = $context['user_message'] ?? '';

                // If user just provided a time, acknowledge it instead of asking again
                if (isset($collectedData['time']) && !empty($collectedData['time'])) {
                    $time = $collectedData['time'];
                    $date = $collectedData['date'] ?? 'your appointment date';
                    $doctorName = $collectedData['doctor_name'] ?? 'your doctor';

                    // Check if user is confirming or providing new time
                    $hasTimeInMessage = preg_match('/\b(\d{1,2})(?::\d{2})?\s*(am|pm)\b/i', $userMessage);

                    if ($hasTimeInMessage) {
                        return "Thanks for confirming {$time} for {$date} with {$doctorName}. Let me check availability and confirm that for you.";
                    } else {
                        return "I see you'd like {$time} for your appointment. Let me check if that time slot is available for {$date} with {$doctorName}.";
                    }
                }

                return "What time works best for you?";
            },
            ConversationState::CONFIRM_BOOKING => "Great! Let me confirm your appointment. Is this correct?",
            ConversationState::EXECUTE_BOOKING => "Perfect! Your appointment has been booked.",
            ConversationState::CLOSING => $this->buildClosingSummary($context),
            ConversationState::END => "Thank you for calling. Have a great day!",
            default => "How may I help you?",
        };

        // Handle callable responses
        if (is_callable($response)) {
            return $response($context);
        }

        return $response;
    }

    /**
     * Build optimized user prompt for performance
     */
    private function buildOptimizedUserPrompt(string $state, array $context): string
    {
        $prompt = "State: {$state}\n";

        // Only include essential collected data (limit to prevent token bloat)
        if (isset($context['collected_data']) && !empty($context['collected_data'])) {
            $essential = [];
            $importantKeys = ['patient_name', 'doctor_name', 'date', 'time', 'phone', 'date_of_birth'];

            foreach ($importantKeys as $key) {
                if (isset($context['collected_data'][$key])) {
                    $value = $context['collected_data'][$key];
                    if (is_string($value) && strlen($value) > 30) {
                        $value = substr($value, 0, 30) . '...';
                    }
                    $essential[$key] = $value;
                }
            }

            if (!empty($essential)) {
                $prompt .= "Info: " . json_encode($essential) . "\n";
            }
        }

        // Include user message (limit length)
        if (!empty($context['user_message'])) {
            $userMsg = substr($context['user_message'], 0, 80);
            $prompt .= "User: \"{$userMsg}\"\n";
        }

        return $prompt . "\nRespond naturally.";
    }

    private function buildAvailableSlotsResponse(array $context): string
    {
        $data = $context['collected_data'] ?? [];
        $doctor = $data['doctor_name'] ?? 'your doctor';
        $date = $data['date'] ?? null;

        if (!$date) {
            return "Let me check available times for you.";
        }

        // Check if we have available slots
        if (isset($data['no_slots_available']) && $data['no_slots_available']) {
            return "I'm sorry, there are no available slots for {$doctor} on {$date}. Would you like to try a different date?";
        }

        $availableSlots = $data['available_slots'] ?? [];
        $slotRanges = $data['slot_ranges'] ?? [];

        if (empty($availableSlots)) {
            return "Let me check available times for you.";
        }

        // Build response with available slots
        $response = "Here are the available times for {$doctor} on {$date}:\n\n";

        if (!empty($slotRanges)) {
            // Display slot ranges for better readability
            foreach ($slotRanges as $range) {
                $response .= "• {$range}\n";
            }
        } else {
            // Display individual slots if no ranges
            $count = count($availableSlots);
            if ($count > 6) {
                // Show first few if there are many
                $response .= "• " . implode("\n• ", array_slice($availableSlots, 0, 6)) . "\n";
                $response .= "• and " . ($count - 6) . " more times";
            } else {
                $response .= "• " . implode("\n• ", $availableSlots);
            }
        }

        $response .= "\n\nWhat time would you prefer?";

        return $response;
    }

    private function buildClosingSummary(array $context): string
    {
        $data = $context['collected_data'] ?? [];

        $doctor   = $data['doctor_name'] ?? 'the doctor';
        $date     = $data['date'] ?? null;
        $time     = $data['selected_time'] ?? null;

        if ($date && $time) {
            return "✅ Your appointment with {$doctor} is booked for {$date} at {$time}. Is there anything else I can help you with?";
        }

        return "Your appointment has been booked successfully. Is there anything else I can help you with?";
    }


    /**
     * Build system prompt for LLM
     */
    private function buildLLMSystemPrompt(): string
    {
        return <<<PROMPT
You are a professional, friendly, efficient medical receptionist for {{hospital_name}}.
Your goal is to guide the caller through the appointment process step-by-step.

### Core Responsibilities
- Help patients book, cancel, or reschedule appointments.
- Ask only one clear question at a time.
- Collect missing required information gradually.
- Keep responses short (1–2 sentences).
- Maintain a warm, professional tone.
- Never give medical advice.
- Never make assumptions about information the system did not provide.

### Behavior Rules
1. **Follow the conversation_state strictly.**
2. **If required data is missing, ask ONLY for that data.**
3. **If all required data for this state is present, acknowledge and move to the next required item.**
4. **Do NOT jump ahead to states the system has not instructed.**
5. **Do NOT ask irrelevant questions.**
6. **If the patient provides extra information early, politely acknowledge and continue the expected flow.**
7. **If the caller is unclear, ask a simple clarification question.**
8. **Never confirm an appointment unless the system explicitly says the state is CONFIRM_BOOKING or EXECUTE_BOOKING.**
9. **Never invent doctor names, dates, or times. Use ONLY what is provided in context.**

### What each state means (for behavior guidance)
- **GREETING**: Give a warm greeting and ask how you may help.
- **BOOK_APPOINTMENT**: Acknowledge booking request and ask for the patient's full name first.
- **COLLECT_PATIENT_NAME**: Ask for the full name.
- **COLLECT_PATIENT_DOB**: Ask for the date of birth (YYYY-MM-DD or natural language).
- **COLLECT_PATIENT_PHONE**: Ask for the phone number.
- **SELECT_DOCTOR**: Ask which doctor and/or Department they want to book the appointment with.
- **SELECT_DATE**: Ask what date they prefer for the appointment.
- **SHOW_AVAILABLE_SLOTS**: Display the available slots clearly. Use available_slots or slot_ranges from context. If no slots available, say so and offer alternative dates.
- **SELECT_SLOT**: Ask which of the available times works best.
- **CONFIRM_BOOKING**: Repeat the appointment details and ask for confirmation.
- **CLOSING**: End politely.

### Allowed Output Format
- Respond with natural language only.
- Never mention system internals, states, JSON, or rules.
- Do not output placeholders or variables.
- Keep sentences short and friendly.

Follow the rules above and generate the best next message for the caller.
PROMPT;
    }

    /**
     * Build context-aware system prompt
     */
    private function buildContextAwareSystemPrompt(): string
    {
        $hospitalName = $this->hospitalName;
        return <<<PROMPT
You are a professional, friendly, efficient medical receptionist for {$hospitalName}.
You are an ASSISTANT, not a decision-maker. Your role is to:
1. Acknowledge what just happened
2. Confirm what was collected
3. Guide to the next step
4. Never repeat questions for information already collected

### Key Instructions
- You must be aware of what information has already been collected
- Never ask for information that's already been provided
- Acknowledge recent user input explicitly
- Guide smoothly to the next required step
- Keep responses short (1-2 sentences)
- Stay within your role as verbalizer of system decisions

### CRITICAL: GREETING and DETECT_INTENT Rules
- In GREETING or DETECT_INTENT states: Just give a simple welcome and ask generally how you can help
- DO NOT suggest specific services like "check in" or "book appointment"
- Wait for the user to tell you what they want
- Be conversational, not transactional

### State Awareness
You will receive complete context about:
- Current conversation state
- What information has already been collected
- What just happened in this turn
- What comes next in the flow

Never make flow decisions. Only verbalize what the system has already decided.
PROMPT;
    }

    /**
     * Build context-aware user prompt
     */
    private function buildContextAwareUserPrompt(string $state, array $context): string
    {
        $prompt = "CONVERSATION STATE: {$state}\n\n";

        // Add what was just collected/happened
        if (isset($context['recent_action'])) {
            $prompt .= "RECENT ACTION: {$context['recent_action']}\n";
        }

        // Add what we already know
        if (isset($context['collected_data']) && !empty($context['collected_data'])) {
            $collected = $context['collected_data'];
            unset($collected['available_slots']); // Remove large arrays
            $prompt .= "ALREADY COLLECTED: " . json_encode($collected) . "\n";
        }

        // Add correction context
        if (isset($context['correction_detected']) && $context['correction_detected']) {
            $prompt .= "CORRECTION CONTEXT: User is correcting previously provided information\n";
        }

        // Add state-specific guidance
        $prompt .= "\n" . $this->getStateSpecificResponseGuidance($state) . "\n";

        $prompt .= "\nTASK: Generate a natural response that acknowledges the context and guides to the next step.";

        return $prompt;
    }

    /**
     * Get state-specific response guidance
     */
    private function getStateSpecificResponseGuidance(string $state): string
    {
        return match($state) {
            'GREETING' => 'Give a warm, simple greeting and ask how you may help. Do NOT assume they want to check in or book. Just welcome them and ask what they need.',
            'DETECT_INTENT' => 'Listen to what the user wants and help them. Do NOT jump to conclusions about check-ins or bookings.',
            'SELECT_DATE' => 'If user provided a date, acknowledge it and proceed to check availability. If not, ask for appointment date.',
            'SELECT_SLOT' => 'If user selected a time, check if it\'s available. If available slots exist, show them as ranges (e.g., "8:00 AM - 11:30 AM"). If user asks "what are the available slots", show the actual available times.',
            'CONFIRM_BOOKING' => 'Present the booking details for confirmation. Ask for yes/no response.',
            'SELECT_DOCTOR' => 'If doctor was selected, confirm and move to date selection. If not, ask for doctor preference.',
            'COLLECT_PATIENT_NAME' => 'Acknowledge the name provided and move to next information needed.',
            'COLLECT_PATIENT_DOB' => 'Acknowledge the date of birth and ask for contact information.',
            'COLLECT_PATIENT_PHONE' => 'Acknowledge the phone number and proceed to doctor selection.',
            'CLOSING' => 'Provide closing confirmation and offer additional assistance.',
            default => 'Provide appropriate response based on context and guide conversation forward.',
        };
    }

    /**
     * Extract time from message when not found in entities
     */
    private function extractTimeFromMessage(string $message): ?string
    {
        $message = strtolower(trim($message));

        try {
            // Match patterns like "10 am", "10:30 am", "10:30am", "10:30", "10am", "10 pm"
            if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/', $message, $matches)) {
                $hour = (int)$matches[1];
                $minute = isset($matches[2]) ? (int)$matches[2] : 0;
                $period = $matches[3] ?? 'am';

                return $this->normalizeTimeFormat("{$hour}:{$minute} {$period}");
            }

            // Match patterns like "10 o'clock", "10 o'clock am"
            if (preg_match('/(\d{1,2})\s*o\'\clock\s*(am|pm)?/', $message, $matches)) {
                $hour = (int)$matches[1];
                $period = $matches[2] ?? 'am';
                return $this->normalizeTimeFormat("{$hour}:00 {$period}");
            }

            // Match simple hour patterns
            if (preg_match('/\b(\d{1,2})\s*(am|pm)\b/', $message, $matches)) {
                $hour = (int)$matches[1];
                $period = $matches[2];
                return $this->normalizeTimeFormat("{$hour}:00 {$period}");
            }

        } catch (\Exception $e) {
            Log::warning('[DialogueManager] Time extraction failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Normalize time format for comparison
     */
    private function normalizeTime(string $time): string
    {
        // Remove spaces and convert to lowercase
        $time = strtolower(trim($time));

        // Convert 12-hour to 24-hour format
        if (preg_match('/(\d{1,2}):?(\d{0,2})\s*(am|pm)/', $time, $matches)) {
            $hour = (int)$matches[1];
            $minute = $matches[2] ? (int)$matches[2] : 0;
            $period = $matches[3];

            if ($period === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        // If it's already in HH:MM format, return as is
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return sprintf('%02d:%02d', ...explode(':', $time));
        }

        return $time;
    }

    /**
     * Check if user message is a confirmation of existing data
     */
    private function isConfirmationMessage(string $message): bool
    {
        $message = strtolower(trim($message));

        $confirmationPatterns = [
            'yes', 'yeah', 'yep', 'correct', 'that\'s right', 'that\'s it',
            'exactly', 'perfect', 'good', 'great', 'sounds good', 'sounds great',
            'that works', 'that would work', 'that would be good', 'that would be great',
            'confirmed', 'confirm', 'sure', 'definitely', 'absolutely',
            'fine by me', 'ok', 'okay', 'alright'
        ];

        // Check for exact matches
        foreach ($confirmationPatterns as $pattern) {
            if ($message === $pattern) {
                return true;
            }
        }

        // Check for phrases containing confirmation words
        foreach ($confirmationPatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        // Check for specific time confirmation patterns
        if (preg_match('/\b(\d{1,2})(?::\d{2})?\s*(am|pm)\b/', $message) &&
            (preg_match('/\b(best|perfect|great|good|exactly|confirmed)\b/', $message) ||
             strlen($message) < 15)) { // Short messages like "9 am", "10:30", etc.
            return true;
        }

        return false;
    }

    /**
     * Normalize time format to 24-hour HH:MM
     */
    private function normalizeTimeFormat(string $time): string
    {
        $time = strtolower(trim($time));

        // Handle "10 am" -> "10:00"
        if (preg_match('/(\d{1,2})\s*(am|pm)/', $time, $matches)) {
            $hour = (int)$matches[1];
            $period = $matches[2];

            if ($period === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:00', $hour);
        }

        // Handle "10:30 am" -> "10:30"
        if (preg_match('/(\d{1,2}):(\d{2})\s*(am|pm)/', $time, $matches)) {
            $hour = (int)$matches[1];
            $minute = (int)$matches[2];
            $period = $matches[3];

            if ($period === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        return $time;
    }

    /**
     * Create slot ranges for better display
     */
    private function createSlotRanges(array $slots): array
    {
        if (empty($slots)) {
            return [];
        }

        $ranges = [];
        $currentRange = null;

        foreach ($slots as $slot) {
            $time = $slot['time'];

            if ($currentRange === null) {
                $currentRange = ['start' => $time, 'end' => $time];
            } else {
                // Check if this slot continues the current range
                $prevTime = strtotime(substr($currentRange['end'], 0, -3)); // Remove AM/PM
                $currTime = strtotime(substr($time, 0, -3)); // Remove AM/PM

                // If the time difference is 15 minutes (typical slot duration), continue the range
                if (($currTime - $prevTime) === 900) { // 15 minutes = 900 seconds
                    $currentRange['end'] = $time;
                } else {
                    // Start a new range
                    $ranges[] = $currentRange;
                    $currentRange = ['start' => $time, 'end' => $time];
                }
            }
        }

        // Add the last range
        if ($currentRange) {
            $ranges[] = $currentRange;
        }

        // Format ranges as strings
        return array_map(function($range) {
            return $range['start'] === $range['end']
                ? $range['start']
                : $range['start'] . ' - ' . $range['end'];
        }, $ranges);
    }
}
