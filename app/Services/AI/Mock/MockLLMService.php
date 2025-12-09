<?php


namespace App\Services\AI\Mock;

use App\Contracts\LLMServiceInterface;

/**
 * Mock LLM Service
 *
 * Rule-based language model for testing without external API.
 * Uses keyword matching and templates to generate responses.
 *
 * Accuracy: 70-80%
 * Speed: Instant
 * Cost: Free
 */
class MockLLMService implements LLMServiceInterface
{
    private float $delay;
    private bool $verbose;

    public function __construct()
    {
        $this->delay = config('ai.mock.delay_ms', 100);
        $this->verbose = config('ai.mock.verbose', false);
    }

    /**
     * Send a chat completion request
     */
    public function chat(string $systemPrompt, array $messages): string
    {
        $this->simulateDelay();

        // Get the last user message
        $lastMessage = collect($messages)->last(fn($msg) => $msg['role'] === 'user');

        if (!$lastMessage) {
            return "I didn't receive a message. Could you please try again?";
        }

        $userContent = $lastMessage['content'] ?? '';

        if ($this->verbose) {
            Log::info('[MockLLM] Chat request', [
                'message_count' => count($messages),
                'last_message' => $userContent,
            ]);
        }

        return $this->generateResponse($userContent, $systemPrompt);
    }

    /**
     * Send a single prompt and get response
     */
    public function complete(string $prompt): string
    {
        $this->simulateDelay();

        if ($this->verbose) {
            Log::info('[MockLLM] Complete request', [
                'prompt_length' => strlen($prompt),
            ]);
        }

        return $this->generateResponse($prompt);
    }

    /**
     * Check if the service is available
     */
    public function isAvailable(): bool
    {
        return true; // Mock is always available
    }

    /**
     * Get service provider name
     */
    public function getProvider(): string
    {
        return 'mock';
    }

    /**
     * Get model being used
     */
    public function getModel(): string
    {
        return 'mock-llm';
    }

    /**
     * Generate response based on input
     */
    private function generateResponse(string $input, string $systemPrompt = ''): string
    {
        $input = strtolower($input);

        // Greeting detection
        if ($this->contains($input, ['hello', 'hi', 'hey', 'good morning', 'good afternoon'])) {
            return "Hello! How may I help you today?";
        }

        // Booking intent
        if ($this->contains($input, ['book', 'schedule', 'appointment', 'make appointment'])) {
            return "I'd be happy to help you book an appointment. May I have your full name please?";
        }

        // Cancellation intent
        if ($this->contains($input, ['cancel', 'delete', 'remove appointment'])) {
            return "I can help you cancel an appointment. Could you please provide your name and date of birth for verification?";
        }

        // Rescheduling intent
        if ($this->contains($input, ['reschedule', 'change', 'move appointment', 'different time'])) {
            return "I can help you reschedule your appointment. First, let me verify your identity. What's your name?";
        }

        // Check appointment
        if ($this->contains($input, ['check', 'when is', 'what time', 'my appointment'])) {
            return "Let me check your appointment. What's your name and date of birth?";
        }

        // Providing name
        if ($this->contains($input, ['my name is', 'i am', 'this is', 'name:', "i'm"])) {
            return "Thank you. What's your date of birth?";
        }

        // Providing phone
        if ($this->matchesPattern($input, '/\d{3}[-.]?\d{3}[-.]?\d{4}/')) {
            return "Thank you. What type of appointment do you need?";
        }

        // Date mentioned
        if ($this->contains($input, ['tomorrow', 'next week', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'january', 'february', 'march'])) {
            return "Let me check available times for that date. What time would work best for you - morning or afternoon?";
        }

        // Time mentioned
        if ($this->contains($input, ['morning', 'afternoon', '9', '10', '11', '12', '1', '2', 'am', 'pm'])) {
            return "Perfect! Let me find available slots at that time.";
        }

        // Confirmation
        if ($this->contains($input, ['yes', 'correct', 'that\'s right', 'sounds good', 'okay', 'ok'])) {
            return "Great! I've confirmed that for you. Is there anything else I can help you with?";
        }

        // Denial
        if ($this->contains($input, ['no', 'not', 'wrong', 'incorrect', 'nope'])) {
            return "I apologize for the confusion. Let me try again. Could you please clarify?";
        }

        // Help/unclear
        if ($this->contains($input, ['help', 'what can you do', 'confused', 'don\'t understand'])) {
            return "I can help you book, cancel, or reschedule appointments. I can also answer general questions about our clinic. What would you like to do?";
        }

        // Goodbye
        if ($this->contains($input, ['goodbye', 'bye', 'thanks', 'thank you', 'that\'s all'])) {
            return "You're welcome! Thank you for calling. Have a great day!";
        }

        // Default response
        return "I didn't understand. Could you please provide more details?";
    }

    /**
     * Check if input contains any of the keywords
     */
    private function contains(string $input, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($input, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if input matches a pattern
     */
    private function matchesPattern(string $input, string $pattern): bool
    {
        return preg_match($pattern, $input) === 1;
    }

    /**
     * Simulate processing delay for realistic testing
     */
    private function simulateDelay(): void
    {
        if ($this->delay > 0) {
            usleep($this->delay * 1000); // Convert ms to microseconds
        }
    }
}
