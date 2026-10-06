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
    'EstaveTicketService naložen' => class_exists('EstaveTicketService'),
];

$engine = new EstaveTicketService();
$two = $engine->buildTicket([
    ['fight_id' => 1, 'selection' => 'A', 'odds' => 1.70, 'p' => 0.72, 'confidence' => 0.70, 'fighter_a' => 'A', 'fighter_b' => 'B'],
    ['fight_id' => 2, 'selection' => 'C', 'odds' => 1.90, 'p' => 0.68, 'confidence' => 0.66, 'fighter_a' => 'C', 'fighter_b' => 'D'],
], 500.0, ['kelly_fraction' => 0.5, 'max_bet_fraction' => 0.15, 'max_event_fraction' => 0.35, 'available' => 500.0]);
$checks['Kombinacija 2 nogi'] = $two !== null && $two['type'] === 'kombinacija' && $two['leg_count'] === 2 && $two['stake'] >= 0.50;
$three = $engine->buildTicket([
    ['fight_id' => 1, 'selection' => 'A', 'odds' => 1.70, 'p' => 0.72, 'confidence' => 0.70, 'fighter_a' => 'A', 'fighter_b' => 'B', 'kelly' => 0.32],
    ['fight_id' => 2, 'selection' => 'C', 'odds' => 1.90, 'p' => 0.68, 'confidence' => 0.66, 'fighter_a' => 'C', 'fighter_b' => 'D', 'kelly' => 0.29],
    ['fight_id' => 3, 'selection' => 'E', 'odds' => 1.80, 'p' => 0.70, 'confidence' => 0.64, 'fighter_a' => 'E', 'fighter_b' => 'F', 'kelly' => 0.28],
], 500.0, ['kelly_fraction' => 0.5, 'max_bet_fraction' => 0.15, 'max_event_fraction' => 0.35, 'available' => 500.0]);
$checks['Sistem 2/3'] = $three !== null && $three['type'] === 'sistem' && $three['leg_count'] === 3 && $three['stake'] === round($three['unit_stake'] * 3, 2);
$sysWin = $three;
$sysWin['legs'][0]['actual_winner'] = 'A';
$sysWin['legs'][1]['actual_winner'] = 'C';
$sysWin['legs'][2]['actual_winner'] = 'Z';
$partial = $engine->settleTicket($sysWin);
$checks['Sistem 2/3 ena noga pade'] = $partial['won'] === true && $partial['profit'] > 0;
$comboWin = $engine->settleTicket(['type'=>'kombinacija','stake'=>10.0,'combined_odds'=>3.0,'legs'=>[['selection'=>'A','actual_winner'=>'A'],['selection'=>'C','actual_winner'=>'C']]]);
$comboLoss = $engine->settleTicket(['type'=>'kombinacija','stake'=>10.0,'combined_odds'=>3.0,'legs'=>[['selection'=>'A','actual_winner'=>'A'],['selection'=>'C','actual_winner'=>'Z']]]);
$checks['Listek WIN profit'] = $comboWin['profit'] === 20.0;
$checks['Listek LOSS profit'] = $comboLoss['profit'] === -10.0;
$taxed = $engine->settleTicket(['type'=>'kombinacija','stake'=>100.0,'combined_odds'=>4.0,'legs'=>[['selection'=>'A','actual_winner'=>'A'],['selection'=>'C','actual_winner'=>'C']]]);
$checks['Davek nad 300'] = $taxed['profit'] === 240.0;
$service = new PredictionService($database);
$singleMetrics = $service->ownerMetrics('jev', 'single');
$ticketMetrics = $service->ownerMetrics('jev', 'ticket');
$checks['Single bankroll ločen'] = isset($singleMetrics['bankroll'], $singleMetrics['roi']);
$checks['Listek bankroll ločen'] = isset($ticketMetrics['bankroll'], $ticketMetrics['roi']);
$backtest = new BacktestService($database);
$storedSingles = $backtest->loadNamed(BacktestService::SINGLES_BATCH_NAME);
$storedTicket = $backtest->loadNamed(BacktestService::BATCH_NAME);
$checks['Shranjen single backtest'] = $storedSingles !== null && (int) ($storedSingles['version'] ?? 0) >= 5;
$checks['Shranjen listek backtest'] = $storedTicket !== null && (int) ($storedTicket['version'] ?? 0) >= 6;
$slug = StakeMethodMarket::eventUrl('UFC Fight Night: Allen vs Duncan');
$checks['Stake event URL'] = $slug === 'https://stake.com/sports/mma/ufc/ufc-fight-night-allen-vs-duncan';
$koHit = StakeMethodMarket::selectionHits('Ernesta Kareckaite by KO/TKO', 'winning_method', 'Ernesta Kareckaite', 'KO/TKO');
$koMiss = StakeMethodMarket::selectionHits('Ernesta Kareckaite by KO/TKO', 'winning_method', 'Ernesta Kareckaite', 'Decision');
$mlHit = StakeMethodMarket::selectionHits('Melissa Gatto', 'moneyline', 'Melissa Gatto', 'Decision');
$checks['Method stava zadane KO'] = $koHit === true;
$checks['Method stava pade na decision'] = $koMiss === false;
$checks['Moneyline ignorira method'] = $mlHit === true;
$checks['Smetnje method kvote'] = StakeMethodMarket::methodOddsUsable(29.0, 1.41, 'a_ko_tko') === false;
$checks['Realna method kvota'] = StakeMethodMarket::methodOddsUsable(2.10, 1.55, 'a_ko_tko') === true;
$methodBacktest = $backtest->ensureMethodSinglesBacktest();
$checks['Method backtest shranjen'] = $methodBacktest !== null && (int) ($methodBacktest['version'] ?? 0) >= 7;
$checks['Winner-only primerjava'] = isset($methodBacktest['winner_only']['profit'], $methodBacktest['winner_only_locked']['profit']);
$checks['Stari single backtest ostane'] = $storedSingles !== null && $methodBacktest !== null && (string) $methodBacktest['event_name'] !== (string) $storedSingles['event_name'];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
