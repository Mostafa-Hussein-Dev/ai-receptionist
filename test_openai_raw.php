<?php


require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = new \GuzzleHttp\Client();

try {
    $systemPrompt = 'You are an AI assistant extracting structured information from patient messages.

Current Context:
- Today\'s date: 2025-12-14
- Current time: 23:05
- Timezone: UTC

Entities to Extract:
1. patient_name (string): Full name of the patient
2. date (string): Appointment date in YYYY-MM-DD format
3. time (string): Appointment time in HH:MM format (24-hour)
4. phone (string): Phone number with country code
5. date_of_birth (string): Patient\'s birth date in YYYY-MM-DD format
6. doctor_name (string): Doctor\'s name (format as "Dr. LastName")
7. department (string): Medical department name

Rules:
1. Extract ONLY the entities listed above
2. Use null for entities that are not mentioned
3. Format dates as YYYY-MM-DD
4. Format times as HH:MM in 24-hour format
5. Include country code for phone numbers (assume +961 if not specified)
6. Return JSON with this exact structure:
   {
     "patient_name": "John Doe" or null,
     "date": "2024-01-20" or null,
     "time": "14:30" or null,
     "phone": "+96123456789" or null,
     "date_of_birth": "1980-03-15" or null,
     "doctor_name": "Dr. Smith" or null,
     "department": "Cardiology" or null
   }

DO NOT include any text outside the JSON object.
DO NOT use markdown code blocks.
Respond ONLY with valid JSON.';

    $userPrompt = 'User Message: "My name is John Smith, I need Tuesday at 2pm"

Extract all mentioned entities and return as JSON.';

    $response = $client->post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . config('openai.api_key'),
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'model' => 'gpt-5-nano',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'max_completion_tokens' => 500,
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    $rawContent = $data['choices'][0]['message']['content'] ?? '';

    echo "RAW OpenAI Response:\n";
    echo "==================\n";
    echo $rawContent . "\n";
    echo "==================\n";
    echo "Length: " . strlen($rawContent) . " characters\n\n";

    // Try to parse it
    $parsed = json_decode($rawContent, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ JSON is valid!\n";
        print_r($parsed);
    } else {
        echo "❌ JSON Error: " . json_last_error_msg() . "\n";
        echo "First 200 chars: " . substr($rawContent, 0, 200) . "\n";

        // Show each character for debugging
        echo "\nCharacter by character (first 100):\n";
        for ($i = 0; $i < min(100, strlen($rawContent)); $i++) {
            $char = $rawContent[$i];
            $ord = ord($char);
            echo "[$i] '$char' ($ord)\n";
        }
    }

} catch (Exception $e) {
    echo "❌ API Error: " . $e->getMessage() . "\n";
}
