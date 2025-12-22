<?php


namespace App\Orchestrators;

use App\Contracts\IntentParserServiceInterface;
use App\Contracts\EntityExtractorServiceInterface;
use App\Contracts\SessionManagerServiceInterface;
use App\Contracts\DialogueManagerServiceInterface;
use App\DTOs\ConversationTurnDTO;
use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\DTOs\StructuredAIResponseDTO;
use App\Enums\ConversationState;
use App\Enums\IntentType;
use Illuminate\Support\Facades\Log;
use App\Services\Business\PatientService;
use App\Services\Business\DoctorService;
use App\Services\Business\SlotService;
use App\Services\Business\AppointmentService;
use Carbon\Carbon;
use App\Exceptions\AppointmentException;
use App\Exceptions\SlotException;


/**
 * Conversation Orchestrator
 *
 * Orchestrates processing of a single conversation turn.
 * Coordinates Intent Parser, Entity Extractor, Session Manager, Dialogue Manager.
 */
class ConversationOrchestrator
{
    public function __construct(
        private IntentParserServiceInterface    $intentParser,
        private EntityExtractorServiceInterface $entityExtractor,
        private SessionManagerServiceInterface  $sessionManager,
        private DialogueManagerServiceInterface $dialogueManager,
        private PatientService                  $patientService,
        private DoctorService                   $doctorService,
        private SlotService                     $slotService,
        private AppointmentService              $appointmentService,



    )
    {
    }

    /**
     * Process one conversation turn
     */
    public function processTurn(string $sessionId, string $userMessage): ConversationTurnDTO
    {
        $startTime = microtime(true);

        try {
            // Step 1: Get session
            $session = $this->sessionManager->get($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session not found: {$sessionId}");
            }

            $turnNumber = $session->turnCount + 1;

            Log::info('[ConversationOrchestrator] Processing turn', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'state' => $session->conversationState,
            ]);

            // Step 2: Parse intent (if not already detected)
            $intent = $this->parseIntent($userMessage, $session);

            // Step 2.5: Check for patient correction patterns in non-patient-collection states
            $patientCorrection = $this->detectPatientCorrection($userMessage, $session->conversationState, $session->collectedData);
            if ($patientCorrection !== null) {
                Log::info('[ConversationOrchestrator] Patient correction detected', [
                    'correction_type' => $patientCorrection['type'],
                    'new_value' => $patientCorrection['value'],
                    'current_state' => $session->conversationState
                ]);

                // Update the patient information
                $this->sessionManager->updateCollectedData($sessionId, [
                    $patientCorrection['type'] => $patientCorrection['value']
                ]);

                // Change intent to PROVIDE_INFO and move to appropriate verification state
                $intent = new IntentDTO(
                    intent: IntentType::PROVIDE_INFO->value,
                    confidence: 0.9,
                    reasoning: 'Patient corrected their information'
                );

                // Determine which verification state to go to based on what was corrected
                $nextState = $this->getPatientVerificationState($patientCorrection['type']);

                // Update session state
                $this->sessionManager->updateState($sessionId, $nextState);

                // Generate response and return early
                $response = $this->dialogueManager->generateResponse($nextState, [
                    'collected_data' => $session->collectedData,
                    'correction_detected' => true,
                    'correction_type' => $patientCorrection['type']
                ]);

                return $this->earlyTurnDTO(
                    $sessionId,
                    $userMessage,
                    $response,
                    $session,
                    $intent,
                    new EntityDTO() // Empty entities since we handled them directly
                );
            }

            // Step 3: Extract entities
            $entities = $this->extractEntities($userMessage, $session);

            // Step 4: Update collected data with enhanced doctor verification
            if ($entities->count() > 0) {
                $relevantEntities = $this->filterEntitiesForState($entities, $session->conversationState);
                $newData = array_filter($relevantEntities, fn($value) => $value !== null);

                // Enhanced doctor verification for any doctor_name detected
                if (isset($newData['doctor_name']) && !isset($session->collectedData['doctor_id'])) {
                    $doctorVerification = $this->verifyAndStoreDoctor($sessionId, $newData['doctor_name']);
                    if ($doctorVerification['verified']) {
                        // Replace doctor_name with verified details
                        unset($newData['doctor_name']); // Remove unverified name
                        $newData = array_merge($newData, $doctorVerification['doctor_data']);
                        Log::info('[ConversationOrchestrator] Doctor verified during entity extraction', [
                            'original_name' => $newData['doctor_name'] ?? 'unknown',
                            'verified_data' => $doctorVerification['doctor_data']
                        ]);
                    } else {
                        // Keep original name but log verification failure
                        Log::warning('[ConversationOrchestrator] Doctor verification failed', [
                            'doctor_name' => $newData['doctor_name'],
                            'reason' => $doctorVerification['reason'] ?? 'Unknown'
                        ]);
                    }
                }

                // Enhanced patient verification when we have enough patient information
                if (!isset($session->collectedData['patient_id'])) {
                    $patientData = $this->shouldVerifyPatient($newData, $session->collectedData);
                    if ($patientData['should_verify']) {
                        $patientVerification = $this->verifyAndStorePatient($sessionId, $patientData['info']);
                        if ($patientVerification['verified']) {
                            Log::info('[ConversationOrchestrator] Patient verified during entity extraction', [
                                'patient_id' => $patientVerification['patient_id'],
                                'patient_name' => $patientData['info']['full_name']
                            ]);
                        }
                    }
                }

                if (!empty($newData)) {
                    $this->sessionManager->updateCollectedData($sessionId, $newData);
                }
            }

            // Re-fetch session after updating data
            $session = $this->sessionManager->get($sessionId);

            // Step 5: Determine next state (now with updated session data)
            Log::info('[DEBUG] getNextState params', [
                'current_state' => $session->conversationState,
                'intent' => $intent->intent,
                'entities_count' => $entities->count(),
                'collected_data' => $session->collectedData
            ]);

            $nextState = $this->dialogueManager->getNextState(
                $session->conversationState,
                $intent,
                $entities,
                ['collected_data' => $session->collectedData, 'user_message' => $userMessage]
            );

            Log::info('[DEBUG] getNextState result', [
                'returned_state' => $nextState
            ]);

            // Update session state IMMEDIATELY
            $this->sessionManager->update($sessionId, [
                'conversation_state' => $nextState,
                'intent' => $intent->intent,
            ]);

            // Re-fetch session with updated state
            $session = $this->sessionManager->get($sessionId);

            // Check if we can auto-advance to the next state if we already have all required data
            // Don't auto-advance from SHOW_AVAILABLE_SLOTS - we need to display slots first
            if (($this->dialogueManager->canProceed($nextState, $session->collectedData) || $nextState === ConversationState::BOOK_APPOINTMENT->value) &&
                $nextState !== ConversationState::SHOW_AVAILABLE_SLOTS->value) {
                // Get the next state after current one
                $autoAdvanceState = $this->dialogueManager->getNextState(
                    $nextState,
                    $intent,
                    $entities,
                    ['collected_data' => $session->collectedData, 'user_message' => $userMessage]
                );

                // Booking Execution Logic - Check for EXECUTE_BOOKING transition
                Log::info('[DEBUG] Checking booking execution', [
                    'nextState' => $nextState,
                    'autoAdvanceState' => $autoAdvanceState,
                    'CONFIRM_BOOKING' => ConversationState::CONFIRM_BOOKING->value,
                    'EXECUTE_BOOKING' => ConversationState::EXECUTE_BOOKING->value,
                    'condition1' => $nextState === ConversationState::CONFIRM_BOOKING->value,
                    'condition2' => $autoAdvanceState === ConversationState::EXECUTE_BOOKING->value,
                    'condition3' => $nextState === ConversationState::EXECUTE_BOOKING->value
                ]);

                if ($nextState === ConversationState::EXECUTE_BOOKING->value ||
                    ($nextState === ConversationState::CONFIRM_BOOKING->value && $autoAdvanceState === ConversationState::EXECUTE_BOOKING->value)) {
                    Log::info('[DEBUG] Executing booking during CONFIRM->EXECUTE transition', [
                        'current_state' => $session->conversationState,
                        'next_state' => $nextState,
                        'auto_advance_state' => $autoAdvanceState
                    ]);

                    $doctorName = $session->collectedData['doctor_name'] ?? 'the doctor';
                    $date = $session->collectedData['date'] ?? null;
                    $time = $session->collectedData['time'] ?? null;
                    $patientName = $session->collectedData['patient_name'] ?? 'Patient';

                    if ($date && $time) {
                        try {
                            // Validate booking data before execution
                            $this->validateBookingData($session);

                            $appointment = $this->executeBooking($session);

                            // Update session with appointment ID and set proper completion state
                            $this->sessionManager->update($sessionId, [
                                'appointment_id' => $appointment->id,
                                'conversation_state' => ConversationState::CLOSING->value
                            ]);

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "✅ Your appointment is confirmed: {$patientName} with {$doctorName} on {$date} at {$time}. Appointment ID: {$appointment->id}. Is there anything else I can help you with?",
                                $session,
                                $intent,
                                $entities
                            );
                        } catch (SlotException $e) {
                            Log::warning('[ConversationOrchestrator] Slot validation failed during booking', [
                                'session' => $sessionId,
                                'error' => $e->getMessage()
                            ]);

                            // Reset to SELECT_SLOT with specific feedback
                            $this->sessionManager->update($sessionId, [
                                'conversation_state' => ConversationState::SELECT_SLOT->value
                            ]);

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "❌ I'm sorry, that time slot is no longer available. {$e->getMessage()} Would you like to try a different time?",
                                $session,
                                $intent,
                                $entities
                            );
                        } catch (AppointmentException $e) {
                            Log::error('[ConversationOrchestrator] Appointment booking failed', [
                                'session' => $sessionId,
                                'error' => $e->getMessage()
                            ]);

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "❌ I couldn't complete your appointment booking: {$e->getMessage()}. Would you like to try a different date or time?",
                                $session,
                                $intent,
                                $entities
                            );
                        } catch (\Exception $e) {
                            Log::error('[ConversationOrchestrator] Booking execution failed', [
                                'session' => $sessionId,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "❌ I'm sorry, there was an unexpected error while confirming your appointment. Please try again or contact our reception desk directly at +961-1-234567.",
                                $session,
                                $intent,
                                $entities
                            );
                        }
                    }
                }

                // Only auto-advance if it's a different state
                if ($autoAdvanceState !== $nextState) {
                    Log::info('[ConversationOrchestrator] Auto-advancing state', [
                        'from_state' => $nextState,
                        'to_state' => $autoAdvanceState,
                        'collected_data' => $session->collectedData
                    ]);

                    $nextState = $autoAdvanceState;
                    $this->sessionManager->update($sessionId, [
                        'conversation_state' => $nextState,
                    ]);
                    $session = $this->sessionManager->get($sessionId);
                }
            }

            // Available Slots Display Logic
            if ($nextState === ConversationState::SHOW_AVAILABLE_SLOTS->value) {
                $this->fetchAndStoreAvailableSlots($sessionId, $session->collectedData);
            }

            //Doctor Selection Logic (process entities BEFORE auto-advance check)
            if ($nextState === ConversationState::SELECT_DOCTOR->value) {
                $doctorName = $entities->doctorName ?? $session->collectedData['doctor_name'] ?? null;
                $department = $entities->department ?? $session->collectedData['department'] ?? null;

                Log::info('[ConversationOrchestrator] SELECT_DOCTOR business logic starting', [
                    'doctorName' => $doctorName,
                    'department' => $department,
                    'entities_doctorName' => $entities->doctorName,
                    'collected_doctor_name' => $session->collectedData['doctor_name'] ?? null,
                    'collected_department' => $session->collectedData['department'] ?? null
                ]);

                // CASE 1: User provided a doctor name
                if ($doctorName) {
                    Log::info('[ConversationOrchestrator] Searching for doctor', ['doctorName' => $doctorName]);
                    try {
                        // First attempt: search by name
                        $doctors = $this->doctorService->searchDoctors($doctorName);
                        Log::info('[ConversationOrchestrator] Doctor search results', [
                            'search_term' => $doctorName,
                            'results_count' => $doctors->count(),
                            'results' => $doctors->map(fn($d) => "Dr. {$d->first_name} {$d->last_name}")->toArray()
                        ]);

                        // EXACT MATCH
                        if ($doctors->count() === 1) {
                            $doctor = $doctors->first();

                            $this->sessionManager->updateCollectedData($sessionId, [
                                'doctor_id' => $doctor->id,
                                'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                                'department' => $doctor->department->name ?? null,
                            ]);

                            Log::info('[ConversationOrchestrator] Doctor selected', [
                                'doctor_id' => $doctor->id,
                                'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                                'department' => $doctor->department->name ?? null,
                            ]);

                            // Update session data for the rest of the flow
                            $session = $this->sessionManager->get($sessionId);
                        }

                        // MULTIPLE MATCHES
                        elseif ($doctors->count() > 1) {
                            $names = $doctors->map(fn($d) =>
                                "Dr. {$d->first_name} {$d->last_name}" .
                                ($d->department ? " ({$d->department->name})" : "")
                            )->implode(', ');

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "I found several doctors with that name: {$names}. Which one would you prefer?",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                        // NO MATCH - offer alternatives
                        else {
                            Log::info('[ConversationOrchestrator] No doctor found', [
                                'search_term' => $doctorName,
                                'search_type' => 'by_name'
                            ]);
                            // Try to suggest similar names or departments
                            $availableDepartments = $this->doctorService->getAvailableDepartments()
                                ->pluck('name')
                                ->take(5)
                                ->implode(', ');

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "I couldn't find a doctor with that name. Could you try the full name or tell me which department you prefer? We have: {$availableDepartments}.",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                    } catch (\Exception $e) {
                        Log::error('[ConversationOrchestrator] Doctor search failed', [
                            'doctor_name' => $doctorName,
                            'error' => $e->getMessage()
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I'm having trouble searching for doctors right now. Could you tell me which department you'd prefer instead?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                }
            }

            //Verify Patient Logic
            if ($nextState === ConversationState::VERIFY_PATIENT->value) {

                $name = $entities->patientName ?? $session->collectedData['patient_name'] ?? null;
                $dob  = $entities->dateOfBirth ?? $session->collectedData['date_of_birth'] ?? null;
                $phone = $entities->phone ?? $session->collectedData['phone'] ?? null;

                Log::info('[ConversationOrchestrator] VERIFY_PATIENT logic', [
                    'name' => $name,
                    'dob' => $dob,
                    'phone' => $phone,
                    'collected_data' => $session->collectedData,
                    'entities_count' => $entities->count()
                ]);

                if ($name && $phone) {

                    // Split name
                    $parts = explode(' ', trim($name), 2);
                    $first = $parts[0] ?? '';
                    $last  = $parts[1] ?? '';

                    try {
                        // Try verifying identity
                        $result = $this->patientService->verifyIdentity($phone, $first, $last);

                        if ($result['verified']) {
                            // Store patient_id in both session metadata and collected data
                            $this->sessionManager->update($sessionId, [
                                'patient_id' => $result['patient']->id,
                            ]);

                            $this->sessionManager->updateCollectedData($sessionId, [
                                'patient_id' => $result['patient']->id,
                            ]);

                            Log::info('[ConversationOrchestrator] Patient verification successful', [
                                'patient_id' => $result['patient']->id,
                                'patient_name' => $result['patient']->first_name . ' ' . $result['patient']->last_name,
                                'phone_verified' => $phone
                            ]);

                            // Check if this is a correction scenario by comparing with session turn history
                            $isCorrection = $this->isCorrectionScenario($session);
                            $message = $isCorrection
                                ? "Perfect! I've updated your information and verified your record. Which doctor would you like to see, or do you have a preference for a department?"
                                : "Thank you! I've found your record. Which doctor would you like to see, or do you have a preference for a department?";

                            // Return success message and proceed to doctor selection
                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                $message,
                                $session,
                                $intent,
                                $entities
                            );

                        } else {
                            // Create new patient automatically
                            $new = $this->patientService->createPatient([
                                'first_name' => $first,
                                'last_name' => $last,
                                'date_of_birth' => $dob,
                                'phone' => $phone,
                            ]);

                            // Store patient_id in both session metadata and collected data
                            $this->sessionManager->update($sessionId, [
                                'patient_id' => $new->id,
                            ]);

                            $this->sessionManager->updateCollectedData($sessionId, [
                                'patient_id' => $new->id,
                            ]);
                        }

                        // Return success message and proceed to doctor selection
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "Thank you! I've verified your information. Which doctor would you like to see, or do you have a preference for a department?",
                            $session,
                            $intent,
                            $entities
                        );

                    } catch (\Exception $e) {
                        Log::error('[ConversationOrchestrator] Patient verification failed', [
                            'error' => $e->getMessage()
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I'm having trouble verifying your information. Let me help you proceed with the appointment. Which doctor would you like to see?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                }
            }

            
            
            // Appointment Cancellation Logic
            if ($nextState === ConversationState::CANCEL_APPOINTMENT->value) {
                $patientId = $session->patientId ?? null;

                if (!$patientId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I need to verify your identity first. Could you please provide your full name and phone number?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    $appointments = $this->appointmentService->getUpcomingAppointments($patientId);

                    if ($appointments->isEmpty()) {
                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "I couldn't find any upcoming appointments to cancel. Is there anything else I can help you with?",
                            $session,
                            $intent,
                            $entities
                        );
                    }

                    if ($appointments->count() === 1) {
                        $appointment = $appointments->first();
                        $this->sessionManager->updateCollectedData($sessionId, [
                            'appointment_id' => $appointment->id
                        ]);

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "You have an appointment on {$appointment->date} at {$appointment->start_time}. Would you like me to cancel it? Please say 'yes' to confirm.",
                            $session,
                            $intent,
                            $entities
                        );
                    } else {
                        // Check if user is referring to a specific appointment (e.g., "the 10 am one")
                        $cancellationContext = $session->collectedData['cancellation_context'] ?? [];
                        $matchedAppointment = $this->matchAppointmentByReference($appointments, $cancellationContext, $userMessage);

                        if ($matchedAppointment) {
                            $this->sessionManager->updateCollectedData($sessionId, [
                                'appointment_id' => $matchedAppointment->id
                            ]);

                            return $this->earlyTurnDTO(
                                $sessionId,
                                $userMessage,
                                "You have an appointment on {$matchedAppointment->date} at {$matchedAppointment->start_time}. Would you like me to cancel it? Please say 'yes' to confirm.",
                                $session,
                                $intent,
                                $entities
                            );
                        }

                        // Show all options if no specific match found
                        $options = $appointments
                            ->map(fn($a) => "{$a->date} at {$a->start_time}")
                            ->implode(', ');

                        return $this->earlyTurnDTO(
                            $sessionId,
                            $userMessage,
                            "You have multiple upcoming appointments: {$options}. Which one would you like to cancel?",
                            $session,
                            $intent,
                            $entities
                        );
                    }
                } catch (\Exception $e) {
                    Log::error('[ConversationOrchestrator] Appointment lookup failed', [
                        'error' => $e->getMessage()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm having trouble looking up your appointments. Could you provide more details about which appointment you'd like to cancel?",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Confirm Cancellation Logic
            if (
                $session->conversationState === ConversationState::CANCEL_APPOINTMENT->value &&
                $intent->intent === IntentType::CONFIRM->value
            ) {
                $appointmentId = $session->collectedData['appointment_id'] ?? null;

                if (!$appointmentId) {
                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm missing the appointment details. Could you repeat which appointment you want to cancel?",
                        $session,
                        $intent,
                        $entities
                    );
                }

                try {
                    $this->appointmentService->cancelAppointment($appointmentId);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "✅ Your appointment has been successfully cancelled. Is there anything else I can help you with?",
                        $session,
                        $intent,
                        $entities
                    );
                } catch (\Exception $e) {
                    Log::error('[ConversationOrchestrator] Cancellation failed', [
                        'error' => $e->getMessage()
                    ]);

                    return $this->earlyTurnDTO(
                        $sessionId,
                        $userMessage,
                        "I'm having trouble cancelling your appointment. Please contact our office directly for assistance.",
                        $session,
                        $intent,
                        $entities
                    );
                }
            }

            // Re-fetch session again before final processing (in case business logic updated it)
            $session = $this->sessionManager->get($sessionId);

            // Step 6: Check if we can proceed
            // Fix: Filter out null values from entities to prevent overwriting collected_data
            $entitiesArray = $entities->toArray();
            $filteredEntities = array_filter($entitiesArray, function($value) {
                return $value !== null && $value !== '';
            });

            // Special handling for CANCEL_APPOINTMENT state - don't overwrite booking data
            if ($nextState === ConversationState::CANCEL_APPOINTMENT->value) {
                // Clear stale booking data to avoid confusion during cancellation
                $this->clearStaleBookingDataForCancellation($sessionId);

                // Store cancellation references separately to avoid data contamination
                $cancellationData = [];
                if (!empty($filteredEntities['time'])) {
                    $cancellationData['cancel_time'] = $filteredEntities['time'];
                    unset($filteredEntities['time']); // Remove from main data
                }
                if (!empty($filteredEntities['date'])) {
                    $cancellationData['cancel_date'] = $filteredEntities['date'];
                    unset($filteredEntities['date']); // Remove from main data
                }
                if (!empty($filteredEntities['doctor'])) {
                    $cancellationData['cancel_doctor'] = $filteredEntities['doctor'];
                    unset($filteredEntities['doctor']); // Remove from main data
                }

                // Store cancellation context separately
                if (!empty($cancellationData)) {
                    $this->sessionManager->updateCollectedData($sessionId, ['cancellation_context' => $cancellationData]);
                }
            }

            // Merge collected data with filtered entities, preserving existing data
            $mergedData = array_merge($session->collectedData, $filteredEntities);
            $canProceed = $this->dialogueManager->canProceed($nextState, $mergedData);

            Log::info('[DEBUG] canProceed check', [
                'nextState' => $nextState,
                'merged_data' => $mergedData,
                'canProceed' => $canProceed,
                'required_entities' => $this->dialogueManager->getRequiredEntities($nextState)
            ]);

            Log::info('[DEBUG] Response Generation', [
                'nextState' => $nextState,
                'session_state' => $session->conversationState,
                'canProceed' => $canProceed,
                'collected_data' => $session->collectedData,
                'user_message' => $userMessage,
                'intent' => $intent->intent
            ]);

            // Re-fetch session one more time to get the absolute latest state
            $finalSession = $this->sessionManager->get($sessionId);
            $finalState = $finalSession->conversationState;

            // Step 7: Generate response using the latest state
            $response = $this->generateResponse($finalState, $intent, $entities, $finalSession, $canProceed);
            $nextState = $finalState; // Update nextState for the response

            Log::info('[DEBUG] Generated Response', [
                'response' => $response,
                'state_used' => $nextState
            ]);

            $this->sessionManager->addMessage($sessionId, [
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => now()->toISOString(),
            ]);

            $this->sessionManager->addMessage($sessionId, [
                'role' => 'assistant',
                'content' => $response,
                'timestamp' => now()->toISOString(),
            ]);

            // Calculate processing time
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Create turn DTO
            $turn = new ConversationTurnDTO(
                turnNumber: $turnNumber,
                userMessage: $userMessage,
                systemResponse: $response,
                intent: $intent,
                entities: $entities,
                conversationState: $nextState,
                processingTimeMs: (int)$processingTime,
                timestamp: now(),
                sessionId: $sessionId
            );

            Log::info('[ConversationOrchestrator] Turn processed', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'intent' => $intent->intent,
                'entities' => $entities->count(),
                'next_state' => $nextState,
                'processing_time_ms' => (int)$processingTime,
            ]);

            return $turn;

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Turn processing failed', [
                'session' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error turn
            $processingTime = (microtime(true) - $startTime) * 1000;

            return new ConversationTurnDTO(
                turnNumber: ($session->turnCount ?? 0) + 1,
                userMessage: $userMessage,
                systemResponse: "I'm sorry, I encountered an error. Could you please try again?",
                intent: new IntentDTO('UNKNOWN', 0.0, 'Error occurred'),
                entities: EntityDTO::fromArray([]),
                conversationState: $session->conversationState ?? ConversationState::DETECT_INTENT->value,
                processingTimeMs: (int)$processingTime,
                timestamp: now(),
                sessionId: $sessionId
            );
        }
    }

    /**
     * Enhanced processTurn with contextual NLU and structured AI responses
     * Integrates new AI service improvements while maintaining backward compatibility
     */
    public function processTurnEnhanced(string $sessionId, string $userMessage): ConversationTurnDTO
    {
        $startTime = microtime(true);

        try {
            // Step 1: Get session
            $session = $this->sessionManager->get($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session not found: {$sessionId}");
            }

            $turnNumber = $session->turnCount + 1;

            Log::info('[ConversationOrchestrator] Processing enhanced turn', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'state' => $session->conversationState,
            ]);

            // Step 2: Build context for AI services
            $context = $this->buildAIContext($session, $userMessage, $turnNumber);

            // Step 3: Enhanced intent parsing with context
            $intentResponse = $this->parseIntentWithContext($userMessage, $context);

            // Step 4: Enhanced entity extraction with context
            $entityResponse = $this->extractEntitiesWithContext($userMessage, $context);

            // Step 5: Process structured AI responses through dialogue manager
            $dialogueResult = $this->dialogueManager->processStructuredResponse(
                $session,
                $this->chooseBestAIResponse($intentResponse, $entityResponse),
                array_merge($context, [
                    'intent_response' => $intentResponse,
                    'entity_response' => $entityResponse
                ])
            );

            // Step 6: Handle business logic based on dialogue result
            $businessResult = $this->processBusinessLogic(
                $session,
                $dialogueResult,
                $intentResponse,
                $entityResponse,
                $context
            );

            // Step 7: Update session based on results
            $this->updateSessionFromResults($sessionId, $dialogueResult, $businessResult);

            // Step 8: Generate final response
            $finalResponse = $this->generateFinalResponse($dialogueResult, $businessResult, $context);

            // Step 9: Create and return DTO
            $processingTime = (microtime(true) - $startTime) * 1000;

            return new ConversationTurnDTO(
                turnNumber: $turnNumber,
                userMessage: $userMessage,
                systemResponse: $finalResponse,
                intent: new IntentDTO(
                    intent: $intentResponse->slots['intent'] ?? 'UNKNOWN',
                    confidence: $intentResponse->confidence,
                    reasoning: $intentResponse->reasoning
                ),
                entities: EntityDTO::fromArray($entityResponse->slots),
                conversationState: $dialogueResult['state'] ?? $session->conversationState,
                processingTimeMs: round($processingTime, 2),
                timestamp: now(),
                metadata: array_merge(
                    $dialogueResult['metadata'] ?? [],
                    $businessResult['metadata'] ?? [],
                    [
                        'enhanced_processing' => true,
                        'intent_confidence' => $intentResponse->confidence,
                        'entity_confidence' => $entityResponse->confidence,
                        'task_switch_detected' => $intentResponse->task_switch_detected,
                        'clarification_requested' => $intentResponse->requires_clarification || $entityResponse->requires_clarification,
                        'auto_advance' => $dialogueResult['auto_advance'] ?? false
                    ]
                ),
                sessionId: $sessionId
            );

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Enhanced processing failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Analyze error type to provide specific user feedback
            $userMessage = $this->analyzeErrorAndProvideUserFeedback($e, $userMessage);

            // Fallback to original processTurn for reliability
            Log::info('[ConversationOrchestrator] Falling back to original processing');

            try {
                return $this->processTurn($sessionId, $userMessage);
            } catch (\Exception $fallbackException) {
                // If fallback also fails, return a basic error response
                Log::error('[ConversationOrchestrator] Fallback processing also failed', [
                    'session_id' => $sessionId,
                    'fallback_error' => $fallbackException->getMessage()
                ]);

                return $this->createBasicErrorResponse($sessionId, $userMessage, $e);
            }
        }
    }

    /**
     * Build comprehensive context for AI services
     */
    private function buildAIContext($session, string $userMessage, int $turnNumber): array
    {
        // Get conversation history
        $history = $this->sessionManager->getConversationHistory($session->sessionId, 5);

        // Determine previous intent from session or history
        $previousIntent = $this->extractPreviousIntent($session, $history);

        return [
            'conversation_state' => $session->conversationState,
            'collected_data' => $session->collectedData,
            'conversation_history' => $history,
            'turn_number' => $turnNumber,
            'previous_intent' => $previousIntent,
            'patient_id' => $session->patientId,
            'message_length' => strlen($userMessage),
            'current_time' => now()->toISOString(),
            'hospital_context' => $this->getHospitalContext()
        ];
    }

    /**
     * Enhanced intent parsing with context
     */
    private function parseIntentWithContext(string $userMessage, array $context): StructuredAIResponseDTO
    {
        // Use enhanced parsing if available
        if (method_exists($this->intentParser, 'parseWithContext')) {
            return $this->intentParser->parseWithContext($userMessage, $context);
        }

        // Fallback to legacy parsing and convert to structured response
        $legacyIntent = $this->intentParser->parseWithHistory(
            $userMessage,
            $context['conversation_history'] ?? [],
            $context
        );

        return StructuredAIResponseDTO::success(
            nextAction: 'CONTINUE',
            responseText: 'Intent understood',
            confidence: $legacyIntent->confidence,
            reasoning: $legacyIntent->reasoning,
            slots: ['intent' => $legacyIntent->intent]
        );
    }

    /**
     * Enhanced entity extraction with context
     */
    private function extractEntitiesWithContext(string $userMessage, array $context): StructuredAIResponseDTO
    {
        // Use enhanced extraction if available
        if (method_exists($this->entityExtractor, 'extractWithContext')) {
            return $this->entityExtractor->extractWithContext($userMessage, $context);
        }

        // Fallback to legacy extraction and convert to structured response
        $legacyEntities = $this->entityExtractor->extractWithState(
            $userMessage,
            $context['conversation_state'] ?? '',
            $context
        );

        return StructuredAIResponseDTO::success(
            nextAction: 'ENTITIES_EXTRACTED',
            responseText: 'Information extracted',
            confidence: 0.8, // Default confidence for legacy extraction
            slots: $legacyEntities->toArray()
        );
    }

    /**
     * Choose the best AI response when both intent and entity parsing provide results
     */
    private function chooseBestAIResponse(StructuredAIResponseDTO $intentResponse, StructuredAIResponseDTO $entityResponse): StructuredAIResponseDTO
    {
        // Prioritize responses that require clarification or indicate task switching
        if ($intentResponse->requires_clarification || $intentResponse->task_switch_detected) {
            return $intentResponse;
        }

        if ($entityResponse->requires_clarification || $entityResponse->task_switch_detected) {
            return $entityResponse;
        }

        // Choose response with higher confidence
        if ($intentResponse->confidence >= $entityResponse->confidence) {
            return StructuredAIResponseDTO::success(
                nextAction: $intentResponse->next_action,
                responseText: $intentResponse->response_text,
                updatedState: $intentResponse->updated_state,
                slots: array_merge($intentResponse->slots, $entityResponse->slots),
                confidence: $intentResponse->confidence,
                reasoning: $intentResponse->reasoning
            );
        } else {
            return StructuredAIResponseDTO::success(
                nextAction: $entityResponse->next_action,
                responseText: $entityResponse->response_text,
                updatedState: $entityResponse->updated_state,
                slots: array_merge($intentResponse->slots, $entityResponse->slots),
                confidence: $entityResponse->confidence,
                reasoning: $entityResponse->reasoning
            );
        }
    }

    /**
     * Process business logic based on AI responses
     */
    private function processBusinessLogic(
        $session,
        array $dialogueResult,
        StructuredAIResponseDTO $intentResponse,
        StructuredAIResponseDTO $entityResponse,
        array $context
    ): array {
        $result = [
            'business_logic_applied' => false,
            'business_data' => [],
            'metadata' => []
        ];

        // Handle task switching data preservation
        if (isset($dialogueResult['preserved_data'])) {
            $result['business_data']['preserved_data'] = $dialogueResult['preserved_data'];
            $result['metadata']['data_preservation_applied'] = true;
        }

        // Apply auto-advance logic if enabled
        if ($dialogueResult['auto_advance'] ?? false) {
            $result['business_data']['auto_advance'] = true;
            $result['metadata']['auto_advance_applied'] = true;
        }

        // Extract and store entities if available
        if (!empty($entityResponse->slots)) {
            // Handle both array and EntityDTO formats
            $entityDTO = $entityResponse->slots instanceof EntityDTO
                ? $entityResponse->slots
                : EntityDTO::fromArray($entityResponse->slots);

            $relevantEntities = $this->filterEntitiesForState(
                $entityDTO,
                $session->conversationState
            );

            // filterEntitiesForState already returns an array, so no need to call toArray()
            $filteredData = array_filter($relevantEntities, fn($v) => $v !== null);
            if (!empty($filteredData)) {
                $result['business_data']['extracted_entities'] = $filteredData;
                $result['metadata']['entities_extracted'] = true;
            }
        }

        return $result;
    }

    /**
     * Update session based on processing results
     */
    private function updateSessionFromResults(string $sessionId, array $dialogueResult, array $businessResult): void
    {
        $updates = [];

        // Update state if changed
        if (isset($dialogueResult['state']) && $dialogueResult['state'] !== null) {
            $updates['conversation_state'] = $dialogueResult['state'];
        }

        // Update collected data
        if (isset($businessResult['business_data']['extracted_entities'])) {
            $updates['collected_data'] = $businessResult['business_data']['extracted_entities'];
        }

        // Restore preserved data if task switching occurred
        if (isset($businessResult['business_data']['preserved_data'])) {
            $updates['collected_data'] = array_merge(
                $updates['collected_data'] ?? [],
                $businessResult['business_data']['preserved_data']
            );
        }

        // Apply updates if any
        if (!empty($updates)) {
            $this->sessionManager->update($sessionId, $updates);
        }
    }

    /**
     * Generate final response considering all processing results
     */
    private function generateFinalResponse(array $dialogueResult, array $businessResult, array $context): string
    {
        // Use dialogue manager response as primary
        $response = $dialogueResult['response'] ?? 'How can I help you?';

        // Add context-aware enhancements
        if ($businessResult['metadata']['data_preservation_applied'] ?? false) {
            $response .= ' I\'ve saved your information for the new request.';
        }

        if ($businessResult['metadata']['auto_advance_applied'] ?? false) {
            $response .= ' Let me continue with the next step.';
        }

        return $response;
    }

    /**
     * Extract previous intent from session or conversation history
     */
    private function extractPreviousIntent($session, array $history): ?string
    {
        // Try to get from session metadata first
        if (isset($session->collectedData['last_intent'])) {
            return $session->collectedData['last_intent'];
        }

        // Extract from conversation history
        foreach (array_reverse($history) as $turn) {
            if (isset($turn['metadata']['intent'])) {
                return $turn['metadata']['intent'];
            }
        }

        return null;
    }

    /**
     * Get hospital context for AI processing
     */
    private function getHospitalContext(): array
    {
        return [
            'hospital_name' => config('hospital.name', 'Our Hospital'),
            'timezone' => config('app.timezone', 'UTC'),
            'operating_hours' => config('hospital.operating_hours', []),
            'appointment_rules' => config('hospital.appointment_rules', [])
        ];
    }

    /**
     * Parse intent (legacy method)
     */
    private function parseIntent(string $userMessage, $session): IntentDTO
    {
        // If already in a specific flow, intent might be implied
        if ($session->intent && $this->isInFlow($session->conversationState)) {
            Log::debug('[ConversationOrchestrator] Using existing intent', [
                'intent' => $session->intent,
            ]);

            return new IntentDTO($session->intent, 0.95, 'Flow continuation');
        }

        // Parse intent from message with comprehensive context
        return $this->intentParser->parseWithHistory(
            $userMessage,
            $session->conversationHistory,
            [
                'state' => $session->conversationState,
                'conversation_state' => $session->conversationState,
                'collected_data' => $session->collectedData,
                'missing_entities' => $this->getMissingEntities($session->conversationState, $session->collectedData),
                'current_focus' => $this->getCurrentStateFocus($session->conversationState)
            ]
        );
    }

    /**
     * Extract entities
     */
    private function extractEntities(string $userMessage, $session): EntityDTO
    {
        return $this->entityExtractor->extractWithState(
            $userMessage,
            $session->conversationState,
            [
                'collected_data' => $session->collectedData,
                'conversation_state' => $session->conversationState,
                'missing_entities' => $this->getMissingEntities($session->conversationState, $session->collectedData),
                'current_focus' => $this->getCurrentStateFocus($session->conversationState),
                'recent_history' => array_slice($session->conversationHistory, -2)
            ]
        );
    }

    /**
     * Get missing entities for current state
     */
    private function getMissingEntities(string $state, array $collectedData): array
    {
        $required = $this->dialogueManager->getRequiredEntities($state);
        $missing = [];

        foreach ($required as $entity) {
            // Special handling for doctor selection
            if ($entity === 'doctor_id' && isset($collectedData['doctor_name'])) {
                continue;
            }
            if ($entity === 'doctor_name' && isset($collectedData['doctor_id'])) {
                continue;
            }

            if (!isset($collectedData[$entity]) || $collectedData[$entity] === null) {
                $missing[] = $entity;
            }
        }

        return $missing;
    }

    /**
     * Get current state focus description
     */
    private function getCurrentStateFocus(string $state): string
    {
        return match($state) {
            'SELECT_DATE' => 'Collecting appointment date',
            'SELECT_SLOT' => 'Collecting appointment time',
            'COLLECT_PATIENT_NAME' => 'Collecting patient name',
            'COLLECT_PATIENT_DOB' => 'Collecting date of birth',
            'COLLECT_PATIENT_PHONE' => 'Collecting phone number',
            'SELECT_DOCTOR' => 'Selecting doctor or department',
            'CONFIRM_BOOKING' => 'Confirming booking details',
            default => 'General conversation',
        };
    }

    /**
     * Check if this is a correction scenario by analyzing conversation history
     */
    private function isCorrectionScenario($session): bool
    {
        // Simple heuristic: if we have patient_id already set but we're back in VERIFY_PATIENT,
        // it's likely a correction scenario
        return !empty($session->patientId) && $session->conversationState === ConversationState::VERIFY_PATIENT->value;
    }

    /**
     * Detect patient correction patterns in user message
     */
    private function detectPatientCorrection(string $message, string $currentState, array $collectedData): ?array
    {
        // Only check for corrections in non-patient-collection states
        $nonPatientStates = ['SELECT_DOCTOR', 'SELECT_DATE', 'SHOW_AVAILABLE_SLOTS', 'SELECT_SLOT', 'CONFIRM_BOOKING'];
        if (!in_array($currentState, $nonPatientStates)) {
            return null;
        }

        $messageLower = strtolower($message);

        // Patient correction patterns
        $correctionPatterns = [
            'name' => [
                'my name is',
                'name is',
                'actually my name',
                'my real name',
                'call me',
                'wrong name',
                'correct name',
                'my name\'s'
            ],
            'dob' => [
                'my date of birth',
                'my birthday',
                'my dob',
                'actually my birthday',
                'wrong birthday',
                'correct dob'
            ],
            'phone' => [
                'my phone',
                'my number',
                'my phone number',
                'actually my phone',
                'wrong phone',
                'correct phone'
            ]
        ];

        foreach ($correctionPatterns as $field => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($messageLower, $pattern) !== false) {
                    // Extract the new value using regex
                    $newValue = $this->extractCorrectedValue($message, $field);
                    if ($newValue !== null) {
                        return [
                            'type' => "patient_{$field}" === 'patient_name' ? 'patient_name' :
                                      ("patient_{$field}" === 'patient_dob' ? 'date_of_birth' : 'phone'),
                            'field' => $field,
                            'value' => $newValue
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract corrected value from user message
     */
    private function extractCorrectedValue(string $message, string $field): ?string
    {
        // Use regex patterns to extract the corrected value
        switch ($field) {
            case 'name':
                // Look for name patterns: "My name is John Smith", "Call me John", etc.
                if (preg_match('/(?:my name is|name is|call me|actually.*?name.*?is|name\'s)\s+([A-Za-z\s]{2,50})/i', $message, $matches)) {
                    return trim($matches[1]);
                }
                break;

            case 'dob':
                // Look for DOB patterns: "My birthday is Jan 1 2001", "DOB: 2001-01-01", etc.
                if (preg_match('/(?:birthday|dob|date of birth)[^\d]*(\d{1,2}[\/\-\s]\d{1,2}[\/\-\s]\d{2,4}|\d{4}[\/\-\s]\d{1,2}[\/\-\s]\d{1,2})/i', $message, $matches)) {
                    return $this->normalizeDate($matches[1]);
                }
                break;

            case 'phone':
                // Look for phone patterns: "My phone is 123456789", "Number: +961123456"
                if (preg_match('/(?:phone|number|contact)[^\d+]*(\+?\d{8,15})/i', $message, $matches)) {
                    return $this->normalizePhone($matches[1]);
                }
                break;
        }

        return null;
    }

    /**
     * Normalize date to YYYY-MM-DD format
     */
    private function normalizeDate(string $date): string
    {
        try {
            $carbon = \Carbon\Carbon::parse($date);
            return $carbon->format('Y-m-d');
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }

    /**
     * Normalize phone number to E.164 format
     */
    private function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/[^\d]/', '', $phone);

        // Add Lebanon country code if missing and number is 8 digits
        if (strlen($phone) === 8 && !str_starts_with($phone, '961')) {
            $phone = '961' . $phone;
        }

        // Add + prefix if missing
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Normalize time format to match slot times (HH:MM format)
     */
    private function normalizeTimeFormat(string $time): string
    {
        try {
            // Parse various time formats and convert to HH:MM
            $carbon = \Carbon\Carbon::parse($time);
            return $carbon->format('H:i');
        } catch (\Exception $e) {
            // If parsing fails, try to clean the string
            $cleaned = strtolower(trim($time));

            // Remove common words and normalize
            $cleaned = str_replace(['am', 'pm', 'a.m.', 'p.m.'], '', $cleaned);
            $cleaned = trim($cleaned);

            // Try parsing again
            try {
                $carbon = \Carbon\Carbon::parse($cleaned);
                return $carbon->format('H:i');
            } catch (\Exception $e2) {
                // Return original if all parsing fails
                return $time;
            }
        }
    }

    /**
     * Get appropriate verification state for patient correction
     */
    private function getPatientVerificationState(string $correctionType): string
    {
        return match($correctionType) {
            'patient_name' => \App\Enums\ConversationState::VERIFY_PATIENT->value,
            'date_of_birth' => \App\Enums\ConversationState::VERIFY_PATIENT->value,
            'phone' => \App\Enums\ConversationState::VERIFY_PATIENT->value,
            default => \App\Enums\ConversationState::VERIFY_PATIENT->value,
        };
    }

    /**
     * Filter entities based on conversation state
     */
    private function filterEntitiesForState(EntityDTO $entities, string $state): array
    {
        $allowedEntities = $this->getAllowedEntitiesForState($state);
        $entityArray = $entities->toArray();

        $filtered = [];
        foreach ($entityArray as $key => $value) {
            if ($value !== null && in_array($key, $allowedEntities)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Get allowed entities for each state
     */
    private function getAllowedEntitiesForState(string $state): array
    {
        return match($state) {
            'GREETING' => [],
            'DETECT_INTENT' => ['patient_name', 'date', 'time', 'phone', 'date_of_birth', 'doctor_name', 'department'],
            'COLLECT_PATIENT_NAME' => ['patient_name'],
            'COLLECT_PATIENT_DOB' => ['date_of_birth'],
            'COLLECT_PATIENT_PHONE' => ['phone'],
            'VERIFY_PATIENT' => [],
            'SELECT_DOCTOR' => ['doctor_name', 'department'],
            'SELECT_DATE' => ['date'],
            'SHOW_AVAILABLE_SLOTS' => ['time'],
            'SELECT_SLOT' => ['time'],
            'CONFIRM_BOOKING' => [],
            'EXECUTE_BOOKING' => [],
            'CLOSING' => [],
            'GENERAL_INQUIRY' => ['patient_name', 'date', 'time', 'phone', 'date_of_birth', 'doctor_name', 'department'],
            'CANCEL_APPOINTMENT' => ['patient_name', 'date', 'time', 'phone', 'doctor_name'],
            'RESCHEDULE_APPOINTMENT' => ['patient_name', 'date', 'time', 'phone', 'doctor_name'],
            default => ['patient_name', 'date', 'time', 'phone', 'date_of_birth', 'doctor_name', 'department'],
        };
    }

    /**
     * Generate response
     */
    private function generateResponse(
        string    $nextState,
        IntentDTO $intent,
        EntityDTO $entities,
                  $session,
        bool      $canProceed
    ): string
    {
        // Check if we're missing required entities
        if (!$canProceed) {
            $required = $this->dialogueManager->getRequiredEntities($nextState);
            $collected = array_merge($session->collectedData, $entities->toArray());
            $missing = array_diff($required, array_keys(array_filter($collected)));

            if (!empty($missing)) {
                return $this->dialogueManager->generatePromptForMissingEntities($missing, $nextState);
            }
        }

        // Generate normal response
        return $this->dialogueManager->generateResponse($nextState, [
            'intent' => $intent,
            'entities' => $entities->toArray(),
            'collected_data' => $session->collectedData,
        ]);
    }

    /**
     * Execute booking appointment
     */
    private function executeBooking($session)
    {
        // Get or create patient ID
        $patientId = $session->patientId;
        if (!$patientId) {
            // For demo/testing purposes, use a test patient ID
            // In production, this should come from patient verification
            $patientId = 1;
        }

        // Get doctor ID from collected data
        $doctorId = $session->collectedData['doctor_id'] ?? $session->doctorId ?? null;
        $doctor = null;

        if (!$doctorId && isset($session->collectedData['doctor_name'])) {
            // Look up doctor by name
            $doctor = $this->doctorService->searchDoctors($session->collectedData['doctor_name'])->first();
            if ($doctor) {
                $doctorId = $doctor->id;
            }
        } elseif ($doctorId) {
            // Get doctor details for slot requirements
            $doctor = $this->doctorService->getDoctor($doctorId);
        }

        if (!$doctor) {
            throw new \Exception('Doctor not found for booking');
        }

        $date = $session->collectedData['date'];
        $time = $session->collectedData['time'];
        $slotId = $session->collectedData['slot_id'] ?? null;

        // Use doctor-specific slot requirements
        $slotCount = $doctor->slots_per_appointment ?? 1;
        $appointmentStartTime = $session->collectedData['start_time'] ?? $time;

        Log::info('[ConversationOrchestrator] Executing booking', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'date' => $date,
            'time' => $time,
            'slot_id' => $slotId,
            'slot_count' => $slotCount,
            'start_time' => $appointmentStartTime
        ]);

        if (!$patientId || !$doctorId || !$date || !$time) {
            throw new \Exception('Missing required booking information');
        }

        // Validate slot availability before booking
        $this->validateSlotAvailability($doctorId, $date, $time);

        // Book the appointment
        $appointment = $this->appointmentService->bookAppointment([
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'date' => $date,
            'start_time' => $appointmentStartTime,
            'slot_id' => $slotId,
            'slot_count' => $slotCount,
            'type' => 'general',
            'reason' => 'Patient booking via AI receptionist'
        ]);

        // Clean up by removing the large available_slots array after successful booking
        if (isset($session->sessionId)) {
            $this->sessionManager->removeCollectedData($session->sessionId, ['available_slots']);
        }

        return $appointment;
    }

    /**
     * Create slot ranges for display with doctor-specific slot grouping
     */
    private function createSlotRangesForDisplay(array $slots, array $collectedData = []): array
    {
        if (empty($slots)) {
            return [];
        }

        // Sort slots by start time
        usort($slots, function($a, $b) {
            return strtotime($a['start_time']) - strtotime($b['start_time']);
        });

        // Get doctor-specific slot requirements
        $doctorSlotsPerAppointment = 1; // Default
        if (isset($collectedData['doctor_id'])) {
            try {
                $doctor = $this->doctorService->getDoctor($collectedData['doctor_id']);
                if ($doctor) {
                    $doctorSlotsPerAppointment = $doctor->slots_per_appointment ?? 1;
                }
            } catch (\Exception $e) {
                Log::warning('[ConversationOrchestrator] Failed to get doctor for slot grouping', [
                    'doctor_id' => $collectedData['doctor_id'] ?? null,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Generate overlapping appointment windows using sliding window approach
        $appointmentSlots = [];
        $totalSlots = count($slots);

        // Create sliding windows of required slot length
        for ($i = 0; $i <= $totalSlots - $doctorSlotsPerAppointment; $i++) {
            $startSlot = $slots[$i];
            $endSlotIndex = $i + $doctorSlotsPerAppointment - 1;
            $endSlot = $slots[$endSlotIndex];

            $startTime = date('h:i A', strtotime($startSlot['start_time']));
            $endTime = date('h:i A', strtotime($endSlot['end_time']));

            if ($startTime !== $endTime) {
                $appointmentSlots[] = $startTime . ' - ' . $endTime;
            } else {
                $appointmentSlots[] = $startTime;
            }
        }

        return $appointmentSlots;
    }

    /**
     * Create human-friendly conversational time ranges
     */
    private function createHumanFriendlyTimeRanges(array $slots): array
    {
        if (empty($slots)) {
            return [];
        }

        // Sort slots by start time
        usort($slots, function($a, $b) {
            return strtotime($a['start_time']) - strtotime($b['start_time']);
        });

        $ranges = [];
        $currentRange = null;
        $expectedNextTime = null;

        foreach ($slots as $slot) {
            $slotStartTime = date('H:i', strtotime($slot['start_time']));

            if ($currentRange === null) {
                // Start new range
                $currentRange = [
                    'start' => $slotStartTime,
                    'end' => $slotStartTime,
                    'count' => 1
                ];
                $expectedNextTime = date('H:i', strtotime($slot['end_time']));
            } else {
                // Check if this slot continues the current range (15-minute intervals)
                if ($slotStartTime === $expectedNextTime) {
                    // Continue current range
                    $currentRange['end'] = $slotStartTime;
                    $expectedNextTime = date('H:i', strtotime($slot['end_time']));
                    $currentRange['count']++;
                } else {
                    // Save current range and start new one
                    $ranges[] = $currentRange;
                    $currentRange = [
                        'start' => $slotStartTime,
                        'end' => $slotStartTime,
                        'count' => 1
                    ];
                    $expectedNextTime = date('H:i', strtotime($slot['end_time']));
                }
            }
        }

        // Add the last range
        if ($currentRange) {
            $ranges[] = $currentRange;
        }

        // Convert ranges to human-friendly format
        $humanRanges = [];
        foreach ($ranges as $range) {
            $startTime = date('h:i A', strtotime($range['start']));
            $endTime = date('h:i A', strtotime($range['end'] . ' +15 minutes'));

            if ($range['count'] === 1) {
                // Single slot - show as time
                $humanRanges[] = $startTime;
            } else {
                // Multiple consecutive slots - show as range
                $humanRanges[] = $startTime . ' - ' . $endTime;
            }
        }

        return $humanRanges;
    }

    /**
     * Get current session ID (helper for slot display)
     */
    private function getCurrentSessionId(): ?string
    {
        // This is a workaround - we'll need to pass session ID through context
        // For now, we'll extract from the most recent session data if available
        return null; // Will be handled differently in proper implementation
    }

    /**
     * Check if in active flow
     */
    private function isInFlow(string $state): bool
    {
        $flowStates = [
            ConversationState::COLLECT_PATIENT_NAME->value,
            ConversationState::COLLECT_PATIENT_DOB->value,
            ConversationState::COLLECT_PATIENT_PHONE->value,
            ConversationState::SELECT_DOCTOR->value,
            ConversationState::SELECT_DATE->value,
            ConversationState::SELECT_SLOT->value,
            ConversationState::CONFIRM_BOOKING->value,
        ];

        return in_array($state, $flowStates);
    }

    /**
     * Validate booking data before execution
     */
    private function validateBookingData($session): void
    {
        $collectedData = $session->collectedData;
        $requiredFields = ['doctor_id', 'date', 'time', 'patient_id'];

        foreach ($requiredFields as $field) {
            if (!isset($collectedData[$field]) || empty($collectedData[$field])) {
                throw new AppointmentException("Missing required information: {$field}. Please provide all required details.");
            }
        }

        // Additional validation for date format and future date
        try {
            $appointmentDate = new \Carbon\Carbon($collectedData['date']);
            if ($appointmentDate->isPast()) {
                throw new AppointmentException("Appointment date must be in the future.");
            }
        } catch (\Exception $e) {
            throw new AppointmentException("Invalid date format. Please provide a valid date.");
        }

        // Validate time format
        if (!$this->isValidTimeFormat($collectedData['time'])) {
            throw new AppointmentException("Invalid time format. Please provide a valid time.");
        }

        Log::info('[ConversationOrchestrator] Booking data validation successful', [
            'session_id' => $session->sessionId,
            'validated_fields' => $requiredFields
        ]);
    }

    /**
     * Validate time format
     */
    private function isValidTimeFormat(string $time): bool
    {
        try {
            $normalizedTime = $this->normalizeTimeFormat($time);
            return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $normalizedTime) === 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate slot availability before booking
     */
    private function validateSlotAvailability(int $doctorId, string $date, string $time): void
    {
        try {
            Log::info('[ConversationOrchestrator] Validating slot availability', [
                'doctor_id' => $doctorId,
                'date' => $date,
                'time' => $time
            ]);

            // Get available slots for the doctor and date
            $availableSlots = $this->slotService->getAvailableSlots($doctorId, new \Carbon\Carbon($date));

            // Normalize both times to the same format for comparison (HH:MM)
            $normalizedRequestedTime = $this->normalizeTimeFormat($time);

            // Get doctor's slot requirements
            $doctor = $this->doctorService->getDoctor($doctorId);
            $requiredSlots = $doctor->slots_per_appointment ?? 1;

            Log::info('[ConversationOrchestrator] Validating slot availability', [
                'doctor_id' => $doctorId,
                'required_slots' => $requiredSlots,
                'requested_time' => $time,
                'normalized_time' => $normalizedRequestedTime
            ]);

            // Check if the requested time slot is available and has enough consecutive slots
            $requestedSlot = $availableSlots->first(function ($slot) use ($normalizedRequestedTime) {
                $slotStartTime = $this->normalizeTimeFormat($slot->start_time);
                return $slotStartTime === $normalizedRequestedTime;
            });

            if (!$requestedSlot) {
                throw new SlotException("The time {$time} is not available. Please select a different time.");
            }

            // Check if we have enough consecutive slots starting from the requested slot
            $consecutiveSlots = $this->slotService->getAvailableConsecutiveSlots(
                $doctorId,
                new \Carbon\Carbon($date),
                $requiredSlots
            );

            $hasRequiredSlots = $consecutiveSlots->contains(function ($slotGroup) use ($requestedSlot) {
                return $slotGroup->first()->slot_number === $requestedSlot->slot_number;
            });

            if (!$hasRequiredSlots) {
                Log::warning('[ConversationOrchestrator] Insufficient consecutive slots', [
                    'doctor_id' => $doctorId,
                    'date' => $date,
                    'time' => $time,
                    'required_slots' => $requiredSlots,
                    'requested_slot_number' => $requestedSlot->slot_number,
                    'available_consecutive_groups' => $consecutiveSlots->count()
                ]);

                throw new SlotException("The time {$time} requires {$requiredSlots} consecutive slot(s) for Dr. {$doctor->first_name} {$doctor->last_name}. Please select a different time.");
            }

            Log::info('[ConversationOrchestrator] Slot validation successful', [
                'doctor_id' => $doctorId,
                'date' => $date,
                'time' => $time
            ]);

        } catch (SlotException $e) {
            // Re-throw SlotException as is
            throw $e;
        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Slot validation failed', [
                'doctor_id' => $doctorId,
                'date' => $date,
                'time' => $time,
                'error' => $e->getMessage()
            ]);

            // Re-throw as SlotException for proper handling
            throw new SlotException("Slot validation failed: " . $e->getMessage());
        }
    }

    
    /**
     * Match appointment by user reference (e.g., "the 10 am one")
     */
    private function matchAppointmentByReference($appointments, array $cancellationContext, string $userMessage): ?object
    {
        if (empty($cancellationContext) && empty($userMessage)) {
            return null;
        }

        // Look for time references in user message
        $timePattern = '/(\d{1,2})(?::\d{2})?\s*(am|pm)/i';
        if (preg_match($timePattern, $userMessage, $matches)) {
            $hour = (int)$matches[1];
            $period = strtolower($matches[2]);

            if ($period === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }

            $referenceTime = sprintf('%02d:00:00', $hour);

            // Try to match by time
            foreach ($appointments as $appointment) {
                if (strpos($appointment->start_time, $referenceTime) !== false) {
                    return $appointment;
                }
            }
        }

        // Check cancellation context for time/date references
        if (!empty($cancellationContext['cancel_time'])) {
            $contextTime = $this->normalizeTimeFormat($cancellationContext['cancel_time']);

            foreach ($appointments as $appointment) {
                $appointmentTime = $this->normalizeTimeFormat($appointment->start_time);
                if ($appointmentTime === $contextTime) {
                    return $appointment;
                }
            }
        }

        return null;
    }

    /**
     * Clear stale booking data when switching to cancellation flow
     */
    private function clearStaleBookingDataForCancellation(string $sessionId): void
    {
        try {
            $session = $this->sessionManager->get($sessionId);
            $collectedData = $session->collectedData ?? [];

            // Preserve patient identity data but remove booking-specific data
            $preservedData = [];
            $preserveKeys = ['patient_name', 'date_of_birth', 'phone', 'patient_id'];

            foreach ($preserveKeys as $key) {
                if (isset($collectedData[$key])) {
                    $preservedData[$key] = $collectedData[$key];
                }
            }

            // Keep cancellation context if it exists
            if (isset($collectedData['cancellation_context'])) {
                $preservedData['cancellation_context'] = $collectedData['cancellation_context'];
            }

            $this->sessionManager->updateCollectedData($sessionId, $preservedData);

            Log::info('[ConversationOrchestrator] Cleared stale booking data for cancellation', [
                'preserved_keys' => array_keys($preservedData),
                'removed_keys' => array_diff(array_keys($collectedData), array_keys($preservedData))
            ]);

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Failed to clear stale booking data', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if we should verify patient information
     */
    private function shouldVerifyPatient(array $newData, array $existingData): array
    {
        // Combine new and existing data for checking
        $allData = array_merge($existingData, $newData);

        // Check if we have enough patient information for verification
        $hasName = !empty($allData['patient_name']);
        $hasPhone = !empty($allData['phone']);
        $hasDOB = !empty($allData['date_of_birth']);

        // We need at least name + phone OR name + phone + dob for verification
        $shouldVerify = $hasName && $hasPhone && strlen($allData['phone']) >= 7;

        if ($shouldVerify) {
            // Parse patient name into first and last name
            $nameParts = explode(' ', trim($allData['patient_name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            return [
                'should_verify' => true,
                'info' => [
                    'full_name' => $allData['patient_name'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $allData['phone'],
                    'date_of_birth' => $allData['date_of_birth'] ?? null
                ]
            ];
        }

        return ['should_verify' => false];
    }

    /**
     * Verify patient and store patient information
     */
    private function verifyAndStorePatient(string $sessionId, array $patientInfo): array
    {
        try {
            Log::info('[ConversationOrchestrator] Verifying patient', [
                'full_name' => $patientInfo['full_name'],
                'phone' => $patientInfo['phone'],
                'has_dob' => !empty($patientInfo['date_of_birth'])
            ]);

            // Try verifying identity
            $result = $this->patientService->verifyIdentity(
                $patientInfo['phone'],
                $patientInfo['first_name'],
                $patientInfo['last_name']
            );

            if ($result['verified']) {
                // Store patient_id in both session metadata and collected data
                $this->sessionManager->update($sessionId, [
                    'patient_id' => $result['patient']->id,
                ]);

                $this->sessionManager->updateCollectedData($sessionId, [
                    'patient_id' => $result['patient']->id,
                ]);

                Log::info('[ConversationOrchestrator] Patient verification successful', [
                    'patient_id' => $result['patient']->id,
                    'patient_name' => $result['patient']->first_name . ' ' . $result['patient']->last_name,
                    'phone_verified' => $patientInfo['phone']
                ]);

                return [
                    'verified' => true,
                    'patient_id' => $result['patient']->id,
                    'message' => 'Patient verified successfully'
                ];
            } else {
                // Create new patient automatically if we have enough information
                if (!empty($patientInfo['date_of_birth'])) {
                    try {
                        $newPatient = $this->patientService->createPatient([
                            'first_name' => $patientInfo['first_name'],
                            'last_name' => $patientInfo['last_name'],
                            'date_of_birth' => $patientInfo['date_of_birth'],
                            'phone' => $patientInfo['phone'],
                        ]);

                        // Store patient_id in both session metadata and collected data
                        $this->sessionManager->update($sessionId, [
                            'patient_id' => $newPatient->id,
                        ]);

                        $this->sessionManager->updateCollectedData($sessionId, [
                            'patient_id' => $newPatient->id,
                        ]);

                        Log::info('[ConversationOrchestrator] New patient created', [
                            'patient_id' => $newPatient->id,
                            'patient_name' => $newPatient->first_name . ' ' . $newPatient->last_name,
                            'phone' => $patientInfo['phone']
                        ]);

                        return [
                            'verified' => true,
                            'patient_id' => $newPatient->id,
                            'new_patient' => true,
                            'message' => 'New patient created and verified'
                        ];
                    } catch (\Exception $e) {
                        Log::error('[ConversationOrchestrator] Patient creation failed', [
                            'patient_info' => $patientInfo,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                Log::info('[ConversationOrchestrator] Patient verification failed - insufficient data for creation', [
                    'patient_info' => $patientInfo
                ]);

                return [
                    'verified' => false,
                    'reason' => 'Patient not found and insufficient data for creation',
                    'message' => 'Patient verification pending'
                ];
            }

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Patient verification failed', [
                'patient_info' => $patientInfo,
                'error' => $e->getMessage()
            ]);

            return [
                'verified' => false,
                'reason' => 'Verification error: ' . $e->getMessage(),
                'message' => 'Unable to verify patient at this time'
            ];
        }
    }

    /**
     * Verify doctor and store doctor information
     */
    private function verifyAndStoreDoctor(string $sessionId, string $doctorName): array
    {
        try {
            Log::info('[ConversationOrchestrator] Verifying doctor', [
                'doctor_name' => $doctorName
            ]);

            // Search for doctors by name
            $doctors = $this->doctorService->searchDoctors($doctorName);

            if ($doctors->count() === 1) {
                // Exact match found
                $doctor = $doctors->first();

                $doctorData = [
                    'doctor_id' => $doctor->id,
                    'doctor_name' => "Dr. {$doctor->first_name} {$doctor->last_name}",
                    'department' => $doctor->department->name ?? null
                ];

                // Store verified doctor data
                $this->sessionManager->updateCollectedData($sessionId, $doctorData);

                return [
                    'verified' => true,
                    'doctor_data' => $doctorData,
                    'message' => "Doctor verified: {$doctorData['doctor_name']}"
                ];
            } elseif ($doctors->count() > 1) {
                // Multiple matches - return options
                $options = $doctors->map(fn($d) => [
                    'id' => $d->id,
                    'name' => "Dr. {$d->first_name} {$d->last_name}",
                    'department' => $d->department->name ?? 'General Practice'
                ])->toArray();

                return [
                    'verified' => false,
                    'reason' => 'Multiple matches found',
                    'options' => $options,
                    'message' => 'Multiple doctors found with that name'
                ];
            } else {
                // No matches found
                return [
                    'verified' => false,
                    'reason' => 'No doctor found',
                    'message' => 'No doctor found with that name'
                ];
            }

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Doctor verification failed', [
                'doctor_name' => $doctorName,
                'error' => $e->getMessage()
            ]);

            return [
                'verified' => false,
                'reason' => 'Verification error: ' . $e->getMessage(),
                'message' => 'Unable to verify doctor at this time'
            ];
        }
    }

    /**
     * Fetch and store available slots for display
     */
    private function fetchAndStoreAvailableSlots(string $sessionId, array $collectedData): void
    {
        $doctorId = $collectedData['doctor_id'] ?? null;
        $doctorName = $collectedData['doctor_name'] ?? null;
        $date = $collectedData['date'] ?? null;

        // If we don't have doctor_id but have doctor_name, try to find the doctor first
        if (!$doctorId && $doctorName) {
            try {
                $doctors = $this->doctorService->searchDoctors($doctorName);
                if ($doctors->count() === 1) {
                    $doctorId = $doctors->first()->id;
                    Log::info('[ConversationOrchestrator] Resolved doctor from name', [
                        'doctor_name' => $doctorName,
                        'doctor_id' => $doctorId
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('[ConversationOrchestrator] Failed to resolve doctor from name', [
                    'doctor_name' => $doctorName,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($doctorId && $date) {
            try {
                Log::info('[ConversationOrchestrator] Fetching available slots for display', [
                    'doctor_id' => $doctorId,
                    'date' => $date,
                    'doctor_name' => $doctorName
                ]);

                $slots = $this->slotService->getAvailableSlots($doctorId, new \Carbon\Carbon($date));

                if ($slots->isNotEmpty()) {
                    // Format slots for display
                    $formattedSlots = $slots->map(fn($s) => date('h:i A', strtotime($s->start_time)))->toArray();

                    // Create slot ranges for better readability with doctor-specific grouping
                    $slotRanges = $this->createSlotRangesForDisplay($slots->toArray(), $collectedData);

                    // Create human-friendly conversational time ranges
                    $humanRanges = $this->createHumanFriendlyTimeRanges($slots->toArray());

                    // Store comprehensive slot information in session
                    $this->sessionManager->updateCollectedData($sessionId, [
                        'available_slots' => $formattedSlots,
                        'slot_ranges' => $slotRanges,
                        'human_ranges' => $humanRanges,
                        'slots_count' => $slots->count(),
                        'slots_fetched_at' => now()->toISOString()
                    ]);

                    Log::info('[ConversationOrchestrator] Available slots stored for display', [
                        'doctor_id' => $doctorId,
                        'date' => $date,
                        'count' => $slots->count(),
                        'slot_ranges' => $slotRanges,
                        'sample_slots' => array_slice($formattedSlots, 0, 3)
                    ]);
                } else {
                    // Store empty slots information
                    $this->sessionManager->updateCollectedData($sessionId, [
                        'available_slots' => [],
                        'slot_ranges' => [],
                        'slots_count' => 0,
                        'no_slots_available' => true,
                        'slots_fetched_at' => now()->toISOString()
                    ]);

                    Log::info('[ConversationOrchestrator] No available slots found', [
                        'doctor_id' => $doctorId,
                        'date' => $date
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[ConversationOrchestrator] Failed to fetch slots', [
                    'doctor_id' => $doctorId,
                    'date' => $date,
                    'error' => $e->getMessage()
                ]);

                // Store error information
                $this->sessionManager->updateCollectedData($sessionId, [
                    'slots_error' => true,
                    'slots_error_message' => 'Unable to fetch available slots at this time'
                ]);
            }
        } else {
            Log::warning('[ConversationOrchestrator] Missing required data for slot fetching', [
                'doctor_id' => $doctorId,
                'doctor_name' => $doctorName,
                'date' => $date
            ]);
        }
    }

    /**
     * Analyze error and provide specific user feedback
     */
    private function analyzeErrorAndProvideUserFeedback(\Exception $e, string $originalMessage): string
    {
        $errorMessage = strtolower($e->getMessage());

        // Check for common error patterns and provide helpful feedback
        if (strpos($errorMessage, 'timeout') !== false || strpos($errorMessage, 'connection') !== false) {
            Log::info('[ConversationOrchestrator] Detected timeout/connection error', [
                'error_pattern' => 'timeout/connection'
            ]);
            return "I'm experiencing a temporary slowdown. Could you please try again in a moment?";
        }

        if (strpos($errorMessage, 'api') !== false || strpos($errorMessage, 'rate limit') !== false) {
            Log::info('[ConversationOrchestrator] Detected API/rate limit error', [
                'error_pattern' => 'api/rate_limit'
            ]);
            return "I'm getting too many requests right now. Could you please rephrase that?";
        }

        if (strpos($errorMessage, 'json') !== false || strpos($errorMessage, 'decode') !== false) {
            Log::info('[ConversationOrchestrator] Detected JSON parsing error', [
                'error_pattern' => 'json/parsing'
            ]);
            return "I had trouble understanding that. Could you try saying it differently?";
        }

        if (strpos($errorMessage, 'validation') !== false || strpos($errorMessage, 'invalid') !== false) {
            Log::info('[ConversationOrchestrator] Detected validation error', [
                'error_pattern' => 'validation'
            ]);
            return "I need some clarification. Could you provide more details?";
        }

        // Default fallback
        Log::info('[ConversationOrchestrator] Using generic error response', [
            'error_type' => 'unknown'
        ]);
        return $originalMessage; // Return original message for fallback processing
    }

    /**
     * Create basic error response when all processing fails
     */
    private function createBasicErrorResponse(string $sessionId, string $userMessage, \Exception $originalError): ConversationTurnDTO
    {
        Log::error('[ConversationOrchestrator] All processing methods failed', [
            'session_id' => $sessionId,
            'original_error' => $originalError->getMessage()
        ]);

        return new ConversationTurnDTO(
            turnNumber: 1,
            userMessage: $userMessage,
            systemResponse: "I'm having some technical difficulties right now. Please try again in a moment, or you can call our reception desk directly for immediate assistance.",
            intent: new IntentDTO('UNKNOWN', 0.0, 'All processing failed'),
            entities: EntityDTO::fromArray([]),
            conversationState: ConversationState::DETECT_INTENT->value,
            processingTimeMs: 0,
            timestamp: now(),
            metadata: ['error' => true, 'error_type' => 'complete_failure'],
            sessionId: $sessionId
        );
    }

    private function earlyTurnDTO(
        string $sessionId,
        string $userMessage,
        string $assistantMessage,
               $session,
        ?IntentDTO $intent = null,
        ?EntityDTO $entities = null
    ): ConversationTurnDTO {

        $turnNumber = ($session->turnCount ?? 0) + 1;

        return new ConversationTurnDTO(
            turnNumber: $turnNumber,
            userMessage: $userMessage,
            systemResponse: $assistantMessage,
            intent: $intent ?? new IntentDTO('UNKNOWN', 0.0, 'Early return'),
            entities: $entities ?? EntityDTO::fromArray([]),
            conversationState: $session->conversationState ?? 'DETECT_INTENT',
            processingTimeMs: 0,
            timestamp: now(),
            sessionId: $sessionId
        );
    }
}
