<?php

namespace App\Exceptions;

use Exception;

class SlotException extends Exception
{
    public static function notAvailable(): self
    {
        return new self('The requested slot is not available.');
    }

    public static function alreadyBooked(): self
    {
        return new self('The requested slot is already booked.');
    }

    public static function insufficientConsecutiveSlots(int $required): self
    {
        return new self("Unable to find {$required} consecutive available slots.");
    }

    public static function outsideWorkingHours(): self
    {
        return new self('The requested time is outside working hours.');
    }

    public static function onWeekend(): self
    {
        return new self('Cannot book appointments on weekends.');
    }
}
