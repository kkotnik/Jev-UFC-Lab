<?php
declare(strict_types=1);

final class EventReviewService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function completeFromResults(int $eventId): array
    {
        $pdo = $this->database->pdo();
        $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            throw new InvalidArgumentException('Dogodek ne obstaja.');
        }

        (new UfcStatsSync($pdo))->sync(300);
        $eventDate = substr((string) $event['event_date'], 0, 10);
        $from = date('Y-m-d', strtotime($eventDate . ' -1 day'));
        $to = date('Y-m-d', strtotime($eventDate . ' +1 day'));
        $historyStmt = $pdo->prepare('SELECT * FROM historical_fights WHERE event_date BETWEEN ? AND ? AND winner IS NOT NULL');
        $historyStmt->execute([$from, $to]);
        $history = $historyStmt->fetchAll();
        $fightsStmt = $pdo->prepare('SELECT * FROM fights WHERE event_id=? ORDER BY card_order');
        $fightsStmt->execute([$eventId]);
        $fights = $fightsStmt->fetchAll();
        $matched = 0;

        $pdo->beginTransaction();
        try {
            foreach ($fights as $fight) {
                if ((int) $fight['completed'] === 1) {
                    $matched++;
                    continue;
                }
                foreach ($history as $actual) {
                    if (!$this->samePair($fight, $actual)) {
                        continue;
                    }
                    $winner = $this->normalize($actual['winner']) === $this->normalize($fight['fighter_a']) ? $fight['fighter_a'] : $fight['fighter_b'];
                    $update = $pdo->prepare('UPDATE fights SET winner=?,method=?,result_round=?,completed=1 WHERE id=?');
                    $update->execute([$winner, $actual['method'], $actual['result_round'], $fight['id']]);
                    $this->settleBets((int) $fight['id'], $winner);
                    $matched++;
                    break;
                }
            }
            if ($matched === 0) {
                throw new RuntimeException('Dejanski rezultati za ta datum še niso v viru. Poskusi znova kasneje ali rezultate vnesi ročno pri posamezni borbi.');
            }
            $status = $matched === count($fights) ? 'completed' : 'results_partial';
            $statusStmt = $pdo->prepare('UPDATE events SET status=? WHERE id=?');
            $statusStmt->execute([$status, $eventId]);
            $pdo->commit();
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
        return $this->buildReport($eventId);
    }

    public function buildReport(int $eventId): array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare('SELECT f.*,p.winner_pick,p.p_a,p.p_b,p.method_pick FROM fights f LEFT JOIN predictions p ON p.id=(SELECT id FROM predictions WHERE fight_id=f.id ORDER BY id DESC LIMIT 1) WHERE f.event_id=? AND f.completed=1');
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll();
        $correct = $methods = $predictions = 0;
        $brierSum = 0.0;
        $details = [];
        foreach ($rows as $row) {
            if ($row['winner_pick'] === null) continue;
            $predictions++;
            $winnerCorrect = $row['winner_pick'] === $row['winner'];
            if ($winnerCorrect) $correct++;
            $methodCorrect = $winnerCorrect && $this->methodCategory((string) $row['method_pick']) === $this->methodCategory((string) $row['method']);
            if ($methodCorrect) $methods++;
            $actualA = $row['winner'] === $row['fighter_a'] ? 1.0 : 0.0;
            $brierSum += (((float) $row['p_a'] - $actualA) ** 2);
            $details[] = ['fight' => $row['fighter_a'] . ' vs ' . $row['fighter_b'], 'pick' => $row['winner_pick'], 'actual' => $row['winner'], 'winner_correct' => $winnerCorrect, 'method_correct' => $methodCorrect];
        }
        $betStmt = $pdo->prepare('SELECT COALESCE(SUM(stake),0) stake,COALESCE(SUM(profit),0) profit FROM bets WHERE event_id=? AND owner="jev" AND result!="open"');
        $betStmt->execute([$eventId]);
        $bet = $betStmt->fetch();
        $stake = (float) $bet['stake'];
        $report = [
            'event_id' => $eventId, 'fights_settled' => count($rows), 'correct_winner' => $correct,
            'total_predictions' => $predictions, 'correct_method' => $methods,
            'profit' => (float) $bet['profit'], 'roi' => $stake > 0 ? (float) $bet['profit'] / $stake : 0,
            'brier' => $predictions > 0 ? $brierSum / $predictions : null, 'details' => $details,
        ];
        $existing = $pdo->prepare('SELECT id FROM event_reports WHERE event_id=? ORDER BY id DESC LIMIT 1');
        $existing->execute([$eventId]);
        $id = $existing->fetchColumn();
        if ($id) {
            $save = $pdo->prepare('UPDATE event_reports SET completed_at=CURRENT_TIMESTAMP,fights_settled=?,correct_winner=?,total_predictions=?,correct_method=?,profit=?,roi=?,brier=?,report_json=? WHERE id=?');
            $save->execute([$report['fights_settled'],$correct,$predictions,$methods,$report['profit'],$report['roi'],$report['brier'],json_encode($report, JSON_UNESCAPED_UNICODE),$id]);
        } else {
            $save = $pdo->prepare('INSERT INTO event_reports(event_id,fights_settled,correct_winner,total_predictions,correct_method,profit,roi,brier,report_json) VALUES(?,?,?,?,?,?,?,?,?)');
            $save->execute([$eventId,$report['fights_settled'],$correct,$predictions,$methods,$report['profit'],$report['roi'],$report['brier'],json_encode($report, JSON_UNESCAPED_UNICODE)]);
        }
        return $report;
    }

    private function settleBets(int $fightId, string $winner): void
    {
        $stmt = $this->database->pdo()->prepare('SELECT * FROM bets WHERE fight_id=? AND result="open"');
        $stmt->execute([$fightId]);
        $update = $this->database->pdo()->prepare('UPDATE bets SET result=?,profit=? WHERE id=?');
        foreach ($stmt->fetchAll() as $bet) {
            $won = $bet['selection'] === $winner;
            $profit = $won ? (float) $bet['stake'] * ((float) $bet['odds'] - 1) : -(float) $bet['stake'];
            $update->execute([$won ? 'win' : 'loss', round($profit, 2), $bet['id']]);
        }
        $pred = $this->database->pdo()->prepare('UPDATE predictions SET status="settled",profit=COALESCE((SELECT profit FROM bets WHERE owner="jev" AND fight_id=? ORDER BY id DESC LIMIT 1),0) WHERE fight_id=? AND status="open"');
        $pred->execute([$fightId, $fightId]);
    }

    private function samePair(array $fight, array $actual): bool
    {
        $a = $this->normalize($fight['fighter_a']); $b = $this->normalize($fight['fighter_b']);
        $x = $this->normalize($actual['fighter_a']); $y = $this->normalize($actual['fighter_b']);
        return ($a === $x && $b === $y) || ($a === $y && $b === $x);
    }

    private function normalize(?string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $name);
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii === false ? (string) $name : $ascii));
    }

    private function methodCategory(string $method): string
    {
        $method = strtolower($method);
        if (str_contains($method, 'sub')) return 'submission';
        if (str_contains($method, 'decision') || str_contains($method, 'judges')) return 'decision';
        return 'ko_tko';
    }
}
