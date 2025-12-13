<?php


namespace App\Services\AI\Mock;

use App\Contracts\IntentParserServiceInterface;
use App\DTOs\IntentDTO;
use App\Enums\IntentType;

/**
 * Mock Intent Parser Service
 *
 * Keyword-based intent classification for testing without external API.
 * Uses simple pattern matching to detect user intent.
 *
 * Accuracy: 70-80%
 * Speed: Instant
 * Cost: Free
 */
class MockIntentParserService implements IntentParserServiceInterface
{
    private float $defaultConfidence;
    private bool $verbose;

    public function __construct()
    {
        $this->defaultConfidence = config('ai.mock.default_confidence', 0.85);
        $this->verbose = config('ai.mock.verbose', false);
    }

    /**
     * Parse user input and detect intent
     */
    public function parse(string $userMessage, array $context = []): IntentDTO
    {
        $input = strtolower(trim($userMessage));

        if ($this->verbose) {
            Log::info('[MockIntentParser] Parsing', [
                'message' => $userMessage,
                'context' => $context,
            ]);
        }

        // Check each intent pattern
        $result = $this->detectIntent($input, $context);

        if ($this->verbose) {
            Log::info('[MockIntentParser] Result', [
                'intent' => $result->intent,
                'confidence' => $result->confidence,
            ]);
        }

        return $result;
    }

    /**
     * Parse with conversation history for better context
     */
    public function parseWithHistory(
        string $userMessage,
        array  $conversationHistory = [],
        array  $context = []
    ): IntentDTO
    {
        // For mock, we don't use history much, but check if previous was a question
        $input = strtolower(trim($userMessage));

        // Check if this is an answer to a previous question
        if (!empty($conversationHistory)) {
            $lastAssistantMessage = collect($conversationHistory)
                ->reverse()
                ->first(fn($msg) => ($msg['role'] ?? '') === 'assistant');

            if ($lastAssistantMessage) {
                $lastContent = strtolower($lastAssistantMessage['content'] ?? '');

                // If last message was asking for name
                if (str_contains($lastContent, 'name') || str_contains($lastContent, 'who')) {
                    return new IntentDTO(
                        intent: IntentType::PROVIDE_INFO->value,
                        confidence: 0.9,
                        reasoning: 'User answering name question',
                        metadata: ['context' => 'providing_name']
                    );
                }

                // If last message was asking for DOB
                if (str_contains($lastContent, 'birth') || str_contains($lastContent, 'dob')) {
                    return new IntentDTO(
                        intent: IntentType::PROVIDE_INFO->value,
                        confidence: 0.9,
                        reasoning: 'User answering DOB question',
                        metadata: ['context' => 'providing_dob']
                    );
                }
            }
        }

        return $this->parse($userMessage, $context);
    }

    /**
     * Check if the parser is available
     */
    public function isAvailable(): bool
    {
        return true; // Mock is always available
    }

    /**
     * Get parser type
     */
    public function getType(): string
    {
        return 'mock';
    }

    /**
     * Detect intent from input
     */
    private function detectIntent(string $input, array $context): IntentDTO
    {
        // ** PRIORITY ORDER MATTERS **
        // Most specific → least specific

        // 1. CANCEL APPOINTMENT
        if ($this->matchesAny($input, [
            'cancel', 'cancel my', 'cancel the', 'delete my appointment',
            'remove my appointment', 'don\'t need the appointment'
        ])) {
            return new IntentDTO(
                intent: IntentType::CANCEL_APPOINTMENT->value,
                confidence: 0.92,
                reasoning: 'Cancellation keywords detected'
            );
        }

        // 2. RESCHEDULE APPOINTMENT
        if ($this->matchesAny($input, [
            'resched', 'change my appointment', 'move my appointment',
            'different time', 'different day', 'reschedule'
        ])) {
            return new IntentDTO(
                intent: IntentType::RESCHEDULE_APPOINTMENT->value,
                confidence: 0.90,
                reasoning: 'Rescheduling keywords detected'
            );
        }

        // 3. CHECK APPOINTMENT
        if ($this->matchesAny($input, [
            'when is my appointment', 'what time is my appointment',
            'check my appointment', 'verify my appointment',
            'confirm my appointment', 'my appointment'
        ])) {
            return new IntentDTO(
                intent: IntentType::CHECK_APPOINTMENT->value,
                confidence: 0.88,
                reasoning: 'Check appointment keywords detected'
            );
        }

        // 4. BOOK APPOINTMENT
        if ($this->matchesAny($input, [
            'book', 'schedule', 'make an appointment',
            'need an appointment', 'want to see', 'set an appointment'
        ])) {
            return new IntentDTO(
                intent: IntentType::BOOK_APPOINTMENT->value,
                confidence: 0.85,
                reasoning: 'Booking keywords detected'
            );
        }

        // 5. GREETING
        if ($this->matchesAny($input, [
            'hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening'
        ])) {
            return new IntentDTO(
                intent: IntentType::GREETING->value,
                confidence: 0.95,
                reasoning: 'Greeting detected'
            );
        }

        // 6. GOODBYE
        if ($this->matchesAny($input, [
            'goodbye', 'bye', 'see you', 'thanks that\'s all',
            'i\'m done', 'that\'s everything'
        ])) {
            return new IntentDTO(
                intent: IntentType::GOODBYE->value,
                confidence: 0.95,
                reasoning: 'Goodbye detected'
            );
        }

        // 7. CONFIRM
        if ($this->matchesAny($input, [
            'yes', 'yeah', 'yep', 'ok', 'okay', 'correct', 'that’s right'
        ])) {
            return new IntentDTO(
                intent: IntentType::CONFIRM->value,
                confidence: 0.90,
                reasoning: 'Confirmation detected'
            );
        }

        // 8. DENY
        if ($this->matchesAny($input, [
            'no', 'nope', 'not correct', 'that is wrong'
        ])) {
            return new IntentDTO(
                intent: IntentType::DENY->value,
                confidence: 0.90,
                reasoning: 'Denial detected'
            );
        }

        // 9. GENERAL INQUIRY
        if ($this->matchesAny($input, [
            'location', 'hours', 'open', 'close', 'insurance', 'accept'
        ])) {
            return new IntentDTO(
                intent: IntentType::GENERAL_INQUIRY->value,
                confidence: 0.80,
                reasoning: 'General inquiry keywords detected'
            );
        }

        // 10. PROVIDE INFORMATION
        if ($this->looksLikeInformation($input)) {
            return new IntentDTO(
                intent: IntentType::PROVIDE_INFO->value,
                confidence: 0.70,
                reasoning: 'User providing information'
            );
        }

        // 11. UNKNOWN
        return new IntentDTO(
            intent: IntentType::UNKNOWN->value,
            confidence: 0.50,
            reasoning: 'No clear intent detected'
        );
    }

    /**
     * Check if input matches any of the keywords
     */
    private function matchesKeywords(string $input, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($input, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if input looks like providing information
     */
    private function looksLikeInformation(string $input): bool
    {
        // Contains name patterns
        if (preg_match('/my name is|i am|this is|i\'m/i', $input)) {
            return true;
        }

        // Contains date patterns
        if (preg_match('/\d{1,2}\/\d{1,2}\/\d{2,4}|\d{4}-\d{2}-\d{2}/', $input)) {
            return true;
        }

        // Contains phone number patterns
        if (preg_match('/\d{3}[-.]?\d{3}[-.]?\d{4}/', $input)) {
            return true;
        }

        // Contains time references
        if (preg_match('/tomorrow|next week|monday|tuesday|wednesday|thursday|friday|morning|afternoon|evening|am|pm/i', $input)) {
            return true;
        }

        return false;
    }

    private function matchesAny(string $input, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($input, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

}
