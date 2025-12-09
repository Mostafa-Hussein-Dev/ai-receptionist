<?php
// ============================================================================
// FILE: AIServiceProvider.php
// Location: app/Providers/AIServiceProvider.php
// ============================================================================

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Interfaces
use App\Contracts\{
    LLMServiceInterface,
    IntentParserServiceInterface,
    EntityExtractorServiceInterface,
    SessionManagerServiceInterface,
    DialogueManagerServiceInterface
};

// Mock Implementations
use App\Services\AI\{
    Mock\MockLLMService,
    Mock\MockIntentParserService,
    Mock\MockEntityExtractorService
};

// Real Implementations
use App\Services\AI\{
    OpenAI\OpenAILLMService,
    OpenAI\IntentParserService,
    OpenAI\EntityExtractorService
};

// Conversation Services
use App\Services\Conversation\{
    SessionManagerService,
    DialogueManagerService
};

/**
 * AI Service Provider
 *
 * Binds all AI-related service interfaces to their implementations.
 * Determines whether to use Mock or Real services based on configuration.
 */
class AIServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // ==================================================================
        // LLM SERVICE BINDING
        // ==================================================================
        $this->app->bind(LLMServiceInterface::class, function ($app) {
            $provider = config('ai.provider', 'mock');

            return match ($provider) {
                'openai' => new OpenAILLMService(),
                'mock' => new MockLLMService(),
                default => new MockLLMService(),
            };
        });

        // ==================================================================
        // INTENT PARSER BINDING
        // ==================================================================
        $this->app->bind(IntentParserServiceInterface::class, function ($app) {
            $provider = config('ai.provider', 'mock');

            return match ($provider) {
                'openai' => new IntentParserService(
                    $app->make(LLMServiceInterface::class)
                ),
                'mock' => new MockIntentParserService(),
                default => new MockIntentParserService(),
            };
        });

        // ==================================================================
        // ENTITY EXTRACTOR BINDING
        // ==================================================================
        $this->app->bind(EntityExtractorServiceInterface::class, function ($app) {
            $provider = config('ai.provider', 'mock');

            return match ($provider) {
                'openai' => new EntityExtractorService(
                    $app->make(LLMServiceInterface::class)
                ),
                'mock' => new MockEntityExtractorService(),
                default => new MockEntityExtractorService(),
            };
        });

        // ==================================================================
        // SESSION MANAGER BINDING
        // ==================================================================
        $this->app->singleton(SessionManagerServiceInterface::class, SessionManagerService::class);

        // ==================================================================
        // DIALOGUE MANAGER BINDING
        // ==================================================================
        $this->app->singleton(DialogueManagerServiceInterface::class, function ($app) {
            $llm = config('ai.response.use_llm', true) && config('ai.provider') !== 'mock'
                ? $app->make(LLMServiceInterface::class)
                : null;

            return new DialogueManagerService($llm);
        });

        // ==================================================================
        // ORCHESTRATORS (Automatically resolved)
        // ==================================================================
        // ConversationOrchestrator and CallOrchestrator will be automatically
        // resolved by Laravel's service container since they have typed dependencies
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Log which AI provider is being used
        $provider = config('ai.provider', 'mock');

        Log::info('[AIServiceProvider] AI services registered', [
            'provider' => $provider,
            'llm_enabled' => config('ai.response.use_llm', true),
        ]);
    }
}

// ============================================================================
// USAGE EXAMPLES
// ============================================================================
//
// 1. Inject interface in constructor (automatic resolution):
//
//    public function __construct(IntentParserServiceInterface $intentParser)
//    {
//        $this->intentParser = $intentParser;
//    }
//
// 2. Resolve from container:
//
//    $intentParser = app(IntentParserServiceInterface::class);
//    $intent = $intentParser->parse("I need an appointment", []);
//
// 3. Use in tests (swap implementation):
//
//    $this->app->bind(
//        IntentParserServiceInterface::class,
//        MockIntentParserService::class
//    );
//
// ============================================================================

