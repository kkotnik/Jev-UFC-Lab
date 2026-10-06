<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$database = app_db();
$pdo = $database->pdo();
$checks = [
    'SQLite povezava' => $pdo->query('SELECT 1')->fetchColumn() === 1,
    'Začetni dogodek' => (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() >= 1,
    'Fight card' => (int) $pdo->query('SELECT COUNT(*) FROM fights')->fetchColumn() >= 12,
    'Začetni bankroll' => (float) $database->setting('starting_bankroll') === 500.0,
    'Pre-fight enrichment schema' => (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='fight_prefight_data'")->fetchColumn() === 1,
    'Brez obveznega event vložka' => (float) $database->setting('min_event_stake', 0) === 0.0,
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
