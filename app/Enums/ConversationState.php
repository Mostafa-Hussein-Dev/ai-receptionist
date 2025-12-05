<?php


namespace App\Enums;

/**
 * Conversation State Enum
 *
 * All possible states in the conversation flow.
 * Used by DialogueManager to track conversation progress.
 */
enum ConversationState: string
{
    // Initial states
    case START = 'START';
    case GREETING = 'GREETING';
    case DETECT_INTENT = 'DETECT_INTENT';

    // Booking flow
    case BOOK_APPOINTMENT = 'BOOK_APPOINTMENT';
    case COLLECT_PATIENT_NAME = 'COLLECT_PATIENT_NAME';
    case COLLECT_PATIENT_DOB = 'COLLECT_PATIENT_DOB';
    case COLLECT_PATIENT_PHONE = 'COLLECT_PATIENT_PHONE';
    case VERIFY_PATIENT = 'VERIFY_PATIENT';
    case CREATE_PATIENT = 'CREATE_PATIENT';
    case SELECT_DOCTOR = 'SELECT_DOCTOR';
    case SELECT_DEPARTMENT = 'SELECT_DEPARTMENT';
    case SELECT_DATE = 'SELECT_DATE';
    case SHOW_AVAILABLE_SLOTS = 'SHOW_AVAILABLE_SLOTS';
    case SELECT_SLOT = 'SELECT_SLOT';
    case CONFIRM_BOOKING = 'CONFIRM_BOOKING';
    case EXECUTE_BOOKING = 'EXECUTE_BOOKING';

    // Cancel flow
    case CANCEL_APPOINTMENT = 'CANCEL_APPOINTMENT';
    case FIND_APPOINTMENT_TO_CANCEL = 'FIND_APPOINTMENT_TO_CANCEL';
    case CONFIRM_CANCELLATION = 'CONFIRM_CANCELLATION';
    case EXECUTE_CANCELLATION = 'EXECUTE_CANCELLATION';

    // Reschedule flow
    case RESCHEDULE_APPOINTMENT = 'RESCHEDULE_APPOINTMENT';
    case FIND_APPOINTMENT_TO_RESCHEDULE = 'FIND_APPOINTMENT_TO_RESCHEDULE';
    case SELECT_NEW_DATE = 'SELECT_NEW_DATE';
    case SHOW_NEW_SLOTS = 'SHOW_NEW_SLOTS';
    case CONFIRM_RESCHEDULE = 'CONFIRM_RESCHEDULE';
    case EXECUTE_RESCHEDULE = 'EXECUTE_RESCHEDULE';

    // General
    case CHECK_APPOINTMENT = 'CHECK_APPOINTMENT';
    case GENERAL_INQUIRY = 'GENERAL_INQUIRY';
    case TRANSFER_TO_HUMAN = 'TRANSFER_TO_HUMAN';
    case CLOSING = 'CLOSING';
    case END = 'END';

    // Error states
    case CLARIFICATION_NEEDED = 'CLARIFICATION_NEEDED';
    case ERROR = 'ERROR';

    /**
     * Get human-readable description
     */
    public function description(): string
    {
        return match ($this) {
            self::START => 'Conversation initialized',
            self::GREETING => 'Greeting the patient',
            self::DETECT_INTENT => 'Determining what the patient wants',
            self::BOOK_APPOINTMENT => 'Starting booking process',
            self::COLLECT_PATIENT_NAME => 'Collecting patient name',
            self::COLLECT_PATIENT_DOB => 'Collecting patient date of birth',
            self::COLLECT_PATIENT_PHONE => 'Collecting patient phone number',
            self::VERIFY_PATIENT => 'Verifying patient identity',
            self::CREATE_PATIENT => 'Creating new patient record',
            self::SELECT_DOCTOR => 'Selecting doctor',
            self::SELECT_DEPARTMENT => 'Selecting department',
            self::SELECT_DATE => 'Selecting appointment date',
            self::SHOW_AVAILABLE_SLOTS => 'Showing available time slots',
            self::SELECT_SLOT => 'Patient selecting time slot',
            self::CONFIRM_BOOKING => 'Confirming booking details',
            self::EXECUTE_BOOKING => 'Creating appointment',
            self::CANCEL_APPOINTMENT => 'Starting cancellation process',
            self::FIND_APPOINTMENT_TO_CANCEL => 'Finding appointment to cancel',
            self::CONFIRM_CANCELLATION => 'Confirming cancellation',
            self::EXECUTE_CANCELLATION => 'Cancelling appointment',
            self::RESCHEDULE_APPOINTMENT => 'Starting reschedule process',
            self::FIND_APPOINTMENT_TO_RESCHEDULE => 'Finding appointment to reschedule',
            self::SELECT_NEW_DATE => 'Selecting new date',
            self::SHOW_NEW_SLOTS => 'Showing new available slots',
            self::CONFIRM_RESCHEDULE => 'Confirming reschedule',
            self::EXECUTE_RESCHEDULE => 'Rescheduling appointment',
            self::CHECK_APPOINTMENT => 'Checking appointment details',
            self::GENERAL_INQUIRY => 'Answering general question',
            self::TRANSFER_TO_HUMAN => 'Transferring to staff',
            self::CLOSING => 'Ending conversation',
            self::END => 'Conversation ended',
            self::CLARIFICATION_NEEDED => 'Requesting clarification',
            self::ERROR => 'Error occurred',
        };
    }

    /**
     * Check if state is part of booking flow
     */
    public function isBookingFlow(): bool
    {
        return in_array($this, [
            self::BOOK_APPOINTMENT,
            self::COLLECT_PATIENT_NAME,
            self::COLLECT_PATIENT_DOB,
            self::COLLECT_PATIENT_PHONE,
            self::VERIFY_PATIENT,
            self::CREATE_PATIENT,
            self::SELECT_DOCTOR,
            self::SELECT_DEPARTMENT,
            self::SELECT_DATE,
            self::SHOW_AVAILABLE_SLOTS,
            self::SELECT_SLOT,
            self::CONFIRM_BOOKING,
            self::EXECUTE_BOOKING,
        ]);
    }

    /**
     * Check if state is terminal (conversation ending)
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::END,
            self::TRANSFER_TO_HUMAN,
        ]);
    }

    /**
     * Check if state requires user input
     */
    public function requiresUserInput(): bool
    {
        return !in_array($this, [
            self::VERIFY_PATIENT,
            self::CREATE_PATIENT,
            self::EXECUTE_BOOKING,
            self::EXECUTE_CANCELLATION,
            self::EXECUTE_RESCHEDULE,
            self::END,
        ]);
    }

    /**
     * Get all states as array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
