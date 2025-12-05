<?php

namespace App\DTOs;

use Carbon\Carbon;

class AppointmentDTO
{
    public function __construct(
        public int $patientId,
        public int $doctorId,
        public Carbon $date,
        public string $startTime,
        public int $slotCount,
        public ?int $callId = null,
        public string $type = 'general',
        public ?string $reason = null,
        public ?string $notes = null,
    ) {}

    public function getEndTime(): string
    {
        $start = Carbon::parse($this->startTime);
        $durationMinutes = $this->slotCount * 15;
        return $start->addMinutes($durationMinutes)->format('H:i:s');
    }

    public function getDurationMinutes(): int
    {
        return $this->slotCount * 15;
    }

    public function toArray(): array
    {
        return [
            'patient_id' => $this->patientId,
            'doctor_id' => $this->doctorId,
            'call_id' => $this->callId,
            'date' => $this->date->toDateString(),
            'start_time' => $this->startTime,
            'end_time' => $this->getEndTime(),
            'slot_count' => $this->slotCount,
            'type' => $this->type,
            'reason' => $this->reason,
            'notes' => $this->notes,
        ];
    }
}
