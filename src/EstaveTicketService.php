<?php
declare(strict_types=1);

final class EstaveTicketService
{
    public const MIN_LEGS = 2;
    public const CANDIDATE_CAP = 8;
    public const MIN_COMBINED_ODDS = 1.10;
    public const MIN_STAKE = 0.50;
    public const MAX_TICKET_STAKE = 250.0;
    public const MAX_PAYOUT = 20000.0;
    public const WINNINGS_TAX_RATE = 0.15;
    public const WINNINGS_TAX_THRESHOLD = 300.0;

    private float $minStake = self::MIN_STAKE;
    private float $maxTicketStake = self::MAX_TICKET_STAKE;
    private float $maxPayout = self::MAX_PAYOUT;
    private int $moneyScale = 100;

    public function qualifyingLegs(array $rows, float $minEdge, float $minConfidence): array
    {
        $legs = [];
        foreach ($rows as $row) {
            $odds = (float) $row['odds'];
            $p = (float) $row['p'];
            $confidence = (float) $row['confidence'];
            if ($odds <= 1.0) {
                continue;
            }
            $edge = $p - (1 / $odds);
            $kelly = (($odds * $p) - 1) / max(0.01, $odds - 1);
            if ($edge < $minEdge) {
                continue;
            }
            if ($kelly <= 0) {
                continue;
            }
            if ($confidence < $minConfidence) {
                continue;
            }
            $leg = $row;
            $leg['odds'] = $odds;
            $leg['p'] = $p;
            $leg['edge'] = $edge;
            $leg['kelly'] = $kelly;
            $leg['confidence'] = $confidence;
            $legs[] = $leg;
        }
        usort($legs, static function (array $a, array $b): int {
            return $b['edge'] <=> $a['edge'];
        });
        if (count($legs) > self::CANDIDATE_CAP) {
            $legs = array_slice($legs, 0, self::CANDIDATE_CAP);
        }
        return array_values($legs);
    }

    public function legsFromBacktestFights(array $rows, float $minEdge, float $minConfidence): array
    {
        $mapped = [];
        foreach ($rows as $index => $row) {
            $mapped[] = [
                'index' => $index,
                'fight_id' => $index,
                'selection' => $row['bet']['selection'],
                'odds' => (float) $row['bet']['odds'],
                'p' => (float) $row['bet']['p'],
                'confidence' => (float) $row['confidence'],
                'fighter_a' => $row['fighter_a'],
                'fighter_b' => $row['fighter_b'],
                'actual_winner' => $row['actual_winner'] ?? null,
            ];
        }
        return $this->qualifyingLegs($mapped, $minEdge, $minConfidence);
    }

    public function buildTicket(array $legs, float $bankroll, array $settings): ?array
    {
        if (count($legs) < self::MIN_LEGS) {
            return null;
        }
        $kellyFraction = (float) ($settings['kelly_fraction'] ?? 0.5);
        $maxBet = $bankroll * (float) ($settings['max_bet_fraction'] ?? 0.15);
        $eventCap = $bankroll * (float) ($settings['max_event_fraction'] ?? 0.35);
        $available = (float) ($settings['available'] ?? $bankroll);
        $this->minStake = self::MIN_STAKE;
        if (isset($settings['min_stake'])) {
            $this->minStake = (float) $settings['min_stake'];
        }
        $this->maxTicketStake = self::MAX_TICKET_STAKE;
        if (isset($settings['max_ticket_stake'])) {
            $this->maxTicketStake = (float) $settings['max_ticket_stake'];
        }
        $this->moneyScale = 100;
        if (isset($settings['money_scale'])) {
            $this->moneyScale = (int) $settings['money_scale'];
        }
        $this->maxPayout = self::MAX_PAYOUT;
        if (isset($settings['max_payout'])) {
            $this->maxPayout = (float) $settings['max_payout'];
        }
        $combo = $this->bestKombinacija($legs, $bankroll, $kellyFraction, $maxBet, $eventCap, $available);
        if (count($legs) < 3) {
            return $combo;
        }
        $system = $this->scoreSystem23(array_slice($legs, 0, 3), $bankroll, $kellyFraction, $eventCap, $available);
        if ($system === null) {
            return $combo;
        }
        if ($combo === null) {
            return $system;
        }
        if ($system['ev'] >= $combo['ev']) {
            return $system;
        }
        return $combo;
    }

    public function encodeSelection(array $ticket): string
    {
        $legs = [];
        foreach ($ticket['legs'] as $leg) {
            $legs[] = [
                'fight_id' => (int) $leg['fight_id'],
                'selection' => (string) $leg['selection'],
                'odds' => (float) $leg['odds'],
                'p' => (float) $leg['p'],
                'edge' => (float) $leg['edge'],
                'confidence' => (float) $leg['confidence'],
                'fighter_a' => (string) ($leg['fighter_a'] ?? ''),
                'fighter_b' => (string) ($leg['fighter_b'] ?? ''),
            ];
        }
        return json_encode([
            'type' => $ticket['type'],
            'system' => $ticket['system'] ?? null,
            'label' => $ticket['label'],
            'legs' => $legs,
            'combos' => $ticket['combos'] ?? [],
            'unit_stake' => $ticket['unit_stake'] ?? $ticket['stake'],
            'combined_p' => $ticket['combined_p'],
            'combined_edge' => $ticket['combined_edge'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function parseSelection(string $selection): ?array
    {
        $decoded = json_decode($selection, true);
        if (!is_array($decoded)) {
            return null;
        }
        $type = (string) ($decoded['type'] ?? '');
        if ($type !== 'kombinacija' && $type !== 'sistem') {
            return null;
        }
        if (empty($decoded['legs']) || !is_array($decoded['legs'])) {
            return null;
        }
        return $decoded;
    }

    public function settleTicket(array $ticket): array
    {
        $hits = [];
        foreach ($ticket['legs'] as $leg) {
            $hits[] = $leg['selection'] === ($leg['actual_winner'] ?? null);
        }
        $profit = $this->profitFromHits($ticket, $hits);
        $won = $profit > 0;
        return ['profit' => $profit, 'won' => $won, 'hits' => $hits];
    }

    public function settleOpenTickets(PDO $pdo, int $eventId): void
    {
        $fightStmt = $pdo->prepare('SELECT id, winner, completed FROM fights WHERE event_id=?');
        $fightStmt->execute([$eventId]);
        $fights = [];
        foreach ($fightStmt->fetchAll() as $fight) {
            $fights[(int) $fight['id']] = $fight;
        }
        $betStmt = $pdo->prepare('SELECT * FROM bets WHERE event_id=? AND owner="jev" AND market IN ("kombinacija","sistem") AND result="open"');
        $betStmt->execute([$eventId]);
        $update = $pdo->prepare('UPDATE bets SET result=?, profit=? WHERE id=?');
        $settlePrediction = $pdo->prepare('UPDATE predictions SET status="settled", profit=? WHERE fight_id=? AND status="open"');
        foreach ($betStmt->fetchAll() as $bet) {
            $ticket = $this->parseSelection((string) $bet['selection']);
            if ($ticket === null) {
                continue;
            }
            $ticket['stake'] = (float) $bet['stake'];
            if (!isset($ticket['unit_stake'])) {
                $ticket['unit_stake'] = (float) $bet['stake'];
            }
            $ticket['combined_odds'] = (float) $bet['odds'];
            $ready = $this->evaluateAgainstFights($ticket, $fights);
            if ($ready === null) {
                continue;
            }
            $profit = $ready['profit'];
            if ($ready['won']) {
                $result = 'win';
            } else {
                $result = 'loss';
            }
            $update->execute([$result, $profit, $bet['id']]);
            $first = true;
            foreach ($ticket['legs'] as $leg) {
                $legProfit = 0.0;
                if ($first) {
                    $legProfit = $profit;
                    $first = false;
                }
                $settlePrediction->execute([$legProfit, (int) $leg['fight_id']]);
            }
        }
    }

    private function evaluateAgainstFights(array $ticket, array $fights): ?array
    {
        $hits = [];
        $knownLosses = 0;
        $open = 0;
        foreach ($ticket['legs'] as $leg) {
            $fightId = (int) $leg['fight_id'];
            if (!isset($fights[$fightId])) {
                return ['profit' => round(-$ticket['stake'], 2), 'won' => false];
            }
            $fight = $fights[$fightId];
            if ((int) $fight['completed'] !== 1 || $fight['winner'] === null || $fight['winner'] === '') {
                $open++;
                $hits[] = null;
                continue;
            }
            $hit = $fight['winner'] === $leg['selection'];
            $hits[] = $hit;
            if (!$hit) {
                $knownLosses++;
            }
        }
        $type = $ticket['type'] ?? 'kombinacija';
        if ($type === 'sistem') {
            if ($knownLosses >= 2) {
                return ['profit' => round(-$ticket['stake'], 2), 'won' => false];
            }
            if ($open > 0) {
                return null;
            }
            $profit = $this->profitFromHits($ticket, $hits);
            return ['profit' => $profit, 'won' => $profit > 0];
        }
        if ($knownLosses > 0) {
            return ['profit' => round(-$ticket['stake'], 2), 'won' => false];
        }
        if ($open > 0) {
            return null;
        }
        $profit = $this->profitFromHits($ticket, $hits);
        return ['profit' => $profit, 'won' => $profit > 0];
    }

    private function profitFromHits(array $ticket, array $hits): float
    {
        $type = $ticket['type'] ?? 'kombinacija';
        if ($type === 'sistem') {
            $unit = (float) ($ticket['unit_stake'] ?? 0);
            $legs = $ticket['legs'];
            $payout = 0.0;
            $pairs = [[0, 1], [0, 2], [1, 2]];
            foreach ($pairs as $pair) {
                $a = $pair[0];
                $b = $pair[1];
                if (!empty($hits[$a]) && !empty($hits[$b])) {
                    $comboPayout = $unit * (float) $legs[$a]['odds'] * (float) $legs[$b]['odds'];
                    $payout += $this->netPayout($comboPayout);
                }
            }
            return round($payout - (float) $ticket['stake'], 2);
        }
        $allHit = true;
        foreach ($hits as $hit) {
            if (!$hit) {
                $allHit = false;
            }
        }
        if (!$allHit) {
            return round(-(float) $ticket['stake'], 2);
        }
        $payout = (float) $ticket['stake'] * (float) $ticket['combined_odds'];
        return round($this->netPayout($payout) - (float) $ticket['stake'], 2);
    }

    private function bestKombinacija(array $legs, float $bankroll, float $kellyFraction, float $maxBet, float $eventCap, float $available): ?array
    {
        $best = null;
        $n = count($legs);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $candidate = $this->scoreKombinacija([$legs[$i], $legs[$j]], $bankroll, $kellyFraction, $maxBet, $eventCap, $available);
                if ($candidate === null) {
                    continue;
                }
                if ($best === null || $candidate['ev'] > $best['ev']) {
                    $best = $candidate;
                }
            }
        }
        return $best;
    }

    private function scoreKombinacija(array $subset, float $bankroll, float $kellyFraction, float $maxBet, float $eventCap, float $available): ?array
    {
        $combinedP = 1.0;
        $combinedOdds = 1.0;
        $minConf = 1.0;
        $names = [];
        foreach ($subset as $leg) {
            $combinedP *= (float) $leg['p'];
            $combinedOdds *= (float) $leg['odds'];
            if ((float) $leg['confidence'] < $minConf) {
                $minConf = (float) $leg['confidence'];
            }
            $names[] = (string) $leg['selection'];
        }
        $combinedOdds = round($combinedOdds, 4);
        if ($combinedOdds < self::MIN_COMBINED_ODDS) {
            return null;
        }
        $combinedEdge = $combinedP - (1 / $combinedOdds);
        $kelly = (($combinedOdds * $combinedP) - 1) / max(0.01, $combinedOdds - 1);
        if ($kelly <= 0) {
            return null;
        }
        $raw = $bankroll * $kellyFraction * $kelly * $minConf;
        $stake = min($raw, $maxBet, $eventCap, $available, $this->maxTicketStake);
        $maxStakeForPayout = $this->maxPayout / $combinedOdds;
        if ($stake > $maxStakeForPayout) {
            $stake = $maxStakeForPayout;
        }
        $stake = $this->moneyFloor($stake);
        if ($stake < $this->minStake) {
            return null;
        }
        $payout = $stake * $combinedOdds;
        $netPayout = $this->netPayout($payout);
        $ev = $combinedP * $netPayout - $stake;
        if ($ev <= 0) {
            return null;
        }
        return [
            'type' => 'kombinacija',
            'market' => 'kombinacija',
            'system' => null,
            'label' => implode(' + ', $names),
            'legs' => array_values($subset),
            'leg_count' => 2,
            'combos' => [],
            'unit_stake' => $stake,
            'combined_odds' => $combinedOdds,
            'combined_p' => $combinedP,
            'combined_edge' => $combinedEdge,
            'kelly' => $kelly,
            'stake' => $stake,
            'possible_payout' => $this->moneyRound($payout),
            'possible_profit' => $this->moneyRound($netPayout - $stake),
            'ev' => $ev,
        ];
    }

    private function scoreSystem23(array $legs, float $bankroll, float $kellyFraction, float $eventCap, float $available): ?array
    {
        if (count($legs) !== 3) {
            return null;
        }
        $raw = 0.0;
        $names = [];
        foreach ($legs as $leg) {
            $raw += $bankroll * $kellyFraction * (float) $leg['kelly'] * (float) $leg['confidence'];
            $names[] = (string) $leg['selection'];
        }
        $total = min($raw, $eventCap, $available, $this->maxTicketStake);
        $unit = $this->moneyFloor($total / 3);
        if ($unit < $this->minStake) {
            return null;
        }
        $total = $this->moneyRound($unit * 3);
        $pairs = [[0, 1], [0, 2], [1, 2]];
        $combos = [];
        $allHitPayout = 0.0;
        foreach ($pairs as $pair) {
            $a = $legs[$pair[0]];
            $b = $legs[$pair[1]];
            $odds = round((float) $a['odds'] * (float) $b['odds'], 4);
            $p = (float) $a['p'] * (float) $b['p'];
            $combos[] = [
                'label' => $a['selection'] . ' + ' . $b['selection'],
                'odds' => $odds,
                'p' => $p,
            ];
            $allHitPayout += $unit * $odds;
        }
        $ev = $this->system23ExpectedValue($legs, $unit, $total);
        if ($ev <= 0) {
            return null;
        }
        $combinedOdds = round($allHitPayout / $total, 4);
        $combinedP = (float) $legs[0]['p'] * (float) $legs[1]['p'] * (float) $legs[2]['p'];
        return [
            'type' => 'sistem',
            'market' => 'sistem',
            'system' => '2/3',
            'label' => implode(' + ', $names),
            'legs' => array_values($legs),
            'leg_count' => 3,
            'combos' => $combos,
            'unit_stake' => $unit,
            'combined_odds' => $combinedOdds,
            'combined_p' => $combinedP,
            'combined_edge' => $combinedP - (1 / max(0.01, $combinedOdds)),
            'kelly' => 0.0,
            'stake' => $total,
            'possible_payout' => $this->moneyRound($this->netPayout($allHitPayout)),
            'possible_profit' => $this->moneyRound($this->netPayout($allHitPayout) - $total),
            'ev' => $ev,
        ];
    }

    private function system23ExpectedValue(array $legs, float $unit, float $total): float
    {
        $ev = 0.0;
        for ($mask = 0; $mask < 8; $mask++) {
            $prob = 1.0;
            $hits = [];
            for ($i = 0; $i < 3; $i++) {
                $p = (float) $legs[$i]['p'];
                if (($mask & (1 << $i)) !== 0) {
                    $hits[$i] = true;
                    $prob *= $p;
                } else {
                    $hits[$i] = false;
                    $prob *= (1 - $p);
                }
            }
            $ticket = [
                'type' => 'sistem',
                'unit_stake' => $unit,
                'stake' => $total,
                'legs' => $legs,
            ];
            $profit = $this->profitFromHits($ticket, $hits);
            $ev += $prob * $profit;
        }
        return $ev;
    }

    private function netPayout(float $payout): float
    {
        if ($payout > self::WINNINGS_TAX_THRESHOLD) {
            return $payout * (1 - self::WINNINGS_TAX_RATE);
        }
        return $payout;
    }

    private function moneyFloor(float $value): float
    {
        return floor($value * $this->moneyScale) / $this->moneyScale;
    }

    private function moneyRound(float $value): float
    {
        return round($value * $this->moneyScale) / $this->moneyScale;
    }
}
