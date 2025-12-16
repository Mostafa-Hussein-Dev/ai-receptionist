<?php


namespace App\Services\Conversation;

use App\Contracts\DialogueManagerServiceInterface;
use App\Contracts\LLMServiceInterface;
use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\DTOs\SessionDTO;
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
                $doctorId = $collectedData['doctor_id'] ?? null;
                $doctorName = $collectedData['doctor_name'] ?? null;

                Log::info('[DialogueManager] SELECT_DOCTOR state check', [
                    'doctor_id' => $doctorId,
                    'doctor_name' => $doctorName,
                    'collected_data_keys' => array_keys($collectedData)
                ]);

                // If we have doctor_id, proceed
                if (isset($collectedData['doctor_id']) && !empty($collectedData['doctor_id'])) {
                    Log::info('[DialogueManager] Proceeding to SELECT_DATE - valid doctor_id', [
                        'doctor_id' => $collectedData['doctor_id']
                    ]);
                    return ConversationState::SELECT_DATE->value;
                }

                // If we have doctor_name, validate it exists in database
                if (isset($collectedData['doctor_name']) && !empty($collectedData['doctor_name'])) {
                    try {
                        $doctors = $this->doctorService->searchDoctors($collectedData['doctor_name']);

                        if ($doctors->count() === 1) {
                            // Exact match - proceed
                            $doctor = $doctors->first();
                            Log::info('[DialogueManager] Proceeding to SELECT_DATE - doctor validated', [
                                'doctor_name' => $collectedData['doctor_name'],
                                'doctor_id' => $doctor->id,
                                'validated_doctor' => "Dr. {$doctor->first_name} {$doctor->last_name}"
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

                Log::info('[DialogueManager] Staying in SELECT_DOCTOR - no doctor found');
                return ConversationState::SELECT_DOCTOR->value;

            case ConversationState::SELECT_DATE:
                $collectedData = $context['collected_data'] ?? [];
                if ($entities->has('date') || isset($collectedData['date'])) {
                    return ConversationState::SHOW_AVAILABLE_SLOTS->value;
                }
                return ConversationState::SELECT_DATE->value;

            case ConversationState::SHOW_AVAILABLE_SLOTS:
                $collectedData = $context['collected_data'] ?? [];
                $doctorId = $collectedData['doctor_id'] ?? null;
                $date = $collectedData['date'] ?? null;

                if ($doctorId && $date) {
                    try {
                        Log::info('[DialogueManager] Fetching available slots', [
                            'doctor_id' => $doctorId,
                            'date' => $date
                        ]);

                        // Get available slots from SlotService
                        $slots = $this->slotService->getAvailableSlots($doctorId, Carbon::parse($date));

                        if ($slots->isNotEmpty()) {
                            // Format slots for display and store in session
                            $formattedSlots = $slots->map(fn($s) => [
                                'time' => date('h:i A', strtotime($s->start_time)),
                                'start_time' => $s->start_time,
                                'end_time' => $s->end_time,
                                'slot_number' => $s->slot_number
                            ])->toArray();

                            // Create slot ranges for better display
                            $slotRanges = $this->createSlotRanges($formattedSlots);

                            Log::info('[DialogueManager] Available slots found', [
                                'count' => $slots->count(),
                                'formatted_slots' => $formattedSlots,
                                'slot_ranges' => $slotRanges
                            ]);

                            // Store in session for LLM access
                            if (isset($context['session_id'])) {
                                $sessionManager = app(\App\Services\Conversation\SessionManagerServiceInterface::class);
                                $sessionManager->updateCollectedData($context['session_id'], [
                                    'available_slots' => $formattedSlots,
                                    'slot_ranges' => $slotRanges,
                                    'slots_count' => $slots->count()
                                ]);
                            }
                        } else {
                            Log::info('[DialogueManager] No available slots found', [
                                'doctor_id' => $doctorId,
                                'date' => $date
                            ]);

                            if (isset($context['session_id'])) {
                                $sessionManager = app(\App\Services\Conversation\SessionManagerServiceInterface::class);
                                $sessionManager->updateCollectedData($context['session_id'], [
                                    'available_slots' => [],
                                    'no_slots_available' => true
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('[DialogueManager] Failed to fetch slots', [
                            'doctor_id' => $doctorId,
                            'date' => $date,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::warning('[DialogueManager] Missing doctor_id or date for slot checking', [
                        'doctor_id' => $doctorId,
                        'date' => $date
                    ]);
                }

                return ConversationState::SELECT_SLOT->value;

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
                            'date' => $date
                        ]);

                        // Check if the requested time slot is actually available
                        $availableSlots = $this->slotService->getAvailableSlots($doctorId, Carbon::parse($date));
                        $requestedTime = $this->normalizeTime($time);

                        $isSlotAvailable = $availableSlots->contains(function ($slot) use ($requestedTime) {
                            $slotTime = $this->normalizeTime($slot->start_time);
                            return $slotTime === $requestedTime;
                        });

                        if ($isSlotAvailable) {
                            Log::info('[DialogueManager] Slot is available - proceeding to confirmation', [
                                'time' => $time,
                                'available_slots_count' => $availableSlots->count()
                            ]);
                            return ConversationState::CONFIRM_BOOKING->value;
                        } else {
                            Log::info('[DialogueManager] Requested slot not available - staying in SELECT_SLOT', [
                                'requested_time' => $time,
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
        $systemPrompt = $this->buildContextAwareSystemPrompt();
        $userPrompt = $this->buildContextAwareUserPrompt($state, $context);

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

        return match (ConversationState::from($state)) {
            ConversationState::GREETING => $this->getGreeting(),
            ConversationState::BOOK_APPOINTMENT => "I'd be happy to help you book an appointment. May I have your full name?",
            ConversationState::COLLECT_PATIENT_NAME => "May I have your full name please?",
            ConversationState::COLLECT_PATIENT_DOB => "What's your date of birth?",
            ConversationState::COLLECT_PATIENT_PHONE => "What's the best phone number to reach you?",
            ConversationState::VERIFY_PATIENT => "Thank you. Which doctor would you like to see, or do you have a preference for a department?",
            ConversationState::SELECT_DOCTOR => "Which doctor would you like to see, or do you have a preference for a department?",
            ConversationState::SELECT_DATE => "What date would you like for your appointment? Please provide a specific date.",
            ConversationState::SHOW_AVAILABLE_SLOTS => "Let me check available times for you.",
            ConversationState::SELECT_SLOT => "What time works best for you?",
            ConversationState::CONFIRM_BOOKING => "Great! Let me confirm your appointment. Is this correct?",
            ConversationState::EXECUTE_BOOKING => "Perfect! Your appointment has been booked.",
            ConversationState::CLOSING => $this->buildClosingSummary($context),
            ConversationState::END => "Thank you for calling. Have a great day!",
            default => "How may I help you?",
        };
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
- **COLLECT_PATIENT_NAME**: Ask for the full name.
- **COLLECT_PATIENT_DOB**: Ask for the date of birth (YYYY-MM-DD or natural language).
- **COLLECT_PATIENT_PHONE**: Ask for the phone number.
- **SELECT_DOCTOR**: Ask which doctor and/or Department they want to book the appointment with.
- **SELECT_DATE**: Ask what date they prefer for the appointment.
- **SHOW_AVAILABLE_SLOTS**: If available_slots are provided, summarize them briefly.
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
