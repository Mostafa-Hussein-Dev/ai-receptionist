<?php


namespace App\Contracts;

use App\DTOs\SessionDTO;

/**
 * Session Manager Service Interface
 *
 * Contract for conversation session management (Redis storage).
 * Handles storing and retrieving conversation state.
 */
interface SessionManagerServiceInterface
{
    /**
     * Create a new session
     *
     * @param string $sessionId Unique session identifier
     * @param array $initialData Initial session data
     * @return SessionDTO Created session
     * @throws \Exception If session creation fails
     */
    public function create(string $sessionId, array $initialData = []): SessionDTO;

    /**
     * Get existing session
     *
     * @param string $sessionId Session identifier
     * @return SessionDTO|null Session data or null if not found
     */
    public function get(string $sessionId): ?SessionDTO;

    /**
     * Update session
     *
     * @param string $sessionId Session identifier
     * @param array $updates Data to update
     * @return SessionDTO Updated session
     * @throws \Exception If session not found or update fails
     */
    public function update(string $sessionId, array $updates): SessionDTO;

    /**
     * Delete session
     *
     * @param string $sessionId Session identifier
     * @return bool True if deleted successfully
     */
    public function delete(string $sessionId): bool;

    /**
     * Check if session exists
     *
     * @param string $sessionId Session identifier
     * @return bool True if session exists
     */
    public function exists(string $sessionId): bool;

    /**
     * Update collected data in session
     *
     * @param string $sessionId Session identifier
     * @param array $data Data to merge into collected_data
     * @return SessionDTO Updated session
     */
    public function updateCollectedData(string $sessionId, array $data): SessionDTO;

    /**
     * Update conversation state
     *
     * @param string $sessionId Session identifier
     * @param string $newState New conversation state
     * @return SessionDTO Updated session
     */
    public function updateState(string $sessionId, string $newState): SessionDTO;

    /**
     * Add message to conversation history
     *
     * @param string $sessionId Session identifier
     * @param array $message Message data ['role' => 'user/assistant', 'content' => '...']
     * @return SessionDTO Updated session
     */
    public function addMessage(string $sessionId, array $message): SessionDTO;

    /**
     * Extend session TTL (time to live)
     *
     * @param string $sessionId Session identifier
     * @param int $seconds TTL in seconds
     * @return bool True if TTL extended
     */
    public function extendTTL(string $sessionId, int $seconds): bool;

    /**
     * Get all active sessions count
     *
     * @return int Number of active sessions
     */
    public function getActiveCount(): int;

    /**
     * Clear expired sessions
     *
     * @return int Number of sessions cleared
     */
    public function clearExpired(): int;
}
