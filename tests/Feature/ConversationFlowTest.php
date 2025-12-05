<?php
// ============================================================================
// FILE 4: ConversationFlowTest.php
// Location: tests/Feature/ConversationFlowTest.php
// ============================================================================

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use App\Orchestrators\ConversationOrchestrator;
use App\Services\Conversation\SessionManagerService;
use App\Services\Conversation\DialogueManagerService;
use App\Services\AI\Mock\MockIntentParserService;
use App\Services\AI\Mock\MockEntityExtractorService;
use App\Enums\ConversationState;
use App\Enums\IntentType;

class ConversationFlowTest extends TestCase
{
    use RefreshDatabase;

    private ConversationOrchestrator $orchestrator;
    private SessionManagerService $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::flushdb();

        $intentParser = new MockIntentParserService();
        $entityExtractor = new MockEntityExtractorService();
        $this->sessionManager = new SessionManagerService();
        $dialogueManager = new DialogueManagerService();

        $this->orchestrator = new ConversationOrchestrator(
            $intentParser,
            $entityExtractor,
            $this->sessionManager,
            $dialogueManager
        );
    }

    protected function tearDown(): void
    {
        Redis::flushdb();
        parent::tearDown();
    }

    #[Test]
    public function it_handles_complete_greeting_to_booking_flow()
    {
        $sessionId = 'session:test:flow1';

        // Create session
        $this->sessionManager->create($sessionId, [
            'call_id' => 1,
            'channel' => 'test',
        ]);

        // Turn 1: User says "I need an appointment"
        $turn1 = $this->orchestrator->processTurn($sessionId, "I need to book an appointment");

        $this->assertEquals(IntentType::BOOK_APPOINTMENT->value, $turn1->intent->intent);
        $this->assertEquals(ConversationState::COLLECT_PATIENT_NAME->value, $turn1->conversationState);
        $this->assertStringContainsString('name', strtolower($turn1->systemResponse));

        // Turn 2: User provides name
        $turn2 = $this->orchestrator->processTurn($sessionId, "My name is John Smith");

        $this->assertStringContainsString('john smith', strtolower($turn2->entities->toArray()['patient_name']));
        $this->assertEquals(ConversationState::COLLECT_PATIENT_DOB->value, $turn2->conversationState);

        // Turn 3: User provides DOB
        $turn3 = $this->orchestrator->processTurn($sessionId, "My birthday is 01/15/1980");

        $this->assertArrayHasKey('date_of_birth', $turn3->entities->toArray());
        $this->assertEquals(ConversationState::COLLECT_PATIENT_PHONE->value, $turn3->conversationState);

        // Turn 4: User provides phone
        $turn4 = $this->orchestrator->processTurn($sessionId, "My phone is 555-1234");

        $this->assertArrayHasKey('phone', $turn4->entities->toArray());

        // Verify session has all collected data
        $session = $this->sessionManager->get($sessionId);
        $this->assertArrayHasKey('patient_name', $session->collectedData);
        $this->assertArrayHasKey('phone', $session->collectedData);
    }

    #[Test]
    public function it_tracks_turn_numbers()
    {
        $sessionId = 'session:test:turns';

        $this->sessionManager->create($sessionId, ['call_id' => 2]);

        $turn1 = $this->orchestrator->processTurn($sessionId, "Hello");
        $this->assertEquals(1, $turn1->turnNumber);

        $turn2 = $this->orchestrator->processTurn($sessionId, "I need an appointment");
        $this->assertEquals(2, $turn2->turnNumber);

        $turn3 = $this->orchestrator->processTurn($sessionId, "My name is Jane");
        $this->assertEquals(3, $turn3->turnNumber);
    }

    #[Test]
    public function it_maintains_conversation_history()
    {
        $sessionId = 'session:test:history';

        $this->sessionManager->create($sessionId, ['call_id' => 3]);

        $this->orchestrator->processTurn($sessionId, "Hello");
        $this->orchestrator->processTurn($sessionId, "I need an appointment");
        $this->orchestrator->processTurn($sessionId, "My name is Bob");

        $session = $this->sessionManager->get($sessionId);

        $this->assertCount(6, $session->conversationHistory); // 3 turns x 2 messages (user + assistant)
        $this->assertEquals('user', $session->conversationHistory[0]['role']);
        $this->assertEquals('assistant', $session->conversationHistory[1]['role']);
    }

    #[Test]
    public function it_handles_errors_gracefully()
    {
        $sessionId = 'session:test:nonexistent';

        // Try to process turn without creating session
        $turn = $this->orchestrator->processTurn($sessionId, "Hello");

        $this->assertStringContainsString('error', strtolower($turn->systemResponse));
        $this->assertEquals(IntentType::UNKNOWN->value, $turn->intent->intent);
    }
}
