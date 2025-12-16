<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Orchestrators\ConversationOrchestrator;
use App\Services\Conversation\SessionManagerService;

echo "🧪 Testing AI Receptionist Booking Flow\n";
echo "==========================================\n\n";

// Initialize services
$sessionManager = app(\App\Services\Conversation\SessionManagerService::class);
$conversationOrchestrator = app(\App\Orchestrators\ConversationOrchestrator::class);

// Create test session
$sessionId = 'session:test:' . uniqid();
$session = $sessionManager->create($sessionId, [
    'channel' => 'test',
    'external_id' => 'test_fixes'
]);

echo "✅ Session created: {$sessionId}\n";
echo "📊 Initial state: {$session->conversationState}\n\n";

// Test conversation flow
$testMessages = [
    "Hello, I want to book an appointment",
    "Test User",
    "Jan 1, 2001",
    "71717171",
    "Dr. Sarah Johnson",
    "2025-12-20",
    "10:00",
    "yes"
];

foreach ($testMessages as $index => $message) {
    echo "👤 User: {$message}\n";

    try {
        $startTime = microtime(true);
        $turn = $conversationOrchestrator->processTurn($sessionId, $message);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        echo "🤖 AI: " . $turn->systemResponse . "\n";
        echo "📊 [Intent: {$turn->intent->intent} | Confidence: {$turn->intent->confidence} | State: {$turn->conversationState} | {$duration}ms]\n";

        // Show collected data if any
        $session = $sessionManager->get($sessionId);
        if (!empty($session->collectedData)) {
            $cleanData = $session->collectedData;
            // Remove large arrays for readability
            unset($cleanData['available_slots']);
            echo "💾 Collected: " . json_encode($cleanData, JSON_PRETTY_PRINT) . "\n";
        }

        echo "\n" . str_repeat("-", 60) . "\n";

        // Stop if we reach closing or error state
        if (in_array($turn->conversationState, ['CLOSING', 'END'])) {
            echo "🎯 Booking flow completed!\n";
            break;
        }

    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n\n";
        break;
    }
}

echo "\n🏁 Test completed!\n";
echo "📝 Summary:\n";
$finalSession = $sessionManager->get($sessionId);
echo "- Final state: {$finalSession->conversationState}\n";
echo "- Total turns: {$finalSession->turnCount}\n";
echo "- Patient ID: " . ($finalSession->patientId ?? 'None') . "\n";

if (!empty($finalSession->collectedData)) {
    echo "- Collected data keys: " . implode(', ', array_keys($finalSession->collectedData)) . "\n";
}