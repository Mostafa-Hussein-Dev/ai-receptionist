<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AI\OpenAI\IntentParserService;
use App\Services\AI\OpenAI\OpenAILLMService;
use App\DTOs\StructuredAIResponseDTO;
use App\Enums\IntentType;
use Mockery;

class EnhancedIntentParserServiceTest extends TestCase
{
    private IntentParserService $intentParser;
    private $mockLLMService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLLMService = Mockery::mock(OpenAILLMService::class);
        $this->intentParser = new IntentParserService($this->mockLLMService);

        // Set test configuration
        config([
            'ai.intent.confidence_threshold' => 0.7,
            'ai.intent.clarification_threshold' => 0.5,
            'ai.intent.use_history' => true,
            'ai.intent.max_history_turns' => 5
        ]);
    }

    /** @test */
    public function it_can_parse_intent_with_context_high_confidence()
    {
        // Arrange
        $userMessage = "I want to book an appointment with Dr. Smith tomorrow";
        $context = [
            'conversation_state' => 'GREETING',
            'conversation_history' => [
                ['role' => 'assistant', 'content' => 'Hello! How can I help you today?']
            ],
            'previous_intent' => null
        ];

        $mockAIResponse = [
            'intent' => 'BOOK_APPOINTMENT',
            'confidence' => 0.9,
            'reasoning' => 'Clear booking intent with doctor and date mentioned',
            'next_action' => 'CONTINUE',
            'response_text' => 'I understand you want to book an appointment',
            'updated_state' => 'BOOK_APPOINTMENT',
            'slots' => [],
            'requires_clarification' => false,
            'task_switch_detected' => false
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertEquals('BOOK_APPOINTMENT', $result->slots['intent'] ?? 'UNKNOWN');
        $this->assertEquals(0.9, $result->confidence);
        $this->assertFalse($result->requires_clarification);
        $this->assertFalse($result->task_switch_detected);
        $this->assertEquals('CONTINUE', $result->next_action);
    }

    /** @test */
    public function it_detects_task_switching_mid_conversation()
    {
        // Arrange
        $userMessage = "Actually, I want to cancel my appointment instead";
        $context = [
            'conversation_state' => 'SELECT_DATE',
            'conversation_history' => [
                ['role' => 'user', 'content' => 'I want to book an appointment'],
                ['role' => 'assistant', 'content' => 'What doctor would you like to see?'],
                ['role' => 'user', 'content' => 'Dr. Johnson']
            ],
            'previous_intent' => 'BOOK_APPOINTMENT'
        ];

        $mockAIResponse = [
            'intent' => 'CANCEL_APPOINTMENT',
            'confidence' => 0.85,
            'reasoning' => 'User switched from booking to cancellation',
            'next_action' => 'TASK_SWITCH',
            'response_text' => 'I notice you want to cancel instead of book',
            'updated_state' => 'CANCEL_APPOINTMENT',
            'slots' => ['preserved_slots' => ['doctor_name' => 'Dr. Johnson']],
            'requires_clarification' => false,
            'task_switch_detected' => true,
            'preserved_slots' => ['doctor_name' => 'Dr. Johnson']
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertTrue($result->task_switch_detected);
        $this->assertEquals('CANCEL_APPOINTMENT', $result->previous_intent);
        $this->assertEquals('TASK_SWITCH', $result->next_action);
        $this->assertEquals('I notice you want to cancel instead of book', $result->response_text);
    }

    /** @test */
    public function it_requests_clarification_for_low_confidence_intents()
    {
        // Arrange
        $userMessage = "um maybe";
        $context = [
            'conversation_state' => 'CONFIRM_BOOKING',
            'conversation_history' => [],
            'previous_intent' => 'BOOK_APPOINTMENT'
        ];

        $mockAIResponse = [
            'intent' => 'UNKNOWN',
            'confidence' => 0.3,
            'reasoning' => 'Very unclear user response',
            'next_action' => 'CLARIFY',
            'response_text' => "I'm not sure what you'd like to do",
            'clarification_question' => 'Could you please tell me if you want to confirm or cancel this appointment?',
            'slots' => [],
            'requires_clarification' => true,
            'task_switch_detected' => false
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertTrue($result->requires_clarification);
        $this->assertEquals('CLARIFY', $result->next_action);
        $this->assertEquals(0.3, $result->confidence);
        $this->assertNotNull($result->clarification_question);
    }

    /** @test */
    public function it_handles_error_scenarios_gracefully()
    {
        // Arrange
        $userMessage = "Test message";
        $context = [];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andThrow(new \Exception('API Error'));

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertEquals('ERROR', $result->next_action);
        $this->assertEquals(0.0, $result->confidence);
        $this->assertStringContainsString('trouble understanding', $result->response_text);
    }

    /** @test */
    public function it_validates_and_sanitizes_ai_responses()
    {
        // Arrange - AI response with invalid intent and out-of-range confidence
        $userMessage = "Test message";
        $context = [];

        $mockAIResponse = [
            'intent' => 'INVALID_INTENT',
            'confidence' => 1.5, // Out of range
            'reasoning' => 'Test reasoning',
            'next_action' => 'INVALID_ACTION',
            'response_text' => 'Test response'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertEquals('UNKNOWN', $result->slots['intent'] ?? 'UNKNOWN');
        $this->assertEquals(1.0, $result->confidence); // Clamped to valid range
        $this->assertEquals('CONTINUE', $result->next_action); // Default action
    }

    /** @test */
    public function it_detects_task_switching_during_active_booking_flow()
    {
        // Arrange
        $userMessage = "What are your hours?";
        $context = [
            'conversation_state' => 'COLLECT_PATIENT_DOB',
            'previous_intent' => 'BOOK_APPOINTMENT'
        ];

        $mockAIResponse = [
            'intent' => 'GENERAL_INQUIRY',
            'confidence' => 0.8,
            'reasoning' => 'User asking about hospital hours during booking',
            'next_action' => 'TASK_SWITCH',
            'response_text' => 'I notice you changed from booking to asking about hours',
            'updated_state' => 'GENERAL_INQUIRY',
            'slots' => ['preserved_slots' => []],
            'requires_clarification' => false,
            'task_switch_detected' => true
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertTrue($result->task_switch_detected);
        $this->assertEquals('GENERAL_INQUIRY', $result->slots['intent'] ?? 'UNKNOWN');
        $this->assertEquals('BOOK_APPOINTMENT', $result->previous_intent);
    }

    /** @test */
    public function it_handles_confirmation_responses_in_appropriate_states()
    {
        // Arrange
        $userMessage = "Yes, that's correct";
        $context = [
            'conversation_state' => 'CONFIRM_BOOKING',
            'previous_intent' => 'BOOK_APPOINTMENT'
        ];

        $mockAIResponse = [
            'intent' => 'CONFIRM',
            'confidence' => 0.95,
            'reasoning' => 'Clear confirmation in booking confirmation state',
            'next_action' => 'CONTINUE',
            'response_text' => 'Great! I\'ll proceed with booking your appointment',
            'updated_state' => 'EXECUTE_BOOKING',
            'slots' => [],
            'requires_clarification' => false,
            'task_switch_detected' => false
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertEquals('CONFIRM', $result->slots['intent'] ?? 'UNKNOWN');
        $this->assertEquals(0.95, $result->confidence);
        $this->assertFalse($result->requires_clarification);
        $this->assertEquals('EXECUTE_BOOKING', $result->updated_state);
    }

    /** @test */
    public function it_uses_conversation_history_for_better_context()
    {
        // Arrange
        $userMessage = "tomorrow morning";
        $context = [
            'conversation_state' => 'SELECT_DATE',
            'conversation_history' => [
                ['role' => 'user', 'content' => 'I want to book with Dr. Smith'],
                ['role' => 'assistant', 'content' => 'What date would you like?'],
                ['role' => 'user', 'content' => 'next week'],
                ['role' => 'assistant', 'content' => 'Could you be more specific about the date?']
            ],
            'previous_intent' => 'BOOK_APPOINTMENT'
        ];

        $mockAIResponse = [
            'intent' => 'PROVIDE_INFO',
            'confidence' => 0.85,
            'reasoning' => 'User providing date information after clarification',
            'next_action' => 'CONTINUE',
            'response_text' => 'I understand you want tomorrow morning',
            'updated_state' => 'SELECT_SLOT',
            'slots' => [],
            'requires_clarification' => false,
            'task_switch_detected' => false
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->intentParser->parseWithContext($userMessage, $context);

        // Assert
        $this->assertEquals('PROVIDE_INFO', $result->slots['intent'] ?? 'UNKNOWN');
        $this->assertEquals(0.85, $result->confidence);
        $this->assertEquals('SELECT_SLOT', $result->updated_state);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}