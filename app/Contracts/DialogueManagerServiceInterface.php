<?php


namespace App\Contracts;

use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\DTOs\SessionDTO;

/**
 * Dialogue Manager Service Interface
 *
 * Contract for conversation flow management (state machine).
 * Handles state transitions and response generation.
 */
interface DialogueManagerServiceInterface
{
    /**
     * Get next conversation state based on current state and input
     *
     * @param string $currentState Current conversation state
     * @param IntentDTO $intent Detected intent
     * @param EntityDTO $entities Extracted entities
     * @param array $context Additional context
     * @return string Next conversation state
     */
    public function getNextState(
        string    $currentState,
        IntentDTO $intent,
        EntityDTO $entities,
        array     $context = []
    ): string;

    /**
     * Get required entities for current state
     *
     * @param string $state Conversation state
     * @return array List of required entity names
     */
    public function getRequiredEntities(string $state): array;

    /**
     * Check if we can proceed from current state
     *
     * @param string $state Current state
     * @param array $collectedData Data collected so far
     * @return bool True if all required data is collected
     */
    public function canProceed(string $state, array $collectedData): bool;

    /**
     * Generate prompt/response for current state
     *
     * @param string $state Current conversation state
     * @param array $context Conversation context
     * @return string Response text to send to user
     */
    public function generateResponse(string $state, array $context = []): string;

    /**
     * Generate prompt for missing entities
     *
     * @param array $missingEntities List of missing entity names
     * @param string $state Current state
     * @return string Prompt asking for missing information
     */
    public function generatePromptForMissingEntities(
        array  $missingEntities,
        string $state
    ): string;

    /**
     * Get initial greeting message
     *
     * @return string Greeting message
     */
    public function getGreeting(): string;

    /**
     * Get closing message
     *
     * @return string Closing message
     */
    public function getClosing(): string;

    /**
     * Get clarification request message
     *
     * @param string $reason Why clarification is needed
     * @return string Clarification request
     */
    public function getClarification(string $reason = ''): string;

    /**
     * Handle state transition
     *
     * @param SessionDTO $session Current session
     * @param string $newState New state to transition to
     * @return array Transition result ['state' => '...', 'response' => '...', 'metadata' => [...]]
     */
    public function handleStateTransition(SessionDTO $session, string $newState): array;

    /**
     * Check if state is valid
     *
     * @param string $state State to check
     * @return bool True if state exists
     */
    public function isValidState(string $state): bool;

    /**
     * Get possible next states from current state
     *
     * @param string $currentState Current state
     * @return array List of possible next states
     */
    public function getPossibleNextStates(string $currentState): array;
}
