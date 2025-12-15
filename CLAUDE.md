# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is an AI-powered hospital receptionist system built with Laravel 12 and PHP 8.2. The system handles natural language conversations for appointment booking, cancellation, and rescheduling through a state-based conversation flow.

## Architecture

### Core Components

**Orchestrators**: Main business logic coordinators
- `ConversationOrchestrator` - Central coordinator for processing conversation turns, coordinating all services and managing the appointment booking flow
- `CallOrchestrator` - Handles call-related operations

**Services**: Business logic layer
- **Conversation Services**: `DialogueManagerService`, `SessionManagerService`, `TurnTakingService` - Manage conversation state, flow, and sessions
- **AI Services**: OpenAI implementations (`IntentParserService`, `EntityExtractorService`, `OpenAILLMService`) and Mock versions for testing
- **Business Services**: `DoctorService`, `PatientService`, `AppointmentService`, `SlotService`, `ValidationService` - Core domain operations

**Contracts**: Service interfaces defining contracts for AI services and conversation management
- All AI services implement `LLMServiceInterface`, `IntentParserServiceInterface`, `EntityExtractorServiceInterface`
- Conversation services implement `DialogueManagerServiceInterface`, `SessionManagerServiceInterface`

**DTOs**: Data transfer objects for clean data flow
- `ConversationTurnDTO`, `SessionDTO`, `IntentDTO`, `EntityDTO`, `AppointmentDTO`, `SlotDTO`

**Enums**: Type-safe state and intent definitions
- `ConversationState` - Complete conversation flow states (GREETING, DETECT_INTENT, BOOK_APPOINTMENT, etc.)
- `IntentType` - User intent classifications (BOOK_APPOINTMENT, CANCEL_APPOINTMENT, etc.)

### Data Flow

1. User message enters `ConversationOrchestrator::processTurn()`
2. Intent parsing and entity extraction
3. State transition via `DialogueManagerService::getNextState()`
4. Business logic execution (patient verification, doctor selection, slot checking)
5. Response generation through template or LLM
6. Session and conversation history updates

### Key Features

- **Hybrid AI System**: Uses OpenAI services with Mock implementations for testing
- **MongoDB Integration**: Primary database with MongoDB for session storage
- **Redis Support**: Session caching and queue management
- **Telegram Integration**: Bot API for messaging
- **API Documentation**: Swagger/L5-Swagger integration

## Development Commands

### Setup
```bash
composer run setup          # Install dependencies, generate key, migrate, install npm
php artisan key:generate    # Generate application key
php artisan migrate         # Run database migrations
```

### Development Server
```bash
composer run dev           # Start Laravel server, queue worker, logs, and Vite concurrently
php artisan serve          # Start development server only
php artisan queue:listen   # Start queue worker
php artisan pail           # Show real-time logs
npm run dev               # Start Vite frontend development
```

### Testing
```bash
composer run test          # Run PHPUnit tests
php artisan test           # Alternative test command
php artisan test --filter TestName  # Run specific test
```

### Code Quality
```bash
php artisan pint           # Laravel Pint formatter
composer dump-autoload     # Refresh autoloader
```

## Configuration

### Environment Configuration
- AI service configuration in `config/ai.php`
- Hospital settings and conversation prompts in database configs
- OpenAI API keys and MongoDB/Redis connections in `.env`

### Service Providers
- `AIServiceProvider` - Registers AI service implementations (OpenAI vs Mock)
- Business service bindings for dependency injection

## Important Notes

- State management is critical - the `ConversationState` enum drives all conversation flow
- Use dependency injection for all services through the constructor
- All AI services have both OpenAI and Mock implementations for reliable testing
- Session data is stored in MongoDB with Redis caching for performance
- Error handling includes early return pattern via `earlyTurnDTO()` in ConversationOrchestrator
- Business logic (patient verification, doctor search, slot checking) is embedded within ConversationOrchestrator state transitions