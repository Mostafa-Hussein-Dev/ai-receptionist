<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hospital Information
    |--------------------------------------------------------------------------
    */
    'name' => env('HOSPITAL_NAME', 'General Hospital'),
    'phone' => env('HOSPITAL_PHONE', '+1234567890'),
    'address' => env('HOSPITAL_ADDRESS', '123 Medical Center Drive'),
    'timezone' => env('HOSPITAL_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Working Hours
    |--------------------------------------------------------------------------
    */
    'working_hours' => [
        'start' => env('WORKING_HOURS_START', '08:00'),
        'end' => env('WORKING_HOURS_END', '14:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slot Configuration
    |--------------------------------------------------------------------------
    */
    'slots' => [
        'duration_minutes' => env('SLOT_DURATION_MINUTES', 15),
        'min_per_appointment' => 1,
        'max_per_appointment' => 4,
        'pre_generate_days' => env('SLOTS_PRE_GENERATE_DAYS', 30), // Generate slots for next 30 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment Settings
    |--------------------------------------------------------------------------
    */
    'appointments' => [
        // Booking Rules
        'booking_advance_days' => env('BOOKING_ADVANCE_DAYS', 90), // 90 days in advance
        'minimum_notice_hours' => env('MINIMUM_NOTICE_HOURS', 2), // 2 hours minimum notice
        'max_per_patient_per_day' => env('MAX_APPOINTMENTS_PER_PATIENT_PER_DAY', 2), // 2 appointments max per day

        // Cancellation Rules
        'allow_cancellation_anytime' => true,
        'cancellation_notice_hours' => 0, // No notice required

        // Scheduling Rules
        'allow_double_booking' => true, // Patient can book multiple appointments (different times)
        'block_weekends' => true, // Block Saturday and Sunday
        'block_holidays' => false, // Holidays handled via schedule exceptions

        // Appointment Types
        'default_type' => 'general',
        'types' => [
            'general' => 'General Consultation',
            'followup' => 'Follow-up Visit',
            'urgent' => 'Urgent Care',
            'checkup' => 'Regular Checkup',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Weekend Configuration
    |--------------------------------------------------------------------------
    */
    'weekends' => [
        6, // Saturday
        0, // Sunday (0 = Sunday in PHP's date('w'))
    ],
];
