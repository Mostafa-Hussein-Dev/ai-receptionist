<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="AI Receptionist API",
 *     version="1.0.0"
 * )
 *
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
