<?php
// Local stand-in for DwemerDistro MiniMe-T5 and TXT2VEC on one port, for tests.
// Endpoints match CHIM: GET /extract, /detectMemory, /command, /topic and POST /embed.
// Usage: php -S 127.0.0.1:PORT scripts/minime_txt2vec_stub.php

if (PHP_SAPI !== 'cli-server' && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function stub_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stub_text(): string
{
    if (isset($_GET['text']) && is_string($_GET['text'])) {
        return $_GET['text'];
    }
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    return is_array($json) && is_string($json['text'] ?? null) ? $json['text'] : '';
}

// Deterministic 32-dimension vector from word hashes. Shared words score high; it is enough
// to test storage and recall without a real model. Unit length, like the server expects.
function stub_embedding(string $text): array
{
    preg_match_all('/[\p{L}\p{N}]{3,32}/u', mb_strtolower($text), $matches);
    $vector = array_fill(0, 32, 0.0);
    foreach ($matches[0] as $word) {
        $hash = crc32($word);
        $vector[$hash % 32] += ($hash >> 16) & 1 ? 1.0 : -1.0;
    }
    $sum = 0.0;
    foreach ($vector as $value) {
        $sum += $value * $value;
    }
    $norm = sqrt($sum);
    if ($norm < 1e-9) {
        $vector[0] = 1.0;
        $norm = 1.0;
    }
    return array_map(fn($value) => round($value / $norm, 6), $vector);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$text = stub_text();

if ($method === 'GET' && in_array($path, ['/extract', '/detectMemory'], true)) {
    $recall = preg_match('/\b(remember|recall|memory|forgot|bridge)\b/i', $text) ? 'Yes' : 'No';
    $tags = [];
    if (preg_match_all('/[\p{L}\p{N}]{4,32}/u', $text, $matches)) {
        $tags = array_slice(array_unique(array_map('strtolower', $matches[0])), 0, 6);
    }
    stub_json([
        'is_memory_recall' => $recall,
        'generated_tags' => implode(' ', $tags),
        'elapsed_time' => 0.01,
        'version' => 2,
    ]);
}

if ($method === 'GET' && $path === '/command') {
    $command = 'Talk';
    if (preg_match('/\bfollow\b/i', $text)) {
        $command = 'Follow';
    } elseif (preg_match('/\b(stay|wait|sit|hold position)\b/i', $text)) {
        $command = 'TakeASeat';
    } elseif (preg_match('/\battack\b/i', $text)) {
        $command = 'Attack';
    } elseif (preg_match('/\bgive\b/i', $text)) {
        $command = 'ExchangeItems';
    }
    stub_json(['is_command' => $command, 'elapsed_time' => 0.01]);
}

if ($method === 'GET' && $path === '/topic') {
    stub_json(['generated_tags' => preg_match('/\bbridge\b/i', $text) ? 'bridge' : 'chat', 'elapsed_time' => 0.01]);
}

if ($method === 'POST' && $path === '/embed') {
    if (trim($text) === '') {
        stub_json(['error' => 'missing text'], 400);
    }
    stub_json(['embedding' => stub_embedding($text), 'timing' => ['generation_time_seconds' => 0.001]]);
}

stub_json(['error' => 'not found'], 404);
