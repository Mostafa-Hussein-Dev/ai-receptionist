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

    public function __construct(?LLMServiceInterface $llm = null)
    {
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
                return $this->routeFromIntent($intent);

            case ConversationState::BOOK_APPOINTMENT:
                return ConversationState::COLLECT_PATIENT_NAME->value;

            case ConversationState::COLLECT_PATIENT_NAME:
                if ($entities->has('patient_name')) {
                    return ConversationState::COLLECT_PATIENT_DOB->value;
                }
                return ConversationState::COLLECT_PATIENT_NAME->value;

            case ConversationState::COLLECT_PATIENT_DOB:
                if ($entities->has('date_of_birth')) {
                    return ConversationState::COLLECT_PATIENT_PHONE->value;
                }
                return ConversationState::COLLECT_PATIENT_DOB->value;

            case ConversationState::COLLECT_PATIENT_PHONE:
                if ($entities->has('phone')) {
                    return ConversationState::VERIFY_PATIENT->value;
                }
                return ConversationState::COLLECT_PATIENT_PHONE->value;

            case ConversationState::VERIFY_PATIENT:
                // After verification, move to doctor selection or date
                return ConversationState::SELECT_DATE->value;

            case ConversationState::SELECT_DATE:
                if ($entities->has('date')) {
                    return ConversationState::SHOW_AVAILABLE_SLOTS->value;
                }
                return ConversationState::SELECT_DATE->value;

            case ConversationState::SHOW_AVAILABLE_SLOTS:
                return ConversationState::SELECT_SLOT->value;

            case ConversationState::SELECT_SLOT:
                if ($entities->has('time')) {
                    return ConversationState::CONFIRM_BOOKING->value;
                }
                return ConversationState::SELECT_SLOT->value;

            case ConversationState::CONFIRM_BOOKING:
                if ($intent->intent === IntentType::CONFIRM->value) {
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
        $systemPrompt = $this->buildLLMSystemPrompt();
        $userPrompt = "Current State: {$state}\nContext: " . json_encode($context) . "\n\nGenerate appropriate response.";

        try {
            return $this->llm->chat($systemPrompt, [
                ['role' => 'user', 'content' => $userPrompt],
            ]);
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
        return match (ConversationState::from($state)) {
            ConversationState::GREETING => $this->getGreeting(),
            ConversationState::BOOK_APPOINTMENT => "I'd be happy to help you book an appointment. May I have your full name?",
            ConversationState::COLLECT_PATIENT_NAME => "May I have your full name please?",
            ConversationState::COLLECT_PATIENT_DOB => "What's your date of birth?",
            ConversationState::COLLECT_PATIENT_PHONE => "What's the best phone number to reach you?",
            ConversationState::SELECT_DATE => "What date would you like for your appointment?",
            ConversationState::SHOW_AVAILABLE_SLOTS => "Let me check available times for you.",
            ConversationState::SELECT_SLOT => "What time works best for you?",
            ConversationState::CONFIRM_BOOKING => "Great! Let me confirm your appointment. Is this correct?",
            ConversationState::EXECUTE_BOOKING => "Perfect! Your appointment has been booked.",
            ConversationState::CLOSING => $this->getClosing(),
            ConversationState::END => "Thank you for calling. Have a great day!",
            default => "How may I help you?",
        };
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
}
