<?php
// ============================================================================
// FILE 3: MockEntityExtractorServiceTest.php
// Location: tests/Unit/Services/MockEntityExtractorServiceTest.php
// ============================================================================

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Services\AI\Mock\MockEntityExtractorService;

class MockEntityExtractorServiceTest extends TestCase
{
    private MockEntityExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MockEntityExtractorService();
    }

    #[Test]
    public function it_extracts_patient_name()
    {
        $result = $this->service->extract("My name is John Smith", []);

        $this->assertArrayHasKey('patient_name', $result->toArray());
        $this->assertEquals('john smith', strtolower($result->toArray()['patient_name']));
    }

    #[Test]
    public function it_extracts_phone_number()
    {
        $result = $this->service->extract("My phone is (555) 123-4567", []);

        $this->assertArrayHasKey('phone', $result->toArray());
        $this->assertStringContainsString('555', $result->toArray()['phone']);
    }

    #[Test]
    public function it_extracts_date()
    {
        $result = $this->service->extract("I want an appointment on 01/15/2025", []);

        $this->assertArrayHasKey('date', $result->toArray());
        $this->assertEquals('2025-01-15', $result->toArray()['date']);
    }

    #[Test]
    public function it_extracts_relative_date_tomorrow()
    {
        $result = $this->service->extract("I want it tomorrow", []);

        $this->assertArrayHasKey('date', $result->toArray());
        $this->assertNotNull($result->toArray()['date']);
    }

    #[Test]
    public function it_extracts_time()
    {
        $result = $this->service->extract("How about 2:30 PM?", []);

        $this->assertArrayHasKey('time', $result->toArray());
        $this->assertEquals('14:30', $result->toArray()['time']);
    }

    #[Test]
    public function it_extracts_doctor_name()
    {
        $result = $this->service->extract("I want to see Dr. Johnson", []);

        $this->assertArrayHasKey('doctor_name', $result->toArray());
        $this->assertStringContainsString('Johnson', $result->toArray()['doctor_name']);
    }

    #[Test]
    public function it_extracts_department()
    {
        $result = $this->service->extract("I need cardiology", []);

        $this->assertArrayHasKey('department', $result->toArray());
        $this->assertEquals('cardiology', $result->toArray()['department']);
    }

    #[Test]
    public function it_returns_supported_entities()
    {
        $supported = $this->service->getSupportedEntities();

        $this->assertContains('patient_name', $supported);
        $this->assertContains('phone', $supported);
        $this->assertContains('date', $supported);
        $this->assertContains('time', $supported);
        $this->assertCount(7, $supported);
    }

    #[Test]
    public function it_extracts_specific_entities()
    {
        $result = $this->service->extractSpecific(
            "My name is Jane Doe and my phone is 555-9999",
            ['patient_name', 'phone'],
            []
        );

        $this->assertArrayHasKey('patient_name', $result->toArray());
        $this->assertArrayHasKey('phone', $result->toArray());
        $this->assertFalse($result->has('date'));
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
