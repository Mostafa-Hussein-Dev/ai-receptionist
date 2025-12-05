<?php

namespace App\DTOs;

/**
 * Conversation Turn DTO
 *
 * Represents a single turn in a conversation (user message + system response).
 * Used for logging and conversation flow management.
 */
class ConversationTurnDTO
{
    public function __construct(
        public readonly int $turnNumber,
        public readonly string $userMessage,
        public readonly string $systemResponse,
        public readonly IntentDTO $intent,
        public readonly EntityDTO $entities,
        public readonly string $conversationState,
        public readonly float $processingTimeMs,
        public readonly ?\DateTime $timestamp = null,
        public readonly ?array $metadata = null
    ) {}

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            turnNumber: $data['turn_number'],
            userMessage: $data['user_message'],
            systemResponse: $data['system_response'],
            intent: $data['intent'] instanceof IntentDTO
                ? $data['intent']
                : IntentDTO::fromArray($data['intent']),
            entities: $data['entities'] instanceof EntityDTO
                ? $data['entities']
                : EntityDTO::fromArray($data['entities']),
            conversationState: $data['conversation_state'],
            processingTimeMs: $data['processing_time_ms'],
            timestamp: isset($data['timestamp'])
                ? new \DateTime($data['timestamp'])
                : new \DateTime(),
            metadata: $data['metadata'] ?? null
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'turn_number' => $this->turnNumber,
            'user_message' => $this->userMessage,
            'system_response' => $this->systemResponse,
            'intent' => $this->intent->toArray(),
            'entities' => $this->entities->toArray(),
            'conversation_state' => $this->conversationState,
            'processing_time_ms' => $this->processingTimeMs,
            'timestamp' => $this->timestamp?->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get formatted timestamp
     */
    public function getFormattedTimestamp(): string
    {
        return $this->timestamp?->format('Y-m-d H:i:s') ?? '';
    }
}
