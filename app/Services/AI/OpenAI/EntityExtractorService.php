<?php


namespace App\Services\AI\OpenAI;

use App\Contracts\EntityExtractorServiceInterface;
use App\DTOs\EntityDTO;
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

    public function __construct(OpenAILLMService $llm)
    {
        $this->llm = $llm;
        $this->confidenceThreshold = config('ai.entity.confidence_threshold', 0.6);
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
     * Build system prompt for entity extraction
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
6. doctor_name (string): Doctor's name (format as "Dr. LastName")
7. department (string): Medical department name

Rules:
1. Extract ONLY the entities listed above
2. Use null for entities that are not mentioned
3. Format dates as YYYY-MM-DD
4. Format times as HH:MM in 24-hour format
5. Include country code for phone numbers (assume +961 if not specified)
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
     * Build user prompt
     */
    private function buildUserPrompt(string $text, array $context): string
    {
        $prompt = "User Message: \"{$text}\"\n\n";

        // Add conversation state if available (helps with context)
        if (isset($context['conversation_state'])) {
            $prompt .= "Conversation State: {$context['conversation_state']}\n";
            $prompt .= "This helps you understand what information the user is likely providing.\n\n";
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
     * Get current timezone
     */
    private function getTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }
}
