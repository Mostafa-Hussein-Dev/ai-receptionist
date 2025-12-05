<?php

namespace App\Exceptions;

use Exception;

class NoAvailableSlotsException extends Exception
{
    public static function forBooking(int $slotCount): self
    {
        return new self("No {$slotCount} consecutive available slots found for this appointment.");
    }

    public static function forDate(string $date): self
    {
        return new self("No available slots on {$date}.");
    }

    public static function forDoctorAndDate(int $doctorId, string $date): self
    {
        return new self("No available slots for doctor #{$doctorId} on {$date}.");
    }
}
