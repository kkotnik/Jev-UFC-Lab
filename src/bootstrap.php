<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Ljubljana');

load_local_env(dirname(__DIR__) . '/.env');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JevClient.php';
require_once __DIR__ . '/UfcStatsSync.php';
require_once __DIR__ . '/UfcEventSync.php';
require_once __DIR__ . '/OddsClient.php';
require_once __DIR__ . '/EstaveTicketService.php';
require_once __DIR__ . '/PredictionService.php';
require_once __DIR__ . '/PreFightDataService.php';
require_once __DIR__ . '/EventReviewService.php';
require_once __DIR__ . '/BacktestService.php';

function load_local_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || getenv($key) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function app_db(): Database
{
    static $database;
    if (!$database instanceof Database) {
        $database = new Database(dirname(__DIR__) . '/data/ufc_lab.sqlite');
    }
    return $database;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Neveljaven JSON.');
    }
    return $decoded;
}
