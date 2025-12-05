<?php

namespace App\Exceptions;

use Exception;

class AppointmentException extends Exception
{
    public static function doctorNotActive(): self
    {
        return new self('The selected doctor is not currently active.');
    }

    public static function doctorNotFound(): self
    {
        return new self('The specified doctor was not found.');
    }

    public static function patientNotFound(): self
    {
        return new self('The specified patient was not found.');
    }

    public static function cannotBookInPast(): self
    {
        return new self('Cannot book appointments in the past.');
    }

    public static function beyondAdvanceLimit(int $days): self
    {
        return new self("Cannot book appointments more than {$days} days in advance.");
    }

    public static function minimumNoticeRequired(int $hours): self
    {
        return new self("Appointments require at least {$hours} hours notice.");
    }

    public static function maxAppointmentsReached(int $max): self
    {
        return new self("Patient has reached the maximum of {$max} appointments per day.");
    }

    public static function appointmentNotFound(): self
    {
        return new self('The specified appointment was not found.');
    }

    public static function alreadyCancelled(): self
    {
        return new self('This appointment has already been cancelled.');
    }

    public static function doctorOnDayOff(): self
    {
        return new self('The doctor is not available on this date (day off).');
    }

    public static function invalidSlotCount(int $min, int $max): self
    {
        return new self("Slot count must be between {$min} and {$max}.");
    }
}
