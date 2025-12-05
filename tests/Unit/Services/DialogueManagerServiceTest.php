<?php
// ============================================================================
// FILE 1: DialogueManagerServiceTest.php
// Location: tests/Unit/Services/DialogueManagerServiceTest.php
// ============================================================================

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Conversation\DialogueManagerService;
use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\DTOs\SessionDTO;
use App\Enums\ConversationState;
use App\Enums\IntentType;

class DialogueManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    private DialogueManagerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DialogueManagerService();
    }

    #[Test]
    public function it_moves_from_greeting_to_detect_intent()
    {
        $intent = new IntentDTO(IntentType::GREETING->value, 0.9, 'test');
        $entities = new EntityDTO([]);

        $nextState = $this->service->getNextState(
            ConversationState::GREETING->value,
            $intent,
            $entities
        );

        $this->assertEquals(ConversationState::DETECT_INTENT->value, $nextState);
    }

    #[Test]
    public function it_routes_to_booking_on_book_intent()
    {
        $intent = new IntentDTO(IntentType::BOOK_APPOINTMENT->value, 0.95, 'test');
        $entities = new EntityDTO([]);

        $nextState = $this->service->getNextState(
            ConversationState::DETECT_INTENT->value,
            $intent,
            $entities
        );

        $this->assertEquals(ConversationState::BOOK_APPOINTMENT->value, $nextState);
    }

    #[Test]
    public function it_collects_patient_name_in_booking_flow()
    {
        $intent = new IntentDTO(IntentType::PROVIDE_INFO->value, 0.9, 'test');
        $entities = new EntityDTO([]);

        $nextState = $this->service->getNextState(
            ConversationState::BOOK_APPOINTMENT->value,
            $intent,
            $entities
        );

        $this->assertEquals(ConversationState::COLLECT_PATIENT_NAME->value, $nextState);
    }

    #[Test]
    public function it_progresses_when_name_collected()
    {
        $intent = new IntentDTO(IntentType::PROVIDE_INFO->value, 0.9, 'test');
        $entities = new EntityDTO(['patient_name' => 'John Doe']);

        $nextState = $this->service->getNextState(
            ConversationState::COLLECT_PATIENT_NAME->value,
            $intent,
            $entities
        );

        $this->assertEquals(ConversationState::COLLECT_PATIENT_DOB->value, $nextState);
    }

    #[Test]
    public function it_stays_in_state_when_required_entity_missing()
    {
        $intent = new IntentDTO(IntentType::PROVIDE_INFO->value, 0.9, 'test');
        $entities = new EntityDTO([]); // No name provided

        $nextState = $this->service->getNextState(
            ConversationState::COLLECT_PATIENT_NAME->value,
            $intent,
            $entities
        );

        $this->assertEquals(ConversationState::COLLECT_PATIENT_NAME->value, $nextState);
    }

    #[Test]
    public function it_returns_required_entities_for_state()
    {
        $required = $this->service->getRequiredEntities(ConversationState::COLLECT_PATIENT_NAME->value);
        $this->assertEquals(['patient_name'], $required);

        $required = $this->service->getRequiredEntities(ConversationState::COLLECT_PATIENT_DOB->value);
        $this->assertEquals(['date_of_birth'], $required);

        $required = $this->service->getRequiredEntities(ConversationState::SELECT_DATE->value);
        $this->assertEquals(['date'], $required);
    }

    #[Test]
    public function it_checks_if_can_proceed()
    {
        // Can proceed when has required data
        $canProceed = $this->service->canProceed(
            ConversationState::COLLECT_PATIENT_NAME->value,
            ['patient_name' => 'John Doe']
        );
        $this->assertTrue($canProceed);

        // Cannot proceed when missing required data
        $canProceed = $this->service->canProceed(
            ConversationState::COLLECT_PATIENT_NAME->value,
            []
        );
        $this->assertFalse($canProceed);
    }

    #[Test]
    public function it_generates_prompt_for_missing_entities()
    {
        $prompt = $this->service->generatePromptForMissingEntities(
            ['patient_name'],
            ConversationState::COLLECT_PATIENT_NAME->value
        );

        $this->assertStringContainsString('name', strtolower($prompt));
    }

    #[Test]
    public function it_returns_greeting()
    {
        $greeting = $this->service->getGreeting();
        $this->assertNotEmpty($greeting);
        $this->assertStringContainsString('calling', strtolower($greeting));
    }

    #[Test]
    public function it_validates_states()
    {
        $this->assertTrue($this->service->isValidState(ConversationState::GREETING->value));
        $this->assertTrue($this->service->isValidState(ConversationState::BOOK_APPOINTMENT->value));
        $this->assertFalse($this->service->isValidState('INVALID_STATE'));
    }
}
