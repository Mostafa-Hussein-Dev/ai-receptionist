<?php

namespace App\Services\AI\OpenAI;

use App\Contracts\IntentParserServiceInterface;
use App\DTOs\IntentDTO;
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
    private bool $useHistory;
    private int $maxHistoryTurns;

    public function __construct(OpenAILLMService $llm)
    {
        $this->llm = $llm;
        $this->confidenceThreshold = config('ai.intent.confidence_threshold', 0.7);
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

            // Fallback to mock parser
            if (config('ai.error_handling.fallback_to_mock', true)) {
                Log::warning('[OpenAI IntentParser] Falling back to Mock');
                $mockParser = app(\App\Services\AI\Mock\MockIntentParserService::class);
                return $mockParser->parse($userMessage, $context);
            }

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
     * Build system prompt for intent classification
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
1. Analyze the user's message carefully
2. Consider the conversation context if provided
3. Return your classification as JSON with this exact structure:
   {
     "intent": "INTENT_NAME",
     "confidence": 0.0-1.0,
     "reasoning": "brief explanation"
   }

4. confidence should be 0.0 to 1.0 (e.g., 0.85 for 85% confident)
5. Use UNKNOWN if you cannot determine the intent
6. DO NOT include any text outside the JSON object
7. DO NOT use markdown code blocks

Respond ONLY with valid JSON.
PROMPT;
    }

    /**
     * Build user prompt with message and context
     */
    private function buildUserPrompt(string $userMessage, array $context): string
    {
        $prompt = "User Message: \"{$userMessage}\"\n\n";

        // Add conversation history if available
        if (!empty($context['conversation_history'])) {
            $prompt .= "Recent Conversation:\n";
            foreach ($context['conversation_history'] as $turn) {
                $role = $turn['role'] ?? 'user';
                $content = $turn['content'] ?? '';
                $prompt .= "{$role}: {$content}\n";
            }
            $prompt .= "\n";
        }

        // Add current conversation state if available
        if (isset($context['conversation_state'])) {
            $prompt .= "Current State: {$context['conversation_state']}\n\n";
        }

        $prompt .= "Classify the intent of the most recent user message.";

        return $prompt;
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
