<?php
declare(strict_types=1);

final class BacktestService
{
    public const BATCH_NAME = 'Jev E-Stave kombinacija — 20 UFC dogodkov';
    public const SINGLES_BATCH_NAME = 'Jev concentrated full-card — 20 UFC dogodkov';
    private const DATA_FILE = __DIR__ . '/../data/ultimate_ufc_dataset.csv';
    private const EVENT_LIMIT = 20;
    private const STARTING_BANKROLL = 500.0;
    private const PROGRESS_KEY = 'backtest_v6_estave_progress';
    private const MIN_EDGE = 0.05;
    private const MIN_CONFIDENCE = 0.55;

    public function __construct(private readonly Database $database) {}

    public function step(string $apiKey, string $mode = 'ticket'): array
    {
        if ($mode === 'single') {
            $existingSingles = $this->existing(self::SINGLES_BATCH_NAME);
            if ($existingSingles !== null) {
                return ['complete' => true, 'backtest' => $existingSingles, 'already_ran' => true];
            }
            throw new RuntimeException('Single bet backtest ni shranjen.');
        }
        $existing = $this->existing(self::BATCH_NAME);
        if ($existing !== null) {
            return ['complete' => true, 'backtest' => $existing, 'already_ran' => true];
        }
        $progress = $this->database->setting(self::PROGRESS_KEY);
        if (!is_array($progress) || ($progress['version'] ?? null) !== 6) {
            $reused = $this->reusableEvaluations();
            if ($reused !== null) {
                $progress = ['version' => 6, 'next' => self::EVENT_LIMIT, 'events' => [], 'results' => $reused['events'], 'model' => $reused['model']];
            } else {
                $progress = ['version' => 6, 'next' => 0, 'events' => $this->loadEvents(), 'results' => [], 'model' => 'jev-latest'];
            }
            $this->database->setSetting(self::PROGRESS_KEY, $progress);
        }
        $next = (int) ($progress['next'] ?? 0);
        $events = $progress['events'] ?? [];
        if (!isset($events[$next])) {
            return ['complete' => true, 'backtest' => $this->finish($progress)];
        }
        $evaluated = $this->evaluateEvent($events[$next], $apiKey);
        $progress['results'][] = $evaluated;
        $progress['model'] = $evaluated['model'] ?? $progress['model'];
        $progress['next'] = $next + 1;
        $this->database->setSetting(self::PROGRESS_KEY, $progress);
        if ($progress['next'] >= count($events)) {
            return ['complete' => true, 'backtest' => $this->finish($progress)];
        }
        return ['complete' => false, 'completed_events' => $progress['next'], 'total_events' => count($events), 'last_event' => $evaluated['event_date']];
    }

    public function hydrate(array $row): array
    {
        foreach (['total_fights', 'correct_winner', 'correct_method'] as $field) $row[$field] = (int) $row[$field];
        foreach (['brier', 'total_stake', 'profit'] as $field) $row[$field] = (float) $row[$field];
        $payload = json_decode((string) $row['results_json'], true) ?: [];
        unset($row['results_json']);
        if (isset($payload['version'])) return array_merge($row, $payload);
        $row['version'] = 1; $row['results'] = $payload; $row['event_count'] = 1;
        return $row;
    }

    public function loadNamed(string $batchName): ?array
    {
        return $this->existing($batchName);
    }

    private function existing(string $batchName): ?array
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM backtest_runs WHERE event_name=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$batchName]);
        $row = $stmt->fetch();
        if ($row) {
            return $this->hydrate($row);
        }
        return null;
    }

    private function reusableEvaluations(): ?array
    {
        $rows = $this->database->pdo()->query('SELECT * FROM backtest_runs ORDER BY id DESC')->fetchAll();
        foreach ($rows as $row) {
            $test = $this->hydrate($row);
            if ((int) ($test['version'] ?? 0) < 4 || empty($test['results'])) {
                continue;
            }
            $grouped = [];
            foreach ($test['results'] as $fight) {
                if (!isset($fight['event_date'], $fight['bet'], $fight['confidence'])) {
                    continue;
                }
                $date = $fight['event_date'];
                $grouped[$date]['event_date'] = $date;
                $grouped[$date]['location'] = $fight['location'] ?? '';
                $grouped[$date]['fights'][] = $fight;
            }
            if (count($grouped) === self::EVENT_LIMIT) {
                return ['model' => $test['model'] ?? 'jev-latest', 'events' => array_values($grouped)];
            }
        }
        return null;
    }

    private function loadEvents(): array
    {
        if (!is_file(self::DATA_FILE)) throw new RuntimeException('Manjka lokalni dataset z zgodovinskimi UFC kvotami.');
        $handle = fopen(self::DATA_FILE, 'rb');
        if ($handle === false) throw new RuntimeException('Dataseta za backtest ni mogoče odpreti.');
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        $grouped = [];
        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($values) !== count($header)) continue;
            $row = array_combine($header, $values);
            $date = (string) ($row['date'] ?? '');
            if ($date === '' || $date >= date('Y-m-d') || !$this->validAmericanOdds($row['r_odds'] ?? null) || !$this->validAmericanOdds($row['b_odds'] ?? null)) continue;
            if (!in_array($row['winner'] ?? '', ['Red', 'Blue'], true)) continue;
            if (!isset($grouped[$date]) && count($grouped) >= self::EVENT_LIMIT) continue;
            $grouped[$date][] = $this->caseFromRow($row);
        }
        fclose($handle);
        $events = [];
        foreach ($grouped as $date => $fights) {
            if (count($fights) < 6) continue;
            $events[] = ['event_date' => $date, 'location' => $fights[0]['location'], 'fights' => $fights];
        }
        $events = array_slice($events, 0, self::EVENT_LIMIT);
        usort($events, static fn(array $a, array $b): int => strcmp($a['event_date'], $b['event_date']));
        if (count($events) < self::EVENT_LIMIT) throw new RuntimeException('Dataset nima dovolj popolnih dogodkov s kvotami za 20-eventni test.');
        return $events;
    }

    private function caseFromRow(array $row): array
    {
        return [
            'fighter_a' => $row['r_fighter'], 'fighter_b' => $row['b_fighter'], 'weight_class' => $row['weight_class'],
            'location' => trim((string) $row['location'], ' "'),
            'odds_a' => $this->americanToDecimal((float) $row['r_odds']), 'odds_b' => $this->americanToDecimal((float) $row['b_odds']),
            'actual_winner' => $row['winner'] === 'Red' ? $row['r_fighter'] : $row['b_fighter'],
            'actual_method' => $this->methodCategory((string) $row['finish']),
            'fighter_a_profile' => $this->profile($row, 'r'), 'fighter_b_profile' => $this->profile($row, 'b'),
        ];
    }

    private function profile(array $row, string $corner): array
    {
        $fields = ['wins', 'losses', 'draw', 'current_win_streak', 'current_lose_streak', 'longest_win_streak', 'avg_sig_str_landed', 'avg_sig_str_pct', 'avg_td_landed', 'avg_td_pct', 'avg_sub_att', 'height_cms', 'reach_cms', 'age', 'stance'];
        $profile = ['name' => $row[$corner . '_fighter']];
        foreach ($fields as $field) {
            $value = $row[$corner . '_' . $field] ?? null;
            $profile[$field] = $value === 'NA' || $value === '' ? null : (is_numeric($value) ? (float) $value : $value);
        }
        return $profile;
    }

    private function evaluateEvent(array $event, string $apiKey): array
    {
        $stateFights = []; $questions = [];
        foreach ($event['fights'] as $index => $fight) {
            $stateFights[] = ['id' => $index, 'weight_class' => $fight['weight_class'], 'fighter_a' => $fight['fighter_a_profile'], 'fighter_b' => $fight['fighter_b_profile']];
            $questions['winner_' . $index] = ['type' => 'choice', 'instructions' => 'Fight ' . $index . ': who is more likely to win?', 'criteria' => ['fighter_a' => $fight['fighter_a'] . ' wins', 'fighter_b' => $fight['fighter_b'] . ' wins']];
            $questions['method_' . $index] = ['type' => 'choice', 'instructions' => 'Fight ' . $index . ': which complete winner and method outcome is most likely?', 'criteria' => $this->methodCriteria($fight)];
        }
        $state = ['task' => 'Historical walk-forward UFC prediction. Use only these pre-fight performance features. Results and betting odds are deliberately withheld so the probability is independent of the market. Unknown is not zero. Avoid false precision and keep probabilities calibrated.', 'event' => ['date' => $event['event_date'], 'location' => $event['location']], 'fights' => $stateFights];
        $response = (new JevClient($apiKey))->evaluate(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $questions);
        $results = [];
        foreach ($event['fights'] as $index => $fight) {
            $answer = $response['answers']['winner_' . $index] ?? [];
            $pA = (float) ($answer['probabilities']['fighter_a'] ?? .5); $pB = (float) ($answer['probabilities']['fighter_b'] ?? .5);
            $sum = max(.0001, $pA + $pB); $pA /= $sum; $pB /= $sum;
            $pick = $pA >= $pB ? $fight['fighter_a'] : $fight['fighter_b'];
            $criteria = $this->methodCriteria($fight); $methodAnswer = $response['answers']['method_' . $index] ?? []; $methodKey = (string) ($methodAnswer['choice'] ?? '');
            $options = [['selection' => $fight['fighter_a'], 'p' => $pA, 'odds' => $fight['odds_a']], ['selection' => $fight['fighter_b'], 'p' => $pB, 'odds' => $fight['odds_b']]];
            foreach ($options as &$option) $option['edge'] = $option['p'] - (1 / $option['odds']);
            unset($option);
            $bet = $options[0]['edge'] >= $options[1]['edge'] ? $options[0] : $options[1];
            $favorite = $fight['odds_a'] <= $fight['odds_b'] ? $fight['fighter_a'] : $fight['fighter_b'];
            $results[] = $fight + ['pick' => $pick, 'p_a' => $pA, 'p_b' => $pB, 'confidence' => (float) ($answer['confidence'] ?? max($pA, $pB)), 'method_pick' => $criteria[$methodKey] ?? $methodKey, 'bet' => $bet, 'favorite' => $favorite, 'favorite_odds' => min($fight['odds_a'], $fight['odds_b'])];
        }
        return ['event_date' => $event['event_date'], 'location' => $event['location'], 'model' => (string) ($response['model'] ?? 'jev-latest'), 'fights' => $results];
    }

    private function finish(array $progress): array
    {
        $engine = new EstaveTicketService();
        $all = [];
        $eventSummaries = [];
        $bankroll = self::STARTING_BANKROLL;
        $peak = $bankroll;
        $maxDrawdown = 0.0;
        $maxDrawdownPct = 0.0;
        $lossStreak = 0;
        $maxLossStreak = 0;
        $favoriteProfit = 0.0;
        $favoriteStake = 0.0;
        $totalStake = 0.0;
        $profit = 0.0;
        $ticketCount = 0;
        $ticketWins = 0;
        $singlesBankroll = self::STARTING_BANKROLL;
        $singlesStakeTotal = 0.0;
        $singlesProfitTotal = 0.0;
        $singlesBets = 0;
        foreach ($progress['results'] as $event) {
            $rows = $event['fights'];
            $singlesRows = $this->allocateEventSingles($event['fights'], $singlesBankroll);
            $ticketLegs = $engine->legsFromBacktestFights($rows, self::MIN_EDGE, self::MIN_CONFIDENCE);
            $ticket = $engine->buildTicket($ticketLegs, $bankroll, [
                'kelly_fraction' => 0.5,
                'max_bet_fraction' => 0.15,
                'max_event_fraction' => 0.35,
                'available' => $bankroll,
            ]);
            $onTicket = [];
            if ($ticket !== null) {
                foreach ($ticket['legs'] as $leg) {
                    $onTicket[(int) $leg['index']] = true;
                }
            }
            $eventProfit = 0.0;
            $eventStake = 0.0;
            $eventFavoriteProfit = 0.0;
            $correct = 0;
            $correctMethod = 0;
            $eventBets = 0;
            $ticketWon = false;
            foreach ($rows as $index => &$row) {
                $row['on_ticket'] = isset($onTicket[$index]);
                $row['stake'] = 0.0;
                $row['bet_profit'] = 0.0;
                $favoriteWon = $row['favorite'] === $row['actual_winner'];
                $row['favorite_stake'] = 10.0;
                if ($favoriteWon) {
                    $row['favorite_profit'] = round(10 * ($row['favorite_odds'] - 1), 2);
                } else {
                    $row['favorite_profit'] = -10.0;
                }
                $eventFavoriteProfit += $row['favorite_profit'];
                if ($row['pick'] === $row['actual_winner']) {
                    $correct++;
                }
                if ($row['pick'] === $row['actual_winner'] && $this->methodCategory($row['method_pick']) === $row['actual_method']) {
                    $correctMethod++;
                }
                unset($row['fighter_a_profile'], $row['fighter_b_profile']);
                $all[] = ['event_date' => $event['event_date'], 'location' => $event['location']] + $row;
            }
            unset($row);
            if ($ticket !== null) {
                $settled = $engine->settleTicket($ticket);
                $ticketWon = $settled['won'];
                $eventStake = $ticket['stake'];
                $eventProfit = $settled['profit'];
                $eventBets = 1;
                $ticketCount++;
                if ($ticketWon) {
                    $ticketWins++;
                    $lossStreak = 0;
                } else {
                    $lossStreak++;
                    $maxLossStreak = max($maxLossStreak, $lossStreak);
                }
            }
            $singlesEventProfit = 0.0;
            $singlesEventStake = 0.0;
            foreach ($singlesRows as $singleRow) {
                $singleStake = (float) $singleRow['stake'];
                if ($singleStake <= 0) {
                    continue;
                }
                $singlesBets++;
                $singlesEventStake += $singleStake;
                if ($singleRow['bet']['selection'] === $singleRow['actual_winner']) {
                    $singlesEventProfit += $singleStake * ($singleRow['bet']['odds'] - 1);
                } else {
                    $singlesEventProfit -= $singleStake;
                }
            }
            $singlesBankroll = round($singlesBankroll + $singlesEventProfit, 2);
            $singlesStakeTotal += $singlesEventStake;
            $singlesProfitTotal += $singlesEventProfit;
            $bankroll = round($bankroll + $eventProfit, 2);
            $peak = max($peak, $bankroll);
            $drawdown = $peak - $bankroll;
            $maxDrawdown = max($maxDrawdown, $drawdown);
            if ($peak > 0) {
                $maxDrawdownPct = max($maxDrawdownPct, $drawdown / $peak);
            }
            $favoriteProfit += $eventFavoriteProfit;
            $favoriteStake += count($rows) * 10;
            $totalStake += $eventStake;
            $profit += $eventProfit;
            $ticketPayload = null;
            if ($ticket !== null) {
                $ticketPayload = [
                    'type' => $ticket['type'],
                    'system' => $ticket['system'] ?? null,
                    'label' => $ticket['label'],
                    'leg_count' => $ticket['leg_count'],
                    'combined_odds' => $ticket['combined_odds'],
                    'combined_p' => $ticket['combined_p'],
                    'combined_edge' => $ticket['combined_edge'],
                    'stake' => $ticket['stake'],
                    'unit_stake' => $ticket['unit_stake'],
                    'combos' => $ticket['combos'] ?? [],
                    'possible_payout' => $ticket['possible_payout'],
                    'won' => $ticketWon,
                    'profit' => round($eventProfit, 2),
                    'legs' => array_map(static function (array $leg): array {
                        return [
                            'selection' => $leg['selection'],
                            'odds' => $leg['odds'],
                            'p' => $leg['p'],
                            'edge' => $leg['edge'],
                            'fighter_a' => $leg['fighter_a'],
                            'fighter_b' => $leg['fighter_b'],
                            'actual_winner' => $leg['actual_winner'],
                            'hit' => $leg['selection'] === $leg['actual_winner'],
                        ];
                    }, $ticket['legs']),
                ];
            }
            $eventSummaries[] = [
                'event_date' => $event['event_date'],
                'location' => $event['location'],
                'correct_winner' => $correct,
                'correct_method' => $correctMethod,
                'total_fights' => count($rows),
                'bets' => $eventBets,
                'skipped' => count($rows) - count($onTicket),
                'stake' => round($eventStake, 2),
                'profit' => round($eventProfit, 2),
                'favorite_profit' => round($eventFavoriteProfit, 2),
                'ending_bankroll' => $bankroll,
                'ticket' => $ticketPayload,
                'singles_profit' => round($singlesEventProfit, 2),
                'singles_stake' => round($singlesEventStake, 2),
            ];
        }
        $correct = 0;
        $methods = 0;
        $brier = 0.0;
        foreach ($all as $row) {
            if ($row['pick'] === $row['actual_winner']) {
                $correct++;
            }
            if ($row['pick'] === $row['actual_winner'] && $this->methodCategory($row['method_pick']) === $row['actual_method']) {
                $methods++;
            }
            if ($row['actual_winner'] === $row['fighter_a']) {
                $actualA = 1.0;
            } else {
                $actualA = 0.0;
            }
            $brier += ($row['p_a'] - $actualA) ** 2;
        }
        $totalStake = round($totalStake, 2);
        $profit = round($profit, 2);
        $singlesStakeTotal = round($singlesStakeTotal, 2);
        $singlesProfitTotal = round($singlesProfitTotal, 2);
        $payload = [
            'version' => 6,
            'event_count' => count($eventSummaries),
            'card_scope' => 'full_card',
            'independent_from_market' => true,
            'staking' => 'E-Stave sistem 2/3 ali kombinacija 2, ½ Kelly',
            'market' => 'estave_ticket',
            'bet_count' => $ticketCount,
            'ticket_wins' => $ticketWins,
            'skipped_count' => count($all) - array_sum(array_map(static function (array $event): int {
                if (empty($event['ticket'])) {
                    return 0;
                }
                return count($event['ticket']['legs']);
            }, $eventSummaries)),
            'results' => $all,
            'event_summaries' => $eventSummaries,
            'favorite' => ['total_stake' => round($favoriteStake, 2), 'profit' => round($favoriteProfit, 2)],
            'selective' => ['total_stake' => $totalStake, 'profit' => $profit],
            'singles_counterfactual' => [
                'total_stake' => $singlesStakeTotal,
                'profit' => $singlesProfitTotal,
                'bet_count' => $singlesBets,
                'ending_bankroll' => round($singlesBankroll, 2),
            ],
            'risk' => [
                'starting_bankroll' => self::STARTING_BANKROLL,
                'ending_bankroll' => $bankroll,
                'max_drawdown' => round($maxDrawdown, 2),
                'max_drawdown_pct' => $maxDrawdownPct,
                'max_loss_streak' => $maxLossStreak,
            ],
        ];
        $stmt = $this->database->pdo()->prepare('INSERT INTO backtest_runs(event_name,event_date,model,total_fights,correct_winner,correct_method,brier,total_stake,profit,results_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $lastEvent = end($eventSummaries);
        $stmt->execute([self::BATCH_NAME, $lastEvent['event_date'], $progress['model'] ?? 'jev-latest', count($all), $correct, $methods, $brier / max(1, count($all)), $totalStake, $profit, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $this->database->setSetting(self::PROGRESS_KEY, null);
        $saved = $this->database->pdo()->prepare('SELECT * FROM backtest_runs WHERE id=?');
        $saved->execute([(int) $this->database->pdo()->lastInsertId()]);
        return $this->hydrate($saved->fetch());
    }

    private function allocateEventSingles(array $rows, float $bankroll): array
    {
        $out = [];
        $desired = 0.0;
        foreach ($rows as $row) {
            $kelly = max(0.0, (($row['bet']['p'] * $row['bet']['odds']) - 1) / max(.01, $row['bet']['odds'] - 1));
            if ($row['bet']['edge'] >= self::MIN_EDGE && $row['confidence'] >= self::MIN_CONFIDENCE) {
                $row['stake'] = $bankroll * .5 * $kelly * $row['confidence'];
            } else {
                $row['stake'] = 0.0;
            }
            $desired += $row['stake'];
            $out[] = $row;
        }
        $cap = $bankroll * .35;
        if ($desired > $cap && $desired > 0) {
            $scale = $cap / $desired;
        } else {
            $scale = 1.0;
        }
        foreach ($out as &$row) {
            $row['stake'] = floor(min($row['stake'] * $scale, $bankroll * .15) * 100) / 100;
        }
        unset($row);
        return $out;
    }

    private function methodCriteria(array $fight): array
    {
        return ['a_ko_tko' => $fight['fighter_a'] . ' wins by KO/TKO', 'a_submission' => $fight['fighter_a'] . ' wins by submission', 'a_decision' => $fight['fighter_a'] . ' wins by decision', 'b_ko_tko' => $fight['fighter_b'] . ' wins by KO/TKO', 'b_submission' => $fight['fighter_b'] . ' wins by submission', 'b_decision' => $fight['fighter_b'] . ' wins by decision'];
    }

    private function validAmericanOdds(mixed $value): bool { return $value !== null && $value !== '' && $value !== 'NA' && is_numeric($value) && (float) $value !== 0.0; }
    private function americanToDecimal(float $odds): float { return round($odds > 0 ? 1 + ($odds / 100) : 1 + (100 / abs($odds)), 6); }
    private function methodCategory(string $method): string { $method = strtolower($method); if (str_contains($method, 'sub')) return 'submission'; if (str_contains($method, 'dec') || str_contains($method, 'judge')) return 'decision'; return 'ko_tko'; }
}
