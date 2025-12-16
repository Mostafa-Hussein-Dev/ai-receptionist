<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Services\AI\OpenAI\EntityExtractorService;

echo "🔍 Debug Entity Extraction\n";
echo "========================\n\n";

$entityExtractor = app(EntityExtractorService::class);

// Test entity extraction in SELECT_DATE state
$testInput = "2025-12-20";
$context = [
    'conversation_state' => 'SELECT_DATE',
    'collected_data' => [
        'patient_name' => 'Test User',
        'doctor_name' => 'Dr. Sarah Johnson',
        'doctor_id' => 4,
        'department' => 'General Medicine',
        'date_of_birth' => '2001-01-01',
        'phone' => '+96171717171'
    ],
    'missing_entities' => ['date'],
    'current_focus' => 'Collecting appointment date'
];

echo "Testing: {$testInput}\n";
echo "State: SELECT_DATE\n";
echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n\n";

try {
    $result = $entityExtractor->extractWithState($testInput, 'SELECT_DATE', $context);

    echo "✅ Extraction Result:\n";
    echo "- patient_name: " . ($result->patientName ?? 'null') . "\n";
    echo "- date: " . ($result->date ?? 'null') . "\n";
    echo "- time: " . ($result->time ?? 'null') . "\n";
    echo "- phone: " . ($result->phone ?? 'null') . "\n";
    echo "- date_of_birth: " . ($result->dateOfBirth ?? 'null') . "\n";
    echo "- doctor_name: " . ($result->doctorName ?? 'null') . "\n";
    echo "- department: " . ($result->department ?? 'null') . "\n";
    echo "- Entity Count: " . $result->count() . "\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}