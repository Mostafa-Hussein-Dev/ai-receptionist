<?php


namespace App\Services\Conversation;

use App\Contracts\SessionManagerServiceInterface;
use App\DTOs\SessionDTO;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Session Manager Service
 *
 * Manages conversation sessions in Redis.
 * Stores conversation state, history, and collected data.
 *
 * Storage: Redis (in-memory, fast)
 * TTL: Configurable (default 1 hour)
 */
class SessionManagerService implements SessionManagerServiceInterface
{
    private string $keyPrefix;
    private int $ttl;
    private bool $autoExtend;

    public function __construct()
    {
        $this->keyPrefix = config('conversation.session.key_prefix', 'session:');
        $this->ttl = config('conversation.session.ttl_seconds', 3600);
        $this->autoExtend = config('conversation.session.auto_extend', true);
    }

    /**
     * Create a new session
     */
    public function create(string $sessionId, array $initialData = []): SessionDTO
    {
        $key = $this->getKey($sessionId);

        $sessionData = array_merge([
            'session_id' => $sessionId,
            'conversation_state' => 'GREETING',
            'collected_data' => [],
            'conversation_history' => [],
            'context' => [],
            'started_at' => now()->format('Y-m-d H:i:s'),
            'last_activity_at' => now()->format('Y-m-d H:i:s'),
            'turn_count' => 0,
        ], $initialData);

        // Store in Redis
        Redis::setex($key, $this->ttl, json_encode($sessionData));

        Log::info('[SessionManager] Session created', [
            'session_id' => $sessionId,
            'ttl' => $this->ttl,
        ]);

        return SessionDTO::fromArray($sessionData);
    }

    /**
     * Get existing session
     */
    public function get(string $sessionId): ?SessionDTO
    {
        $key = $this->getKey($sessionId);
        $data = Redis::get($key);

        if (!$data) {
            return null;
        }

        $sessionData = json_decode($data, true);

        // Auto-extend TTL if configured
        if ($this->autoExtend) {
            $this->extendTTL($sessionId, $this->ttl);
        }

        return SessionDTO::fromArray($sessionData);
    }

    /**
     * Update session
     */
    public function update(string $sessionId, array $updates): SessionDTO
    {
        $session = $this->get($sessionId);

        if (!$session) {
            throw new \Exception("Session not found: {$sessionId}");
        }

        $sessionData = $session->toArray();
        $sessionData = array_merge($sessionData, $updates, [
            'last_activity_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $key = $this->getKey($sessionId);
        Redis::setex($key, $this->ttl, json_encode($sessionData));

        return SessionDTO::fromArray($sessionData);
    }

    /**
     * Delete session
     */
    public function delete(string $sessionId): bool
    {
        $key = $this->getKey($sessionId);
        $result = Redis::del($key);

        Log::info('[SessionManager] Session deleted', [
            'session_id' => $sessionId,
        ]);

        return $result > 0;
    }

    /**
     * Check if session exists
     */
    public function exists(string $sessionId): bool
    {
        $key = $this->getKey($sessionId);
        return Redis::exists($key) > 0;
    }

    /**
     * Update collected data in session
     */
    public function updateCollectedData(string $sessionId, array $data): SessionDTO
    {
        $session = $this->get($sessionId);

        if (!$session) {
            throw new \Exception("Session not found: {$sessionId}");
        }

        $collectedData = $session->collectedData;
        $collectedData = array_merge($collectedData, $data);

        return $this->update($sessionId, [
            'collected_data' => $collectedData,
        ]);
    }

    /**
     * Update conversation state
     */
    public function updateState(string $sessionId, string $newState): SessionDTO
    {
        Log::info('[SessionManager] State transition', [
            'session_id' => $sessionId,
            'new_state' => $newState,
        ]);

        return $this->update($sessionId, [
            'conversation_state' => $newState,
        ]);
    }

    /**
     * Add message to conversation history
     */
    public function addMessage(string $sessionId, array $message): SessionDTO
    {
        $session = $this->get($sessionId);

        if (!$session) {
            throw new \Exception("Session not found: {$sessionId}");
        }

        $history = $session->conversationHistory;
        $history[] = $message;

        // Keep only last N messages (configurable)
        $maxHistory = config('conversation.history.max_in_session', 10);
        if (count($history) > $maxHistory) {
            $history = array_slice($history, -$maxHistory);
        }

        return $this->update($sessionId, [
            'conversation_history' => $history,
            'turn_count' => $session->turnCount + 1,
        ]);
    }

    /**
     * Extend session TTL
     */
    public function extendTTL(string $sessionId, int $seconds): bool
    {
        $key = $this->getKey($sessionId);
        return Redis::expire($key, $seconds) > 0;
    }

    /**
     * Get all active sessions count
     */
    public function getActiveCount(): int
    {
        $pattern = $this->keyPrefix . '*';
        $keys = Redis::keys($pattern);
        return count($keys);
    }

    /**
     * Clear expired sessions
     */
    public function clearExpired(): int
    {
        // Redis automatically removes expired keys
        // This method is here for interface compliance
        // and can be extended for manual cleanup if needed
        return 0;
    }

    /**
     * Get Redis key for session
     */
    private function getKey(string $sessionId): string
    {
        return $this->keyPrefix . $sessionId;
    }

    /**
     * Get session statistics
     */
    public function getStats(string $sessionId): array
    {
        $session = $this->get($sessionId);

        if (!$session) {
            return [];
        }

        return [
            'age_minutes' => $session->getAgeInMinutes(),
            'turn_count' => $session->turnCount,
            'conversation_state' => $session->conversationState,
            'collected_data_count' => count($session->collectedData),
            'history_size' => count($session->conversationHistory),
        ];
    }
}
