<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Business\PatientService;
use App\Models\PostgreSQL\Patient;

class PatientServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PatientService $patientService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patientService = app(PatientService::class);
    }

    #[Test]
    public function it_can_get_all_patients()
    {
        // Arrange: Create test patients
        Patient::factory()->count(3)->create();

        // Act
        $patients = $this->patientService->getAllPatients();

        // Assert
        $this->assertGreaterThanOrEqual(3, $patients->count());
    }

    #[Test]
    public function it_can_lookup_patient_by_phone()
    {
        // Arrange
        $patient = Patient::factory()->create([
            'phone' => '+15551234567'
        ]);

        // Act
        $found = $this->patientService->lookupByPhone('+15551234567');

        // Assert
        $this->assertCount(1, $found);
        $this->assertEquals($patient->id, $found->first()->id);
    }

    #[Test]
    public function it_can_lookup_patient_by_phone_and_name()
    {
        // Arrange
        $patient = Patient::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+15551234567'
        ]);

        // Act
        $found = $this->patientService->lookupByPhoneAndName(
            '+15551234567',
            'John',
            'Doe'
        );

        // Assert
        $this->assertNotNull($found);
        $this->assertEquals($patient->id, $found->id);
    }

    #[Test]
    public function it_can_verify_patient_identity()
    {
        // Arrange
        $patient = Patient::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+15551234567'
        ]);

        // Act
        $result = $this->patientService->verifyIdentity(
            '+15551234567',
            'John',
            'Doe'
        );

        // Assert
        $this->assertTrue($result['verified']);
        $this->assertEquals(1.0, $result['confidence']);
        $this->assertEquals($patient->id, $result['patient']->id);
    }

    #[Test]
    public function it_can_create_patient_with_auto_mrn()
    {
        // Act
        $patient = $this->patientService->createPatient([
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'phone' => '+15559999999',
            'email' => 'test@example.com',
            'gender' => 'male',
        ]);

        // Assert
        $this->assertNotNull($patient->id);
        $this->assertStringStartsWith('MRN2025', $patient->medical_record_number);
        $this->assertEquals('Test Patient', $patient->full_name);
    }

    #[Test]
    public function it_returns_empty_collection_for_non_existent_phone()
    {
        // Act
        $found = $this->patientService->lookupByPhone('+15559999999');

        // Assert
        $this->assertCount(0, $found);
    }

    #[Test]
    public function it_can_get_patient_by_mrn()
    {
        // Arrange
        $patient = Patient::factory()->create();

        // Act
        $found = $this->patientService->getPatientByMRN($patient->medical_record_number);

        // Assert
        $this->assertNotNull($found);
        $this->assertEquals($patient->id, $found->id);
    }
}
