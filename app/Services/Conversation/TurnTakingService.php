<?php


namespace App\Services\Conversation;

use Illuminate\Support\Facades\Log;

/**
 * Turn Taking Service
 *
 * Manages turn-taking in conversations.
 * Determines when to respond, how to handle interruptions.
 */
class TurnTakingService
{
    private int $responseDelayMs;
    private int $silenceTimeoutMs;
    private int $maxWaitForSpeechMs;
    private bool $allowInterruptions;

    public function __construct()
    {
        $this->responseDelayMs = config('conversation.turn_taking.response_delay_ms', 300);
        $this->silenceTimeoutMs = config('conversation.turn_taking.silence_timeout_ms', 5000);
        $this->maxWaitForSpeechMs = config('conversation.turn_taking.max_wait_for_speech_ms', 10000);
        $this->allowInterruptions = config('conversation.turn_taking.allow_interruptions', true);
    }

    /**
     * Determine if system should respond
     */
    public function shouldRespond(array $context = []): bool
    {
        // Check if speech is final (from STT)
        $speechFinal = $context['speech_final'] ?? false;

        if (!$speechFinal) {
            return false;
        }

        // Check if we have complete text
        $hasText = !empty($context['text'] ?? '');

        return $hasText;
    }

    /**
     * Calculate response delay
     */
    public function getResponseDelay(array $context = []): int
    {
        // Base delay
        $delay = $this->responseDelayMs;

        // Add delay for complex queries (optional enhancement)
        $textLength = strlen($context['text'] ?? '');
        if ($textLength > 100) {
            $delay += 200; // Slightly longer pause for long messages
        }

        return $delay;
    }

    /**
     * Check if user interrupted
     */
    public function detectInterruption(array $context = []): bool
    {
        if (!$this->allowInterruptions) {
            return false;
        }

        $isSpeaking = $context['is_speaking'] ?? false;
        $systemResponding = $context['system_responding'] ?? false;

        return $isSpeaking && $systemResponding;
    }

    /**
     * Handle interruption
     */
    public function handleInterruption(): array
    {
        Log::info('[TurnTaking] User interruption detected');

        return [
            'action' => 'stop_response',
            'message' => 'Interruption detected, stopping current response',
        ];
    }

    /**
     * Check if should wait longer
     */
    public function shouldWaitLonger(int $elapsedMs, array $context = []): bool
    {
        // Don't wait longer than max
        if ($elapsedMs >= $this->maxWaitForSpeechMs) {
            return false;
        }

        // Wait if user seems to be continuing
        $hasInterimResults = !empty($context['interim_results'] ?? []);
        $recentActivity = ($context['last_activity_ms'] ?? PHP_INT_MAX) < 2000;

        return $hasInterimResults || $recentActivity;
    }

    /**
     * Get silence timeout
     */
    public function getSilenceTimeout(): int
    {
        return $this->silenceTimeoutMs;
    }

    /**
     * Mark turn as complete
     */
    public function markTurnComplete(string $sessionId): void
    {
        Log::debug('[TurnTaking] Turn complete', ['session' => $sessionId]);

        // Could store turn timing metrics here
    }

    /**
     * Check if timeout occurred
     */
    public function hasTimedOut(int $elapsedMs): bool
    {
        return $elapsedMs >= $this->maxWaitForSpeechMs;
    }
}
