<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

function aliceResponse(array $input, string $text, bool $endSession = false): array
{
    return [
        'version' => $input['version'] ?? '1.0',
        'session' => $input['session'] ?? new stdClass(),
        'response' => [
            'text' => mb_substr($text, 0, 1024),
            'tts' => mb_substr($text, 0, 1024),
            'end_session' => $endSession,
        ],
    ];
}

function cleanRequest(string $request): string
{
    foreach (['Алиса', 'алиса', 'Алеся', 'алеся'] as $word) {
        if (mb_stripos($request, $word) === 0) {
            $request = mb_substr($request, mb_strlen($word));
        }
    }
    $request = preg_replace('/^[\\s,.:!?—-]+/u', '', $request) ?? $request;
    return trim($request);
}

function askOpenAI(string $message, array $history, Client $client): string
{
    $apiKey = $_ENV['GROQ_API_KEY'] ?? '';
    if ($apiKey === '') {
        error_log('OPENAI_API_KEY is not configured');
        return 'Сервис пока не настроен. Попробуйте позже.';
    }

    $messages = [[
        'role' => 'system',
        'content' => 'Ты — Алеся, дружелюбный голосовой помощник. Всегда отвечай на русском языке, естественно, понятно и по существу. Не называй себя ChatGPT, OpenAI или языковой моделью и не говори, что обучен OpenAI; представляйся Алесей только если спрашивают, как тебя зовут. Обычно отвечай кратко — 2–5 предложений, без длинных вступлений и повторов. Если просят подробнее, раскрывай тему. Не выдумывай факты: если не уверен, честно скажи об этом. Старайся укладываться примерно в 800 символов, чтобы Алиса не обрывала ответ. Завершай мысль целиком.'
    ]];
    $messages = array_merge($messages, array_slice($history, -18));
    $messages[] = ['role' => 'user', 'content' => $message];

    try {
        $response = $client->post('v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'openai/gpt-oss-120b',
                'messages' => $messages,
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $answer = $body['choices'][0]['message']['content'] ?? null;
        return is_string($answer) && $answer !== ''
            ? trim($answer)
            : 'Не удалось получить ответ от сервиса.';
    } catch (GuzzleException $e) {
        error_log('OpenAI request failed: ' . $e->getMessage());
        return 'Не удалось связаться с сервисом. Попробуйте ещё раз.';
    } catch (Throwable $e) {
        error_log('Unexpected error: ' . $e->getMessage());
        return 'Произошла внутренняя ошибка. Попробуйте позже.';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['session']['session_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Alice request'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Yandex sends its own session_id; do not rely on browser cookies.
$stateDir = is_dir('/data') && is_writable('/data')
    ? '/data/alice-sessions'
    : sys_get_temp_dir() . '/alice-gpt-sessions';
if (!is_dir($stateDir) && !@mkdir($stateDir, 0770, true) && !is_dir($stateDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Session storage unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionId = (string) $input['session']['session_id'];
$stateFile = $stateDir . '/' . hash('sha256', $sessionId) . '.json';
$stateHandle = fopen($stateFile, 'c+');
if ($stateHandle === false || !flock($stateHandle, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['error' => 'Session storage unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}
$stored = stream_get_contents($stateHandle);
$state = json_decode($stored ?: '', true);
if (!is_array($state) || !isset($state['messages']) || !is_array($state['messages'])) {
    $state = ['messages' => []];
}

$userMessage = cleanRequest((string) ($input['request']['original_utterance'] ?? ''));
if ($userMessage === '') {
    $reply = 'Я умный чат-бот. Спроси что-нибудь.';
} else {
    $history = $state['messages'];
    $client = new Client([
        'base_uri' => 'https://api.groq.com/openai/',
        'timeout' => 30.0,
        'connect_timeout' => 10.0,
    ]);

    $reply = askOpenAI($userMessage, $history, $client);

    // Store a proper user/assistant pair for the next turn.
    $history[] = ['role' => 'user', 'content' => $userMessage];
    $history[] = ['role' => 'assistant', 'content' => $reply];
    $state['messages'] = array_slice($history, -20);
}

rewind($stateHandle);
ftruncate($stateHandle, 0);
fwrite($stateHandle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
fflush($stateHandle);
flock($stateHandle, LOCK_UN);
fclose($stateHandle);

echo json_encode(aliceResponse($input, $reply), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
