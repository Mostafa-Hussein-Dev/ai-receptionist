<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="AI Receptionist API",
 *     version="1.0.0",
 *     description="AI-powered voice receptionist system for hospital appointment management. This API handles doctor schedules, patient information, appointment booking, and slot management. Some endpoints require API key authentication via the X-API-Key header (appointments and patient lookup).",
 *     @OA\Contact(
 *         name="API Support",
 *         email="support@hospital.com"
 *     )
 * )
 *
 * @OA\Server(
 *     url="http://127.0.0.1:8000",
 *     description="Local Development Server"
 * )
 *
 * @OA\Server(
 *     url="http://localhost:8000",
 *     description="Alternative Local Server"
 * )
 *
 * @OA\Tag(
 *     name="Doctors",
 *     description="Doctor management and availability endpoints"
 * )
 *
 * @OA\Tag(
 *     name="Patients",
 *     description="Patient lookup and management endpoints"
 * )
 *
 * @OA\Tag(
 *     name="Slots",
 *     description="Available time slot management endpoints"
 * )
 *
 * @OA\Tag(
 *     name="Appointments",
 *     description="Appointment booking and management endpoints"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="ApiKeyAuth",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-Key",
 *     description="API Key authentication. Use one of the configured API keys in the X-API-Key header."
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="error", type="string", example="Error message"),
 *     @OA\Property(property="message", type="string", example="Validation error message")
 * )
 */
abstract class Controller
{
    //
}
