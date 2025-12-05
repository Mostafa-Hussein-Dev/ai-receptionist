<?php

namespace App\DTOs;

/**
 * Entity Extraction Result DTO
 *
 * Represents structured data extracted from user input.
 * Used by both Mock and Real entity extractors.
 */
class EntityDTO
{
    public function __construct(
        public readonly ?string $patientName = null,
        public readonly ?string $date = null,           // YYYY-MM-DD format
        public readonly ?string $time = null,           // HH:MM 24-hour format
        public readonly ?string $phone = null,          // E.164 format
        public readonly ?string $dateOfBirth = null,    // YYYY-MM-DD format
        public readonly ?string $doctorName = null,
        public readonly ?string $department = null,
        public readonly ?array $metadata = null         // Additional extracted data
    ) {}

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            patientName: $data['patient_name'] ?? null,
            date: $data['date'] ?? null,
            time: $data['time'] ?? null,
            phone: $data['phone'] ?? null,
            dateOfBirth: $data['date_of_birth'] ?? null,
            doctorName: $data['doctor_name'] ?? null,
            department: $data['department'] ?? null,
            metadata: $data['metadata'] ?? null
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'patient_name' => $this->patientName,
            'date' => $this->date,
            'time' => $this->time,
            'phone' => $this->phone,
            'date_of_birth' => $this->dateOfBirth,
            'doctor_name' => $this->doctorName,
            'department' => $this->department,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get all non-null entities
     */
    public function getExtractedEntities(): array
    {
        return array_filter($this->toArray(), fn($value) => $value !== null);
    }

    /**
     * Check if a specific entity was extracted
     */
    public function has(string $entity): bool
    {
        return match($entity) {
            'patient_name' => $this->patientName !== null,
            'date' => $this->date !== null,
            'time' => $this->time !== null,
            'phone' => $this->phone !== null,
            'date_of_birth' => $this->dateOfBirth !== null,
            'doctor_name' => $this->doctorName !== null,
            'department' => $this->department !== null,
            default => false,
        };
    }

    /**
     * Count how many entities were extracted
     */
    public function count(): int
    {
        return count($this->getExtractedEntities());
    }
}
