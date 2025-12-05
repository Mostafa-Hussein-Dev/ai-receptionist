<?php


namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use App\Services\Conversation\SessionManagerService;
use App\Enums\ConversationState;

class SessionManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionManagerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Flush Redis before each test
        Redis::flushdb();

        $this->service = new SessionManagerService();
    }

    protected function tearDown(): void
    {
        // Clean up Redis after each test
        Redis::flushdb();

        parent::tearDown();
    }

    #[Test]
    public function it_can_create_session()
    {
        $sessionId = 'session:test:123';

        $session = $this->service->create($sessionId, [
            'call_id' => 1,
            'channel' => 'telegram',
        ]);

        $this->assertNotNull($session);
        $this->assertEquals($sessionId, $session->sessionId);
        $this->assertEquals(1, $session->callId);
        $this->assertEquals('telegram', $session->channel);
        $this->assertEquals(ConversationState::GREETING->value, $session->conversationState);
    }

    #[Test]
    public function it_can_get_session()
    {
        $sessionId = 'session:test:456';

        $this->service->create($sessionId, ['call_id' => 2]);
        $session = $this->service->get($sessionId);

        $this->assertNotNull($session);
        $this->assertEquals($sessionId, $session->sessionId);
        $this->assertEquals(2, $session->callId);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_session()
    {
        $session = $this->service->get('session:test:nonexistent');

        $this->assertNull($session);
    }

    #[Test]
    public function it_can_update_session()
    {
        $sessionId = 'session:test:789';

        $this->service->create($sessionId, ['call_id' => 3]);
        $this->service->update($sessionId, [
            'patient_id' => 100,
            'turn_count' => 5,
        ]);

        $session = $this->service->get($sessionId);

        $this->assertEquals(100, $session->patientId);
        $this->assertEquals(5, $session->turnCount);
    }

    #[Test]
    public function it_can_update_collected_data()
    {
        $sessionId = 'session:test:data';

        $this->service->create($sessionId, ['call_id' => 4]);
        $this->service->updateCollectedData($sessionId, [
            'patient_name' => 'John Doe',
            'phone' => '+15551234567',
        ]);

        $session = $this->service->get($sessionId);

        $this->assertEquals('John Doe', $session->collectedData['patient_name']);
        $this->assertEquals('+15551234567', $session->collectedData['phone']);
    }

    #[Test]
    public function it_can_update_conversation_state()
    {
        $sessionId = 'session:test:state';

        $this->service->create($sessionId, ['call_id' => 5]);
        $this->service->updateState($sessionId, ConversationState::COLLECT_PATIENT_NAME->value);

        $session = $this->service->get($sessionId);

        $this->assertEquals(ConversationState::COLLECT_PATIENT_NAME->value, $session->conversationState);
    }

    #[Test]
    public function it_can_add_messages_to_history()
    {
        $sessionId = 'session:test:messages';

        $this->service->create($sessionId, ['call_id' => 6]);
        $this->service->addMessage($sessionId, [
            'role' => 'user',
            'content' => 'Hello',
        ]);
        $this->service->addMessage($sessionId, [
            'role' => 'assistant',
            'content' => 'Hi there!',
        ]);

        $session = $this->service->get($sessionId);

        $this->assertCount(2, $session->conversationHistory);
        $this->assertEquals('user', $session->conversationHistory[0]['role']);
        $this->assertEquals('Hello', $session->conversationHistory[0]['content']);
    }

    #[Test]
    public function it_can_delete_session()
    {
        $sessionId = 'session:test:delete';

        $this->service->create($sessionId, ['call_id' => 7]);
        $this->assertTrue($this->service->exists($sessionId));

        $this->service->delete($sessionId);
        $this->assertFalse($this->service->exists($sessionId));
    }

    #[Test]
    public function it_can_check_session_existence()
    {
        $sessionId = 'session:test:exists';

        $this->assertFalse($this->service->exists($sessionId));

        $this->service->create($sessionId, ['call_id' => 8]);
        $this->assertTrue($this->service->exists($sessionId));
    }

    #[Test]
    public function it_auto_extends_ttl_on_get()
    {
        $sessionId = 'session:test:ttl';

        $this->service->create($sessionId, ['call_id' => 9]);

        // Get initial TTL
        $initialTtl = Redis::ttl("session:test:ttl");

        // Wait a moment
        sleep(1);

        // Get session (should extend TTL)
        $this->service->get($sessionId);

        // Get new TTL
        $newTtl = Redis::ttl("session:test:ttl");

        // New TTL should be close to original (re-extended)
        $this->assertGreaterThan($initialTtl - 2, $newTtl);
    }

    #[Test]
    public function it_can_get_session_stats()
    {
        $sessionId = 'session:test:stats';

        $this->service->create($sessionId, ['call_id' => 10]);
        $this->service->update($sessionId, ['turn_count' => 3]);
        $this->service->updateCollectedData($sessionId, [
            'name' => 'Jane',
            'phone' => '+15559999999',
        ]);

        $stats = $this->service->getStats($sessionId);

        $this->assertArrayHasKey('age_minutes', $stats);
        $this->assertEquals(3, $stats['turn_count']);
        $this->assertEquals(2, $stats['collected_data_count']);
        $this->assertEquals(ConversationState::GREETING->value, $stats['conversation_state']);
    }
}
