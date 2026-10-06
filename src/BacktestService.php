<?php
declare(strict_types=1);

final class BacktestService
{
    public const BATCH_NAME = 'Jev concentrated full-card — 20 UFC dogodkov';
    private const DATA_FILE = __DIR__ . '/../data/ultimate_ufc_dataset.csv';
    private const EVENT_LIMIT = 20;
    private const STARTING_BANKROLL = 500.0;
    private const PROGRESS_KEY = 'backtest_v5_progress';

    public function __construct(private readonly Database $database) {}

    public function step(string $apiKey): array
    {
        $existing = $this->existing();
        if ($existing !== null) return ['complete' => true, 'backtest' => $existing, 'already_ran' => true];
        $progress = $this->database->setting(self::PROGRESS_KEY);
        if (!is_array($progress) || ($progress['version'] ?? null) !== 5) {
            $reused=$this->reusableEvaluations();
            $progress = $reused!==null
                ? ['version'=>5,'next'=>self::EVENT_LIMIT,'events'=>[],'results'=>$reused['events'],'model'=>$reused['model']]
                : ['version' => 5, 'next' => 0, 'events' => $this->loadEvents(), 'results' => [], 'model' => 'jev-latest'];
            $this->database->setSetting(self::PROGRESS_KEY, $progress);
        }
        $next = (int) ($progress['next'] ?? 0);
        $events = $progress['events'] ?? [];
        if (!isset($events[$next])) return ['complete' => true, 'backtest' => $this->finish($progress)];
        $evaluated = $this->evaluateEvent($events[$next], $apiKey);
        $progress['results'][] = $evaluated;
        $progress['model'] = $evaluated['model'] ?? $progress['model'];
        $progress['next'] = $next + 1;
        $this->database->setSetting(self::PROGRESS_KEY, $progress);
        if ($progress['next'] >= count($events)) return ['complete' => true, 'backtest' => $this->finish($progress)];
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

    private function existing(): ?array
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM backtest_runs WHERE event_name=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([self::BATCH_NAME]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    private function reusableEvaluations(): ?array
    {
        $row=$this->database->pdo()->query('SELECT * FROM backtest_runs ORDER BY id DESC LIMIT 1')->fetch();
        if(!$row)return null;
        $test=$this->hydrate($row);
        if((int)($test['version']??0)<4||empty($test['results']))return null;
        $grouped=[];
        foreach($test['results'] as $fight){$date=$fight['event_date'];$grouped[$date]['event_date']=$date;$grouped[$date]['location']=$fight['location'];$grouped[$date]['fights'][]=$fight;}
        return count($grouped)===self::EVENT_LIMIT?['model'=>$test['model'],'events'=>array_values($grouped)]:null;
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
        $all = []; $eventSummaries = []; $bankroll = self::STARTING_BANKROLL; $peak = $bankroll;
        $maxDrawdown = $maxDrawdownPct = 0.0; $lossStreak = $maxLossStreak = 0;
        $favoriteProfit = $favoriteStake = $totalStake = $profit = 0.0; $betCount = 0;
        foreach ($progress['results'] as $event) {
            $rows = $this->allocateEvent($event['fights'], $bankroll);
            $eventProfit = $eventStake = $eventFavoriteProfit = 0.0; $correct = $correctMethod = $eventBets = 0;
            foreach ($rows as &$row) {
                $won = $row['bet']['selection'] === $row['actual_winner'];
                $row['bet_profit'] = $won ? round($row['stake'] * ($row['bet']['odds'] - 1), 2) : -$row['stake'];
                $favoriteWon = $row['favorite'] === $row['actual_winner']; $row['favorite_stake'] = 10.0;
                $row['favorite_profit'] = $favoriteWon ? round(10 * ($row['favorite_odds'] - 1), 2) : -10.0;
                $eventProfit += $row['bet_profit']; $eventStake += $row['stake']; $eventFavoriteProfit += $row['favorite_profit'];
                $correct += (int) ($row['pick'] === $row['actual_winner']);
                $correctMethod += (int) ($row['pick'] === $row['actual_winner'] && $this->methodCategory($row['method_pick']) === $row['actual_method']);
                if ($row['stake'] > 0) {
                    $eventBets++; $betCount++;
                    if ($row['bet_profit'] < 0) { $lossStreak++; $maxLossStreak = max($maxLossStreak, $lossStreak); } else $lossStreak = 0;
                }
                unset($row['fighter_a_profile'], $row['fighter_b_profile']);
                $all[] = ['event_date' => $event['event_date'], 'location' => $event['location']] + $row;
            }
            unset($row);
            $bankroll = round($bankroll + $eventProfit, 2); $peak = max($peak, $bankroll); $drawdown = $peak - $bankroll;
            $maxDrawdown = max($maxDrawdown, $drawdown); $maxDrawdownPct = max($maxDrawdownPct, $peak > 0 ? $drawdown / $peak : 0);
            $favoriteProfit += $eventFavoriteProfit; $favoriteStake += count($rows)*10; $totalStake += $eventStake; $profit += $eventProfit;
            $eventSummaries[] = ['event_date' => $event['event_date'], 'location' => $event['location'], 'correct_winner' => $correct, 'correct_method' => $correctMethod, 'total_fights' => count($rows), 'bets' => $eventBets, 'skipped' => count($rows)-$eventBets, 'stake' => round($eventStake,2), 'profit' => round($eventProfit, 2), 'favorite_profit' => round($eventFavoriteProfit, 2), 'ending_bankroll' => $bankroll];
        }
        $correct = $methods = 0; $brier = 0.0;
        foreach ($all as $row) {
            $correct += (int) ($row['pick'] === $row['actual_winner']);
            $methods += (int) ($row['pick'] === $row['actual_winner'] && $this->methodCategory($row['method_pick']) === $row['actual_method']);
            $actualA = $row['actual_winner'] === $row['fighter_a'] ? 1.0 : 0.0; $brier += ($row['p_a'] - $actualA) ** 2;
        }
        $totalStake=round($totalStake,2);$profit=round($profit,2);
        $payload = ['version' => 5, 'event_count' => count($eventSummaries), 'card_scope' => 'full_card', 'independent_from_market' => true, 'staking' => 'confidence-weighted half Kelly', 'bet_count' => $betCount, 'skipped_count' => count($all)-$betCount, 'results' => $all, 'event_summaries' => $eventSummaries, 'favorite' => ['total_stake' => round($favoriteStake,2), 'profit' => round($favoriteProfit, 2)], 'selective' => ['total_stake' => $totalStake, 'profit' => $profit], 'risk' => ['starting_bankroll' => self::STARTING_BANKROLL, 'ending_bankroll' => $bankroll, 'max_drawdown' => round($maxDrawdown, 2), 'max_drawdown_pct' => $maxDrawdownPct, 'max_loss_streak' => $maxLossStreak]];
        $stmt = $this->database->pdo()->prepare('INSERT INTO backtest_runs(event_name,event_date,model,total_fights,correct_winner,correct_method,brier,total_stake,profit,results_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([self::BATCH_NAME, end($eventSummaries)['event_date'], $progress['model'] ?? 'jev-latest', count($all), $correct, $methods, $brier / max(1, count($all)), $totalStake, $profit, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $this->database->setSetting(self::PROGRESS_KEY, null);
        $saved = $this->database->pdo()->prepare('SELECT * FROM backtest_runs WHERE id=?'); $saved->execute([(int) $this->database->pdo()->lastInsertId()]);
        return $this->hydrate($saved->fetch());
    }

    private function allocateEvent(array $rows, float $bankroll): array
    {
        $desired=0.0;
        foreach($rows as &$row){
            $kelly=max(0.0,(($row['bet']['p']*$row['bet']['odds'])-1)/max(.01,$row['bet']['odds']-1));
            $row['raw_stake']=$row['bet']['edge']>=.05&&$row['confidence']>=.55?$bankroll*.5*$kelly*$row['confidence']:0.0;
            $desired+=$row['raw_stake'];
        }
        unset($row);
        $cap=$bankroll*.35;
        $scale=$desired>$cap&&$desired>0?$cap/$desired:1.0;
        foreach($rows as &$row)$row['stake']=floor(min($row['raw_stake']*$scale,$bankroll*.15)*100)/100;
        unset($row);
        return $rows;
    }

    private function methodCriteria(array $fight): array
    {
        return ['a_ko_tko' => $fight['fighter_a'] . ' wins by KO/TKO', 'a_submission' => $fight['fighter_a'] . ' wins by submission', 'a_decision' => $fight['fighter_a'] . ' wins by decision', 'b_ko_tko' => $fight['fighter_b'] . ' wins by KO/TKO', 'b_submission' => $fight['fighter_b'] . ' wins by submission', 'b_decision' => $fight['fighter_b'] . ' wins by decision'];
    }

    private function validAmericanOdds(mixed $value): bool { return $value !== null && $value !== '' && $value !== 'NA' && is_numeric($value) && (float) $value !== 0.0; }
    private function americanToDecimal(float $odds): float { return round($odds > 0 ? 1 + ($odds / 100) : 1 + (100 / abs($odds)), 6); }
    private function methodCategory(string $method): string { $method = strtolower($method); if (str_contains($method, 'sub')) return 'submission'; if (str_contains($method, 'dec') || str_contains($method, 'judge')) return 'decision'; return 'ko_tko'; }
}
