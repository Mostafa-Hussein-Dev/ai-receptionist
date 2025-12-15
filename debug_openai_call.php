<?php


require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = new \GuzzleHttp\Client();

try {
    echo "API Key: " . (config('openai.api_key') ? 'Set (' . substr(config('openai.api_key'), 0, 10) . '...)' : 'Missing') . "\n";
    echo "Model: " . config('openai.model') . "\n\n";

    $response = $client->post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . config('openai.api_key'),
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'model' => 'gpt-5-nano',
            'messages' => [
                ['role' => 'user', 'content' => 'Say "Hello World"']
            ],
            'max_completion_tokens' => 50,
        ]
    ]);

    echo "HTTP Status: " . $response->getStatusCode() . "\n";

    $body = $response->getBody()->getContents();
    echo "Response Body Length: " . strlen($body) . "\n";

    $data = json_decode($body, true);

    if (isset($data['error'])) {
        echo "❌ OpenAI API Error:\n";
        print_r($data['error']);
    } elseif (isset($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];
        echo "✅ Success! Content: '$content'\n";
    } else {
        echo "❌ Unexpected response structure:\n";
        print_r($data);
    }

} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";

    if ($e instanceof \GuzzleHttp\Exception\ClientException) {
        $response = $e->getResponse();
        echo "Error Response Body: " . $response->getBody()->getContents() . "\n";
    }
}
