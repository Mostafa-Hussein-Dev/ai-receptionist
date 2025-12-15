<?php
// ============================================================================
// FILE: AIServiceProvider.php
// Location: app/Providers/AIServiceProvider.php
// ============================================================================

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

// Interfaces
use App\Contracts\{
    LLMServiceInterface,
    IntentParserServiceInterface,
    EntityExtractorServiceInterface,
    SessionManagerServiceInterface,
    DialogueManagerServiceInterface
};

// Real Implementations
use App\Services\AI\OpenAI\{
    OpenAILLMService,
    IntentParserService,
    EntityExtractorService
};

// Conversation Services
use App\Services\Conversation\{
    SessionManagerService,
    DialogueManagerService
};

/**
 * AI Service Provider - OpenAI
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
            return new OpenAILLMService();
        });

        // ==================================================================
        // INTENT PARSER BINDING
        // ==================================================================
        $this->app->bind(IntentParserServiceInterface::class, function ($app) {
            return new IntentParserService(
                $app->make(LLMServiceInterface::class)
            );
        });

        // ==================================================================
        // ENTITY EXTRACTOR BINDING
        // ==================================================================
        $this->app->bind(EntityExtractorServiceInterface::class, function ($app) {
            return new EntityExtractorService(
                $app->make(LLMServiceInterface::class)
            );
        });

        // ==================================================================
        // SESSION MANAGER BINDING
        // ==================================================================
        $this->app->singleton(SessionManagerServiceInterface::class, SessionManagerService::class);

        // ==================================================================
        // DIALOGUE MANAGER BINDING
        // ==================================================================
        $this->app->singleton(DialogueManagerServiceInterface::class, function ($app) {
            return new DialogueManagerService(
                $app->make(\App\Services\Business\DoctorService::class),
                $app->make(\App\Services\Business\AppointmentService::class),
                $app->make(LLMServiceInterface::class)
            );
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
        Log::info('[AIServiceProvider] Real OpenAI services registered');
    }
}

