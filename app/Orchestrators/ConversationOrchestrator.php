<?php


namespace App\Orchestrators;

use App\Contracts\IntentParserServiceInterface;
use App\Contracts\EntityExtractorServiceInterface;
use App\Contracts\SessionManagerServiceInterface;
use App\Contracts\DialogueManagerServiceInterface;
use App\DTOs\ConversationTurnDTO;
use App\DTOs\IntentDTO;
use App\DTOs\EntityDTO;
use App\Enums\ConversationState;
use Illuminate\Support\Facades\Log;

/**
 * Conversation Orchestrator
 *
 * Orchestrates processing of a single conversation turn.
 * Coordinates Intent Parser, Entity Extractor, Session Manager, Dialogue Manager.
 */
class ConversationOrchestrator
{
    public function __construct(
        private IntentParserServiceInterface    $intentParser,
        private EntityExtractorServiceInterface $entityExtractor,
        private SessionManagerServiceInterface  $sessionManager,
        private DialogueManagerServiceInterface $dialogueManager
    )
    {
    }

    /**
     * Process one conversation turn
     */
    public function processTurn(string $sessionId, string $userMessage): ConversationTurnDTO
    {
        $startTime = microtime(true);

        try {
            // Step 1: Get session
            $session = $this->sessionManager->get($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session not found: {$sessionId}");
            }

            $turnNumber = $session->turnCount + 1;

            Log::info('[ConversationOrchestrator] Processing turn', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'state' => $session->conversationState,
            ]);

            // Step 2: Parse intent (if not already detected)
            $intent = $this->parseIntent($userMessage, $session);

            // Step 3: Extract entities
            $entities = $this->extractEntities($userMessage, $session);

            // Step 4: Update collected data
            if ($entities->count() > 0) {
                $this->sessionManager->updateCollectedData($sessionId, $entities->toArray());
            }

            // Step 5: Determine next state
            $nextState = $this->dialogueManager->getNextState(
                $session->conversationState,
                $intent,
                $entities,
                ['collected_data' => $session->collectedData]
            );

            // Step 6: Check if we can proceed
            $canProceed = $this->dialogueManager->canProceed(
                $nextState,
                array_merge($session->collectedData, $entities->toArray())
            );

            // Step 7: Generate response
            $response = $this->generateResponse($nextState, $intent, $entities, $session, $canProceed);

            // Step 8: Update session
            $this->sessionManager->update($sessionId, [
                'conversation_state' => $nextState,
                'intent' => $intent->intent,
                'turn_count' => $turnNumber,
            ]);

            $this->sessionManager->addMessage($sessionId, [
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => now()->toISOString(),
            ]);

            $this->sessionManager->addMessage($sessionId, [
                'role' => 'assistant',
                'content' => $response,
                'timestamp' => now()->toISOString(),
            ]);

            // Calculate processing time
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Create turn DTO
            $turn = new ConversationTurnDTO(
                turnNumber: $turnNumber,
                userMessage: $userMessage,
                systemResponse: $response,
                intent: $intent,
                entities: $entities,
                conversationState: $nextState,
                processingTimeMs: (int)$processingTime,
                timestamp: now()
            );

            Log::info('[ConversationOrchestrator] Turn processed', [
                'session' => $sessionId,
                'turn' => $turnNumber,
                'intent' => $intent->intent,
                'entities' => $entities->count(),
                'next_state' => $nextState,
                'processing_time_ms' => (int)$processingTime,
            ]);

            return $turn;

        } catch (\Exception $e) {
            Log::error('[ConversationOrchestrator] Turn processing failed', [
                'session' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return error turn
            $processingTime = (microtime(true) - $startTime) * 1000;

            return new ConversationTurnDTO(
                turnNumber: ($session->turnCount ?? 0) + 1,
                userMessage: $userMessage,
                systemResponse: "I'm sorry, I encountered an error. Could you please try again?",
                intent: new IntentDTO('UNKNOWN', 0.0, 'Error occurred'),
                entities: new EntityDTO([]),
                conversationState: $session->conversationState ?? ConversationState::DETECT_INTENT->value,
                processingTimeMs: (int)$processingTime,
                timestamp: now()
            );
        }
    }

    /**
     * Parse intent
     */
    private function parseIntent(string $userMessage, $session): IntentDTO
    {
        // If already in a specific flow, intent might be implied
        if ($session->intent && $this->isInFlow($session->conversationState)) {
            Log::debug('[ConversationOrchestrator] Using existing intent', [
                'intent' => $session->intent,
            ]);

            return new IntentDTO($session->intent, 0.95, 'Flow continuation');
        }

        // Parse intent from message
        return $this->intentParser->parseWithHistory(
            $userMessage,
            $session->conversationHistory,
            ['state' => $session->conversationState]
        );
    }

    /**
     * Extract entities
     */
    private function extractEntities(string $userMessage, $session): EntityDTO
    {
        return $this->entityExtractor->extractWithState(
            $userMessage,
            $session->conversationState,
            ['collected_data' => $session->collectedData]
        );
    }

    /**
     * Generate response
     */
    private function generateResponse(
        string    $nextState,
        IntentDTO $intent,
        EntityDTO $entities,
                  $session,
        bool      $canProceed
    ): string
    {
        // Check if we're missing required entities
        if (!$canProceed) {
            $required = $this->dialogueManager->getRequiredEntities($nextState);
            $collected = array_merge($session->collectedData, $entities->toArray());
            $missing = array_diff($required, array_keys(array_filter($collected)));

            if (!empty($missing)) {
                return $this->dialogueManager->generatePromptForMissingEntities($missing, $nextState);
            }
        }

        // Generate normal response
        return $this->dialogueManager->generateResponse($nextState, [
            'intent' => $intent,
            'entities' => $entities->toArray(),
            'collected_data' => $session->collectedData,
        ]);
    }

    /**
     * Check if in active flow
     */
    private function isInFlow(string $state): bool
    {
        $flowStates = [
            ConversationState::COLLECT_PATIENT_NAME->value,
            ConversationState::COLLECT_PATIENT_DOB->value,
            ConversationState::COLLECT_PATIENT_PHONE->value,
            ConversationState::SELECT_DATE->value,
            ConversationState::SELECT_SLOT->value,
            ConversationState::CONFIRM_BOOKING->value,
        ];

        return in_array($state, $flowStates);
    }
}
