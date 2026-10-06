<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
$database=app_db();
$eventId=(int)($argv[1]??0);
if($eventId<=0){$eventId=(int)($database->pdo()->query("SELECT id FROM events WHERE substr(event_date,1,10)>=date('now') ORDER BY event_date LIMIT 1")->fetchColumn()?:0);}
if($eventId<=0){fwrite(STDERR,"Ni prihodnjega dogodka.\n");exit(1);}
$result=(new PreFightDataService($database))->refreshEvent($eventId);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
