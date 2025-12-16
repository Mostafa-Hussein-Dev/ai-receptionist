<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Orchestrators\ConversationOrchestrator;
use App\Services\Conversation\SessionManagerService;

class TestBookingFix extends Command
{
    protected $signature = 'booking:test-fix';
    protected $description = 'Test the booking flow fixes';

    public function handle()
    {
        $this->info("🧪 Testing AI Receptionist Booking Flow");
        $this->info("==========================================\n");

        try {
            // Initialize services
            $sessionManager = app(SessionManagerService::class);
            $conversationOrchestrator = app(ConversationOrchestrator::class);

            // Create test session
            $sessionId = 'session:test:' . uniqid();
            $session = $sessionManager->create($sessionId, [
                'channel' => 'test',
                'external_id' => 'test_fixes'
            ]);

            $this->info("✅ Session created: {$sessionId}");
            $this->info("📊 Initial state: {$session->conversationState}\n");

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
                $this->info("👤 User: {$message}");

                try {
                    $startTime = microtime(true);
                    $turn = $conversationOrchestrator->processTurn($sessionId, $message);
                    $duration = round((microtime(true) - $startTime) * 1000, 2);

                    $this->info("🤖 AI: " . $turn->systemResponse);
                    $this->info("📊 [Intent: {$turn->intent->intent} | Confidence: {$turn->intent->confidence} | State: {$turn->conversationState} | {$duration}ms]");

                    // Show collected data if any
                    $session = $sessionManager->get($sessionId);
                    if (!empty($session->collectedData)) {
                        $cleanData = $session->collectedData;
                        // Remove large arrays for readability
                        unset($cleanData['available_slots']);
                        $this->info("💾 Collected: " . json_encode($cleanData, JSON_PRETTY_PRINT));
                    }

                    $this->info("\n" . str_repeat("-", 60));

                    // Stop if we reach closing or error state
                    if (in_array($turn->conversationState, ['CLOSING', 'END'])) {
                        $this->info("🎯 Booking flow completed!");
                        break;
                    }

                } catch (\Exception $e) {
                    $this->error("❌ Error: " . $e->getMessage());
                    break;
                }
            }

            $this->info("\n🏁 Test completed!");
            $this->info("📝 Summary:");
            $finalSession = $sessionManager->get($sessionId);
            $this->info("- Final state: {$finalSession->conversationState}");
            $this->info("- Total turns: {$finalSession->turnCount}");
            $this->info("- Patient ID: " . ($finalSession->patientId ?? 'None'));

            if (!empty($finalSession->collectedData)) {
                $this->info("- Collected data keys: " . implode(', ', array_keys($finalSession->collectedData)));
            }

        } catch (\Exception $e) {
            $this->error("❌ Test failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}