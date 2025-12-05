<?php

namespace App\Services\Business;

use App\Exceptions\AppointmentException;
use App\Models\PostgreSQL\Patient;
use Illuminate\Support\Collection;

class PatientService
{
    /**
     * Lookup patient by phone number
     * Returns all patients with matching phone
     */
    public function lookupByPhone(string $phone): Collection
    {
        // Normalize phone number (remove spaces, dashes, etc.)
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);

        return Patient::where('phone', 'LIKE', "%{$normalizedPhone}%")
            ->get();
    }

    /**
     * Lookup patient by phone and verify with name
     * This is the primary lookup method for the conversation flow
     */
    public function lookupByPhoneAndName(string $phone, string $firstName, string $lastName): ?Patient
    {
        $patients = $this->lookupByPhone($phone);

        // Try exact match first
        $exactMatch = $patients->first(function ($patient) use ($firstName, $lastName) {
            return strcasecmp($patient->first_name, $firstName) === 0
                && strcasecmp($patient->last_name, $lastName) === 0;
        });

        if ($exactMatch) {
            return $exactMatch;
        }

        // Try partial match (case-insensitive contains)
        $partialMatch = $patients->first(function ($patient) use ($firstName, $lastName) {
            $fullName = strtolower($patient->full_name);
            $searchName = strtolower("{$firstName} {$lastName}");
            return str_contains($fullName, strtolower($firstName))
                && str_contains($fullName, strtolower($lastName));
        });

        return $partialMatch;
    }

    /**
     * Get patient by ID
     */
    public function getPatient(int $id): ?Patient
    {
        return Patient::find($id);
    }

    /**
     * Get patient by Medical Record Number
     */
    public function getPatientByMRN(string $mrn): ?Patient
    {
        return Patient::where('medical_record_number', $mrn)->first();
    }

    /**
     * Create a new patient
     */
    public function createPatient(array $data): Patient
    {
        return Patient::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'gender' => $data['gender'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'blood_type' => $data['blood_type'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'medical_notes' => $data['medical_notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Update patient information
     */
    public function updatePatient(int $id, array $data): Patient
    {
        $patient = Patient::findOrFail($id);

        $patient->update([
            'first_name' => $data['first_name'] ?? $patient->first_name,
            'last_name' => $data['last_name'] ?? $patient->last_name,
            'date_of_birth' => $data['date_of_birth'] ?? $patient->date_of_birth,
            'gender' => $data['gender'] ?? $patient->gender,
            'phone' => $data['phone'] ?? $patient->phone,
            'email' => $data['email'] ?? $patient->email,
            'address' => $data['address'] ?? $patient->address,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? $patient->emergency_contact_name,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? $patient->emergency_contact_phone,
            'blood_type' => $data['blood_type'] ?? $patient->blood_type,
            'allergies' => $data['allergies'] ?? $patient->allergies,
            'medical_notes' => $data['medical_notes'] ?? $patient->medical_notes,
            'metadata' => $data['metadata'] ?? $patient->metadata,
        ]);

        return $patient->fresh();
    }

    /**
     * Get all patients
     */
    public function getAllPatients(): Collection
    {
        return Patient::orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * Search patients by name
     */
    public function searchByName(string $query): Collection
    {
        return Patient::where(function ($q) use ($query) {
            $q->where('first_name', 'ILIKE', "%{$query}%")
                ->orWhere('last_name', 'ILIKE', "%{$query}%");
        })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Search patients by date of birth
     */
    public function searchByDateOfBirth(string $dob): Collection
    {
        return Patient::where('date_of_birth', $dob)->get();
    }

    /**
     * Find or create patient
     * Used in conversation flow when patient doesn't exist
     */
    public function findOrCreate(array $data): Patient
    {
        // First try to find by phone and name
        $existing = $this->lookupByPhoneAndName(
            $data['phone'],
            $data['first_name'],
            $data['last_name']
        );

        if ($existing) {
            return $existing;
        }

        // Create new patient
        return $this->createPatient($data);
    }

    /**
     * Verify patient identity
     * Used when patient calls: verify phone matches name
     */
    public function verifyIdentity(string $phone, string $firstName, string $lastName): array
    {
        $patient = $this->lookupByPhoneAndName($phone, $firstName, $lastName);

        return [
            'verified' => $patient !== null,
            'patient' => $patient,
            'confidence' => $patient ? $this->calculateMatchConfidence($patient, $firstName, $lastName) : 0,
        ];
    }

    /**
     * Calculate match confidence (for fuzzy matching)
     */
    private function calculateMatchConfidence(Patient $patient, string $firstName, string $lastName): float
    {
        $confidence = 0;

        // Exact first name match
        if (strcasecmp($patient->first_name, $firstName) === 0) {
            $confidence += 0.5;
        } elseif (str_contains(strtolower($patient->first_name), strtolower($firstName))) {
            $confidence += 0.3;
        }

        // Exact last name match
        if (strcasecmp($patient->last_name, $lastName) === 0) {
            $confidence += 0.5;
        } elseif (str_contains(strtolower($patient->last_name), strtolower($lastName))) {
            $confidence += 0.3;
        }

        return min($confidence, 1.0);
    }

    /**
     * Get patient with appointment history
     */
    public function getPatientWithHistory(int $id): ?Patient
    {
        return Patient::with(['appointments' => function ($query) {
            $query->orderBy('date', 'desc')->orderBy('start_time', 'desc');
        }])->find($id);
    }

    /**
     * Check if patient exists by phone
     */
    public function existsByPhone(string $phone): bool
    {
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);
        return Patient::where('phone', 'LIKE', "%{$normalizedPhone}%")->exists();
    }

    /**
     * Get patient statistics
     */
    public function getPatientStats(int $id): array
    {
        $patient = Patient::with('appointments')->findOrFail($id);

        return [
            'total_appointments' => $patient->appointments->count(),
            'upcoming_appointments' => $patient->appointments()
                ->where('date', '>=', now()->toDateString())
                ->whereIn('status', ['scheduled', 'confirmed'])
                ->count(),
            'completed_appointments' => $patient->appointments()
                ->where('status', 'completed')
                ->count(),
            'cancelled_appointments' => $patient->appointments()
                ->where('status', 'cancelled')
                ->count(),
            'no_show_appointments' => $patient->appointments()
                ->where('status', 'no_show')
                ->count(),
        ];
    }
}
