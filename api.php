<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

try {
    $database = app_db();
    $pdo = $database->pdo();
    $service = new PredictionService($database);
    $action = (string) ($_GET['action'] ?? 'dashboard');
    $input = request_json();

    if ($action === 'dashboard') {
        $pdo->exec("UPDATE events SET status='past_unsettled' WHERE status='upcoming' AND substr(event_date,1,10) < date('now')");
        $events = $pdo->query('SELECT * FROM events ORDER BY event_date DESC')->fetchAll();
        if (isset($_GET['event_id'])) {
            $eventId=(int)$_GET['event_id'];
        } else {
            $current=$pdo->query("SELECT id FROM events WHERE substr(event_date,1,10)>=date('now') AND status='upcoming' ORDER BY event_date ASC LIMIT 1")->fetchColumn();
            $eventId=(int)($current?:($events[0]['id']??0));
        }
        $fightStmt = $pdo->prepare(<<<'SQL'
SELECT f.*, p.id prediction_id, p.winner_pick, p.p_a, p.p_b, p.confidence, p.method_pick,
       p.method_probs_json, p.recommended_bet, p.recommended_odds, p.edge, p.stake prediction_stake,
       p.created_at prediction_created_at, p.status prediction_status,
       CASE WHEN p.raw_json LIKE '%pre_fight_enrichment%' THEN 1 ELSE 0 END prediction_enriched
FROM fights f
LEFT JOIN predictions p ON p.id = (SELECT p2.id FROM predictions p2 WHERE p2.fight_id=f.id ORDER BY p2.id DESC LIMIT 1)
WHERE f.event_id=? ORDER BY f.card_order
SQL);
        $fightStmt->execute([$eventId]);
        $fights = $fightStmt->fetchAll();
        foreach ($fights as &$fight) {
            foreach (['odds_a','odds_b','p_a','p_b','confidence','recommended_odds','edge','prediction_stake'] as $field) {
                $fight[$field] = $fight[$field] === null ? null : (float) $fight[$field];
            }
            $rawMethodProbs = json_decode((string) ($fight['method_probs_json'] ?? ''), true);
            if (!is_array($rawMethodProbs) || $rawMethodProbs === []) {
                $fight['method_probs'] = [];
            } else {
                $fight['method_probs'] = StakeMethodMarket::normalizeProbs($rawMethodProbs);
            }
            unset($fight['method_probs_json']);
            $fight['method_markets'] = [];
            if ($fight['prediction_id'] != null && $fight['odds_a'] !== null && $fight['odds_b'] !== null && $fight['method_probs'] !== []) {
                foreach (StakeMethodMarket::keys() as $key) {
                    $methodOdds = StakeMethodMarket::syntheticMethodOdds((float) $fight['odds_a'], (float) $fight['odds_b'], $key);
                    $option = StakeMethodMarket::optionFromKey($key, (string) $fight['fighter_a'], (string) $fight['fighter_b'], $fight['method_probs'], $methodOdds);
                    if ($option !== null) {
                        $fight['method_markets'][] = $option;
                    }
                }
            }
            $fight['prefight']=(new PreFightDataService($database))->getFightData((int)$fight['id'],false);
        }
        unset($fight);
        $betsStmt = $pdo->prepare('SELECT b.*, f.fighter_a, f.fighter_b FROM bets b LEFT JOIN fights f ON f.id=b.fight_id WHERE b.event_id=? ORDER BY b.id DESC');
        $betsStmt->execute([$eventId]);
        $bets = $betsStmt->fetchAll();
        $metrics = [
            'me' => $service->ownerMetrics('me'),
            'jev_single' => $service->ownerMetrics('jev', 'single'),
            'jev_ticket' => $service->ownerMetrics('jev', 'ticket'),
        ];
        $settled = $pdo->query('SELECT p.p_a,f.fighter_a,f.winner FROM predictions p JOIN fights f ON f.id=p.fight_id WHERE f.completed=1 AND f.winner IS NOT NULL')->fetchAll();
        $brier = null;
        if ($settled) {
            $sum = 0.0;
            foreach ($settled as $prediction) {
                $actual = $prediction['winner'] === $prediction['fighter_a'] ? 1.0 : 0.0;
                $sum += (((float) $prediction['p_a'] - $actual) ** 2);
            }
            $brier = $sum / count($settled);
        }
        $reportStmt = $pdo->prepare('SELECT report_json FROM event_reports WHERE event_id=? ORDER BY id DESC LIMIT 1');
        $reportStmt->execute([$eventId]);
        $reportJson = $reportStmt->fetchColumn();
        $backtestService = new BacktestService($database);
        $backtestTicket = $backtestService->loadNamed(BacktestService::BATCH_NAME);
        $backtestSingle = $backtestService->loadNamed(BacktestService::SINGLES_BATCH_NAME);
        $backtestSingleMethod = $backtestService->ensureMethodSinglesBacktest();
        $activeName = '';
        foreach ($events as $eventRow) {
            if ((int) $eventRow['id'] === $eventId) {
                $activeName = (string) $eventRow['name'];
            }
        }
        $history=[];
        $historyStmt=$pdo->query("SELECT * FROM events WHERE substr(event_date,1,10)<date('now') OR status!='upcoming' ORDER BY event_date DESC");
        $historyBetStmt=$pdo->prepare('SELECT b.*,f.fighter_a,f.fighter_b FROM bets b LEFT JOIN fights f ON f.id=b.fight_id WHERE b.event_id=? ORDER BY b.id');
        $historyFightStmt=$pdo->prepare('SELECT f.*,p.winner_pick,p.p_a,p.p_b,p.method_pick FROM fights f LEFT JOIN predictions p ON p.id=(SELECT id FROM predictions WHERE fight_id=f.id ORDER BY id DESC LIMIT 1) WHERE f.event_id=? ORDER BY f.card_order');
        foreach($historyStmt->fetchAll() as $pastEvent){$historyBetStmt->execute([$pastEvent['id']]);$historyFightStmt->execute([$pastEvent['id']]);$pastEvent['bets']=$historyBetStmt->fetchAll();$pastEvent['fights']=$historyFightStmt->fetchAll();$history[]=$pastEvent;}
        json_response([
            'ok' => true, 'events' => $events, 'event_id' => $eventId, 'fights' => $fights, 'bets' => $bets,
            'metrics' => $metrics, 'history_count' => (int) $pdo->query('SELECT COUNT(*) FROM historical_fights')->fetchColumn(),
            'brier' => $brier, 'api_configured' => (string) getenv('TYPESAFE_API_KEY') !== '',
            'event_report' => $reportJson ? json_decode((string)$reportJson, true) : null,
            'backtest' => $backtestTicket,
            'backtest_ticket' => $backtestTicket,
            'backtest_single' => $backtestSingle,
            'backtest_single_method' => $backtestSingleMethod,
            'stake_url' => StakeMethodMarket::eventUrl($activeName),
            'history' => $history,
            'settings' => [
                'kelly_fraction' => $database->setting('kelly_fraction', .5),
                'max_bet_fraction' => $database->setting('max_bet_fraction', .1),
                'max_event_fraction' => $database->setting('max_event_fraction', .35),
                'min_edge' => $database->setting('min_edge', .05),
                'starting_bankroll' => $database->setting('starting_bankroll', 500.0),
                'bankroll_unit' => $database->setting('bankroll_unit', 'eur'),
            ],
        ]);
    }

    if ($action === 'save_odds') {
        $oddsA = nullable_decimal($input['odds_a'] ?? null);
        $oddsB = nullable_decimal($input['odds_b'] ?? null);
        foreach ([$oddsA, $oddsB] as $odds) {
            if ($odds !== null && $odds <= 1.0) {
                throw new InvalidArgumentException('Decimalna kvota mora biti večja od 1.00.');
            }
        }
        $stmt = $pdo->prepare('UPDATE fights SET odds_a=?, odds_b=? WHERE id=? AND completed=0');
        $stmt->execute([$oddsA, $oddsB, (int) ($input['fight_id'] ?? 0)]);
        json_response(['ok' => true]);
    }

    if ($action === 'predict') {
        $apiKey = trim((string) ($_SERVER['HTTP_X_TYPESAFE_KEY'] ?? getenv('TYPESAFE_API_KEY') ?: ''));
        json_response(['ok' => true, 'prediction' => $service->predict((int) ($input['fight_id'] ?? 0), $apiKey)]);
    }

    if ($action === 'finalize_portfolio') {
        $mode = (string) ($input['mode'] ?? 'ticket');
        if ($mode !== 'single') {
            $mode = 'ticket';
        }
        json_response(['ok'=>true,'portfolio'=>$service->finalizeEventPortfolio((int)($input['event_id'] ?? 0), $mode)]);
    }

    if ($action === 'complete_event') {
        set_time_limit(600);
        json_response(['ok'=>true,'report'=>(new EventReviewService($database))->completeFromResults((int)($input['event_id'] ?? 0))]);
    }

    if ($action === 'run_backtest') {
        set_time_limit(600);
        $apiKey = trim((string) ($_SERVER['HTTP_X_TYPESAFE_KEY'] ?? getenv('TYPESAFE_API_KEY') ?: ''));
        $mode = (string) ($input['mode'] ?? 'ticket');
        if ($mode !== 'single') {
            $mode = 'ticket';
        }
        json_response(['ok'=>true]+(new BacktestService($database))->step($apiKey, $mode));
    }

    if ($action === 'my_bet') {
        $fightId = (int) ($input['fight_id'] ?? 0);
        $selection = trim((string) ($input['selection'] ?? ''));
        $odds = (float) ($input['odds'] ?? 0);
        $stake = (float) ($input['stake'] ?? 0);
        $stake = $service->moneyRound($stake);
        $fightStmt = $pdo->prepare('SELECT * FROM fights WHERE id=? AND completed=0');
        $fightStmt->execute([$fightId]);
        $fight = $fightStmt->fetch();
        if (!$fight) {
            throw new InvalidArgumentException('Izberi veljavnega borca.');
        }
        $market = 'moneyline';
        $parsedMethod = StakeMethodMarket::parseSelection($selection);
        if ($parsedMethod !== null) {
            $market = StakeMethodMarket::MARKET;
            $validFighter = false;
            if ($parsedMethod['fighter'] === $fight['fighter_a'] || $parsedMethod['fighter'] === $fight['fighter_b']) {
                $validFighter = true;
            }
            if (!$validFighter) {
                throw new InvalidArgumentException('Izberi veljavnega borca in winning method.');
            }
        } else if (!in_array($selection, [$fight['fighter_a'], $fight['fighter_b']], true)) {
            throw new InvalidArgumentException('Izberi veljavnega borca.');
        }
        if ($odds <= 1 || $stake <= 0 || $stake > $service->availableBankroll('me')) {
            throw new InvalidArgumentException('Preveri kvoto, vložek in razpoložljiv bankroll.');
        }
        $stmt = $pdo->prepare('INSERT INTO bets(event_id,fight_id,owner,selection,market,odds,stake) VALUES(?,?,"me",?,?,?,?)');
        $stmt->execute([$fight['event_id'], $fightId, $selection, $market, $odds, $stake]);
        json_response(['ok' => true]);
    }

    if ($action === 'settle_fight') {
        $fightId = (int) ($input['fight_id'] ?? 0);
        $winner = trim((string) ($input['winner'] ?? ''));
        $fightStmt = $pdo->prepare('SELECT * FROM fights WHERE id=?');
        $fightStmt->execute([$fightId]);
        $fight = $fightStmt->fetch();
        if (!$fight || !in_array($winner, [$fight['fighter_a'], $fight['fighter_b']], true)) {
            throw new InvalidArgumentException('Zmagovalec ni veljaven.');
        }
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE fights SET winner=?,method=?,result_round=?,completed=1 WHERE id=?');
            $round = (int) ($input['round'] ?? 0);
            $update->execute([$winner, trim((string) ($input['method'] ?? '')), $round > 0 ? $round : null, $fightId]);
            $bets = $pdo->prepare('SELECT * FROM bets WHERE fight_id=? AND result="open" AND market NOT IN ("kombinacija","sistem")');
            $bets->execute([$fightId]);
            $settle = $pdo->prepare('UPDATE bets SET result=?,profit=? WHERE id=?');
            foreach ($bets->fetchAll() as $bet) {
                $settled = StakeMethodMarket::settleProfit($bet, $winner, trim((string) ($input['method'] ?? '')));
                $settle->execute([$settled['result'], $settled['profit'], $bet['id']]);
            }
            $onTicket = false;
            $ticketStmt = $pdo->prepare('SELECT selection FROM bets WHERE event_id=? AND owner="jev" AND market IN ("kombinacija","sistem") AND result="open" AND stake>0');
            $ticketStmt->execute([(int) $fight['event_id']]);
            $ticketEngine = new EstaveTicketService();
            foreach ($ticketStmt->fetchAll() as $ticketBet) {
                $ticket = $ticketEngine->parseSelection((string) $ticketBet['selection']);
                if ($ticket === null) {
                    continue;
                }
                foreach ($ticket['legs'] as $leg) {
                    if ((int) $leg['fight_id'] === $fightId) {
                        $onTicket = true;
                    }
                }
            }
            if (!$onTicket) {
                $pred = $pdo->prepare('UPDATE predictions SET status="settled", profit=COALESCE((SELECT profit FROM bets WHERE owner="jev" AND fight_id=? AND market NOT IN ("kombinacija","sistem") ORDER BY id DESC LIMIT 1),0) WHERE fight_id=? AND status="open"');
                $pred->execute([$fightId, $fightId]);
            }
            $ticketEngine->settleOpenTickets($pdo, (int) $fight['event_id']);
            $pdo->commit();
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'sync_history') {
        $limit = max(1, min(300, (int) ($input['event_limit'] ?? 80)));
        json_response(['ok' => true, 'sync' => (new UfcStatsSync($pdo))->sync($limit)]);
    }

    if ($action === 'sync_odds') {
        $oddsKey = trim((string) ($_SERVER['HTTP_X_ODDS_KEY'] ?? getenv('THE_ODDS_API_KEY') ?: ''));
        json_response(['ok' => true, 'sync' => (new OddsClient($pdo, $oddsKey))->sync()]);
    }

    if ($action === 'sync_event_card') {
        json_response(['ok'=>true,'sync'=>(new UfcEventSync($pdo))->syncCard((int)($input['event_id']??0))]);
    }

    if ($action === 'sync_ufc_schedule') {
        set_time_limit(180);
        $sync = (new UfcEventSync($pdo))->syncSchedule((int) ($input['event_id'] ?? 0));
        $oddsKey = trim((string) ($_SERVER['HTTP_X_ODDS_KEY'] ?? getenv('THE_ODDS_API_KEY') ?: ''));
        if ($oddsKey !== '') {
            try {
                $odds = (new OddsClient($pdo, $oddsKey))->sync();
                $sync['odds_updated'] = (int) $odds['updated_fights'];
            } catch (Throwable $throwable) {
                $sync['odds_updated'] = 0;
                $sync['odds_error'] = $throwable->getMessage();
            }
        } else {
            $sync['odds_updated'] = 0;
        }
        json_response(['ok' => true, 'sync' => $sync]);
    }

    if ($action === 'refresh_prefight') {
        json_response(['ok'=>true,'sync'=>(new PreFightDataService($database))->refreshEvent((int)($input['event_id']??0))]);
    }

    if ($action === 'settings') {
        $rules = ['kelly_fraction' => [.05, 1], 'max_bet_fraction' => [.01, .30], 'max_event_fraction' => [.05, .80], 'min_edge' => [0, .25]];
        foreach ($rules as $key => [$min, $max]) {
            if (array_key_exists($key, $input)) {
                $database->setSetting($key, max($min, min($max, (float) $input[$key])));
            }
        }
        if (array_key_exists('bankroll_unit', $input)) {
            $unit = strtolower(trim((string) $input['bankroll_unit']));
            if ($unit !== 'btc') {
                $unit = 'eur';
            }
            $database->setSetting('bankroll_unit', $unit);
        }
        if (array_key_exists('starting_bankroll', $input)) {
            $unit = (string) $database->setting('bankroll_unit', 'eur');
            $bankroll = (float) $input['starting_bankroll'];
            if ($unit === 'btc') {
                $bankroll = round($bankroll, 8);
                if ($bankroll < 0.00000001) {
                    throw new InvalidArgumentException('Bankroll mora biti vsaj 0.00000001 BTC.');
                }
                if ($bankroll > 100) {
                    throw new InvalidArgumentException('Bankroll je omejen na 100 BTC.');
                }
            } else {
                $bankroll = round($bankroll, 2);
                if ($bankroll < 10) {
                    throw new InvalidArgumentException('Bankroll mora biti vsaj 10 €.');
                }
                if ($bankroll > 1000000) {
                    throw new InvalidArgumentException('Bankroll je omejen na 1 000 000 €.');
                }
            }
            $database->setSetting('starting_bankroll', $bankroll);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'create_event') {
        $name = trim((string) ($input['name'] ?? ''));
        $date = trim((string) ($input['event_date'] ?? ''));
        if ($name === '' || strtotime($date) === false) {
            throw new InvalidArgumentException('Vnesi ime in veljaven datum dogodka.');
        }
        $stmt = $pdo->prepare('INSERT INTO events(name,event_date,venue,source_url) VALUES(?,?,?,?)');
        $stmt->execute([$name, date(DATE_ATOM, strtotime($date)), trim((string) ($input['venue'] ?? '')), trim((string) ($input['source_url'] ?? ''))]);
        json_response(['ok' => true, 'event_id' => (int) $pdo->lastInsertId()]);
    }

    if ($action === 'add_fight') {
        $eventId = (int) ($input['event_id'] ?? 0);
        $fighterA = trim((string) ($input['fighter_a'] ?? ''));
        $fighterB = trim((string) ($input['fighter_b'] ?? ''));
        if ($fighterA === '' || $fighterB === '' || $fighterA === $fighterB) {
            throw new InvalidArgumentException('Vnesi dva različna borca.');
        }
        $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(card_order),0)+1 FROM fights WHERE event_id=?');
        $orderStmt->execute([$eventId]);
        $stmt = $pdo->prepare('INSERT INTO fights(event_id,card_order,card_section,weight_class,fighter_a,fighter_b,odds_a,odds_b) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$eventId, (int) $orderStmt->fetchColumn(), trim((string) ($input['card_section'] ?? 'Prelims')), trim((string) ($input['weight_class'] ?? '')), $fighterA, $fighterB, nullable_decimal($input['odds_a'] ?? null), nullable_decimal($input['odds_b'] ?? null)]);
        json_response(['ok' => true]);
    }

    throw new InvalidArgumentException('Neznana akcija.');
} catch (InvalidArgumentException $exception) {
    json_response(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    json_response(['ok' => false, 'error' => $exception->getMessage()], 500);
}

function nullable_decimal(mixed $value): ?float
{
    return $value === null || $value === '' ? null : (float) $value;
}
