<?php
// ============================================================================
// FILE: api.php
// Location: routes/api.php
// ============================================================================

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    DoctorController,
    SlotController,
    AppointmentController,
    PatientController
};

Route::prefix('v1')->group(function () {

    // Doctors
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::get('/doctors/{id}', [DoctorController::class, 'show']);
    Route::get('/doctors/{id}/availability', [DoctorController::class, 'availability']);

    // Slots
    Route::get('/slots', [SlotController::class, 'index']);

    // Appointments
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::get('/appointments/{id}', [AppointmentController::class, 'show']);
    Route::put('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);
    Route::put('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule']);

    // Patients
    Route::post('/patients/lookup', [PatientController::class, 'lookup']);
});

// ============================================================================
// PROTECTED ROUTES (with API key authentication)
// ============================================================================
//
// If you want to protect routes with API key authentication, wrap them like this:
//
// Route::middleware(['api.key'])->prefix('v1')->group(function () {
//     // Your protected routes here
// });
//
// Then register the middleware in app/Http/Kernel.php:
//
// protected $middlewareAliases = [
//     'api.key' => \App\Http\Middleware\ValidateApiKey::class,
// ];
//
// ============================================================================
