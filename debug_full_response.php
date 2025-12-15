<?php


require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = new \GuzzleHttp\Client();

try {
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

    $body = $response->getBody()->getContents();
    $data = json_decode($body, true);

    echo "FULL OpenAI Response Structure:\n";
    echo "================================\n";
    print_r($data);
    echo "\n================================\n";

    // Check specific fields
    if (isset($data['choices'][0])) {
        $choice = $data['choices'][0];
        echo "Choice structure:\n";
        print_r($choice);

        if (isset($choice['message'])) {
            echo "\nMessage structure:\n";
            print_r($choice['message']);

            $content = $choice['message']['content'] ?? 'NOT_SET';
            echo "\nContent value: '" . $content . "'\n";
            echo "Content type: " . gettype($content) . "\n";
            echo "Content is null: " . ($content === null ? 'YES' : 'NO') . "\n";
            echo "Content is empty string: " . ($content === '' ? 'YES' : 'NO') . "\n";
        }

        if (isset($choice['finish_reason'])) {
            echo "\nFinish reason: " . $choice['finish_reason'] . "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
