<?php
// ============================================================================
// FILE 2: MockIntentParserServiceTest.php
// Location: tests/Unit/Services/MockIntentParserServiceTest.php
// ============================================================================

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Services\AI\Mock\MockIntentParserService;
use App\Enums\IntentType;

class MockIntentParserServiceTest extends TestCase
{
    private MockIntentParserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MockIntentParserService();
    }

    #[Test]
    public function it_detects_greeting_intent()
    {
        $result = $this->service->parse("Hello there", []);

        $this->assertEquals(IntentType::GREETING->value, $result->intent);
        $this->assertGreaterThan(0.7, $result->confidence);
    }

    #[Test]
    public function it_detects_booking_intent()
    {
        $result = $this->service->parse("I need to book an appointment", []);

        $this->assertEquals(IntentType::BOOK_APPOINTMENT->value, $result->intent);
        $this->assertGreaterThan(0.7, $result->confidence);
    }

    #[Test]
    public function it_detects_cancel_intent()
    {
        $result = $this->service->parse("I want to cancel my appointment", []);

        $this->assertEquals(IntentType::CANCEL_APPOINTMENT->value, $result->intent);
        $this->assertGreaterThan(0.7, $result->confidence);
    }

    #[Test]
    public function it_detects_reschedule_intent()
    {
        $result = $this->service->parse("Can I reschedule my appointment?", []);

        $this->assertEquals(IntentType::RESCHEDULE_APPOINTMENT->value, $result->intent);
        $this->assertGreaterThan(0.7, $result->confidence);
    }

    #[Test]
    public function it_detects_check_appointment_intent()
    {
        $result = $this->service->parse("When is my appointment?", []);

        $this->assertEquals(IntentType::CHECK_APPOINTMENT->value, $result->intent);
        $this->assertGreaterThan(0.7, $result->confidence);
    }

    #[Test]
    public function it_detects_confirm_intent()
    {
        $result = $this->service->parse("Yes, that's correct", []);

        $this->assertEquals(IntentType::CONFIRM->value, $result->intent);
    }

    #[Test]
    public function it_detects_deny_intent()
    {
        $result = $this->service->parse("No, that's wrong", []);

        $this->assertEquals(IntentType::DENY->value, $result->intent);
    }

    #[Test]
    public function it_detects_goodbye_intent()
    {
        $result = $this->service->parse("Thanks, bye!", []);

        $this->assertEquals(IntentType::GOODBYE->value, $result->intent);
    }

    #[Test]
    public function it_detects_provide_info_intent()
    {
        $result = $this->service->parse("My name is John Smith", []);

        $this->assertEquals(IntentType::PROVIDE_INFO->value, $result->intent);
    }

    #[Test]
    public function it_returns_unknown_for_unclear_message()
    {
        $result = $this->service->parse("asdfghjkl", []);

        $this->assertEquals(IntentType::UNKNOWN->value, $result->intent);
        $this->assertLessThan(0.7, $result->confidence);
    }

    #[Test]
    public function it_is_always_available()
    {
        $this->assertTrue($this->service->isAvailable());
    }

    #[Test]
    public function it_returns_mock_type()
    {
        $this->assertEquals('mock', $this->service->getType());
    }
}
