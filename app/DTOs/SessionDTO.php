<?php

namespace App\DTOs;

/**
 * Session State DTO
 *
 * Represents the current state of a conversation session.
 * Stored in Redis and updated after each turn.
 */
class SessionDTO
{
    public function __construct(
        public readonly string $sessionId,
        public readonly ?int $callId = null,
        public readonly ?int $patientId = null,
        public readonly string $conversationState = 'GREETING',
        public readonly ?string $intent = null,
        public readonly array $collectedData = [],
        public readonly array $conversationHistory = [],
        public readonly array $context = [],
        public readonly ?\DateTime $startedAt = null,
        public readonly ?\DateTime $lastActivityAt = null,
        public readonly int $turnCount = 0,
        public readonly ?array $metadata = null,
        public readonly ?int $doctorId = null,
        public readonly ?string $department = null,
        public readonly ?string $selectedDate = null,
        public readonly ?string $selectedTime = null,
        public readonly ?int $slotNumber = null,
        public readonly ?int $slotCount = null,
        public readonly ?int $appointmentId = null,
        public readonly ?array $availableSlots = null,

    ) {}

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['session_id'],
            callId: $data['call_id'] ?? null,
            patientId: $data['patient_id'] ?? null,
            conversationState: $data['conversation_state'] ?? 'GREETING',
            intent: $data['intent'] ?? null,
            collectedData: $data['collected_data'] ?? [],
            conversationHistory: $data['conversation_history'] ?? [],
            context: $data['context'] ?? [],
            startedAt: isset($data['started_at'])
                ? new \DateTime($data['started_at'])
                : null,
            lastActivityAt: isset($data['last_activity_at'])
                ? new \DateTime($data['last_activity_at'])
                : null,
            turnCount: $data['turn_count'] ?? 0,
            metadata: $data['metadata'] ?? null,
            doctorId: $data['doctor_id'] ?? null,
            department: $data['department'] ?? null,
            selectedDate: $data['selected_date'] ?? null,
            selectedTime: $data['selected_time'] ?? null,
            slotNumber: $data['slot_number'] ?? null,
            slotCount: $data['slot_count'] ?? null,
            appointmentId: $data['appointment_id'] ?? null,
            availableSlots: $data['available_slots'] ?? null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'call_id' => $this->callId,
            'patient_id' => $this->patientId,
            'conversation_state' => $this->conversationState,
            'intent' => $this->intent,
            'collected_data' => $this->collectedData,
            'conversation_history' => $this->conversationHistory,
            'context' => $this->context,
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'last_activity_at' => $this->lastActivityAt?->format('Y-m-d H:i:s'),
            'turn_count' => $this->turnCount,
            'metadata' => $this->metadata,
            'doctor_id' => $this->doctorId,
            'department' => $this->department,
            'selected_date' => $this->selectedDate,
            'selected_time' => $this->selectedTime,
            'slot_number' => $this->slotNumber,
            'slot_count' => $this->slotCount,
            'appointment_id' => $this->appointmentId,
            'available_slots' => $this->availableSlots,
        ];
    }

    /**
     * Create updated session with new data
     */
    public function withUpdates(array $updates): self
    {
        $data = $this->toArray();

        return self::fromArray(array_merge($data, $updates, [
            'last_activity_at' => now()->format('Y-m-d H:i:s'),
            'turn_count' => $this->turnCount + 1,
        ]));
    }

    /**
     * Get collected data for a specific key
     */
    public function getCollectedData(string $key): mixed
    {
        return $this->collectedData[$key] ?? null;
    }

    /**
     * Check if session has collected specific data
     */
    public function hasCollectedData(string $key): bool
    {
        return isset($this->collectedData[$key]) && $this->collectedData[$key] !== null;
    }

    /**
     * Get session age in minutes
     */
    public function getAgeInMinutes(): int
    {
        if (!$this->startedAt) {
            return 0;
        }

        $now = new \DateTime();
        $diff = $now->getTimestamp() - $this->startedAt->getTimestamp();

        return (int) floor($diff / 60);
    }
}
