<?php

$sessionManager = app(\App\Services\Conversation\SessionManagerService::class);
$conversationOrchestrator = app(\App\Orchestrators\ConversationOrchestrator::class);

$sessionId = 'session:interactive:' . uniqid();

// Create session
$session = $sessionManager->create($sessionId, [
    'channel' => 'interactive',
    'external_id' => 'developer_test'
]);

echo "📝 Session created: $sessionId\n";
echo "📝 Initial state: {$session->conversationState}\n\n";
echo "🎯 Type your messages below. Type 'exit' to quit.\n";
echo "💡 Try: 'Hello', 'I want to book an appointment', etc.\n\n";

$turnCount = 0;

// Test conversation sequence
$testMessages = [
    "Hello, i would like to book an appointment",
    "Test User",
    "Jan 1, 2002",
    "81818181",
    "Dr. John Smith"
];

foreach ($testMessages as $userMessage) {
    echo "👤 You: $userMessage\n";

    try {
        echo "\n⏳ Processing...\n";

        $startTime = microtime(true);
        $turn = $conversationOrchestrator->processTurn($sessionId, $userMessage);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        echo "🤖 AI: " . $turn->systemResponse . "\n";
        echo "📊 [Intent: {$turn->intent->intent} | Confidence: {$turn->intent->confidence} | State: {$turn->conversationState} | {$duration}ms]\n";

        // Show collected data if any
        $session = $sessionManager->get($sessionId);
        if (!empty($session->collectedData)) {
            echo "💾 Collected: " . json_encode($session->collectedData, JSON_PRETTY_PRINT) . "\n";
        }

        echo "\n" . str_repeat("-", 60) . "\n";

    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n\n";
    }
}

echo "✅ Test completed. Check storage/logs/laravel.log for debug information.\n";