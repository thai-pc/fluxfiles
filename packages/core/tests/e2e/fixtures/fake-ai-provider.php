<?php

/**
 * Router for `php -S`: a stand-in AI provider for test-ai-test-http.php. The scenario is the
 * first line of the file named by $FAKE_AI_SCENARIO_FILE, so one running server can play
 * every outcome in turn. Speaks the Anthropic Messages API (POST /v1/messages) and the
 * OpenAI chat-completions shape (POST /v1/chat/completions); both under a base ending /v1.
 *
 * Scenarios: ok | badkey | retired | quota. The 401 body deliberately echoes the API key
 * the way real providers echo a masked one, so the test can prove nothing leaks.
 */

$scenario = trim((string) @file_get_contents((string) getenv('FAKE_AI_SCENARIO_FILE'))) ?: 'ok';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$key = $_SERVER['HTTP_X_API_KEY'] ?? preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

// One line per request, so a test can prove a refused call never reached the provider.
if (($log = (string) getenv('FAKE_AI_LOG_FILE')) !== '') {
    file_put_contents($log, ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path . "\n", FILE_APPEND);
}

function reply(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

if (!in_array($path, ['/v1/messages', '/v1/chat/completions'], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(404, ['error' => ['message' => 'no such route']]);
}
$anthropic = $path === '/v1/messages';

switch ($scenario) {
    case 'badkey':
        reply(401, $anthropic
            ? ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key ' . $key]]
            : ['error' => ['message' => 'Incorrect API key provided: ' . $key, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]);
    case 'retired':
        reply(404, $anthropic
            ? ['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: retired-model']]
            : ['error' => ['message' => 'The model `retired-model` does not exist', 'code' => 'model_not_found']]);
    case 'quota':
        reply(402, ['error' => ['message' => 'Insufficient credits for ' . $key]]);
}

reply(200, $anthropic
    ? ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'OK']]]
    : ['id' => 'c1', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]]]);
