<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AI\OpenAI\EntityExtractorService;
use App\Services\AI\OpenAI\OpenAILLMService;
use App\DTOs\StructuredAIResponseDTO;
use Mockery;

class EnhancedEntityExtractorServiceTest extends TestCase
{
    private EntityExtractorService $entityExtractor;
    private $mockLLMService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLLMService = Mockery::mock(OpenAILLMService::class);
        $this->entityExtractor = new EntityExtractorService($this->mockLLMService);

        // Set test configuration
        config([
            'ai.entity.confidence_threshold' => 0.7,
            'ai.entity.clarification_threshold' => 0.5,
            'ai.entity.max_history_turns' => 5
        ]);
    }

    /** @test */
    public function it_extracts_entities_with_confidence_scoring()
    {
        // Arrange
        $text = "My name is John Smith and I was born on March 15, 1985";
        $context = [
            'conversation_state' => 'COLLECT_PATIENT_NAME',
            'conversation_history' => [],
            'collected_data' => []
        ];

        $mockAIResponse = [
            'entities' => [
                'patient_name' => ['value' => 'John Smith', 'confidence' => 0.9],
                'date_of_birth' => ['value' => '1985-03-15', 'confidence' => 0.8],
                'date' => ['value' => null, 'confidence' => 0.0],
                'time' => ['value' => null, 'confidence' => 0.0],
                'phone' => ['value' => null, 'confidence' => 0.0],
                'doctor_name' => ['value' => null, 'confidence' => 0.0],
                'department' => ['value' => null, 'confidence' => 0.0]
            ],
            'average_confidence' => 0.85,
            'reasoning' => 'Clear patient name and DOB extracted',
            'confirmation_text' => 'I\'ve captured your name as John Smith and date of birth as March 15, 1985',
            'requires_clarification' => false,
            'clarification_needed' => [],
            'conflicts_detected' => [],
            'missing_required' => [],
            'context_used' => 'State-specific extraction focused on patient information'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertEquals(0.85, $result->confidence);
        $this->assertFalse($result->requires_clarification);
        $this->assertEquals('John Smith', $result->slots['patient_name']);
        $this->assertEquals('1985-03-15', $result->slots['date_of_birth']);
        $this->assertEquals('ENTITIES_EXTRACTED', $result->next_action);
    }

    /** @test */
    public function it_requests_clarification_for_missing_required_entities()
    {
        // Arrange
        $text = "I want to see Dr. Smith";
        $context = [
            'conversation_state' => 'BOOK_APPOINTMENT',
            'conversation_history' => [],
            'collected_data' => []
        ];

        $mockAIResponse = [
            'entities' => [
                'doctor_name' => ['value' => 'Dr. Smith', 'confidence' => 0.9],
                'patient_name' => ['value' => null, 'confidence' => 0.0],
                'date_of_birth' => ['value' => null, 'confidence' => 0.0],
                'phone' => ['value' => null, 'confidence' => 0.0],
                'date' => ['value' => null, 'confidence' => 0.0],
                'time' => ['value' => null, 'confidence' => 0.0],
                'department' => ['value' => null, 'confidence' => 0.0]
            ],
            'average_confidence' => 0.4,
            'reasoning' => 'Only doctor name extracted, missing required patient information',
            'confirmation_text' => 'I found Dr. Smith but need more information',
            'requires_clarification' => true,
            'clarification_needed' => ['patient_name', 'date_of_birth', 'phone', 'date', 'time'],
            'conflicts_detected' => [],
            'missing_required' => ['patient_name', 'date_of_birth', 'phone', 'date', 'time'],
            'context_used' => 'Booking context requires all entities'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertTrue($result->requires_clarification);
        $this->assertStringContainsString('need some additional information', $result->response_text);
        $this->assertStringContainsString('patient_name', $result->clarification_question);
        $this->assertEquals('Dr. Smith', $result->slots['doctor_name']);
    }

    /** @test */
    public function it_detects_conflicting_entities_with_existing_data()
    {
        // Arrange
        $text = "Actually, my name is Sarah Johnson";
        $context = [
            'conversation_state' => 'COLLECT_PATIENT_NAME',
            'conversation_history' => [],
            'collected_data' => ['patient_name' => 'John Smith']
        ];

        $mockAIResponse = [
            'entities' => [
                'patient_name' => ['value' => 'Sarah Johnson', 'confidence' => 0.9],
                'date_of_birth' => ['value' => null, 'confidence' => 0.0],
                'phone' => ['value' => null, 'confidence' => 0.0],
                'date' => ['value' => null, 'confidence' => 0.0],
                'time' => ['value' => null, 'confidence' => 0.0],
                'doctor_name' => ['value' => null, 'confidence' => 0.0],
                'department' => ['value' => null, 'confidence' => 0.0]
            ],
            'average_confidence' => 0.9,
            'reasoning' => 'Patient provided corrected name',
            'confirmation_text' => 'I\'ve updated your name to Sarah Johnson',
            'requires_clarification' => true,
            'clarification_needed' => [],
            'conflicts_detected' => ['patient_name'],
            'missing_required' => [],
            'context_used' => 'Detected correction to previously collected name'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertTrue($result->requires_clarification);
        $this->assertStringContainsString('conflicting information', $result->response_text);
        $this->assertEquals('Sarah Johnson', $result->slots['patient_name']);
    }

    /** @test */
    public function it_handles_low_confidence_scenarios_appropriately()
    {
        // Arrange
        $text = "maybe 10ish or something";
        $context = [
            'conversation_state' => 'SELECT_SLOT',
            'conversation_history' => [],
            'collected_data' => []
        ];

        $mockAIResponse = [
            'entities' => [
                'time' => ['value' => '10:00', 'confidence' => 0.3],
                'date' => ['value' => null, 'confidence' => 0.0],
                'patient_name' => ['value' => null, 'confidence' => 0.0],
                'date_of_birth' => ['value' => null, 'confidence' => 0.0],
                'phone' => ['value' => null, 'confidence' => 0.0],
                'doctor_name' => ['value' => null, 'confidence' => 0.0],
                'department' => ['value' => null, 'confidence' => 0.0]
            ],
            'average_confidence' => 0.3,
            'reasoning' => 'Very ambiguous time reference',
            'confirmation_text' => 'I think you mean 10:00 but I\'m not sure',
            'requires_clarification' => false,
            'clarification_needed' => [],
            'conflicts_detected' => [],
            'missing_required' => [],
            'context_used' => 'Time selection with ambiguous input'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertEquals('CONFIDENCE_LOW', $result->next_action);
        $this->assertEquals(0.3, $result->confidence);
        $this->assertStringContainsString('make sure I captured', $result->response_text);
    }

    /** @test */
    public function it_handles_state_specific_extraction_focusing()
    {
        // Arrange - Testing that it only extracts time in SELECT_SLOT state
        $text = "Dr. Smith at 2 PM tomorrow";
        $context = [
            'conversation_state' => 'SELECT_SLOT',
            'conversation_history' => [
                ['role' => 'assistant', 'content' => 'What time works best for you with Dr. Smith tomorrow?']
            ],
            'collected_data' => [
                'doctor_name' => 'Dr. Smith',
                'date' => date('Y-m-d', strtotime('tomorrow'))
            ]
        ];

        $mockAIResponse = [
            'entities' => [
                'time' => ['value' => '14:00', 'confidence' => 0.9],
                'date' => ['value' => null, 'confidence' => 0.0], // Should be ignored in SELECT_SLOT
                'doctor_name' => ['value' => null, 'confidence' => 0.0], // Should be ignored
                'patient_name' => ['value' => null, 'confidence' => 0.0],
                'date_of_birth' => ['value' => null, 'confidence' => 0.0],
                'phone' => ['value' => null, 'confidence' => 0.0],
                'department' => ['value' => null, 'confidence' => 0.0]
            ],
            'average_confidence' => 0.9,
            'reasoning' => 'State-specific extraction focusing only on time in SELECT_SLOT state',
            'confirmation_text' => 'I\'ve selected 2:00 PM for your appointment',
            'requires_clarification' => false,
            'clarification_needed' => [],
            'conflicts_detected' => [],
            'missing_required' => [],
            'context_used' => 'SELECT_SLOT state - only time extraction needed'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertEquals('14:00', $result->slots['time']);
        $this->assertNull($result->slots['date']);
        $this->assertNull($result->slots['doctor_name']);
        $this->assertFalse($result->requires_clarification);
        $this->assertEquals(0.9, $result->confidence);
    }

    /** @test */
    public function it_detects_doctor_patient_name_conflicts()
    {
        // Arrange
        $text = "My name is Dr. Williams";
        $context = [
            'conversation_state' => 'COLLECT_PATIENT_NAME',
            'conversation_history' => [
                ['role' => 'assistant', 'content' => 'I\'ve selected Dr. Johnson for you']
            ],
            'collected_data' => ['doctor_name' => 'Dr. Johnson']
        ];

        $mockAIResponse = [
            'entities' => [
                'patient_name' => ['value' => 'Dr. Williams', 'confidence' => 0.8],
                'doctor_name' => ['value' => null, 'confidence' => 0.0],
                'date' => ['value' => null, 'confidence' => 0.0],
                'time' => ['value' => null, 'confidence' => 0.0],
                'date_of_birth' => ['value' => null, 'confidence' => 0.0],
                'phone' => ['value' => null, 'confidence' => 0.0],
                'department' => ['value' => null, 'confidence' => 0.0]
            ],
            'average_confidence' => 0.8,
            'reasoning' => 'Patient name detected with Dr. prefix - potential confusion',
            'confirmation_text' => 'I need to clarify something about your name',
            'requires_clarification' => true,
            'clarification_needed' => [],
            'conflicts_detected' => ['doctor_patient_name_conflict'],
            'missing_required' => [],
            'context_used' => 'Detected potential doctor/patient name confusion'
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertTrue($result->requires_clarification);
        $this->assertEquals('Dr. Williams', $result->slots['patient_name']);
        $this->assertStringContainsString('conflicting information', $result->response_text);
    }

    /** @test */
    public function it_handles_legacy_format_responses_gracefully()
    {
        // Arrange
        $text = "Call me at 555-1234";
        $context = [
            'conversation_state' => 'COLLECT_PATIENT_PHONE',
            'conversation_history' => [],
            'collected_data' => []
        ];

        // Mock old format response (without confidence sub-arrays)
        $mockAIResponse = [
            'phone' => '+15551234',
            'patient_name' => null,
            'date' => null,
            'time' => null,
            'date_of_birth' => null,
            'doctor_name' => null,
            'department' => null
        ];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andReturn($mockAIResponse);

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertEquals('+15551234', $result->slots['phone']);
        $this->assertEquals(0.8, $result->confidence); // Default confidence for legacy format
        $this->assertEquals('ENTITIES_EXTRACTED', $result->next_action);
    }

    /** @test */
    public function it_handles_extraction_errors_with_fallback()
    {
        // Arrange
        $text = "Test message";
        $context = [];

        $this->mockLLMService
            ->shouldReceive('generateJSON')
            ->once()
            ->andThrow(new \Exception('API failure'));

        // Act
        $result = $this->entityExtractor->extractWithContext($text, $context);

        // Assert
        $this->assertInstanceOf(StructuredAIResponseDTO::class, $result);
        $this->assertEquals('ERROR', $result->next_action);
        $this->assertEquals(0.0, $result->confidence);
        $this->assertStringContainsString('trouble extracting', $result->response_text);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}