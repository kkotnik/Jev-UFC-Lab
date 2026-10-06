<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$limit = isset($argv[1]) ? max(1, min(300, (int) $argv[1])) : 80;
echo "Uvažam zadnjih {$limit} UFC dogodkov iz UFCStats ...\n";
$result = (new UfcStatsSync(app_db()->pdo()))->sync($limit);
echo "Dogodki: {$result['events_scanned']} | zapisane borbe: {$result['fights_written']}\n";
