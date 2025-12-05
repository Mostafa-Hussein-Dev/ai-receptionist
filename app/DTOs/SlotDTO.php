<?php

namespace App\DTOs;

use Carbon\Carbon;

class SlotDTO
{
    public function __construct(
        public int $slotNumber,
        public Carbon $date,
        public string $startTime,
        public string $endTime,
        public string $status = 'available',
        public ?int $appointmentId = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isBooked(): bool
    {
        return $this->status === 'booked';
    }

    public function toArray(): array
    {
        return [
            'slot_number' => $this->slotNumber,
            'date' => $this->date->toDateString(),
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'status' => $this->status,
            'appointment_id' => $this->appointmentId,
        ];
    }
}
