<?php


namespace App\Orchestrators;

use App\Contracts\SessionManagerServiceInterface;
use App\Contracts\DialogueManagerServiceInterface;
use App\Models\PostgreSQL\Call;
use App\Enums\ConversationState;
use App\DTOs\SessionDTO;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Call Orchestrator
 *
 * Manages the entire lifecycle of a call/conversation session.
 * Creates sessions, processes messages, ends calls.
 */
class CallOrchestrator
{
    public function __construct(
        private SessionManagerServiceInterface  $sessionManager,
        private DialogueManagerServiceInterface $dialogueManager,
        private ConversationOrchestrator        $conversationOrchestrator
    )
    {
    }

    /**
     * Initiate new call
     */
    public function initiateCall(string $channel, string $externalId, array $metadata = []): array
    {
        try {
            // Create call record
            $call = Call::create([
                'external_id' => $externalId,
                'channel' => $channel,
                'from_number' => $metadata['from'] ?? null,
                'to_number' => $metadata['to'] ?? null,
                'status' => 'initiated',
                'started_at' => now(),
                'metadata' => $metadata,
            ]);

            Log::info('[CallOrchestrator] Call initiated', [
                'call_id' => $call->id,
                'channel' => $channel,
                'external_id' => $externalId,
            ]);

            // Create session
            $sessionId = $this->generateSessionId($channel, $externalId);

            $this->sessionManager->create($sessionId, [
                'call_id' => $call->id,
                'channel' => $channel,
                'external_id' => $externalId,
                'conversation_state' => ConversationState::GREETING->value,
            ]);

            // Generate greeting
            $greeting = $this->dialogueManager->getGreeting();

            // Update call status
            $call->update([
                'status' => 'in_progress',
                'answered_at' => now(),
            ]);

            return [
                'call_id' => $call->id,
                'session_id' => $sessionId,
                'greeting' => $greeting,
                'status' => 'success',
            ];

        } catch (\Exception $e) {
            Log::error('[CallOrchestrator] Failed to initiate call', [
                'channel' => $channel,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process incoming message
     */
    public function processMessage(string $sessionId, string $message): array
    {
        try {
            // Process turn
            $turn = $this->conversationOrchestrator->processTurn($sessionId, $message);

            return [
                'response' => $turn->systemResponse,
                'turn_number' => $turn->turnNumber,
                'intent' => $turn->intent->intent,
                'confidence' => $turn->intent->confidence,
                'state' => $turn->conversationState,
                'processing_time_ms' => $turn->processingTimeMs,
                'status' => 'success',
            ];

        } catch (\Exception $e) {
            Log::error('[CallOrchestrator] Failed to process message', [
                'session' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'response' => "I'm sorry, I'm having trouble understanding. Could you please try again?",
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * End call
     */
    public function endCall(string $sessionId, string $outcome = 'completed'): void
    {
        try {
            // Get session
            $session = $this->sessionManager->get($sessionId);

            if (!$session) {
                Log::warning('[CallOrchestrator] Session not found for end call', [
                    'session' => $sessionId,
                ]);
                return;
            }

            // Update call record
            $call = Call::find($session->callId);

            if ($call) {
                $duration = now()->diffInSeconds($call->started_at);

                $call->update([
                    'status' => 'completed',
                    'ended_at' => now(),
                    'duration_seconds' => $duration,
                    'outcome' => $outcome,
                ]);

                Log::info('[CallOrchestrator] Call ended', [
                    'call_id' => $call->id,
                    'session' => $sessionId,
                    'duration' => $duration,
                    'outcome' => $outcome,
                    'turns' => $session->turnCount,
                ]);
            }

            // Delete session (it's already logged to MongoDB via listeners)
            $this->sessionManager->delete($sessionId);

        } catch (\Exception $e) {
            Log::error('[CallOrchestrator] Failed to end call', [
                'session' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get call status
     */
    public function getCallStatus(string $sessionId): ?array
    {
        $session = $this->sessionManager->get($sessionId);

        if (!$session) {
            return null;
        }

        $call = Call::find($session->callId);

        return [
            'session_id' => $sessionId,
            'call_id' => $session->callId,
            'status' => $call->status ?? 'unknown',
            'state' => $session->conversationState,
            'turn_count' => $session->turnCount,
            'duration_minutes' => $session->getAgeInMinutes(),
            'intent' => $session->intent,
            'collected_data' => $session->collectedData,
        ];
    }

    /**
     * Generate session ID
     */
    private function generateSessionId(string $channel, string $externalId): string
    {
        return "session:{$channel}:{$externalId}";
    }
}
