<?php

namespace App\Enums;

/**
 * Intent Type Enum
 *
 * All possible user intents that the system can detect.
 * Used by both Mock and Real intent parsers.
 */
enum IntentType: string
{
    case BOOK_APPOINTMENT = 'BOOK_APPOINTMENT';
    case CANCEL_APPOINTMENT = 'CANCEL_APPOINTMENT';
    case RESCHEDULE_APPOINTMENT = 'RESCHEDULE_APPOINTMENT';
    case CHECK_APPOINTMENT = 'CHECK_APPOINTMENT';
    case GENERAL_INQUIRY = 'GENERAL_INQUIRY';
    case GREETING = 'GREETING';
    case GOODBYE = 'GOODBYE';
    case CONFIRM = 'CONFIRM';
    case DENY = 'DENY';
    case PROVIDE_INFO = 'PROVIDE_INFO';
    case UNKNOWN = 'UNKNOWN';

    /**
     * Get human-readable description
     */
    public function description(): string
    {
        return match($this) {
            self::BOOK_APPOINTMENT => 'User wants to book a new appointment',
            self::CANCEL_APPOINTMENT => 'User wants to cancel an existing appointment',
            self::RESCHEDULE_APPOINTMENT => 'User wants to reschedule an appointment',
            self::CHECK_APPOINTMENT => 'User wants to check their appointment details',
            self::GENERAL_INQUIRY => 'User has a question about clinic hours, location, etc.',
            self::GREETING => 'User is greeting the system',
            self::GOODBYE => 'User is ending the conversation',
            self::CONFIRM => 'User is confirming a statement or action',
            self::DENY => 'User is denying or disagreeing',
            self::PROVIDE_INFO => 'User is providing requested information',
            self::UNKNOWN => 'Intent could not be determined',
        };
    }

    /**
     * Check if intent requires collecting patient info
     */
    public function requiresPatientInfo(): bool
    {
        return in_array($this, [
            self::BOOK_APPOINTMENT,
            self::CANCEL_APPOINTMENT,
            self::RESCHEDULE_APPOINTMENT,
            self::CHECK_APPOINTMENT,
        ]);
    }

    /**
     * Check if intent involves appointment management
     */
    public function isAppointmentRelated(): bool
    {
        return in_array($this, [
            self::BOOK_APPOINTMENT,
            self::CANCEL_APPOINTMENT,
            self::RESCHEDULE_APPOINTMENT,
            self::CHECK_APPOINTMENT,
        ]);
    }

    /**
     * Check if intent is conversational (greeting, goodbye, etc.)
     */
    public function isConversational(): bool
    {
        return in_array($this, [
            self::GREETING,
            self::GOODBYE,
            self::CONFIRM,
            self::DENY,
        ]);
    }

    /**
     * Get all intent values as array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all intents with descriptions
     */
    public static function all(): array
    {
        return array_map(
            fn(IntentType $intent) => [
                'value' => $intent->value,
                'description' => $intent->description(),
            ],
            self::cases()
        );
    }
}
