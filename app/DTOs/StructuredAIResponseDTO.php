<?php

namespace App\DTOs;

/**
 * Structured AI Response DTO
 *
 * Standardized response format for AI services including intent parsing,
 * entity extraction, and dialogue generation with confidence scoring
 * and next action recommendations.
 */
class StructuredAIResponseDTO
{
    public function __construct(
        public readonly string $next_action,
        public readonly string $response_text,
        public readonly ?string $updated_state = null,
        public readonly array $slots = [],
        public readonly float $confidence = 1.0,
        public readonly ?string $reasoning = null,
        public readonly array $metadata = [],
        public readonly bool $requires_clarification = false,
        public readonly ?string $clarification_question = null,
        public readonly bool $task_switch_detected = false,
        public readonly ?string $previous_intent = null,
        public readonly array $analytics_events = []
    ) {}

    /**
     * Create a response for successful intent/entity extraction
     */
    public static function success(
        string $nextAction,
        string $responseText,
        ?string $updatedState = null,
        array $slots = [],
        float $confidence = 1.0,
        ?string $reasoning = null
    ): self {
        return new self(
            next_action: $nextAction,
            response_text: $responseText,
            updated_state: $updatedState,
            slots: $slots,
            confidence: $confidence,
            reasoning: $reasoning,
            analytics_events: [['event' => 'ai_success', 'confidence' => $confidence]]
        );
    }

    /**
     * Create a response that requires clarification
     */
    public static function clarification(
        string $responseText,
        string $clarificationQuestion,
        float $confidence = 0.5,
        ?string $reasoning = null,
        array $slots = []
    ): self {
        return new self(
            next_action: 'CLARIFY',
            response_text: $responseText,
            slots: $slots,
            confidence: $confidence,
            reasoning: $reasoning,
            requires_clarification: true,
            clarification_question: $clarificationQuestion,
            analytics_events: [['event' => 'clarification_requested', 'confidence' => $confidence]]
        );
    }

    /**
     * Create a response for task switching
     */
    public static function taskSwitch(
        string $nextAction,
        string $responseText,
        string $previousIntent,
        ?string $updatedState = null,
        array $preservedSlots = [],
        float $confidence = 0.8
    ): self {
        return new self(
            next_action: $nextAction,
            response_text: $responseText,
            updated_state: $updatedState,
            slots: $preservedSlots,
            confidence: $confidence,
            reasoning: "Task switch detected from {$previousIntent}",
            task_switch_detected: true,
            previous_intent: $previousIntent,
            analytics_events: [
                ['event' => 'task_switch', 'previous_intent' => $previousIntent, 'new_action' => $nextAction]
            ]
        );
    }

    /**
     * Create a response for low confidence scenarios
     */
    public static function lowConfidence(
        string $responseText,
        float $confidence,
        ?string $reasoning = null,
        array $slots = []
    ): self {
        return new self(
            next_action: 'CONFIDENCE_LOW',
            response_text: $responseText,
            slots: $slots,
            confidence: $confidence,
            reasoning: $reasoning,
            requires_clarification: true,
            clarification_question: 'Could you please rephrase that or provide more details?',
            analytics_events: [['event' => 'low_confidence', 'confidence' => $confidence]]
        );
    }

    /**
     * Create an error response
     */
    public static function error(
        string $responseText,
        string $errorReason,
        array $slots = []
    ): self {
        return new self(
            next_action: 'ERROR',
            response_text: $responseText,
            slots: $slots,
            confidence: 0.0,
            reasoning: $errorReason,
            analytics_events: [['event' => 'ai_error', 'error' => $errorReason]]
        );
    }

    /**
     * Check if response indicates successful processing
     */
    public function isSuccessful(): bool
    {
        return $this->confidence >= 0.7 && !$this->requires_clarification;
    }

    /**
     * Check if response requires human intervention
     */
    public function requiresHumanIntervention(): bool
    {
        return $this->confidence < 0.3 || $this->next_action === 'ERROR';
    }

    /**
     * Get all analytics events for logging
     */
    public function getAnalyticsEvents(): array
    {
        return $this->analytics_events;
    }

    /**
     * Add an analytics event
     */
    public function addAnalyticsEvent(string $event, array $data = []): self
    {
        $analyticsEvent = array_merge(['event' => $event], $data);
        $newEvents = [...$this->analytics_events, $analyticsEvent];

        return new self(
            next_action: $this->next_action,
            response_text: $this->response_text,
            updated_state: $this->updated_state,
            slots: $this->slots,
            confidence: $this->confidence,
            reasoning: $this->reasoning,
            metadata: $this->metadata,
            requires_clarification: $this->requires_clarification,
            clarification_question: $this->clarification_question,
            task_switch_detected: $this->task_switch_detected,
            previous_intent: $this->previous_intent,
            analytics_events: $newEvents
        );
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'next_action' => $this->next_action,
            'response_text' => $this->response_text,
            'updated_state' => $this->updated_state,
            'slots' => $this->slots,
            'confidence' => $this->confidence,
            'reasoning' => $this->reasoning,
            'metadata' => $this->metadata,
            'requires_clarification' => $this->requires_clarification,
            'clarification_question' => $this->clarification_question,
            'task_switch_detected' => $this->task_switch_detected,
            'previous_intent' => $this->previous_intent,
            'analytics_events' => $this->analytics_events,
        ];
    }

    /**
     * Create from array (for JSON deserialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            next_action: $data['next_action'] ?? 'UNKNOWN',
            response_text: $data['response_text'] ?? '',
            updated_state: $data['updated_state'] ?? null,
            slots: $data['slots'] ?? [],
            confidence: $data['confidence'] ?? 0.0,
            reasoning: $data['reasoning'] ?? null,
            metadata: $data['metadata'] ?? [],
            requires_clarification: $data['requires_clarification'] ?? false,
            clarification_question: $data['clarification_question'] ?? null,
            task_switch_detected: $data['task_switch_detected'] ?? false,
            previous_intent: $data['previous_intent'] ?? null,
            analytics_events: $data['analytics_events'] ?? []
        );
    }
}