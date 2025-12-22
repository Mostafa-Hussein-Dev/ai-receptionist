# AI Receptionist Context and Data Documentation

This document provides a comprehensive overview of all the data, context, and information the AI receptionist receives for making intelligent conversation decisions.

## 🧠 **AI Data Architecture Overview**

The AI receptionist operates as a **hybrid system** that combines:
- **Template-based responses** for predictable flows
- **LLM-powered responses** for complex contextual understanding
- **Rule-based state machine** for conversation flow control

## 📋 **1. Core Session Data Structure**

### **Primary Session Context**
```php
// SessionDTO Core Fields
$session = [
    'sessionId' => 'session:interactive:uniqid()',
    'channel' => 'interactive|phone|web',
    'external_id' => 'external_system_id',
    'conversationState' => 'GREETING|BOOK_APPOINTMENT|...',
    'patientId' => int, // When verified
    'appointmentId' => int, // When booked
    'createdAt' => '2025-12-18T13:44:56.200241Z',
    'updatedAt' => '2025-12-18T13:44:56.200241Z'
];
```

### **Collected Data (Session Memory)**
```php
$collectedData = [
    // Patient Information
    'patient_name' => 'Test User',
    'date_of_birth' => '2001-01-01',
    'phone' => '+96171717171',
    'patient_id' => 16, // Verified patient ID

    // Appointment Details
    'doctor_id' => 3,
    'doctor_name' => 'Dr. John Smith',
    'department' => 'General Medicine',
    'date' => '2025-12-22',
    'time' => '10:00',
    'appointment_id' => 17,

    // Slot Information
    'available_slots' => ['08:00 AM', '08:15 AM', '08:30 AM', ...],
    'slot_ranges' => ['08:00 AM - 08:30 AM', '08:15 AM - 08:45 AM', ...],
    'human_ranges' => ['08:00 AM - 11:30 AM', '12:00 PM - 02:00 PM'],
    'slots_count' => 24,
    'slots_fetched_at' => '2025-12-18T13:44:56.200241Z',

    // Cancellation Context (Separated)
    'cancellation_context' => [
        'cancel_time' => '10:00',
        'cancel_date' => '2025-12-22',
        'cancel_doctor' => 'Dr. John Smith'
    ]
];
```

## 🎯 **2. Conversation State Machine**

### **State Enumeration & Transitions**
```php
enum ConversationState {
    // Initial States
    GREETING,              // Welcome and intent detection
    DETECT_INTENT,         // Analyze user's primary intent

    // Booking Flow
    BOOK_APPOINTMENT,      // Start booking process
    COLLECT_PATIENT_NAME,  // Get full name
    COLLECT_PATIENT_DOB,   // Get date of birth
    COLLECT_PATIENT_PHONE, // Get phone number
    VERIFY_PATIENT,        // Verify patient identity
    SELECT_DOCTOR,         // Choose doctor/department
    SELECT_DATE,          // Choose appointment date
    SHOW_AVAILABLE_SLOTS, // Display available times
    SELECT_SLOT,          // Choose specific time slot
    CONFIRM_BOOKING,      // Confirm appointment details
    EXECUTE_BOOKING,      // Execute the booking

    // Cancellation Flow
    CANCEL_APPOINTMENT,   // Cancel existing appointment

    // Rescheduling Flow
    RESCHEDULE_APPOINTMENT, // Change existing appointment

    // General Flow
    GENERAL_INQUIRY,      // Handle general questions
    CLOSING,              // End conversation gracefully
    END                   // Conversation finished
}
```

### **State Transition Logic**
- **Intent-based**: User intent determines next state
- **Data-driven**: Missing required data triggers collection states
- **Flow completion**: Successful actions transition to terminal states

## 🤖 **3. AI Service Context**

### **Intent Analysis Data**
```php
$intentDTO = [
    'intent' => 'BOOK_APPOINTMENT|CANCEL_APPOINTMENT|RESCHEDULE_APPOINTMENT|...',
    'confidence' => 0.95, // 0.0 - 1.0 confidence score
    'extracted_at' => '2025-12-18T13:44:56.200241Z'
];
```

### **Entity Extraction Data**
```php
$entityDTO = [
    'patient_name' => 'Test User',
    'date_of_birth' => 'January 1, 2001',
    'phone' => '71717171',
    'doctor' => 'John Smith',
    'date' => 'Tomorrow|2025-12-22|Today',
    'time' => '10 am|10:00|2pm',
    'department' => 'General Medicine',
    'confidence' => 0.90 // Per-entity confidence
];
```

## 📚 **4. Conversation History Context**

### **Turn Management**
```php
$conversationTurn = [
    'sessionId' => 'session:interactive:uniqid()',
    'turnNumber' => 5,
    'userMessage' => 'i would like to see dr john smith',
    'systemResponse' => 'Which doctor would you like to see, or do you have a preference for a department?',
    'intent' => $intentDTO,
    'entities' => $entityDTO,
    'previousState' => 'COLLECT_PATIENT_PHONE',
    'nextState' => 'SELECT_DOCTOR',
    'createdAt' => '2025-12-18T13:44:56.200241Z',
    'processingTimeMs' => 2150.69
];
```

### **Historical Context Available**
- **Previous 5 turns** by default (configurable)
- **Full conversation** stored in database
- **Key events**: Patient verification, booking completions, cancellations
- **Context continuity**: Maintains awareness of all collected data

## 🏥 **5. Business Logic Context**

### **Doctor Information**
```php
$doctor = [
    'id' => 3,
    'first_name' => 'John',
    'last_name' => 'Smith',
    'specialization' => 'General Medicine',
    'department_id' => 3,
    'email' => 'john.smith@hospital.com',
    'phone' => '+96112345678',
    'slots_per_appointment' => 2, // CRITICAL: Requires 2 consecutive slots (30 minutes)
    'max_appointments_per_day' => 12,
    'is_active' => true,
    'metadata' => [...]
];
```

### **Slot Availability Data**
```php
$availableSlots = [
    [
        'id' => 123,
        'doctor_id' => 3,
        'date' => '2025-12-22',
        'slot_number' => 1,
        'start_time' => '08:00:00',
        'end_time' => '08:15:00',
        'status' => 'available' // available|booked|blocked
    ],
    // ... more slots
];
```

### **Appointment Data**
```php
$appointment = [
    'id' => 17,
    'patient_id' => 16,
    'doctor_id' => 3,
    'date' => '2025-12-22',
    'start_time' => '10:00:00',
    'end_time' => '10:30:00',
    'status' => 'confirmed',
    'created_at' => '2025-12-18T13:44:56.200241Z'
];
```

## ⚙️ **6. Configuration Context**

### **Hospital Configuration**
```php
$hospitalConfig = [
    'name' => 'General Hospital',
    'phone' => '+96112345678',
    'address' => '123 Medical Center Drive',
    'timezone' => 'UTC',
    'working_hours' => [
        'start' => '08:00',
        'end' => '14:00'
    ],
    'weekends' => [6, 0], // Saturday, Sunday
];
```

### **AI Service Configuration**
```php
$aiConfig = [
    'intent' => [
        'confidence_threshold' => 0.7,
        'use_history' => true,
        'max_history_turns' => 5
    ],
    'entity' => [
        'confidence_threshold' => 0.6,
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'phone_format' => 'E.164'
    ],
    'response' => [
        'max_length' => 500,
        'style' => 'professional'
    ]
];
```

## 🔄 **7. Real-Time Processing Context**

### **Session Updates**
```php
// Data merged during processing
$mergedData = array_merge($session->collectedData, $filteredEntities);

// Session updated with new information
$this->sessionManager->update($sessionId, [
    'conversation_state' => $nextState,
    'collectedData' => $mergedData
]);
```

### **Data Validation Rules**
- **Required fields**: Different per state
- **Data format validation**: Phone numbers, dates, times
- **Business logic validation**: Slot availability, doctor schedules
- **Data integrity**: Prevent overwriting with null values

## 🧩 **8. AI LLM System Prompt Context**

### **System Instructions Provided**
```prompt
You are a professional, friendly, efficient medical receptionist for {hospital_name}.
Your ONLY responsibilities are: booking appointments, canceling appointments, rescheduling appointments.

### STRICT BEHAVIOR RULES:
1. NEVER ask more than ONE question per response
2. NEVER offer services outside appointments
3. Only discuss appointment-related topics
4. Keep responses short (1-2 sentences maximum)

### CURRENT CONTEXT PROVIDED TO AI:
- Current conversation state
- All collected patient data
- Available doctor and slot information
- Previous conversation turns (last 5)
- User's current message
- Detected intent and entities
- Hospital configuration and rules
```

## 📊 **9. Performance Metrics Available**

### **Processing Context**
```php
$metrics = [
    'turn_duration_ms' => 2150.69,
    'intent_confidence' => 0.95,
    'entities_extracted' => 3,
    'state_transition' => 'COLLECT_PATIENT_PHONE -> SELECT_DOCTOR',
    'api_calls_made' => 2, // Intent parser + Entity extractor
    'database_queries' => 1 // Doctor/patient verification
];
```

## 🔍 **10. Error Handling Context**

### **Fallback Mechanisms**
- **LLM failure**: Fallback to template responses
- **Service unavailable**: Graceful degradation messages
- **Data validation errors**: Clear user guidance
- **State conflicts**: Recovery to safe states

### **Error Information Available**
```php
$errorContext = [
    'type' => 'SlotException|AppointmentException|ValidationException',
    'message' => 'Slot not available for requested time',
    'session_id' => 'session:interactive:uniqid()',
    'user_input' => 'i want to book at 2am',
    'state_at_error' => 'SELECT_SLOT',
    'timestamp' => '2025-12-18T13:44:56.200241Z'
];
```

## 🎯 **Key AI Decision Points**

### **1. Intent Recognition**
- **Current state** + **User message** → **Intent classification**
- **Confidence threshold**: 0.7 minimum
- **Fallback**: DETECT_INTENT if confidence too low

### **2. State Transitions**
- **Intent-driven**: BOOK_APPOINTMENT → COLLECT_PATIENT_NAME
- **Data-driven**: Missing patient_name → COLLECT_PATIENT_NAME
- **Flow-driven**: Booking complete → CLOSING

### **3. Response Generation**
- **Template use**: Simple states (GREETING, data collection)
- **LLM use**: Complex states (SHOW_AVAILABLE_SLOTS, error handling)
- **Context awareness**: Patient name, doctor name, dates used in responses

### **4. Business Logic Integration**
- **Slot validation**: Doctor-specific slot requirements
- **Time matching**: "10:30" matches "10:15 AM - 10:45 AM"
- **Consecutive slots**: 2-slot appointments require consecutive availability

## 📈 **Data Flow Summary**

1. **Input**: User message + Current session state
2. **Analysis**: Intent parsing + Entity extraction
3. **Decision**: State transition based on intent + data
4. **Processing**: Business logic validation + data updates
5. **Response**: Template or LLM-generated response
6. **Persistence**: Session and turn data storage

## 🔐 **Security & Privacy Considerations**

- **Data encryption**: All session data encrypted at rest
- **PII handling**: Personal data only collected when necessary
- **Audit trail**: All conversation turns logged
- **Session isolation**: Each session data kept separate
- **Data retention**: Configurable cleanup policies

---

*This documentation provides the complete picture of data and context available to the AI receptionist for making intelligent conversation decisions. The system is designed to maintain context awareness while protecting patient privacy and ensuring reliable service delivery.*