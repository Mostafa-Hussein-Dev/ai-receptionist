<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Orchestrators\ConversationOrchestrator;
use App\Services\AI\OpenAI\IntentParserService;
use App\Services\AI\OpenAI\EntityExtractorService;
use App\Services\Conversation\DialogueManagerService;
use App\Services\Conversation\SessionManagerService;
use App\Services\Business\PatientService;
use App\Services\Business\DoctorService;
use App\Services\Business\SlotService;
use App\Services\Business\AppointmentService;
use App\DTOs\ConversationTurnDTO;
use App\DTOs\StructuredAIResponseDTO;
use App\DTOs\SessionDTO;
use App\Enums\ConversationState;
use App\Enums\IntentType;
use Mockery;

class EnhancedConversationOrchestratorTest extends TestCase
{
    private ConversationOrchestrator $orchestrator;
    private $mockIntentParser;
    private $mockEntityExtractor;
    private $mockDialogueManager;
    private $mockSessionManager;
    private $mockPatientService;
    private $mockDoctorService;
    private $mockSlotService;
    private $mockAppointmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockIntentParser = Mockery::mock(IntentParserService::class);
        $this->mockEntityExtractor = Mockery::mock(EntityExtractorService::class);
        $this->mockDialogueManager = Mockery::mock(DialogueManagerService::class);
        $this->mockSessionManager = Mockery::mock(SessionManagerService::class);
        $this->mockPatientService = Mockery::mock(PatientService::class);
        $this->mockDoctorService = Mockery::mock(DoctorService::class);
        $this->mockSlotService = Mockery::mock(SlotService::class);
        $this->mockAppointmentService = Mockery::mock(AppointmentService::class);

        $this->orchestrator = new ConversationOrchestrator(
            $this->mockIntentParser,
            $this->mockEntityExtractor,
            $this->mockSessionManager,
            $this->mockDialogueManager,
            $this->mockPatientService,
            $this->mockDoctorService,
            $this->mockSlotService,
            $this->mockAppointmentService
        );
    }

    /** @test */
    public function it_can_process_turn_with_enhanced_contextual_nlu()
    {
        // Arrange
        $sessionId = 'test-session-123';
        $userMessage = "I want to book an appointment with Dr. Smith tomorrow";
        $turnNumber = 1;

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::GREETING->value,
            turnCount: 0,
            collectedData: []
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockSessionManager
            ->shouldReceive('getConversationHistory')
            ->with($sessionId, 5)
            ->once()
            ->andReturn([]);

        // Mock enhanced intent parsing with context
        $intentResponse = StructuredAIResponseDTO::success(
            nextAction: 'CONTINUE',
            responseText: 'I understand you want to book an appointment',
            updatedState: ConversationState::BOOK_APPOINTMENT->value,
            confidence: 0.9,
            reasoning: 'Clear intent to book appointment with doctor',
            slots: ['intent' => 'BOOK_APPOINTMENT']
        );

        $this->mockIntentParser
            ->shouldReceive('parseWithContext')
            ->once()
            ->andReturn($intentResponse);

        // Mock enhanced entity extraction with context
        $entityResponse = StructuredAIResponseDTO::success(
            nextAction: 'ENTITIES_EXTRACTED',
            responseText: 'I found doctor and date information',
            confidence: 0.85,
            slots: [
                'doctor_name' => 'Dr. Smith',
                'date' => date('Y-m-d', strtotime('tomorrow'))
            ]
        );

        $this->mockEntityExtractor
            ->shouldReceive('extractWithContext')
            ->once()
            ->andReturn($entityResponse);

        // Mock dialogue manager processing
        $dialogueResult = [
            'state' => ConversationState::BOOK_APPOINTMENT->value,
            'response' => 'I can help you book an appointment with Dr. Smith tomorrow',
            'metadata' => ['entities_extracted' => true],
            'auto_advance' => false
        ];

        $this->mockDialogueManager
            ->shouldReceive('processStructuredResponse')
            ->once()
            ->andReturn($dialogueResult);

        $this->mockSessionManager
            ->shouldReceive('update')
            ->with($sessionId, Mockery::type('array'))
            ->once();

        // Act
        $result = $this->orchestrator->processTurnEnhanced($sessionId, $userMessage);

        // Assert
        $this->assertInstanceOf(ConversationTurnDTO::class, $result);
        $this->assertEquals($sessionId, $result->sessionId);
        $this->assertEquals($userMessage, $result->userMessage);
        $this->assertEquals('BOOK_APPOINTMENT', $result->intent->intent);
        $this->assertEquals(0.9, $result->intent->confidence);
        $this->assertTrue($result->metadata['enhanced_processing']);
        $this->assertEquals(0.9, $result->metadata['intent_confidence']);
        $this->assertEquals(0.85, $result->metadata['entity_confidence']);
    }

    /** @test */
    public function it_handles_task_switching_with_data_preservation()
    {
        // Arrange
        $sessionId = 'test-session-456';
        $userMessage = "Actually, I want to cancel my appointment instead";
        $existingData = ['patient_name' => 'John Doe', 'phone' => '+1234567890'];

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::BOOK_APPOINTMENT->value,
            turnCount: 2,
            collectedData: $existingData
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockSessionManager
            ->shouldReceive('getConversationHistory')
            ->once()
            ->andReturn([
                ['role' => 'user', 'content' => 'I want to book an appointment', 'metadata' => ['intent' => 'BOOK_APPOINTMENT']]
            ]);

        // Mock task switching detection
        $intentResponse = StructuredAIResponseDTO::taskSwitch(
            nextAction: 'TASK_SWITCH',
            responseText: 'I notice you want to cancel instead of book',
            previousIntent: 'BOOK_APPOINTMENT',
            updatedState: ConversationState::CANCEL_APPOINTMENT->value,
            preservedSlots: ['patient_name' => 'John Doe'],
            confidence: 0.8
        );

        $this->mockIntentParser
            ->shouldReceive('parseWithContext')
            ->once()
            ->andReturn($intentResponse);

        $entityResponse = StructuredAIResponseDTO::success(
            nextAction: 'ENTITIES_EXTRACTED',
            responseText: 'Information extracted',
            slots: []
        );

        $this->mockEntityExtractor
            ->shouldReceive('extractWithContext')
            ->once()
            ->andReturn($entityResponse);

        $dialogueResult = [
            'state' => ConversationState::CANCEL_APPOINTMENT->value,
            'response' => 'I can help you cancel your appointment. I\'ve saved your patient information.',
            'metadata' => ['task_switch' => true],
            'preserved_data' => ['patient_name' => 'John Doe'],
            'auto_advance' => false
        ];

        $this->mockDialogueManager
            ->shouldReceive('processStructuredResponse')
            ->once()
            ->andReturn($dialogueResult);

        $this->mockSessionManager
            ->shouldReceive('update')
            ->once()
            ->with($sessionId, Mockery::on(function($updates) use ($existingData) {
                return isset($updates['conversation_state']) &&
                       isset($updates['collected_data']) &&
                       $updates['collected_data']['patient_name'] === 'John Doe';
            }));

        // Act
        $result = $this->orchestrator->processTurnEnhanced($sessionId, $userMessage);

        // Assert
        $this->assertTrue($result->metadata['task_switch_detected']);
        $this->assertEquals(ConversationState::CANCEL_APPOINTMENT->value, $result->newState);
        $this->assertStringContainsString('saved your information', $result->response);
    }

    /** @test */
    public function it_handles_clarification_requests_for_low_confidence()
    {
        // Arrange
        $sessionId = 'test-session-789';
        $userMessage = "maybe see someone";

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::SELECT_DOCTOR->value,
            turnCount: 1,
            collectedData: []
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockSessionManager
            ->shouldReceive('getConversationHistory')
            ->once()
            ->andReturn([]);

        // Mock low confidence intent requiring clarification
        $intentResponse = StructuredAIResponseDTO::clarification(
            responseText: "I'm not sure which doctor you'd like to see",
            clarificationQuestion: "Could you please tell me which doctor or department you need?",
            confidence: 0.4,
            reasoning: "Ambiguous doctor reference",
            slots: []
        );

        $this->mockIntentParser
            ->shouldReceive('parseWithContext')
            ->once()
            ->andReturn($intentResponse);

        $entityResponse = StructuredAIResponseDTO::success(
            nextAction: 'ENTITIES_EXTRACTED',
            responseText: 'No entities extracted',
            confidence: 0.3,
            slots: []
        );

        $this->mockEntityExtractor
            ->shouldReceive('extractWithContext')
            ->once()
            ->andReturn($entityResponse);

        $dialogueResult = [
            'state' => ConversationState::SELECT_DOCTOR->value, // Stay in same state
            'response' => 'Could you please tell me which doctor or department you need?',
            'metadata' => ['clarification_requested' => true],
            'auto_advance' => false
        ];

        $this->mockDialogueManager
            ->shouldReceive('processStructuredResponse')
            ->once()
            ->andReturn($dialogueResult);

        // Act
        $result = $this->orchestrator->processTurnEnhanced($sessionId, $userMessage);

        // Assert
        $this->assertTrue($result->metadata['clarification_requested']);
        $this->assertEquals(ConversationState::SELECT_DOCTOR->value, $result->newState); // No state change
        $this->assertStringContainsString('which doctor or department', $result->response);
        $this->assertFalse($result->metadata['auto_advance']);
    }

    /** @test */
    public function it_falls_back_to_legacy_processing_when_enhanced_fails()
    {
        // Arrange
        $sessionId = 'test-session-fallback';
        $userMessage = "Book appointment with Dr. Johnson";

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::GREETING->value,
            turnCount: 0,
            collectedData: []
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockSessionManager
            ->shouldReceive('getConversationHistory')
            ->once()
            ->andThrow(new \Exception('Database error'));

        // Mock legacy processing as fallback
        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockIntentParser
            ->shouldReceive('parseWithHistory')
            ->once()
            ->andReturn(new \App\DTOs\IntentDTO(
                intent: IntentType::BOOK_APPOINTMENT->value,
                confidence: 0.8,
                reasoning: 'Legacy fallback'
            ));

        $this->mockEntityExtractor
            ->shouldReceive('extractWithState')
            ->once()
            ->andReturn(new \App\DTOs\EntityDTO(
                doctorName: 'Dr. Johnson'
            ));

        $this->mockDialogueManager
            ->shouldReceive('getNextState')
            ->once()
            ->andReturn(ConversationState::BOOK_APPOINTMENT->value);

        $this->mockDialogueManager
            ->shouldReceive('generateResponse')
            ->once()
            ->andReturn('I can help you book an appointment');

        $this->mockSessionManager
            ->shouldReceive('updateCollectedData')
            ->once();

        $this->mockSessionManager
            ->shouldReceive('get')
            ->once()
            ->andReturn($mockSession);

        // Act
        $result = $this->orchestrator->processTurnEnhanced($sessionId, $userMessage);

        // Assert - should return a valid result using fallback
        $this->assertInstanceOf(ConversationTurnDTO::class, $result);
        $this->assertEquals($sessionId, $result->sessionId);
        $this->assertFalse($result->metadata['enhanced_processing'] ?? false);
    }

    /** @test */
    public function it_handles_conflicting_entities_during_extraction()
    {
        // Arrange
        $sessionId = 'test-session-conflict';
        $userMessage = "No, my name is Dr. Smith, not John";
        $existingData = ['patient_name' => 'John'];

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::COLLECT_PATIENT_NAME->value,
            turnCount: 1,
            collectedData: $existingData
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockSessionManager
            ->shouldReceive('getConversationHistory')
            ->once()
            ->andReturn([]);

        $intentResponse = StructuredAIResponseDTO::success(
            nextAction: 'CONTINUE',
            responseText: 'Intent understood',
            slots: ['intent' => 'PROVIDE_INFO']
        );

        $this->mockIntentParser
            ->shouldReceive('parseWithContext')
            ->once()
            ->andReturn($intentResponse);

        // Mock entity extraction detecting conflict
        $entityResponse = StructuredAIResponseDTO::clarification(
            responseText: 'I notice a conflict in the information provided',
            clarificationQuestion: 'Is Dr. Smith your name or the doctor you want to see?',
            confidence: 0.3,
            reasoning: 'Conflicting patient/doctor names detected',
            slots: ['patient_name' => 'Dr. Smith']
        );

        $this->mockEntityExtractor
            ->shouldReceive('extractWithContext')
            ->once()
            ->andReturn($entityResponse);

        $dialogueResult = [
            'state' => ConversationState::COLLECT_PATIENT_NAME->value,
            'response' => 'Is Dr. Smith your name or the doctor you want to see?',
            'metadata' => ['conflict_detected' => true],
            'auto_advance' => false
        ];

        $this->mockDialogueManager
            ->shouldReceive('processStructuredResponse')
            ->once()
            ->andReturn($dialogueResult);

        // Act
        $result = $this->orchestrator->processTurnEnhanced($sessionId, $userMessage);

        // Assert
        $this->assertTrue($result->metadata['clarification_requested']);
        $this->assertStringContainsString('conflict', $result->response);
    }

    /** @test */
    public function it_auto_advances_when_all_required_entities_are_present()
    {
        // Arrange
        $sessionId = 'test-session-auto-advance';
        $userMessage = "My name is Jane Doe, I was born 1985-03-15, my phone is +1234567890";

        $existingData = ['doctor_name' => 'Dr. Johnson', 'date' => '2024-01-20', 'time' => '10:00'];

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::BOOK_APPOINTMENT->value,
            turnCount: 1,
            collectedData: $existingData
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->once()
            ->andReturn($mockSession);

        $this->mockSessionManager
            ->shouldReceive('getConversationHistory')
            ->once()
            ->andReturn([]);

        $intentResponse = StructuredAIResponseDTO::success(
            nextAction: 'CONTINUE',
            responseText: 'I understand you want to book an appointment',
            confidence: 0.95,
            slots: ['intent' => 'BOOK_APPOINTMENT']
        );

        $this->mockIntentParser
            ->shouldReceive('parseWithContext')
            ->once()
            ->andReturn($intentResponse);

        // Mock entity extraction with all required entities
        $entityResponse = StructuredAIResponseDTO::success(
            nextAction: 'ENTITIES_EXTRACTED',
            responseText: 'All required information collected',
            confidence: 0.9,
            slots: [
                'patient_name' => 'Jane Doe',
                'date_of_birth' => '1985-03-15',
                'phone' => '+1234567890'
            ]
        );

        $this->mockEntityExtractor
            ->shouldReceive('extractWithContext')
            ->once()
            ->andReturn($entityResponse);

        $dialogueResult = [
            'state' => ConversationState::VERIFY_PATIENT->value,
            'response' => 'Thank you! I have all the information needed. Let me verify your details.',
            'metadata' => ['all_entities_collected' => true],
            'auto_advance' => true,
            'extracted_slots' => [
                'patient_name' => 'Jane Doe',
                'date_of_birth' => '1985-03-15',
                'phone' => '+1234567890'
            ]
        ];

        $this->mockDialogueManager
            ->shouldReceive('processStructuredResponse')
            ->once()
            ->andReturn($dialogueResult);

        $this->mockSessionManager
            ->shouldReceive('update')
            ->once()
            ->with($sessionId, Mockery::type('array'));

        // Act
        $result = $this->orchestrator->processTurnEnhanced($sessionId, $userMessage);

        // Assert
        $this->assertTrue($result->metadata['auto_advance']);
        $this->assertEquals(ConversationState::VERIFY_PATIENT->value, $result->newState);
        $this->assertStringContainsString('all the information needed', $result->response);
    }

    /** @test */
    public function it_maintains_backward_compatibility_with_original_process_turn()
    {
        // This test ensures the original processTurn method still works
        // Arrange
        $sessionId = 'test-session-legacy';
        $userMessage = "Book with Dr. Smith tomorrow";

        $mockSession = new SessionDTO(
            sessionId: $sessionId,
            conversationState: ConversationState::GREETING->value,
            turnCount: 0,
            collectedData: []
        );

        $this->mockSessionManager
            ->shouldReceive('get')
            ->with($sessionId)
            ->andReturn($mockSession);

        $this->mockIntentParser
            ->shouldReceive('parse')
            ->andReturn(new \App\DTOs\IntentDTO(
                intent: IntentType::BOOK_APPOINTMENT->value,
                confidence: 0.8
            ));

        $this->mockEntityExtractor
            ->shouldReceive('extract')
            ->andReturn(new \App\DTOs\EntityDTO(
                doctorName: 'Dr. Smith',
                date: date('Y-m-d', strtotime('tomorrow'))
            ));

        $this->mockDialogueManager
            ->shouldReceive('getNextState')
            ->andReturn(ConversationState::BOOK_APPOINTMENT->value);

        $this->mockDialogueManager
            ->shouldReceive('generateResponse')
            ->andReturn('I can help you book an appointment');

        $this->mockSessionManager
            ->shouldReceive('updateCollectedData')
            ->once();

        // Act
        $result = $this->orchestrator->processTurn($sessionId, $userMessage);

        // Assert
        $this->assertInstanceOf(ConversationTurnDTO::class, $result);
        $this->assertEquals($sessionId, $result->sessionId);
        $this->assertEquals(ConversationState::BOOK_APPOINTMENT->value, $result->newState);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}