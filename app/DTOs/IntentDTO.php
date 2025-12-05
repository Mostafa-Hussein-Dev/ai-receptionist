<?php

namespace App\DTOs;

/**
 * Intent Detection Result DTO
 *
 * Represents the result of intent classification from user input.
 * Used by both Mock and Real intent parsers.
 */
class IntentDTO
{
    public function __construct(
        public readonly string $intent,      // Intent type (e.g., 'BOOK_APPOINTMENT')
        public readonly float $confidence,   // Confidence score (0.0 to 1.0)
        public readonly ?string $reasoning = null,  // Optional: Why this intent was detected
        public readonly ?array $metadata = null     // Optional: Additional context
    ) {}

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            intent: $data['intent'],
            confidence: $data['confidence'],
            reasoning: $data['reasoning'] ?? null,
            metadata: $data['metadata'] ?? null
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'reasoning' => $this->reasoning,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Check if confidence meets minimum threshold
     */
    public function meetsThreshold(float $threshold = 0.7): bool
    {
        return $this->confidence >= $threshold;
    }
}
