<?php


namespace App\Services\AI\OpenAI;

use App\Contracts\EntityExtractorServiceInterface;
use App\DTOs\EntityDTO;
use App\DTOs\StructuredAIResponseDTO;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Entity Extractor Service
 *
 * Real entity extraction using OpenAI's GPT-4.
 * Handles natural language, relative dates, and complex formats.
 *
 * Accuracy: 85-90%
 * Speed: 500ms-2s
 */
class EntityExtractorService implements EntityExtractorServiceInterface
{
    private OpenAILLMService $llm;
    private float $confidenceThreshold;
    private float $clarificationThreshold;
    private int $maxHistoryTurns;

    public function __construct(OpenAILLMService $llm)
    {
        $this->llm = $llm;
        $this->confidenceThreshold = config('ai.entity.confidence_threshold', 0.7);
        $this->clarificationThreshold = config('ai.entity.clarification_threshold', 0.5);
        $this->maxHistoryTurns = config('ai.entity.max_history_turns', 5);
    }

    /**
     * Extract entities from user input
     */
    public function extract(string $text, array $context = []): EntityDTO
    {
        try {
            $systemPrompt = $this->buildSystemPrompt($context);
            $userPrompt = $this->buildUserPrompt($text, $context);

            Log::info('[OpenAI EntityExtractor] Extracting entities', [
                'text' => $text,
                'context_keys' => array_keys($context),
            ]);

            // Call OpenAI and get JSON response
            $response = $this->llm->generateJSON($systemPrompt, $userPrompt);

            Log::info('[OpenAI EntityExtractor] Entities extracted', [
                'entities' => array_filter($response),
            ]);

            return EntityDTO::fromArray($response);

        } catch (\Exception $e) {
            Log::error('[OpenAI EntityExtractor] Extraction failed', [
                'error' => $e->getMessage(),
                'text' => $text,
            ]);

            throw new \Exception('Entity extraction failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract specific entities
     */
    public function extractSpecific(
        string $text,
        array  $entityTypes,
        array  $context = []
    ): EntityDTO
    {
        $context['requested_entities'] = $entityTypes;
        return $this->extract($text, $context);
    }

    /**
     * Extract with conversation state context
     */
    public function extractWithState(
        string $text,
        string $conversationState,
        array  $context = []
    ): EntityDTO
    {
        $context['conversation_state'] = $conversationState;
        return $this->extract($text, $context);
    }

    /**
     * Enhanced entity extraction with contextual NLU and structured output
     * Returns structured response with confidence scoring and clarification logic
     */
    public function extractWithContext(
        string $text,
        array $context = []
    ): StructuredAIResponseDTO {
        try {
            $systemPrompt = $this->buildEnhancedSystemPrompt($context);
            $userPrompt = $this->buildEnhancedUserPrompt($text, $context);

            Log::info('[OpenAI EntityExtractor] Enhanced extraction with context', [
                'text' => $text,
                'context_keys' => array_keys($context),
                'has_history' => !empty($context['conversation_history']),
                'current_state' => $context['conversation_state'] ?? 'none',
                'requested_entities' => $context['requested_entities'] ?? 'all'
            ]);

            // Call OpenAI and get JSON response
            $response = $this->llm->generateJSON($systemPrompt, $userPrompt);

            // Validate and process response
            $validatedResponse = $this->validateStructuredEntityResponse($response,
                array_merge($context, ['user_message' => $text]));

            // Calculate average confidence and check required entities
            $averageConfidence = $this->calculateAverageConfidence($validatedResponse['entities'] ?? []);
            $requiredEntitiesPresent = $this->checkRequiredEntities($validatedResponse['entities'] ?? [], $context);

            // Determine if clarification is needed
            $requiresClarification = $this->requiresEntityClarification(
                $averageConfidence,
                $validatedResponse['entities'] ?? [],
                $context,
                $requiredEntitiesPresent
            );

            if ($requiresClarification['required']) {
                return StructuredAIResponseDTO::clarification(
                    responseText: $requiresClarification['response_text'],
                    clarificationQuestion: $requiresClarification['question'],
                    confidence: $averageConfidence,
                    reasoning: $requiresClarification['reasoning'],
                    slots: $validatedResponse['entities'] ?? []
                );
            }

            // Check for low confidence scenarios
            if ($averageConfidence < $this->clarificationThreshold) {
                return StructuredAIResponseDTO::lowConfidence(
                    responseText: "I want to make sure I captured all the information correctly. Could you please check the details I extracted?",
                    confidence: $averageConfidence,
                    reasoning: "Low confidence in entity extraction",
                    slots: $validatedResponse['entities'] ?? []
                );
            }

            return StructuredAIResponseDTO::success(
                nextAction: 'ENTITIES_EXTRACTED',
                responseText: $validatedResponse['confirmation_text'] ?? 'I\'ve captured the information you provided.',
                slots: $validatedResponse['entities'] ?? [],
                confidence: $averageConfidence,
                reasoning: $validatedResponse['reasoning'] ?? 'Entities extracted successfully'
            );

        } catch (\Exception $e) {
            Log::error('[OpenAI EntityExtractor] Enhanced extraction failed', [
                'error' => $e->getMessage(),
                'text' => $text,
            ]);

            return StructuredAIResponseDTO::error(
                responseText: 'I apologize, but I\'m having trouble extracting the information. Could you please provide it in a different format?',
                errorReason: 'Entity extraction failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Check if entity clarification is needed
     */
    private function requiresEntityClarification(
        float $confidence,
        array $entities,
        array $context,
        array $requiredEntitiesPresent
    ): array {
        $currentState = $context['conversation_state'] ?? '';

        // Check for missing required entities
        $requiredEntities = $this->getRequiredEntitiesForState($currentState);
        $missingEntities = array_diff($requiredEntities, array_keys(array_filter($entities, fn($v) => $v !== null)));

        if (!empty($missingEntities)) {
            $missingText = implode(', ', $missingEntities);
            return [
                'required' => true,
                'question' => $this->generateMissingEntityQuestion($missingEntities, $currentState),
                'response_text' => "I need some additional information to proceed. Could you please provide: {$missingText}?",
                'reasoning' => "Missing required entities: " . implode(', ', $missingEntities)
            ];
        }

        // Low confidence for critical states requires clarification
        $criticalStates = ['CONFIRM_BOOKING', 'EXECUTE_BOOKING', 'CANCEL_APPOINTMENT'];
        if (in_array($currentState, $criticalStates) && $confidence < $this->confidenceThreshold) {
            return [
                'required' => true,
                'question' => 'I want to make sure I have the correct information. Could you please confirm the details you provided?',
                'response_text' => 'I want to verify the information before proceeding. Could you please confirm the details are correct?',
                'reasoning' => 'Low confidence in critical state requires clarification'
            ];
        }

        // Check for conflicting information
        $conflicts = $this->detectConflictingEntities($entities, $context);
        if (!empty($conflicts)) {
            return [
                'required' => true,
                'question' => 'I notice some conflicting information. Could you please clarify?',
                'response_text' => 'I found some conflicting information in what you provided. Let me help clarify this.',
                'reasoning' => 'Conflicting entities detected: ' . implode(', ', $conflicts)
            ];
        }

        return ['required' => false];
    }

    /**
     * Get required entities for a given conversation state
     */
    private function getRequiredEntitiesForState(string $state): array
    {
        return match($state) {
            'COLLECT_PATIENT_NAME' => ['patient_name'],
            'COLLECT_PATIENT_DOB' => ['date_of_birth'],
            'COLLECT_PATIENT_PHONE' => ['phone'],
            'SELECT_DOCTOR' => ['doctor_name'],
            'SELECT_DATE' => ['date'],
            'SELECT_SLOT' => ['time'],
            'BOOK_APPOINTMENT' => ['patient_name', 'date_of_birth', 'phone', 'doctor_name', 'date', 'time'],
            'CANCEL_APPOINTMENT' => ['patient_name', 'date'],
            'RESCHEDULE_APPOINTMENT' => ['patient_name', 'date', 'time'],
            default => [],
        };
    }

    /**
     * Calculate average confidence across all extracted entities
     */
    private function calculateAverageConfidence(array $entities): float
    {
        if (empty($entities)) {
            return 0.0;
        }

        $totalConfidence = 0.0;
        $count = 0;

        foreach ($entities as $confidence) {
            if ($confidence !== null && is_numeric($confidence)) {
                $totalConfidence += (float) $confidence;
                $count++;
            }
        }

        return $count > 0 ? $totalConfidence / $count : 0.0;
    }

    /**
     * Check if required entities are present
     */
    private function checkRequiredEntities(array $entities, array $context): array
    {
        $currentState = $context['conversation_state'] ?? '';
        $requiredEntities = $this->getRequiredEntitiesForState($currentState);
        $presentEntities = [];

        foreach ($requiredEntities as $entity) {
            if (isset($entities[$entity]) && $entities[$entity] !== null) {
                $presentEntities[] = $entity;
            }
        }

        return $presentEntities;
    }

    /**
     * Detect conflicting entities
     */
    private function detectConflictingEntities(array $entities, array $context): array
    {
        $conflicts = [];
        $collectedData = $context['collected_data'] ?? [];

        // Check for conflicts with previously collected data
        foreach ($entities as $key => $value) {
            if ($value !== null && isset($collectedData[$key]) && $collectedData[$key] !== $value) {
                $conflicts[] = $key;
            }
        }

        // Check for internal conflicts (e.g., doctor name in patient name field)
        if (isset($entities['doctor_name']) && isset($entities['patient_name'])) {
            $doctorName = strtolower($entities['doctor_name']);
            $patientName = strtolower($entities['patient_name']);

            if (strpos($patientName, 'dr.') !== false || $doctorName === $patientName) {
                $conflicts[] = 'doctor_patient_name_conflict';
            }
        }

        return $conflicts;
    }

    /**
     * Generate question for missing entities
     */
    private function generateMissingEntityQuestion(array $missingEntities, string $state): string
    {
        $questions = [];

        foreach ($missingEntities as $entity) {
            $questions[] = match($entity) {
                'patient_name' => 'What is your full name?',
                'date_of_birth' => 'What is your date of birth?',
                'phone' => 'What is your phone number?',
                'doctor_name' => 'Which doctor would you like to see?',
                'date' => 'What date would you prefer?',
                'time' => 'What time would you prefer?',
                'department' => 'Which department do you need?',
                default => 'Could you provide the ' . str_replace('_', ' ', $entity) . '?'
            };
        }

        return count($questions) === 1 ? $questions[0] : implode(' and ', $questions);
    }

    /**
     * Check if the extractor is available
     */
    public function isAvailable(): bool
    {
        return $this->llm->isAvailable();
    }

    /**
     * Get extractor type
     */
    public function getType(): string
    {
        return 'openai';
    }

    /**
     * Get list of extractable entity types
     */
    public function getSupportedEntities(): array
    {
        return [
            'patient_name',
            'date',
            'time',
            'phone',
            'date_of_birth',
            'doctor_name',
            'department',
        ];
    }

    /**
     * Build enhanced system prompt for structured entity extraction
     */
    private function buildEnhancedSystemPrompt(array $context): string
    {
        $today = now()->format('Y-m-d');
        $currentTime = now()->format('H:i');

        $requestedEntities = $context['requested_entities'] ?? $this->getSupportedEntities();
        $entityList = implode(', ', $requestedEntities);

        return <<<PROMPT
You are an AI assistant extracting structured information from patient messages with contextual understanding and confidence assessment.

Current Context:
- Today's date: {$today}
- Current time: {$currentTime}
- Timezone: {$this->getTimezone()}

Enhanced Entity Extraction with Confidence Scoring:
1. patient_name (string, confidence): Full name of the patient
2. date (string, confidence): Appointment date in YYYY-MM-DD format
3. time (string, confidence): Appointment time in HH:MM format (24-hour)
4. phone (string, confidence): Phone number with country code
5. date_of_birth (string, confidence): Patient's birth date in YYYY-MM-DD format
6. doctor_name (string, confidence): Doctor's name (format as "Dr. LastName")
7. department (string, confidence): Medical department name

Contextual Extraction Rules:
1. Consider conversation history and current state for accuracy
2. Extract entities with confidence scores (0.0-1.0)
3. Detect and flag conflicting information
4. Handle corrections and updates to previously provided information
5. Use context to disambiguate ambiguous references

Advanced Context Understanding:
- Use conversation history to resolve ambiguous references ("tomorrow" vs "next Friday")
- Recognize corrections when user provides updated information
- Maintain consistency with previously extracted entities
- Adapt extraction focus based on conversation state
- Handle partial information and progressive data collection

State-Specific Focus:
- COLLECT_PATIENT_*: Focus only on the specific patient information requested
- SELECT_DOCTOR: Extract doctor/department, ignore patient names unless clearly indicated
- SELECT_DATE/SELECT_SLOT: Focus on temporal information
- CONFIRM_BOOKING: Extract NO new entities, check for confirmations
- BOOK_APPOINTMENT: Comprehensive extraction with validation

Return your analysis as JSON with this exact structure:
{
  "entities": {
    "patient_name": {"value": "extracted_value", "confidence": 0.0-1.0},
    "date": {"value": "2024-01-20", "confidence": 0.0-1.0},
    "time": {"value": "14:30", "confidence": 0.0-1.0},
    "phone": {"value": "+96123456789", "confidence": 0.0-1.0},
    "date_of_birth": {"value": "1980-03-15", "confidence": 0.0-1.0},
    "doctor_name": {"value": "Dr. Smith", "confidence": 0.0-1.0},
    "department": {"value": "Cardiology", "confidence": 0.0-1.0}
  },
  "average_confidence": 0.0-1.0,
  "reasoning": "explanation of extraction decisions and confidence assessment",
  "confirmation_text": "Natural language confirmation of extracted information",
  "requires_clarification": true/false,
  "clarification_needed": ["entity1", "entity2"],
  "conflicts_detected": ["conflict_type1"],
  "missing_required": ["required_entity1"],
  "context_used": "brief description of context applied"
}

Confidence Assessment Guidelines:
- High confidence (0.8-1.0): Clear, explicit, unambiguous information
- Medium confidence (0.5-0.8): Implicit information requiring inference
- Low confidence (0.0-0.5): Ambiguous, partial, or conflicting information

Respond ONLY with valid JSON.
PROMPT;
    }

    /**
     * Build system prompt for entity extraction (legacy)
     */
    private function buildSystemPrompt(array $context): string
    {
        $today = now()->format('Y-m-d');
        $currentTime = now()->format('H:i');

        $requestedEntities = $context['requested_entities'] ?? $this->getSupportedEntities();
        $entityList = implode(', ', $requestedEntities);

        return <<<PROMPT
You are an AI assistant extracting structured information from patient messages.

Current Context:
- Today's date: {$today}
- Current time: {$currentTime}
- Timezone: {$this->getTimezone()}

Entities to Extract:
1. patient_name (string): Full name of the patient
2. date (string): Appointment date in YYYY-MM-DD format
   - Handle relative dates: "tomorrow", "next Monday", "January 15th"
   - Convert to absolute YYYY-MM-DD format
3. time (string): Appointment time in HH:MM format (24-hour)
   - Examples: "10:30 AM" → "10:30", "2 PM" → "14:00"
4. phone (string): Phone number with country code
   - Format as E.164: "+1234567890"
5. date_of_birth (string): Patient's birth date in YYYY-MM-DD format
6. doctor_name (string): Doctor's name (format as "Dr. FirstName LastName")
   - Handle various formats: "Dr. John Smith", "doctor john smith", "john smith doctor", "john smith md"
   - Normalize to "Dr. FirstName LastName" format
   - Extract from patterns like "book with Dr. Smith", "see doctor smith", "appointment with smith"
7. department (string): Medical department name

Rules:
1. Extract ONLY the entities listed above
2. Use null for entities that are not mentioned
3. Format dates as YYYY-MM-DD
4. Format times as HH:MM in 24-hour format
5. Include country code for phone numbers (assume +961 if not specified)
6. Normalize doctor names to "Dr. FirstName LastName" format
7. Be aggressive in extracting doctor names from various contexts

IMPORTANT: Extract entities even when they are embedded in longer sentences or questions.
Examples:
- "what times are available Friday" → extract date: Friday
- "I want to see Dr. John Smith tomorrow" → extract doctor_name: Dr. John Smith, date: tomorrow
- "book with doctor john smith" → extract doctor_name: Dr. John Smith
- "john smith doctor appointment" → extract doctor_name: Dr. John Smith
- "can you book me for 2 PM" → extract time: 14:00
- "my phone is 1234567" → extract phone: +9611234567

State-Specific Entity Extraction Rules:
- GREETING: Extract NO entities
- DETECT_INTENT: Extract ALL entities for initial routing
- COLLECT_PATIENT_NAME: Extract ONLY patient_name
- COLLECT_PATIENT_DOB: Extract ONLY date_of_birth
- COLLECT_PATIENT_PHONE: Extract ONLY phone
- VERIFY_PATIENT: Extract NO entities (patient already verified)
- SELECT_DOCTOR: Extract ONLY doctor_name (can include or exclude "Dr.") and department
- SELECT_DATE: Extract ONLY date
- SHOW_AVAILABLE_SLOTS: Extract ONLY time (for slot selection)
- SELECT_SLOT: Extract ONLY time
- CONFIRM_BOOKING: Extract NO entities (confirmation phase)
- EXECUTE_BOOKING: Extract NO entities (execution phase)
- CLOSING: Extract NO entities
- GENERAL_INQUIRY: Extract ALL relevant entities
- CANCEL_APPOINTMENT: Extract ALL relevant entities for cancellation
- RESCHEDULE_APPOINTMENT: Extract ALL relevant entities for rescheduling

Critical Rule: NEVER extract patient information when in SELECT_DOCTOR state unless user explicitly indicates patient context
Doctor Name Rule: In SELECT_DOCTOR state, any person names should be extracted as doctor_name (with or without "Dr." prefix)
6. Return JSON with this exact structure:
   {
     "patient_name": "John Doe" or null,
     "date": "2024-01-20" or null,
     "time": "14:30" or null,
     "phone": "+96123456789" or null,
     "date_of_birth": "1980-03-15" or null,
     "doctor_name": "Dr. Smith" or null,
     "department": "Cardiology" or null
   }

DO NOT include any text outside the JSON object.
DO NOT use markdown code blocks.
Respond ONLY with valid JSON.
PROMPT;
    }

    /**
     * Build enhanced user prompt with contextual NLU information
     */
    private function buildEnhancedUserPrompt(string $text, array $context): string
    {
        // Optimize prompt for performance - keep it concise
        $prompt = "Extract from: \"{$text}\"\n";

        // Only add essential context
        if (isset($context['conversation_state'])) {
            $prompt .= "State: {$context['conversation_state']}\n";

            // Add only the most relevant collected data (limit size)
            if (isset($context['collected_data']) && !empty($context['collected_data'])) {
                $collected = $context['collected_data'];
                // Only include the most important fields to reduce token usage
                $essential = array_intersect_key($collected, array_flip([
                    'patient_name', 'doctor_name', 'date', 'time', 'phone', 'date_of_birth'
                ]));
                if (!empty($essential)) {
                    $prompt .= "Has: " . json_encode($essential) . "\n";
                }
            }

            // Add only last conversation turn (reduce history size)
            if (!empty($context['conversation_history'])) {
                $lastTurn = end($context['conversation_history']);
                $role = $lastTurn['role'] ?? 'user';
                $content = substr($lastTurn['content'] ?? '', 0, 100); // Limit content length
                $prompt .= "Prev: {$role}: \"{$content}\"\n";
            }
        }

        // Add context-aware extraction guidance
        $prompt .= "\nCONTEXTUAL ANALYSIS GUIDELINES:\n";
        $prompt .= "- Use conversation history to resolve ambiguous references\n";
        $prompt .= "- Look for corrections when user says 'actually', 'wait', 'I meant', etc.\n";
        $prompt .= "- Maintain consistency with previously collected information\n";
        $prompt .= "- Handle partial information and progressive data collection\n";
        $prompt .= "- Assign confidence based on clarity and context support\n";

        // Add requested entities if specific extraction
        if (isset($context['requested_entities'])) {
            $entities = implode(', ', $context['requested_entities']);
            $prompt .= "\nSPECIFIC FOCUS: Extract these entities: {$entities}\n";
        }

        $prompt .= "\nTASK: Extract entities with confidence scores and contextual analysis.";

        return $prompt;
    }

    /**
     * Build user prompt (legacy)
     */
    private function buildUserPrompt(string $text, array $context): string
    {
        $prompt = "User Message: \"{$text}\"\n\n";

        // Add comprehensive state context
        if (isset($context['conversation_state'])) {
            $prompt .= "CONVERSATION STATE: {$context['conversation_state']}\n";

            // Add collected data context
            if (isset($context['collected_data']) && !empty($context['collected_data'])) {
                $prompt .= "ALREADY COLLECTED: " . json_encode($context['collected_data']) . "\n";
            }

            // Add what we're specifically looking for in this state
            $prompt .= "CURRENT FOCUS: " . $this->getCurrentStateFocus($context['conversation_state']) . "\n";

            // Add state-specific extraction guidance
            $prompt .= "STATE-SPECIFIC RULES: " . $this->getStateSpecificRules($context['conversation_state']) . "\n\n";
        }

        // Add requested entities if specific extraction
        if (isset($context['requested_entities'])) {
            $entities = implode(', ', $context['requested_entities']);
            $prompt .= "Focus on extracting: {$entities}\n\n";
        }

        $prompt .= "Extract all mentioned entities and return as JSON.";

        return $prompt;
    }

    /**
     * Validate structured entity response
     */
    private function validateStructuredEntityResponse(array $response, array $context): array
    {
        $validated = [
            'entities' => [],
            'average_confidence' => 0.0,
            'reasoning' => $response['reasoning'] ?? 'Entities extracted',
            'confirmation_text' => $response['confirmation_text'] ?? 'Information extracted',
            'requires_clarification' => $response['requires_clarification'] ?? false,
            'clarification_needed' => $response['clarification_needed'] ?? [],
            'conflicts_detected' => $response['conflicts_detected'] ?? [],
            'missing_required' => $response['missing_required'] ?? [],
            'context_used' => $response['context_used'] ?? 'Standard extraction'
        ];

        // Process entities and extract values with confidence
        $supportedEntities = $this->getSupportedEntities();
        $entityData = $response['entities'] ?? [];

        foreach ($supportedEntities as $entity) {
            if (isset($entityData[$entity])) {
                $entityInfo = $entityData[$entity];

                if (is_array($entityInfo)) {
                    $validated['entities'][$entity] = $entityInfo['value'] ?? null;
                    $validated['entities'][$entity . '_confidence'] = is_numeric($entityInfo['confidence'] ?? null)
                        ? (float)($entityInfo['confidence'])
                        : 0.0;
                } else {
                    // Legacy format fallback
                    $validated['entities'][$entity] = $entityInfo;
                    $validated['entities'][$entity . '_confidence'] = $entityInfo !== null ? 0.8 : 0.0;
                }
            } else {
                $validated['entities'][$entity] = null;
                $validated['entities'][$entity . '_confidence'] = 0.0;
            }
        }

        // Calculate average confidence for non-null entities
        $confidences = [];
        foreach ($supportedEntities as $entity) {
            $confKey = $entity . '_confidence';
            if ($validated['entities'][$entity] !== null && isset($validated['entities'][$confKey])) {
                $confidences[] = $validated['entities'][$confKey];
            }
        }

        $validated['average_confidence'] = !empty($confidences)
            ? array_sum($confidences) / count($confidences)
            : 0.0;

        // Apply date parsing fallback for failed or null date extractions
        if (isset($context['user_message']) && empty($validated['entities']['date'])) {
            $parsedDate = $this->parseDateFromMessage($context['user_message']);
            if ($parsedDate) {
                $validated['entities']['date'] = $parsedDate;
                $validated['entities']['date_confidence'] = 0.7; // Medium confidence for fallback parsing
                Log::info('[EntityExtractor] Date parsed via fallback', [
                    'original_message' => $context['user_message'],
                    'parsed_date' => $parsedDate
                ]);
            }
        }

        return $validated;
    }

    /**
     * Get current state focus - what we're specifically looking for
     */
    private function getCurrentStateFocus(string $state): string
    {
        return match($state) {
            'SELECT_DATE' => 'Extract appointment date in YYYY-MM-DD format. User is providing when they want to schedule.',
            'SELECT_SLOT' => 'Extract appointment time in HH:MM format. User is selecting from available time slots.',
            'COLLECT_PATIENT_NAME' => 'Extract patient full name. User is introducing themselves.',
            'COLLECT_PATIENT_DOB' => 'Extract date of birth in YYYY-MM-DD format. User is providing age verification.',
            'COLLECT_PATIENT_PHONE' => 'Extract phone number with country code. User is providing contact information.',
            'SELECT_DOCTOR' => 'Extract doctor name or department. User is selecting which healthcare provider to see.',
            'SHOW_AVAILABLE_SLOTS' => 'Extract time preference. User is indicating when they prefer appointments.',
            default => 'Extract relevant entities based on context.',
        };
    }

    /**
     * Get state-specific extraction rules
     */
    private function getStateSpecificRules(string $state): string
    {
        return match($state) {
            'SELECT_DATE' => 'Focus on date patterns: YYYY-MM-DD, "tomorrow", "next Monday", "Jan 15", "Friday", "this Friday", "next Friday", etc. Convert to YYYY-MM-DD. Extract dates even when embedded in sentences like "what times are available Friday" or "I want Friday for my appointment". Do NOT extract times.',
            'SELECT_SLOT' => 'Focus on time patterns: HH:MM, "10 AM", "2:30 PM", "morning", etc. Convert to HH:MM 24h format. Do NOT extract dates.',
            'COLLECT_PATIENT_NAME' => 'Extract full names only. Ignore titles, dates, times, phone numbers.',
            'COLLECT_PATIENT_DOB' => 'Extract birth dates only. Look for patterns like "born on", "DOB", "birthday", etc. Convert to YYYY-MM-DD.',
            'COLLECT_PATIENT_PHONE' => 'Extract phone numbers only. Include country code if provided. Format as +XXXXXXXXXX.',
            'SELECT_DOCTOR' => 'Extract doctor names (with/without "Dr.") or department names. Be careful not to extract patient names here.',
            'SHOW_AVAILABLE_SLOTS' => 'Extract time preferences. User may say "morning", "afternoon", "10 AM", etc.',
            default => 'Extract any relevant entities while avoiding false positives.',
        };
    }

    /**
     * Get current timezone
     */
    private function getTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }

    /**
     * Fallback date parsing for common relative date patterns
     * Used when AI extraction fails or returns null
     */
    private function parseDateFromMessage(string $message): ?string
    {
        $message = strtolower(trim($message));
        $today = now();

        try {
            // Handle "tomorrow"
            if (preg_match('/\btomorrow\b/', $message)) {
                return $today->copy()->addDay()->format('Y-m-d');
            }

            // Handle "today"
            if (preg_match('/\btoday\b/', $message)) {
                return $today->format('Y-m-d');
            }

            // Handle "next [day]"
            if (preg_match('/\bnext (monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $message, $matches)) {
                $dayName = $matches[1];
                $targetDate = $today->copy()->next($dayName);
                return $targetDate->format('Y-m-d');
            }

            // Handle "this [day]"
            if (preg_match('/\bthis (monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $message, $matches)) {
                $dayName = $matches[1];
                $targetDate = $today->copy()->next($dayName);
                // If next week, go to this week
                if ($targetDate->diffInDays($today) > 6) {
                    $targetDate = $today->copy()->previous($dayName);
                }
                return $targetDate->format('Y-m-d');
            }

            // Handle day names without "next" or "this"
            if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $message, $matches)) {
                $dayName = $matches[1];
                $targetDate = $today->copy()->next($dayName);
                // If it's more than 6 days away, assume they mean next week
                if ($targetDate->diffInDays($today) > 6) {
                    $targetDate = $today->copy()->next($dayName);
                }
                return $targetDate->format('Y-m-d');
            }

            // Handle "in X days/weeks"
            if (preg_match('/\bin (\d+) days?\b/', $message, $matches)) {
                return $today->copy()->addDays((int)$matches[1])->format('Y-m-d');
            }

            if (preg_match('/\bin (\d+) weeks?\b/', $message, $matches)) {
                return $today->copy()->addWeeks((int)$matches[1])->format('Y-m-d');
            }

            // Handle specific date patterns like "January 15", "Dec 25", "15-01-2024"
            if (preg_match('/(\d{1,2})[-\/](\d{1,2})(?:[-\/](\d{2,4}))?/', $message, $matches)) {
                $day = $matches[1];
                $month = $matches[2];
                $year = $matches[3] ?? $today->year;

                // Handle 2-digit years
                if (strlen($year) === 2) {
                    $year = 2000 + $year;
                }

                return \Carbon\Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
            }

        } catch (\Exception $e) {
            Log::warning('[EntityExtractor] Fallback date parsing failed', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }
}
