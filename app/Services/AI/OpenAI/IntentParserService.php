<?php

namespace App\Services\AI\OpenAI;

use App\Contracts\IntentParserServiceInterface;
use App\DTOs\IntentDTO;
use App\DTOs\StructuredAIResponseDTO;
use App\Enums\IntentType;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Intent Parser Service
 *
 * Real intent classification using OpenAI's GPT-4.
 * Understands context and natural language nuances.
 *
 * Accuracy: 90-95%
 * Speed: 500ms-2s
 */
class IntentParserService implements IntentParserServiceInterface
{
    private OpenAILLMService $llm;
    private float $confidenceThreshold;
    private float $clarificationThreshold;
    private bool $useHistory;
    private int $maxHistoryTurns;

    public function __construct(OpenAILLMService $llm)
    {
        $this->llm = $llm;
        $this->confidenceThreshold = config('ai.intent.confidence_threshold', 0.7);
        $this->clarificationThreshold = config('ai.intent.clarification_threshold', 0.5);
        $this->useHistory = config('ai.intent.use_history', true);
        $this->maxHistoryTurns = config('ai.intent.max_history_turns', 5);
    }

    /**
     * Parse user input and detect intent
     */
    public function parse(string $userMessage, array $context = []): IntentDTO
    {
        try {
            $systemPrompt = $this->buildSystemPrompt();
            $userPrompt = $this->buildUserPrompt($userMessage, $context);

            Log::info('[OpenAI IntentParser] Parsing intent', [
                'message' => $userMessage,
                'context_keys' => array_keys($context),
            ]);

            // Call OpenAI and get JSON response
            $response = $this->llm->generateJSON($systemPrompt, $userPrompt);

            // Validate response structure
            if (!isset($response['intent']) || !isset($response['confidence'])) {
                throw new \Exception('Invalid response structure from OpenAI');
            }

            // Validate intent is recognized
            $intentValue = $response['intent'];
            if (!in_array($intentValue, IntentType::values())) {
                Log::warning('[OpenAI IntentParser] Unknown intent returned', [
                    'intent' => $intentValue,
                ]);
                $intentValue = IntentType::UNKNOWN->value;
            }

            $result = new IntentDTO(
                intent: $intentValue,
                confidence: (float) $response['confidence'],
                reasoning: $response['reasoning'] ?? null,
                metadata: [
                    'provider' => 'openai',
                    'model' => $this->llm->getModel(),
                ]
            );

            Log::info('[OpenAI IntentParser] Intent detected', [
                'intent' => $result->intent,
                'confidence' => $result->confidence,
            ]);

            return $result;

    } catch (\Exception $e) {
            Log::error('[OpenAI IntentParser] Parsing failed', [
                'error' => $e->getMessage(),
                'message' => $userMessage,
            ]);
            throw new \Exception('Intent parsing failed: ' . $e->getMessage());
        }
    }

    /**
     * Parse with conversation history for better context
     */
    public function parseWithHistory(
        string $userMessage,
        array $conversationHistory = [],
        array $context = []
    ): IntentDTO {
        // Add history to context
        $context['conversation_history'] = array_slice(
            $conversationHistory,
            -$this->maxHistoryTurns
        );

        return $this->parse($userMessage, $context);
    }

    /**
     * Enhanced parsing with contextual NLU and structured output
     * Returns structured response with confidence scoring and next actions
     */
    public function parseWithContext(
        string $userMessage,
        array $context = []
    ): StructuredAIResponseDTO {
        try {
            $systemPrompt = $this->buildEnhancedSystemPrompt();
            $userPrompt = $this->buildEnhancedUserPrompt($userMessage, $context);

            Log::info('[OpenAI IntentParser] Enhanced parsing with context', [
                'message' => $userMessage,
                'context_keys' => array_keys($context),
                'has_history' => !empty($context['conversation_history']),
                'current_state' => $context['conversation_state'] ?? 'none',
                'previous_intent' => $context['previous_intent'] ?? 'none'
            ]);

            // Call OpenAI and get JSON response
            $response = $this->llm->generateJSON($systemPrompt, $userPrompt);

            // Validate and process response
            $validatedResponse = $this->validateStructuredResponse($response);

            // Detect task switching
            $taskSwitchDetected = $this->detectTaskSwitch(
                $validatedResponse['intent'] ?? 'UNKNOWN',
                $context['previous_intent'] ?? null,
                $context['conversation_state'] ?? ''
            );

            // Determine if clarification is needed
            $requiresClarification = $this->requiresClarification(
                $validatedResponse['confidence'] ?? 0,
                $validatedResponse['intent'] ?? 'UNKNOWN',
                $context
            );

            // Create appropriate structured response
            if ($requiresClarification) {
                return StructuredAIResponseDTO::clarification(
                    responseText: $validatedResponse['response_text'] ?? $this->generateClarificationResponse($validatedResponse, $context),
                    clarificationQuestion: $validatedResponse['clarification_question'] ?? $this->generateClarificationQuestion($validatedResponse, $context),
                    confidence: $validatedResponse['confidence'] ?? 0.5,
                    reasoning: $validatedResponse['reasoning'] ?? 'Low confidence detected'
                );
            }

            if ($taskSwitchDetected['detected']) {
                return StructuredAIResponseDTO::taskSwitch(
                    nextAction: $validatedResponse['next_action'] ?? 'TASK_SWITCH',
                    responseText: $validatedResponse['response_text'] ?? $this->generateTaskSwitchResponse($taskSwitchDetected),
                    previousIntent: $taskSwitchDetected['previous_intent'],
                    updatedState: $validatedResponse['updated_state'] ?? null,
                    preservedSlots: $validatedResponse['preserved_slots'] ?? [],
                    confidence: $validatedResponse['confidence'] ?? 0.8
                );
            }

            return StructuredAIResponseDTO::success(
                nextAction: $validatedResponse['next_action'] ?? 'CONTINUE',
                responseText: $validatedResponse['response_text'] ?? 'Intent understood',
                updatedState: $validatedResponse['updated_state'] ?? null,
                slots: $validatedResponse['slots'] ?? [],
                confidence: $validatedResponse['confidence'] ?? 1.0,
                reasoning: $validatedResponse['reasoning'] ?? null
            );

        } catch (\Exception $e) {
            Log::error('[OpenAI IntentParser] Enhanced parsing failed', [
                'error' => $e->getMessage(),
                'message' => $userMessage,
            ]);

            return StructuredAIResponseDTO::error(
                responseText: 'I apologize, but I\'m having trouble understanding your request. Could you please rephrase that?',
                errorReason: 'Intent parsing failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Check if intent requires clarification based on confidence and context
     */
    private function requiresClarification(
        float $confidence,
        string $intent,
        array $context
    ): bool {
        // Low confidence always requires clarification
        if ($confidence < $this->clarificationThreshold) {
            return true;
        }

        // Certain states require high confidence
        $highConfidenceStates = ['CONFIRM_BOOKING', 'EXECUTE_BOOKING', 'CANCEL_APPOINTMENT'];
        if (in_array($context['conversation_state'] ?? '', $highConfidenceStates) && $confidence < $this->confidenceThreshold) {
            return true;
        }

        // Unknown intent always requires clarification
        if ($intent === 'UNKNOWN' && $confidence < 0.8) {
            return true;
        }

        return false;
    }

    /**
     * Detect if user is switching tasks/intents
     */
    private function detectTaskSwitch(
        string $currentIntent,
        ?string $previousIntent,
        string $conversationState
    ): array {
        if (!$previousIntent || $previousIntent === $currentIntent) {
            return ['detected' => false];
        }

        // Define incompatible intent pairs that indicate task switching
        $incompatibleIntents = [
            'BOOK_APPOINTMENT' => ['CANCEL_APPOINTMENT', 'RESCHEDULE_APPOINTMENT'],
            'CANCEL_APPOINTMENT' => ['BOOK_APPOINTMENT', 'RESCHEDULE_APPOINTMENT'],
            'RESCHEDULE_APPOINTMENT' => ['BOOK_APPOINTMENT', 'CANCEL_APPOINTMENT'],
        ];

        $previousIntentUpper = strtoupper($previousIntent);
        $currentIntentUpper = strtoupper($currentIntent);

        if (isset($incompatibleIntents[$previousIntentUpper]) &&
            in_array($currentIntentUpper, $incompatibleIntents[$previousIntentUpper])) {
            return [
                'detected' => true,
                'previous_intent' => $previousIntent,
                'new_intent' => $currentIntent,
                'reason' => 'Incompatible intent detected'
            ];
        }

        // Also check for major intent changes during active booking flows
        $activeBookingStates = ['COLLECT_PATIENT_NAME', 'COLLECT_PATIENT_DOB', 'SELECT_DOCTOR', 'SELECT_DATE'];
        if (in_array($conversationState, $activeBookingStates) &&
            !in_array($currentIntentUpper, ['BOOK_APPOINTMENT', 'PROVIDE_INFO', 'CONFIRM', 'UNKNOWN'])) {
            return [
                'detected' => true,
                'previous_intent' => $previousIntent,
                'new_intent' => $currentIntent,
                'reason' => 'Intent change during active booking flow'
            ];
        }

        return ['detected' => false];
    }

    /**
     * Check if the parser is available
     */
    public function isAvailable(): bool
    {
        return $this->llm->isAvailable();
    }

    /**
     * Get parser type
     */
    public function getType(): string
    {
        return 'openai';
    }

    /**
     * Build enhanced system prompt for structured intent classification
     */
    private function buildEnhancedSystemPrompt(): string
    {
        $intents = $this->getIntentDescriptions();

        return <<<PROMPT
You are an AI assistant for a medical clinic's appointment system.

Your task is to analyze the user's message and provide a structured response including intent classification, confidence scoring, and next actions.

Available Intents:
{$intents}

Enhanced Analysis Rules:
1. Analyze the user's message carefully considering conversation history, current state, and collected data
2. Detect potential task switches when user changes intent mid-conversation
3. Assess confidence based on clarity, context, and specificity of the request
4. Determine if clarification is needed for low-confidence scenarios

Context-Aware Interpretation:
- In CONFIRM_BOOKING state: "yes", "yeah", "yep", "correct", "right" = CONFIRM intent
- In SELECT_SLOT state: "yes", "yeah", "yep" = CONFIRM intent
- In booking states: "book", "schedule", "appointment" = BOOK_APPOINTMENT intent
- Task switching detection: Watch for intent changes that contradict previous intent
- Confidence assessment: Consider message clarity, contextual fit, and specificity

Return your analysis as JSON with this exact structure:
{
  "intent": "INTENT_NAME",
  "confidence": 0.0-1.0,
  "reasoning": "brief explanation of your analysis",
  "next_action": "CONTINUE|CLARIFY|TASK_SWITCH|CONFIDENCE_LOW",
  "response_text": "Natural language response to user",
  "updated_state": "suggested_next_state_or_null",
  "slots": {},
  "requires_clarification": true/false,
  "clarification_question": "specific_question_if_needed_or_null",
  "task_switch_detected": true/false,
  "preserved_slots": []
}

Guidelines for Next Actions:
- CONTINUE: Normal conversation flow, confidence is good
- CLARIFY: Ask specific question to resolve ambiguity
- TASK_SWITCH: User changed intents, preserve relevant data
- CONFIDENCE_LOW: Ask for rephrase or more details

Response Text Guidelines:
- Be conversational and helpful
- For clarification: explain what you need more clearly
- For task switching: acknowledge change and what will be preserved
- For low confidence: offer alternative ways to express the request

Respond ONLY with valid JSON.
PROMPT;
    }

    /**
     * Build system prompt for intent classification (legacy)
     */
    private function buildSystemPrompt(): string
    {
        $intents = $this->getIntentDescriptions();

        return <<<PROMPT
You are an AI assistant for a medical clinic's appointment system.

Your task is to classify the user's intent from their message.

Available Intents:
{$intents}

Rules:
1. Analyze the user's message carefully considering the conversation context/state
2. Pay special attention to confirmation words (yes, yeah, yep, correct, right, ok, okay, sure) in states like CONFIRM_BOOKING or SELECT_SLOT - these should be CONFIRM intent
3. Return your classification as JSON with this exact structure:
   {
     "intent": "INTENT_NAME",
     "confidence": 0.0-1.0,
     "reasoning": "brief explanation"
   }

Context Guidelines:
- In CONFIRM_BOOKING state: "yes", "yeah", "yep", "correct", "right" should be CONFIRM intent
- In SELECT_SLOT state: "yes", "yeah", "yep" as confirmation should be CONFIRM intent
- In general booking flow: "book", "schedule", "appointment" should be BOOK_APPOINTMENT intent
- Single words like "yes" without clear context often indicate CONFIRM intent

4. confidence should be 0.0 to 1.0 (e.g., 0.85 for 85% confident)
5. Use UNKNOWN if you cannot determine the intent
6. DO NOT include any text outside the JSON object
7. DO NOT use markdown code blocks

Respond ONLY with valid JSON.
PROMPT;
    }

    /**
     * Build enhanced user prompt with contextual NLU information
     */
    private function buildEnhancedUserPrompt(string $userMessage, array $context): string
    {
        // Optimize for performance - concise prompts
        $prompt = "Intent: \"{$userMessage}\"\n";

        // Only add essential context
        if (isset($context['conversation_state'])) {
            $prompt .= "State: {$context['conversation_state']}\n";
            $prompt .= "Prev: " . ($context['previous_intent'] ?? 'NONE') . "\n";

            // Only include essential collected data
            if (isset($context['collected_data']) && !empty($context['collected_data'])) {
                $collected = $context['collected_data'];
                $essential = array_intersect_key($collected, array_flip([
                    'patient_name', 'doctor_name', 'date', 'time'
                ]));
                if (!empty($essential)) {
                    $prompt .= "Has: " . json_encode($essential) . "\n";
                }
            }
        }

        // Limit conversation history to last 2 turns
        if (!empty($context['conversation_history'])) {
            $recent = array_slice($context['conversation_history'], -2);
            foreach ($recent as $turn) {
                $role = $turn['role'] ?? 'user';
                $content = substr($turn['content'] ?? '', 0, 80); // Limit content
                $prompt .= "{$role}: \"{$content}\"\n";
            }
        }

        // Add concise analysis guidance
        if (isset($context['conversation_state'])) {
            $prompt .= "\nAnalyze for: " . $this->getStateSpecificInterpretation($context['conversation_state']) . "\n";
            $prompt .= "Check for task switches from: " . ($context['previous_intent'] ?? 'NONE') . "\n";
        }

        // Add confidence assessment guidelines
        $prompt .= "\nCONFIDENCE ASSESSMENT:\n";
        $prompt .= "- High confidence (0.8-1.0): Clear intent, good context fit, specific request\n";
        $prompt .= "- Medium confidence (0.5-0.8): Reasonably clear intent, some ambiguity\n";
        $prompt .= "- Low confidence (0.0-0.5): Unclear intent, contradictory information, missing context\n";

        $prompt .= "\nTASK: Analyze the message considering all context and provide structured response.";

        return $prompt;
    }

    /**
     * Build user prompt with message and context (legacy)
     */
    private function buildUserPrompt(string $userMessage, array $context): string
    {
        $prompt = "User Message: \"{$userMessage}\"\n\n";

        // Add comprehensive state context
        if (isset($context['conversation_state'])) {
            $prompt .= "CONVERSATION STATE: {$context['conversation_state']}\n";

            // Add what we already know
            if (isset($context['collected_data']) && !empty($context['collected_data'])) {
                $prompt .= "ALREADY COLLECTED: " . json_encode($context['collected_data']) . "\n";
            }

            // Add flow context guidance
            $prompt .= "FLOW CONTEXT: " . $this->getFlowContext($context['conversation_state']) . "\n";
        }

        // Add recent conversation history
        if (!empty($context['conversation_history'])) {
            $prompt .= "\nRECENT CONVERSATION:\n";
            foreach (array_slice($context['conversation_history'], -3) as $turn) {
                $role = $turn['role'] ?? 'user';
                $content = $turn['content'] ?? '';
                $prompt .= "{$role}: {$content}\n";
            }
        }

        // Add state-specific disambiguation
        if (isset($context['conversation_state'])) {
            $prompt .= "\nSTATE-SPECIFIC INTERPRETATION:\n";
            $prompt .= $this->getStateSpecificInterpretation($context['conversation_state']) . "\n";
        }

        $prompt .= "\nTASK: Classify the intent considering the conversation flow and context.";

        return $prompt;
    }

    /**
     * Get flow context for conversation state
     */
    private function getFlowContext(string $state): string
    {
        return match($state) {
            'SELECT_DATE' => 'User is providing appointment date. Date inputs like "2025-12-20", "tomorrow", "next Monday" should be interpreted as PROVIDE_INFO intent.',
            'SELECT_SLOT' => 'User is selecting appointment time. Time inputs like "10:00", "morning", "2 PM" should be interpreted as PROVIDE_INFO intent.',
            'CONFIRM_BOOKING' => 'User is responding to confirmation question. "yes", "confirm", "that\'s right" = CONFIRM intent. "no", "wrong", "change" = NEGATIVE intent.',
            'SELECT_DOCTOR' => 'User is selecting healthcare provider. Doctor names or departments should be interpreted as PROVIDE_INFO intent.',
            'COLLECT_PATIENT_*' => 'User is providing personal information. Names, DOB, phone numbers should be interpreted as PROVIDE_INFO intent.',
            'VERIFY_PATIENT' => 'User information is being verified. Corrections should be interpreted as CORRECTION intent.',
            default => 'Standard conversation flow - interpret based on message content.',
        };
    }

    /**
     * Get state-specific interpretation guidance
     */
    private function getStateSpecificInterpretation(string $state): string
    {
        return match($state) {
            'SELECT_DATE' => 'In this state, any date-related input should be classified as PROVIDE_INFO, not GENERAL_INQUIRY. The user is clearly providing what was asked for.',
            'SELECT_SLOT' => 'In this state, any time-related input should be classified as PROVIDE_INFO. The user is selecting from available options.',
            'CONFIRM_BOOKING' => 'Look for explicit confirmation (yes/no) or correction signals. Avoid defaulting to GENERAL_INQUIRY.',
            'SELECT_DOCTOR' => 'Names of doctors or departments should be PROVIDE_INFO. Medical questions should be GENERAL_INQUIRY.',
            'COLLECT_PATIENT_NAME' => 'Person names should be PROVIDE_INFO. Avoid classifying names as DOCTOR_QUERY unless context suggests it.',
            'COLLECT_PATIENT_DOB' => 'Birth dates should be PROVIDE_INFO. Look for date patterns or age-related phrases.',
            'COLLECT_PATIENT_PHONE' => 'Phone numbers should be PROVIDE_INFO. Look for numeric patterns or contact information.',
            default => 'Use general intent classification based on message content.',
        };
    }

    /**
     * Get task switching guidance for analysis
     */
    private function getTaskSwitchingGuidance(string $currentState, ?string $previousIntent): string
    {
        $guidance = "Look for potential task switching when:\n";

        if ($previousIntent) {
            $guidance .= "- User was previously doing: {$previousIntent}\n";
        }

        $guidance .= "- User requests something that contradicts previous intent\n";
        $guidance .= "- User asks about different type of operation (e.g., booking → canceling)\n";
        $guidance .= "- User shifts from providing information to asking questions\n\n";

        $guidance .= "Task switching indicators:\n";
        $guidance .= "- 'Actually, I want to...' or 'Wait, can I...' phrases\n";
        $guidance .= "- Questions about different operations during active booking\n";
        $guidance .= "- Requests for information unrelated to current task\n\n";

        if ($previousIntent) {
            $guidance .= "Special considerations:\n";
            if ($previousIntent === 'BOOK_APPOINTMENT') {
                $guidance .= "- During booking: 'cancel', 'reschedule', 'check my appointments' = task switch\n";
            } elseif ($previousIntent === 'CANCEL_APPOINTMENT') {
                $guidance .= "- During cancellation: 'book new', 'schedule', 'I want to book' = task switch\n";
            }
        }

        return $guidance;
    }

    /**
     * Validate and structure AI response
     */
    private function validateStructuredResponse(array $response): array
    {
        // Set defaults for required fields
        $validated = [
            'intent' => $response['intent'] ?? 'UNKNOWN',
            'confidence' => is_numeric($response['confidence'] ?? null) ? (float)($response['confidence']) : 0.0,
            'reasoning' => $response['reasoning'] ?? null,
            'next_action' => $response['next_action'] ?? 'CONTINUE',
            'response_text' => $response['response_text'] ?? 'I understand your request.',
            'updated_state' => $response['updated_state'] ?? null,
            'slots' => $response['slots'] ?? [],
            'requires_clarification' => $response['requires_clarification'] ?? false,
            'clarification_question' => $response['clarification_question'] ?? null,
            'task_switch_detected' => $response['task_switch_detected'] ?? false,
            'preserved_slots' => $response['preserved_slots'] ?? []
        ];

        // Validate intent is recognized
        if (!in_array($validated['intent'], IntentType::values())) {
            Log::warning('[OpenAI IntentParser] Unknown intent returned', [
                'intent' => $validated['intent'],
            ]);
            $validated['intent'] = IntentType::UNKNOWN->value;
            $validated['reasoning'] = 'Unknown intent, set to UNKNOWN';
        }

        // Validate confidence range
        $validated['confidence'] = max(0.0, min(1.0, $validated['confidence']));

        // Validate next_action
        $validActions = ['CONTINUE', 'CLARIFY', 'TASK_SWITCH', 'CONFIDENCE_LOW', 'ERROR'];
        if (!in_array($validated['next_action'], $validActions)) {
            $validated['next_action'] = 'CONTINUE';
        }

        return $validated;
    }

    /**
     * Generate clarification response when AI doesn't provide one
     */
    private function generateClarificationResponse(array $response, array $context): string
    {
        $intent = $response['intent'] ?? 'UNKNOWN';
        $state = $context['conversation_state'] ?? '';

        if ($intent === 'UNKNOWN') {
            return "I'm not sure what you'd like to do. Could you tell me if you want to book, cancel, or reschedule an appointment?";
        }

        if ($state === 'SELECT_DATE' || $state === 'SELECT_SLOT') {
            return "I'm not sure about the date or time you mentioned. Could you please specify that again?";
        }

        if ($state === 'SELECT_DOCTOR') {
            return "I'm not sure which doctor you're looking for. Could you provide the doctor's name or the department you need?";
        }

        return "I want to make sure I understand correctly. Could you please provide a bit more detail?";
    }

    /**
     * Generate clarification question when AI doesn't provide one
     */
    private function generateClarificationQuestion(array $response, array $context): string
    {
        $state = $context['conversation_state'] ?? '';

        return match($state) {
            'SELECT_DATE' => 'What date would you like for your appointment?',
            'SELECT_SLOT' => 'What time would you prefer for your appointment?',
            'SELECT_DOCTOR' => 'Which doctor would you like to see?',
            'COLLECT_PATIENT_NAME' => 'Could you please tell me your full name?',
            'COLLECT_PATIENT_DOB' => 'Could you please provide your date of birth?',
            'COLLECT_PATIENT_PHONE' => 'Could you please provide your phone number?',
            default => 'Could you please rephrase that or provide more details?'
        };
    }

    /**
     * Generate task switch response
     */
    private function generateTaskSwitchResponse(array $taskSwitchInfo): string
    {
        $previousIntent = $taskSwitchInfo['previous_intent'] ?? 'previous task';
        $newIntent = $taskSwitchInfo['new_intent'] ?? 'new task';

        return "I notice you're changing from {$previousIntent} to {$newIntent}. I'll help you with that. Any information you've already provided that's still relevant will be saved.";
    }

    /**
     * Get intent descriptions for prompt
     */
    private function getIntentDescriptions(): string
    {
        $descriptions = [];

        foreach (IntentType::cases() as $intent) {
            $descriptions[] = "- {$intent->value}: {$intent->description()}";
        }

        return implode("\n", $descriptions);
    }
}
