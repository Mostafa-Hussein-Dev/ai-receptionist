<?php

namespace App\Contracts;

/**
 * LLM Service Interface
 *
 * Contract for Large Language Model services.
 * Both Mock and Real (OpenAI) implementations must implement this.
 */
interface LLMServiceInterface
{
    /**
     * Send a chat completion request
     *
     * @param string $systemPrompt System role/instructions
     * @param array $messages Conversation history [['role' => 'user', 'content' => '...']]
     * @return string The LLM's response text
     * @throws \Exception If LLM request fails
     */
    public function chat(string $systemPrompt, array $messages): string;

    /**
     * Send a single prompt and get response
     *
     * @param string $prompt The prompt to send
     * @return string The LLM's response text
     * @throws \Exception If LLM request fails
     */
    public function complete(string $prompt): string;

    /**
     * Check if the service is available
     *
     * @return bool True if service can be used
     */
    public function isAvailable(): bool;

    /**
     * Get service provider name
     *
     * @return string Provider name (e.g., 'mock', 'openai')
     */
    public function getProvider(): string;

    /**
     * Get model being used
     *
     * @return string Model identifier (e.g., 'mock-v1', 'gpt-4-turbo-preview')
     */
    public function getModel(): string;
}
