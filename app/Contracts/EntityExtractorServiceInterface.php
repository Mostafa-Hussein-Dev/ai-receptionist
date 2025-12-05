<?php


namespace App\Contracts;

use App\DTOs\EntityDTO;

/**
 * Entity Extractor Service Interface
 *
 * Contract for entity extraction services.
 * Both Mock and Real implementations must implement this.
 */
interface EntityExtractorServiceInterface
{
    /**
     * Extract entities from user input
     *
     * @param string $text The user's message
     * @param array $context Optional conversation context
     * @return EntityDTO Extracted entities
     * @throws \Exception If extraction fails
     */
    public function extract(string $text, array $context = []): EntityDTO;

    /**
     * Extract specific entities
     *
     * @param string $text The user's message
     * @param array $entityTypes Which entities to look for
     * @param array $context Optional context
     * @return EntityDTO Extracted entities (only requested types)
     */
    public function extractSpecific(
        string $text,
        array  $entityTypes,
        array  $context = []
    ): EntityDTO;

    /**
     * Extract with conversation state context
     *
     * @param string $text User message
     * @param string $conversationState Current state
     * @param array $context Additional context
     * @return EntityDTO Extracted entities
     */
    public function extractWithState(
        string $text,
        string $conversationState,
        array  $context = []
    ): EntityDTO;

    /**
     * Check if the extractor is available
     *
     * @return bool True if extractor can be used
     */
    public function isAvailable(): bool;

    /**
     * Get extractor type
     *
     * @return string Extractor type (e.g., 'mock', 'openai')
     */
    public function getType(): string;

    /**
     * Get list of extractable entity types
     *
     * @return array List of entity types this extractor can handle
     */
    public function getSupportedEntities(): array;
}
