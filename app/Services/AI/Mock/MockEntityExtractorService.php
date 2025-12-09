<?php

namespace App\Services\AI\Mock;

use App\Contracts\EntityExtractorServiceInterface;
use App\DTOs\EntityDTO;

/**
 * Mock Entity Extractor Service
 *
 * Regex-based entity extraction for testing without external API.
 * Uses pattern matching to extract structured data from text.
 *
 * Accuracy: 60-70%
 * Speed: Instant
 * Cost: Free
 *
 * Limitations:
 * - Can handle "tomorrow" but not "next Tuesday"
 * - Simple date formats only
 * - Basic name extraction
 */
class MockEntityExtractorService implements EntityExtractorServiceInterface
{
    private bool $verbose;

    public function __construct()
    {
        $this->verbose = config('ai.mock.verbose', false);
    }

    /**
     * Extract entities from user input
     */
    public function extract(string $text, array $context = []): EntityDTO
    {
        if ($this->verbose) {
            Log::info('[MockEntityExtractor] Extracting', [
                'text' => $text,
                'context' => $context,
            ]);
        }

        $entities = [
            'patient_name' => $this->extractName($text, $context),
            'date' => $this->extractDate($text, $context),
            'time' => $this->extractTime($text, $context),
            'phone' => $this->extractPhone($text),
            'date_of_birth' => $this->extractDateOfBirth($text, $context),
            'doctor_name' => $this->extractDoctorName($text, $context),
            'department' => $this->extractDepartment($text),
        ];

        if ($this->verbose) {
            Log::info('[MockEntityExtractor] Extracted', [
                'entities' => array_filter($entities),
            ]);
        }

        return EntityDTO::fromArray($entities);
    }

    /**
     * Extract specific entities
     */
    public function extractSpecific(
        string $text,
        array $entityTypes,
        array $context = []
    ): EntityDTO {
        $allEntities = $this->extract($text, $context)->toArray();

        // Filter to only requested entities
        $filtered = array_filter(
            $allEntities,
            fn($key) => in_array($key, $entityTypes),
            ARRAY_FILTER_USE_KEY
        );

        return EntityDTO::fromArray($filtered);
    }

    /**
     * Extract with conversation state context
     */
    public function extractWithState(
        string $text,
        string $conversationState,
        array $context = []
    ): EntityDTO {
        // Add state to context
        $context['conversation_state'] = $conversationState;

        // Prioritize extraction based on state
        switch ($conversationState) {
            case 'COLLECT_PATIENT_NAME':
                return $this->extractSpecific($text, ['patient_name'], $context);

            case 'COLLECT_PATIENT_DOB':
                return $this->extractSpecific($text, ['date_of_birth'], $context);

            case 'COLLECT_PATIENT_PHONE':
                return $this->extractSpecific($text, ['phone'], $context);

            case 'SELECT_DATE':
                return $this->extractSpecific($text, ['date'], $context);

            case 'SELECT_SLOT':
                return $this->extractSpecific($text, ['time'], $context);

            default:
                return $this->extract($text, $context);
        }
    }

    /**
     * Check if the extractor is available
     */
    public function isAvailable(): bool
    {
        return true; // Mock is always available
    }

    /**
     * Get extractor type
     */
    public function getType(): string
    {
        return 'mock';
    }

    /**
     * Get list of extractable entity types
     */
    public function getSupportedEntities(): array
    {
        return [
            'patient_name',
            'date',
            'time',
            'phone',
            'date_of_birth',
            'doctor_name',
            'department',
        ];
    }

    /**
     * Extract patient name
     */
    private function extractName(string $text, array $context): ?string
    {
        // Pattern: "my name is John Smith" or "I am John Smith" or "this is John Smith"
        if (preg_match('/(?:my name is|i am|this is|i\'m)\s+([a-z\s]+)/i', $text, $matches)) {
            return $this->cleanName(trim($matches[1]));
        }

        // Pattern: "John Smith" (when expecting name based on context)
        if (isset($context['expecting']) && $context['expecting'] === 'name') {
            // Try to extract what looks like a name (2-3 words, capitalized)
            if (preg_match('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2})\b/', $text, $matches)) {
                return $this->cleanName($matches[1]);
            }
        }

        return null;
    }

    /**
     * Extract date
     */
    private function extractDate(string $text, array $context): ?string
    {
        $today = now();

        // Relative dates
        if (str_contains(strtolower($text), 'today')) {
            return $today->format('Y-m-d');
        }

        if (str_contains(strtolower($text), 'tomorrow')) {
            return $today->addDay()->format('Y-m-d');
        }

        // Day of week (next occurrence)
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            if (str_contains(strtolower($text), $day)) {
                return $today->next($day)->format('Y-m-d');
            }
        }

        // Explicit date formats
        // Format: 01/15/2024 or 1/15/24
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $text, $matches)) {
            $year = strlen($matches[3]) === 2 ? '20' . $matches[3] : $matches[3];
            return sprintf('%s-%02d-%02d', $year, $matches[1], $matches[2]);
        }

        // Format: 2024-01-15
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $matches)) {
            return $matches[0];
        }

        // Format: January 15 or Jan 15
        if (preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|oct|nov|dec)\s+(\d{1,2})\b/i', $text, $matches)) {
            $month = date('m', strtotime($matches[1]));
            $day = $matches[2];
            $year = $today->year;
            return sprintf('%s-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * Extract time
     */
    private function extractTime(string $text, array $context): ?string
    {
        // Format: 10:30 AM or 2:30 PM
        if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)\b/i', $text, $matches)) {
            $hour = (int) $matches[1];
            $minute = $matches[2];
            $meridiem = strtolower($matches[3]);

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:%s', $hour, $minute);
        }

        // Format: 10am or 2pm
        if (preg_match('/\b(\d{1,2})\s*(am|pm)\b/i', $text, $matches)) {
            $hour = (int) $matches[1];
            $meridiem = strtolower($matches[2]);

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:00', $hour);
        }

        // Format: 14:30 (24-hour)
        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $text, $matches)) {
            return sprintf('%02d:%s', $matches[1], $matches[2]);
        }

        // Relative time
        if (str_contains(strtolower($text), 'morning')) {
            return '09:00';
        }
        if (str_contains(strtolower($text), 'afternoon')) {
            return '14:00';
        }

        return null;
    }

    /**
     * Extract phone number
     */
    private function extractPhone(string $text): ?string
    {
        // Format: (555) 123-4567 or 555-123-4567 or 5551234567
        if (preg_match('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $text, $matches)) {
            // Clean and format to E.164
            $phone = preg_replace('/[^\d]/', '', $matches[0]);
            return '+1' . $phone; // Assume US number
        }

        return null;
    }

    /**
     * Extract date of birth
     */
    private function extractDateOfBirth(string $text, array $context): ?string
    {
        // Similar to date extraction but looking for birth-related context
        // Format: 03/15/1980 or 3/15/80
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $text, $matches)) {
            $year = strlen($matches[3]) === 2 ? '19' . $matches[3] : $matches[3];
            return sprintf('%s-%02d-%02d', $year, $matches[1], $matches[2]);
        }

        // Format: 1980-03-15
        if (preg_match('/\b(19\d{2}|20\d{2})-(\d{2})-(\d{2})\b/', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Extract doctor name
     */
    private function extractDoctorName(string $text, array $context): ?string
    {
        // Pattern: "Dr. Smith" or "Doctor Smith"
        if (preg_match('/(?:Dr\.?|Doctor)\s+([A-Z][a-z]+)/i', $text, $matches)) {
            return 'Dr. ' . ucfirst($matches[1]);
        }

        return null;
    }

    /**
     * Extract department
     */
    private function extractDepartment(string $text): ?string
    {
        $departments = [
            'cardiology' => ['cardiology', 'heart', 'cardiac'],
            'dermatology' => ['dermatology', 'skin'],
            'general' => ['general', 'family', 'primary care'],
            'pediatrics' => ['pediatrics', 'pediatric', 'children'],
            'orthopedics' => ['orthopedics', 'orthopedic', 'bone', 'joint'],
        ];

        $text = strtolower($text);

        foreach ($departments as $dept => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return ucfirst($dept);
                }
            }
        }

        return null;
    }

    /**
     * Clean and format name
     */
    private function cleanName(string $name): string
    {
        // Remove common filler words
        $name = preg_replace('/\b(is|the|a|an|my|name)\b/i', '', $name);
        $name = trim($name);

        // Title case
        return ucwords(strtolower($name));
    }
}
