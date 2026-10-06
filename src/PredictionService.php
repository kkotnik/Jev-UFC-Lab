<?php
declare(strict_types=1);

final class PredictionService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function predict(int $fightId, string $apiKey): array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare('SELECT f.*, e.name event_name, e.event_date, e.venue FROM fights f JOIN events e ON e.id=f.event_id WHERE f.id=?');
        $stmt->execute([$fightId]);
        $fight = $stmt->fetch();
        if (!$fight) {
            throw new InvalidArgumentException('Borba ne obstaja.');
        }

        $preFight = (new PreFightDataService($this->database))->refreshFight($fightId);
        $existing = $pdo->prepare('SELECT * FROM predictions WHERE fight_id=? AND status="open" ORDER BY id DESC LIMIT 1');
        $existing->execute([$fightId]);
        $supersedeId=null;
        if ($prediction = $existing->fetch()) {
            $raw=json_decode((string)$prediction['raw_json'],true)?:[];
            if(isset($raw['state']['pre_fight_enrichment']))return $this->hydratePrediction($prediction) + ['already_locked' => true];
            $supersedeId=(int)$prediction['id'];
        }
        $state = [
            'task' => 'Forecast this UFC bout independently using only the supplied pre-fight performance evidence. The betting market is deliberately hidden and will be compared with your probability only after you answer. Unknown data is unknown, never zero. Be calibrated and resist hype, name recognition and false precision.',
            'event' => [
                'name' => $fight['event_name'],
                'date' => $fight['event_date'],
                'venue' => $fight['venue'],
                'weight_class' => $fight['weight_class'],
            ],
            'fighter_a' => $this->fighterSummary($fight['fighter_a'], $fight['event_date']),
            'fighter_b' => $this->fighterSummary($fight['fighter_b'], $fight['event_date']),
            'pre_fight_enrichment' => [
                'fighter_a' => $preFight['fighter_a'],
                'fighter_b' => $preFight['fighter_b'],
                'event_context' => $preFight['event_context'],
                'data_quality' => $preFight['overall_quality'],
                'verification_rule' => 'Unknown injury, short-notice, replacement and weight-miss flags must remain unknown and must not be inferred.',
            ],
        ];

        $methodCriteria = [
            'a_ko_tko' => $fight['fighter_a'] . ' wins by KO/TKO or doctor stoppage',
            'a_submission' => $fight['fighter_a'] . ' wins by submission',
            'a_decision' => $fight['fighter_a'] . ' wins by judges decision',
            'b_ko_tko' => $fight['fighter_b'] . ' wins by KO/TKO or doctor stoppage',
            'b_submission' => $fight['fighter_b'] . ' wins by submission',
            'b_decision' => $fight['fighter_b'] . ' wins by judges decision',
        ];
        $questions = [
            'winner' => [
                'type' => 'choice',
                'instructions' => 'Who is more likely to win this bout?',
                'criteria' => [
                    'fighter_a' => $fight['fighter_a'] . ' wins',
                    'fighter_b' => $fight['fighter_b'] . ' wins',
                ],
            ],
            'method' => [
                'type' => 'choice',
                'instructions' => 'Which complete winner-and-method outcome is most likely?',
                'criteria' => $methodCriteria,
            ],
            'goes_distance' => [
                'type' => 'noul',
                'instructions' => 'The bout reaches the final horn and is decided by the judges.',
            ],
            'upset_risk' => [
                'type' => 'score',
                'instructions' => 'How volatile and upset-prone is this matchup?',
                'criteria' => [
                    'Very stable matchup with a strong evidence-backed favorite',
                    'Mostly stable but with a credible losing path for the favorite',
                    'Balanced uncertainty with several plausible paths',
                    'High variance; small errors can reverse the result',
                    'Extremely volatile or evidence is too sparse for confidence',
                ],
            ],
            'data_quality' => [
                'type' => 'score',
                'instructions' => 'How sufficient is the supplied historical evidence for this forecast?',
                'criteria' => [
                    'Almost no relevant evidence',
                    'Sparse evidence with major unknowns',
                    'Usable but incomplete evidence',
                    'Good relevant evidence for both fighters',
                    'Extensive recent comparable evidence for both fighters',
                ],
            ],
        ];

        $response = (new JevClient($apiKey))->evaluate(
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $questions
        );
        $answers = $response['answers'];
        $winner = $answers['winner'] ?? [];
        $method = $answers['method'] ?? [];
        $pA = (float) ($winner['probabilities']['fighter_a'] ?? 0.5);
        $pB = (float) ($winner['probabilities']['fighter_b'] ?? (1 - $pA));
        $total = max(0.000001, $pA + $pB);
        $pA /= $total;
        $pB /= $total;
        $confidence = max(0.0, min(1.0, (float) ($winner['confidence'] ?? max($pA, $pB))));
        $methodKey = (string) ($method['choice'] ?? 'unknown');
        $methodPick = $methodCriteria[$methodKey] ?? $methodKey;
        $recommendation = $this->recommendBet($fight, $pA, $pB, $confidence);

        $pdo->beginTransaction();
        try {
            if($supersedeId!==null){$supersede=$pdo->prepare('UPDATE predictions SET status="superseded" WHERE id=?');$supersede->execute([$supersedeId]);}
            $insert = $pdo->prepare(<<<'SQL'
INSERT INTO predictions (
 event_id,fight_id,model,raw_json,winner_pick,p_a,p_b,confidence,method_pick,
 method_probs_json,recommended_bet,recommended_odds,edge,stake
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
            $winnerPick = $pA >= $pB ? $fight['fighter_a'] : $fight['fighter_b'];
            $insert->execute([
                $fight['event_id'], $fightId, (string) ($response['model'] ?? 'jev-latest'),
                json_encode(['state' => $state, 'response' => $response], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $winnerPick, $pA, $pB, $confidence, $methodPick,
                json_encode($method['probabilities'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $recommendation['selection'], $recommendation['odds'], $recommendation['edge'], 0.0,
            ]);
            $predictionId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }

        $saved = $pdo->prepare('SELECT * FROM predictions WHERE id=?');
        $saved->execute([$predictionId]);
        return $this->hydratePrediction($saved->fetch());
    }

    private function fighterSummary(string $name, string $beforeDate): array
    {
        $stmt = $this->database->pdo()->prepare(<<<'SQL'
SELECT * FROM historical_fights
WHERE (fighter_a = :name OR fighter_b = :name) AND event_date < :event_date
ORDER BY event_date DESC LIMIT 15
SQL);
        $stmt->execute(['name' => $name, 'event_date' => substr($beforeDate, 0, 10)]);
        $rows = $stmt->fetchAll();
        $wins = $losses = $draws = 0;
        $kdFor = $kdAgainst = $strFor = $strAgainst = $tdFor = $tdAgainst = $subFor = 0;
        $samples = ['kd' => 0, 'str' => 0, 'td' => 0, 'sub' => 0];
        $history = [];
        foreach ($rows as $row) {
            $isA = $row['fighter_a'] === $name;
            $opponent = $isA ? $row['fighter_b'] : $row['fighter_a'];
            $result = $row['winner'] === null ? 'draw/nc' : ($row['winner'] === $name ? 'win' : 'loss');
            $result === 'win' ? $wins++ : ($result === 'loss' ? $losses++ : $draws++);
            foreach ([['kd','kd'], ['sig_str','str'], ['td','td']] as [$field, $sample]) {
                $for = $row[$field . ($isA ? '_a' : '_b')];
                $against = $row[$field . ($isA ? '_b' : '_a')];
                if ($for !== null && $against !== null) {
                    ${$sample . 'For'} += (int) $for;
                    ${$sample . 'Against'} += (int) $against;
                    $samples[$sample]++;
                }
            }
            $sub = $row['sub' . ($isA ? '_a' : '_b')];
            if ($sub !== null) {
                $subFor += (int) $sub;
                $samples['sub']++;
            }
            $history[] = [
                'date' => $row['event_date'], 'opponent' => $opponent, 'result' => $result,
                'method' => $row['method'], 'round' => $row['result_round'], 'weight_class' => $row['weight_class'],
            ];
        }
        $average = static fn(int $sum, int $count): ?float => $count > 0 ? round($sum / $count, 2) : null;
        return [
            'name' => $name,
            'sample_size' => count($rows),
            'record_in_sample' => ['wins' => $wins, 'losses' => $losses, 'draws_or_nc' => $draws],
            'per_bout_averages' => [
                'knockdowns_for' => $average($kdFor, $samples['kd']),
                'knockdowns_against' => $average($kdAgainst, $samples['kd']),
                'significant_strikes_for' => $average($strFor, $samples['str']),
                'significant_strikes_against' => $average($strAgainst, $samples['str']),
                'takedowns_for' => $average($tdFor, $samples['td']),
                'takedowns_against' => $average($tdAgainst, $samples['td']),
                'submission_attempts' => $average($subFor, $samples['sub']),
            ],
            'recent_fights' => $history,
        ];
    }

    private function recommendBet(array $fight, float $pA, float $pB, float $confidence): array
    {
        $candidates = [];
        foreach ([['fighter_a', $fight['fighter_a'], $pA, $fight['odds_a']], ['fighter_b', $fight['fighter_b'], $pB, $fight['odds_b']]] as $candidate) {
            [, $name, $probability, $odds] = $candidate;
            if ($odds === null || (float) $odds <= 1.0) {
                continue;
            }
            $decimal = (float) $odds;
            $edge = $probability - (1 / $decimal);
            $kelly = (($decimal * $probability) - 1) / ($decimal - 1);
            $candidates[] = compact('name', 'probability', 'decimal', 'edge', 'kelly');
        }
        usort($candidates, static fn(array $a, array $b): int => $b['edge'] <=> $a['edge']);
        $best = $candidates[0] ?? null;
        $minEdge = (float) $this->database->setting('min_edge', 0.03);
        if (!$best || $best['edge'] < $minEdge || $best['kelly'] <= 0 || $confidence < 0.25) {
            return ['selection' => null, 'odds' => null, 'edge' => $best['edge'] ?? null, 'stake' => 0.0];
        }

        $bankroll = $this->bankroll('jev', 'single');
        $fraction = (float) $this->database->setting('kelly_fraction', 0.50);
        $maxBet = $bankroll * (float) $this->database->setting('max_bet_fraction', 0.15);
        $eventLimit = $bankroll * (float) $this->database->setting('max_event_fraction', 0.35);
        $eventExposureStmt = $this->database->pdo()->prepare('SELECT COALESCE(SUM(stake),0) FROM bets WHERE owner="jev" AND event_id=? AND result="open" AND market NOT IN ("kombinacija","sistem")');
        $eventExposureStmt->execute([$fight['event_id']]);
        $eventRoom = max(0.0, $eventLimit - (float) $eventExposureStmt->fetchColumn());
        $stake = min($bankroll * $fraction * $best['kelly'], $maxBet, $eventRoom, $this->availableBankroll('jev', 'single'));
        $stake = floor(max(0.0, $stake) * 100) / 100;
        return ['selection' => $best['name'], 'odds' => $best['decimal'], 'edge' => $best['edge'], 'stake' => $stake];
    }

    public function bankroll(string $owner, string $mode = 'single'): float
    {
        $start = (float) $this->database->setting('starting_bankroll', 500.0);
        if ($owner === 'me') {
            $stmt = $this->database->pdo()->prepare('SELECT COALESCE(SUM(profit),0) FROM bets WHERE owner=? AND result != "open"');
            $stmt->execute([$owner]);
            return round($start + (float) $stmt->fetchColumn(), 2);
        }
        $stmt = $this->database->pdo()->prepare('SELECT COALESCE(SUM(profit),0) FROM bets WHERE owner="jev" AND result != "open" AND ' . $this->modeMarketSql($mode));
        $stmt->execute();
        return round($start + (float) $stmt->fetchColumn(), 2);
    }

    public function availableBankroll(string $owner, string $mode = 'single'): float
    {
        if ($owner === 'me') {
            $stmt = $this->database->pdo()->prepare('SELECT COALESCE(SUM(stake),0) FROM bets WHERE owner=? AND result="open"');
            $stmt->execute([$owner]);
            return max(0.0, $this->bankroll($owner) - (float) $stmt->fetchColumn());
        }
        $stmt = $this->database->pdo()->prepare('SELECT COALESCE(SUM(stake),0) FROM bets WHERE owner="jev" AND result="open" AND ' . $this->modeMarketSql($mode));
        $stmt->execute();
        return max(0.0, $this->bankroll('jev', $mode) - (float) $stmt->fetchColumn());
    }

    public function ownerMetrics(string $owner, string $mode = 'single'): array
    {
        if ($owner === 'me') {
            $metricStmt = $this->database->pdo()->prepare("SELECT COUNT(*) total, SUM(CASE WHEN result='win' THEN 1 ELSE 0 END) wins, SUM(CASE WHEN result='loss' THEN 1 ELSE 0 END) losses, COALESCE(SUM(CASE WHEN result!='open' THEN stake ELSE 0 END),0) settled_stake, COALESCE(SUM(profit),0) profit FROM bets WHERE owner=? AND stake>0");
            $metricStmt->execute([$owner]);
        } else {
            $metricStmt = $this->database->pdo()->prepare("SELECT COUNT(*) total, SUM(CASE WHEN result='win' THEN 1 ELSE 0 END) wins, SUM(CASE WHEN result='loss' THEN 1 ELSE 0 END) losses, COALESCE(SUM(CASE WHEN result!='open' THEN stake ELSE 0 END),0) settled_stake, COALESCE(SUM(profit),0) profit FROM bets WHERE owner='jev' AND stake>0 AND " . $this->modeMarketSql($mode));
            $metricStmt->execute();
        }
        $row = $metricStmt->fetch();
        $settledStake = (float) $row['settled_stake'];
        $roi = 0.0;
        if ($settledStake > 0) {
            $roi = (float) $row['profit'] / $settledStake;
        }
        return [
            'bankroll' => $this->bankroll($owner, $mode),
            'available' => $this->availableBankroll($owner, $mode),
            'total_bets' => (int) $row['total'],
            'wins' => (int) $row['wins'],
            'losses' => (int) $row['losses'],
            'profit' => (float) $row['profit'],
            'roi' => $roi,
        ];
    }

    private function modeMarketSql(string $mode): string
    {
        if ($mode === 'ticket') {
            return 'market IN ("kombinacija","sistem")';
        }
        return 'market NOT IN ("kombinacija","sistem")';
    }

    public function finalizeEventPortfolio(int $eventId, string $mode = 'ticket'): array
    {
        if ($mode === 'single') {
            return $this->finalizeSinglesPortfolio($eventId);
        }
        return $this->finalizeTicketPortfolio($eventId);
    }

    private function collectCandidates(int $eventId, bool $includeMethods = false): array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(<<<'SQL'
SELECT p.id prediction_id,p.fight_id,p.p_a,p.p_b,p.confidence,p.method_probs_json,p.method_pick,
       f.fighter_a,f.fighter_b,f.odds_a,f.odds_b
FROM predictions p JOIN fights f ON f.id=p.fight_id
WHERE p.id=(SELECT p2.id FROM predictions p2 WHERE p2.fight_id=f.id ORDER BY p2.id DESC LIMIT 1)
  AND f.event_id=? AND f.completed=0 AND (f.odds_a IS NOT NULL OR f.odds_b IS NOT NULL)
ORDER BY f.card_order
SQL);
        $stmt->execute([$eventId]);
        $candidates = [];
        $rows = $stmt->fetchAll();
        $minEdge = (float) $this->database->setting('min_edge', .05);
        $policy = $this->database->setting('single_method_policy', StakeMethodMarket::defaultPolicy());
        if (!is_array($policy)) {
            $policy = StakeMethodMarket::defaultPolicy();
        }
        $eligibleSeq = 0;
        foreach ($rows as $row) {
            $oddsA = $row['odds_a'] === null ? null : (float) $row['odds_a'];
            $oddsB = $row['odds_b'] === null ? null : (float) $row['odds_b'];
            $pA = (float) $row['p_a'];
            $pB = (float) $row['p_b'];
            $rawProbs = json_decode((string) $row['method_probs_json'], true);
            if (!is_array($rawProbs)) {
                $rawProbs = [];
            }
            $methodProbs = StakeMethodMarket::normalizeProbs($rawProbs);
            if (!$includeMethods) {
                $options = [];
                $mlA = StakeMethodMarket::moneylineOption((string) $row['fighter_a'], $pA, $oddsA);
                $mlB = StakeMethodMarket::moneylineOption((string) $row['fighter_b'], $pB, $oddsB);
                if ($mlA !== null) {
                    $options[] = $mlA;
                }
                if ($mlB !== null) {
                    $options[] = $mlB;
                }
                $best = StakeMethodMarket::bestOption($options, $minEdge);
                if ($best === null) {
                    continue;
                }
                if ((float) $row['confidence'] < .55) {
                    continue;
                }
                $best['fight_id'] = (int) $row['fight_id'];
                $best['prediction_id'] = (int) $row['prediction_id'];
                $best['confidence'] = (float) $row['confidence'];
                $best['fighter_a'] = (string) $row['fighter_a'];
                $best['fighter_b'] = (string) $row['fighter_b'];
                $best['method_probs'] = $methodProbs;
                $candidates[] = $best;
                continue;
            }
            $built = StakeMethodMarket::fightOptions(
                (string) $row['fighter_a'],
                (string) $row['fighter_b'],
                $pA,
                $pB,
                $oddsA,
                $oddsB,
                $methodProbs,
                []
            );
            $choice = StakeMethodMarket::recommend($built['moneyline'], $built['methods'], $minEdge, $policy, $eligibleSeq);
            if ($choice['bet'] === null) {
                continue;
            }
            if ((float) $row['confidence'] < .55) {
                continue;
            }
            $best = $choice['bet'];
            $best['fight_id'] = (int) $row['fight_id'];
            $best['prediction_id'] = (int) $row['prediction_id'];
            $best['confidence'] = (float) $row['confidence'];
            $best['fighter_a'] = (string) $row['fighter_a'];
            $best['fighter_b'] = (string) $row['fighter_b'];
            $best['method_probs'] = $methodProbs;
            $best['reason'] = $choice['reason'];
            $candidates[] = $best;
        }
        if (!$includeMethods) {
            usort($candidates, static function (array $a, array $b): int {
                return $b['edge'] <=> $a['edge'];
            });
        }
        return ['candidates' => $candidates, 'analyzed' => count($rows)];
    }

    private function finalizeSinglesPortfolio(int $eventId): array
    {
        $pdo = $this->database->pdo();
        $collected = $this->collectCandidates($eventId, true);
        $candidates = $collected['candidates'];
        $analyzedCount = $collected['analyzed'];
        $bankroll = $this->bankroll('jev', 'single');
        $otherStmt = $pdo->prepare('SELECT COALESCE(SUM(stake),0) FROM bets WHERE owner="jev" AND result="open" AND event_id!=? AND market NOT IN ("kombinacija","sistem")');
        $otherStmt->execute([$eventId]);
        $capacity = max(0.0, $bankroll - (float) $otherStmt->fetchColumn());
        $eventCap = min($capacity, $bankroll * (float) $this->database->setting('max_event_fraction', .35));
        $fraction = (float) $this->database->setting('kelly_fraction', .5);
        $maxBet = $bankroll * (float) $this->database->setting('max_bet_fraction', .15);
        $desired = 0.0;
        foreach ($candidates as &$candidate) {
            $candidate['raw_stake'] = $bankroll * $fraction * $candidate['kelly'] * $candidate['confidence'];
            $desired += $candidate['raw_stake'];
        }
        unset($candidate);
        if ($desired > $eventCap && $desired > 0) {
            $scale = $eventCap / $desired;
        } else {
            $scale = 1.0;
        }
        foreach ($candidates as &$candidate) {
            $candidate['stake'] = floor(min($candidate['raw_stake'] * $scale, $maxBet) * 100) / 100;
        }
        unset($candidate);
        $selected = [];
        foreach ($candidates as $candidate) {
            if ($candidate['stake'] > 0) {
                $selected[] = $candidate;
            }
        }
        $target = round(array_sum(array_column($selected, 'stake')), 2);
        $pdo->beginTransaction();
        try {
            $existingStmt = $pdo->prepare('SELECT * FROM bets WHERE owner="jev" AND event_id=? AND result="open" AND market NOT IN ("kombinacija","sistem")');
            $existingStmt->execute([$eventId]);
            $existing = [];
            foreach ($existingStmt->fetchAll() as $bet) {
                $existing[(int) $bet['fight_id']] = $bet;
            }
            $updateBet = $pdo->prepare('UPDATE bets SET selection=?,market=?,odds=?,stake=? WHERE id=?');
            $insertBet = $pdo->prepare('INSERT INTO bets(event_id,fight_id,owner,selection,market,odds,stake) VALUES(?,?,"jev",?,?,?,?)');
            $updatePrediction = $pdo->prepare('UPDATE predictions SET recommended_bet=?,recommended_odds=?,edge=?,stake=? WHERE id=?');
            foreach ($selected as $candidate) {
                $market = (string) ($candidate['market'] ?? 'moneyline');
                if (isset($existing[$candidate['fight_id']])) {
                    $updateBet->execute([$candidate['selection'], $market, $candidate['odds'], $candidate['stake'], $existing[$candidate['fight_id']]['id']]);
                    unset($existing[$candidate['fight_id']]);
                } else {
                    $insertBet->execute([$eventId, $candidate['fight_id'], $candidate['selection'], $market, $candidate['odds'], $candidate['stake']]);
                }
                $updatePrediction->execute([$candidate['selection'], $candidate['odds'], $candidate['edge'], $candidate['stake'], $candidate['prediction_id']]);
            }
            foreach ($existing as $bet) {
                $updateBet->execute([$bet['selection'], $bet['market'], $bet['odds'], 0, $bet['id']]);
            }
            $pdo->commit();
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
        $bets = [];
        foreach ($selected as $c) {
            $bets[] = [
                'fight_id' => $c['fight_id'],
                'selection' => $c['selection'],
                'odds' => $c['odds'],
                'probability' => $c['p'],
                'edge' => $c['edge'],
                'stake' => $c['stake'],
                'market' => $c['market'] ?? 'moneyline',
                'method_label' => $c['method_label'] ?? 'Moneyline',
                'reason' => $c['reason'] ?? '',
            ];
        }
        return [
            'event_id' => $eventId,
            'mode' => 'single',
            'total_stake' => $target,
            'minimum_required' => 0,
            'skipped' => $analyzedCount - count($selected),
            'ticket' => null,
            'bets' => $bets,
        ];
    }

    private function finalizeTicketPortfolio(int $eventId): array
    {
        $pdo = $this->database->pdo();
        $collected = $this->collectCandidates($eventId);
        $candidates = $collected['candidates'];
        $analyzedCount = $collected['analyzed'];
        $bankroll = $this->bankroll('jev', 'ticket');
        $otherStmt = $pdo->prepare('SELECT COALESCE(SUM(stake),0) FROM bets WHERE owner="jev" AND result="open" AND event_id!=? AND market IN ("kombinacija","sistem")');
        $otherStmt->execute([$eventId]);
        $capacity = max(0.0, $bankroll - (float) $otherStmt->fetchColumn());
        $fraction = (float) $this->database->setting('kelly_fraction', .5);
        $engine = new EstaveTicketService();
        $ticket = $engine->buildTicket($candidates, $bankroll, [
            'kelly_fraction' => $fraction,
            'max_bet_fraction' => (float) $this->database->setting('max_bet_fraction', .15),
            'max_event_fraction' => (float) $this->database->setting('max_event_fraction', .35),
            'available' => $capacity,
        ]);

        $pdo->beginTransaction();
        try {
            $existingTicketStmt = $pdo->prepare('SELECT * FROM bets WHERE owner="jev" AND event_id=? AND result="open" AND market IN ("kombinacija","sistem") ORDER BY id DESC');
            $existingTicketStmt->execute([$eventId]);
            $existingTickets = $existingTicketStmt->fetchAll();
            $updateTicket = $pdo->prepare('UPDATE bets SET fight_id=?, selection=?, market=?, odds=?, stake=? WHERE id=?');
            $insertTicket = $pdo->prepare('INSERT INTO bets(event_id,fight_id,owner,selection,market,odds,stake) VALUES(?,?,"jev",?,?,?,?)');
            $updatePrediction = $pdo->prepare('UPDATE predictions SET recommended_bet=?,recommended_odds=?,edge=?,stake=? WHERE id=?');
            $target = 0.0;
            $bets = [];
            if ($ticket !== null) {
                $selectionJson = $engine->encodeSelection($ticket);
                $firstFightId = (int) $ticket['legs'][0]['fight_id'];
                if ($existingTickets) {
                    $keepId = (int) $existingTickets[0]['id'];
                    $updateTicket->execute([$firstFightId, $selectionJson, $ticket['market'], $ticket['combined_odds'], $ticket['stake'], $keepId]);
                    foreach (array_slice($existingTickets, 1) as $duplicate) {
                        $updateTicket->execute([(int) $duplicate['fight_id'], $duplicate['selection'], $duplicate['market'], $duplicate['odds'], 0, $duplicate['id']]);
                    }
                } else {
                    $insertTicket->execute([$eventId, $firstFightId, $selectionJson, $ticket['market'], $ticket['combined_odds'], $ticket['stake']]);
                }
                foreach ($ticket['legs'] as $leg) {
                    $legStake = 0.0;
                    $updatePrediction->execute([$leg['selection'], $leg['odds'], $leg['edge'], $legStake, $leg['prediction_id']]);
                }
                $target = $ticket['stake'];
                $bets[] = [
                    'fight_id' => $firstFightId,
                    'selection' => $ticket['label'],
                    'odds' => $ticket['combined_odds'],
                    'probability' => $ticket['combined_p'],
                    'edge' => $ticket['combined_edge'],
                    'stake' => $ticket['stake'],
                    'market' => $ticket['market'],
                    'legs' => $ticket['legs'],
                    'possible_payout' => $ticket['possible_payout'],
                    'possible_profit' => $ticket['possible_profit'],
                ];
            } else {
                foreach ($existingTickets as $duplicate) {
                    $updateTicket->execute([(int) $duplicate['fight_id'], $duplicate['selection'], $duplicate['market'], $duplicate['odds'], 0, $duplicate['id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
        $skipped = $analyzedCount;
        if ($ticket !== null) {
            $skipped = $analyzedCount - count($ticket['legs']);
        }
        return [
            'event_id' => $eventId,
            'mode' => 'ticket',
            'total_stake' => $target,
            'minimum_required' => EstaveTicketService::MIN_LEGS,
            'skipped' => $skipped,
            'ticket' => $ticket,
            'bets' => $bets,
        ];
    }

    private function hydratePrediction(array $prediction): array
    {
        $prediction['p_a'] = (float) $prediction['p_a'];
        $prediction['p_b'] = (float) $prediction['p_b'];
        $prediction['confidence'] = (float) $prediction['confidence'];
        $prediction['edge'] = $prediction['edge'] === null ? null : (float) $prediction['edge'];
        $prediction['stake'] = (float) $prediction['stake'];
        $prediction['method_probs'] = json_decode($prediction['method_probs_json'], true) ?: [];
        unset($prediction['raw_json'], $prediction['method_probs_json']);
        return $prediction;
    }
}
