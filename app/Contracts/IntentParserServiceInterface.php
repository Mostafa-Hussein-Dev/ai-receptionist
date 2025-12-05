<?php

namespace App\Contracts;

use App\DTOs\IntentDTO;

/**
 * Intent Parser Service Interface
 *
 * Contract for intent classification services.
 * Both Mock and Real implementations must implement this.
 */
interface IntentParserServiceInterface
{
    /**
     * Parse user input and detect intent
     *
     * @param string $userMessage The user's message
     * @param array $context Optional conversation context
     * @return IntentDTO Detected intent with confidence score
     * @throws \Exception If parsing fails
     */
    public function parse(string $userMessage, array $context = []): IntentDTO;

    /**
     * Parse with conversation history for better context
     *
     * @param string $userMessage Current user message
     * @param array $conversationHistory Previous turns
     * @param array $context Additional context
     * @return IntentDTO Detected intent with confidence score
     */
    public function parseWithHistory(
        string $userMessage,
        array $conversationHistory = [],
        array $context = []
    ): IntentDTO;

    /**
     * Check if the parser is available
     *
     * @return bool True if parser can be used
     */
    public function isAvailable(): bool;

    /**
     * Get parser type
     *
     * @return string Parser type (e.g., 'mock', 'openai')
     */
    public function getType(): string;
}
