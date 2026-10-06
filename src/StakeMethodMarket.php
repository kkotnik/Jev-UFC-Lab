<?php
declare(strict_types=1);

final class StakeMethodMarket
{
    public const MARKET = 'winning_method';
    public const METHOD_VIG = 1.18;
    public const PICK_TILT = 1.35;

    public static function keys(): array
    {
        return ['a_ko_tko', 'a_submission', 'a_decision', 'b_ko_tko', 'b_submission', 'b_decision'];
    }

    public static function eventUrl(string $name): string
    {
        $slug = strtolower($name);
        $slug = str_replace(':', '', $slug);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'https://stake.com/sports/mma/ufc';
        }
        return 'https://stake.com/sports/mma/ufc/' . $slug;
    }

    public static function methodLabel(string $key): string
    {
        if ($key === 'a_ko_tko' || $key === 'b_ko_tko') {
            return 'KO/TKO';
        }
        if ($key === 'a_submission' || $key === 'b_submission') {
            return 'Submission';
        }
        return 'Decision';
    }

    public static function selectionLabel(string $fighter, string $methodLabel): string
    {
        return $fighter . ' by ' . $methodLabel;
    }

    public static function defaultMix(): array
    {
        return ['ko_tko' => 0.36, 'submission' => 0.22, 'decision' => 0.42];
    }

    public static function mixFromWins(float $ko, float $sub, float $dec): array
    {
        $total = $ko + $sub + $dec;
        if ($total < 3) {
            return self::defaultMix();
        }
        return [
            'ko_tko' => $ko / $total,
            'submission' => $sub / $total,
            'decision' => $dec / $total,
        ];
    }

    public static function category(string $method): string
    {
        $method = strtolower($method);
        if (str_contains($method, 'dq') || str_contains($method, 'nc') || str_contains($method, 'overturn') || str_contains($method, 'could not')) {
            return 'other';
        }
        if (str_contains($method, 'sub')) {
            return 'submission';
        }
        if (str_contains($method, 'dec') || str_contains($method, 'judge')) {
            return 'decision';
        }
        return 'ko_tko';
    }

    public static function keyFromPick(string $pick, string $fighterA, string $fighterB): ?string
    {
        $pickLower = strtolower($pick);
        $a = strtolower($fighterA);
        $b = strtolower($fighterB);
        $side = null;
        if ($a !== '' && str_contains($pickLower, $a)) {
            $side = 'a';
        } else if ($b !== '' && str_contains($pickLower, $b)) {
            $side = 'b';
        }
        if ($side === null) {
            return null;
        }
        $cat = self::category($pick);
        if ($cat === 'other') {
            return null;
        }
        if ($cat === 'submission') {
            return $side . '_submission';
        }
        if ($cat === 'decision') {
            return $side . '_decision';
        }
        return $side . '_ko_tko';
    }

    public static function parseSelection(string $selection): ?array
    {
        $needle = ' by ';
        $pos = strrpos($selection, $needle);
        if ($pos === false) {
            return null;
        }
        $fighter = trim(substr($selection, 0, $pos));
        $method = trim(substr($selection, $pos + strlen($needle)));
        if ($fighter === '' || $method === '') {
            return null;
        }
        return ['fighter' => $fighter, 'method' => self::category($method), 'method_label' => $method];
    }

    public static function selectionHits(string $selection, string $market, string $winner, string $method): bool
    {
        if ($market === self::MARKET) {
            $parsed = self::parseSelection($selection);
            if ($parsed === null) {
                return false;
            }
            if ($parsed['fighter'] !== $winner) {
                return false;
            }
            return $parsed['method'] === self::category($method);
        }
        return $selection === $winner;
    }

    public static function settleProfit(array $bet, string $winner, string $method): array
    {
        $hits = self::selectionHits((string) $bet['selection'], (string) ($bet['market'] ?? 'moneyline'), $winner, $method);
        $stake = (float) $bet['stake'];
        $odds = (float) $bet['odds'];
        if ($hits) {
            return ['result' => 'win', 'profit' => round($stake * ($odds - 1), 2)];
        }
        return ['result' => 'loss', 'profit' => round(-$stake, 2)];
    }

    public static function normalizeProbs(array $raw): array
    {
        $out = [];
        $sum = 0.0;
        foreach (self::keys() as $key) {
            $value = (float) ($raw[$key] ?? 0);
            if ($value < 0) {
                $value = 0.0;
            }
            $out[$key] = $value;
            $sum += $value;
        }
        if ($sum <= 0) {
            foreach (self::keys() as $key) {
                $out[$key] = 1 / 6;
            }
            return $out;
        }
        foreach ($out as $key => $value) {
            $out[$key] = $value / $sum;
        }
        return $out;
    }

    public static function probsFromPick(float $pA, float $pB, string $pick, string $fighterA, string $fighterB, array $mixA, array $mixB): array
    {
        $sum = max(0.0001, $pA + $pB);
        $pA /= $sum;
        $pB /= $sum;
        $a = [
            'a_ko_tko' => (float) $mixA['ko_tko'],
            'a_submission' => (float) $mixA['submission'],
            'a_decision' => (float) $mixA['decision'],
        ];
        $b = [
            'b_ko_tko' => (float) $mixB['ko_tko'],
            'b_submission' => (float) $mixB['submission'],
            'b_decision' => (float) $mixB['decision'],
        ];
        $picked = self::keyFromPick($pick, $fighterA, $fighterB);
        if ($picked !== null && str_starts_with($picked, 'a_') && isset($a[$picked])) {
            $a[$picked] *= self::PICK_TILT;
        } else if ($picked !== null && str_starts_with($picked, 'b_') && isset($b[$picked])) {
            $b[$picked] *= self::PICK_TILT;
        }
        $a = self::scaleGroup($a, $pA);
        $b = self::scaleGroup($b, $pB);
        return $a + $b;
    }

    private static function scaleGroup(array $group, float $target): array
    {
        $sum = 0.0;
        foreach ($group as $value) {
            $sum += $value;
        }
        if ($sum <= 0) {
            $share = $target / max(1, count($group));
            foreach ($group as $key => $value) {
                $group[$key] = $share;
            }
            return $group;
        }
        foreach ($group as $key => $value) {
            $group[$key] = $target * ($value / $sum);
        }
        return $group;
    }

    public static function syntheticMethodOdds(float $oddsA, float $oddsB, string $key): ?float
    {
        if ($oddsA <= 1.0 || $oddsB <= 1.0) {
            return null;
        }
        $pA = (1 / $oddsA) / ((1 / $oddsA) + (1 / $oddsB));
        $pB = 1 - $pA;
        $mix = self::defaultMix();
        $p = 0.0;
        if ($key === 'a_ko_tko') {
            $p = $pA * $mix['ko_tko'];
        } else if ($key === 'a_submission') {
            $p = $pA * $mix['submission'];
        } else if ($key === 'a_decision') {
            $p = $pA * $mix['decision'];
        } else if ($key === 'b_ko_tko') {
            $p = $pB * $mix['ko_tko'];
        } else if ($key === 'b_submission') {
            $p = $pB * $mix['submission'];
        } else if ($key === 'b_decision') {
            $p = $pB * $mix['decision'];
        }
        $implied = $p / self::METHOD_VIG;
        if ($implied <= 0.015) {
            return null;
        }
        return round(1 / $implied, 2);
    }

    public static function methodOddsUsable(float $methodOdds, float $moneylineOdds, string $key): bool
    {
        if ($methodOdds <= 1.0 || $moneylineOdds <= 1.0) {
            return false;
        }
        if ($methodOdds + 0.02 < $moneylineOdds) {
            return false;
        }
        $impliedMethod = 1 / $methodOdds;
        $impliedWin = 1 / $moneylineOdds;
        $minShare = 0.10;
        if (str_contains($key, 'submission')) {
            $minShare = 0.06;
        }
        if ($impliedMethod < $impliedWin * $minShare) {
            return false;
        }
        if ($impliedMethod > $impliedWin * 0.97) {
            return false;
        }
        return true;
    }

    public static function optionFromKey(string $key, string $fighterA, string $fighterB, array $probs, ?float $odds): ?array
    {
        if ($odds === null || $odds <= 1.0) {
            return null;
        }
        $p = (float) ($probs[$key] ?? 0);
        if ($p <= 0) {
            return null;
        }
        $fighter = $fighterA;
        if (str_starts_with($key, 'b_')) {
            $fighter = $fighterB;
        }
        $label = self::methodLabel($key);
        $selection = self::selectionLabel($fighter, $label);
        $edge = $p - (1 / $odds);
        $kelly = (($odds * $p) - 1) / max(0.01, $odds - 1);
        return [
            'selection' => $selection,
            'p' => $p,
            'odds' => $odds,
            'edge' => $edge,
            'kelly' => $kelly,
            'market' => self::MARKET,
            'method_key' => $key,
            'method_label' => $label,
        ];
    }

    public static function moneylineOption(string $name, float $p, ?float $odds): ?array
    {
        if ($odds === null || $odds <= 1.0) {
            return null;
        }
        $edge = $p - (1 / $odds);
        $kelly = (($odds * $p) - 1) / max(0.01, $odds - 1);
        return [
            'selection' => $name,
            'p' => $p,
            'odds' => $odds,
            'edge' => $edge,
            'kelly' => $kelly,
            'market' => 'moneyline',
            'method_key' => null,
            'method_label' => 'Moneyline',
        ];
    }

    public static function bestOption(array $options, float $minEdge): ?array
    {
        $best = null;
        foreach ($options as $option) {
            if ($option === null) {
                continue;
            }
            if ($option['kelly'] <= 0) {
                continue;
            }
            if ($option['edge'] < $minEdge) {
                continue;
            }
            if ($best === null || $option['edge'] > $best['edge']) {
                $best = $option;
            }
        }
        return $best;
    }

    public static function defaultPolicy(): array
    {
        return [
            'min_method_p' => 0.70,
            'min_method_share' => 0.75,
            'methods' => ['ko_tko', 'submission', 'decision'],
            'every_n' => 1,
            'rank' => 'p',
        ];
    }

    public static function methodFamily(string $key): string
    {
        if (str_contains($key, 'sub')) {
            return 'submission';
        }
        if (str_contains($key, 'dec')) {
            return 'decision';
        }
        return 'ko_tko';
    }

    public static function fightOptions(string $fighterA, string $fighterB, float $pA, float $pB, ?float $oddsA, ?float $oddsB, array $methodProbs, array $historicalMethodOdds = []): array
    {
        $moneyline = [];
        $methods = [];
        $mlA = self::moneylineOption($fighterA, $pA, $oddsA);
        $mlB = self::moneylineOption($fighterB, $pB, $oddsB);
        if ($mlA !== null) {
            $moneyline[] = $mlA;
        }
        if ($mlB !== null) {
            $moneyline[] = $mlB;
        }
        if ($oddsA === null || $oddsB === null) {
            return ['moneyline' => $moneyline, 'methods' => $methods];
        }
        foreach (self::keys() as $key) {
            $methodOdds = $historicalMethodOdds[$key] ?? null;
            if ($methodOdds === null) {
                $methodOdds = self::syntheticMethodOdds($oddsA, $oddsB, $key);
            }
            $moneylineOdds = $oddsA;
            $winnerP = $pA;
            if (str_starts_with($key, 'b_')) {
                $moneylineOdds = $oddsB;
                $winnerP = $pB;
            }
            if ($methodOdds === null) {
                continue;
            }
            if (!self::methodOddsUsable((float) $methodOdds, (float) $moneylineOdds, $key)) {
                continue;
            }
            $option = self::optionFromKey($key, $fighterA, $fighterB, $methodProbs, (float) $methodOdds);
            if ($option === null) {
                continue;
            }
            $option['winner_p'] = $winnerP;
            $methods[] = $option;
        }
        return ['moneyline' => $moneyline, 'methods' => $methods];
    }

    public static function recommend(array $moneylineOptions, array $methodOptions, float $minEdge, array $policy, int &$eligibleSeq): array
    {
        $bestMoneyline = self::bestOption($moneylineOptions, $minEdge);
        $minP = (float) ($policy['min_method_p'] ?? 0.70);
        $minShare = (float) ($policy['min_method_share'] ?? 0.0);
        $allowed = $policy['methods'] ?? ['ko_tko', 'submission', 'decision'];
        if (!is_array($allowed)) {
            $allowed = ['ko_tko', 'submission', 'decision'];
        }
        $everyN = (int) ($policy['every_n'] ?? 1);
        if ($everyN < 1) {
            $everyN = 1;
        }
        $rank = (string) ($policy['rank'] ?? 'p');
        $bestMethod = null;
        foreach ($methodOptions as $option) {
            if ($option === null) {
                continue;
            }
            if ($option['kelly'] <= 0) {
                continue;
            }
            if ($option['edge'] < $minEdge) {
                continue;
            }
            if ($option['p'] < $minP) {
                continue;
            }
            $family = self::methodFamily((string) ($option['method_key'] ?? ''));
            $familyOk = false;
            foreach ($allowed as $allow) {
                if ($allow === $family) {
                    $familyOk = true;
                }
            }
            if (!$familyOk) {
                continue;
            }
            $winnerP = (float) ($option['winner_p'] ?? 0);
            if ($minShare > 0 && $winnerP > 0) {
                if (($option['p'] / $winnerP) < $minShare) {
                    continue;
                }
            }
            if ($bestMethod === null) {
                $bestMethod = $option;
                continue;
            }
            if ($rank === 'edge') {
                if ($option['edge'] > $bestMethod['edge']) {
                    $bestMethod = $option;
                }
            } else if ($option['p'] > $bestMethod['p']) {
                $bestMethod = $option;
            }
        }
        if ($bestMethod === null) {
            if ($bestMoneyline === null) {
                return ['bet' => null, 'reason' => 'Ni +EV stave.', 'method_eligible' => false];
            }
            $bestMoneyline['reason'] = 'Moneyline — metoda ni dovolj ziher (Jev ni dovolj skoncentriran na KO/Sub/Dec).';
            return ['bet' => $bestMoneyline, 'reason' => $bestMoneyline['reason'], 'method_eligible' => false];
        }
        $eligibleSeq++;
        $useMethod = true;
        if ($everyN > 1 && (($eligibleSeq - 1) % $everyN) !== 0) {
            $useMethod = false;
        }
        if (!$useMethod) {
            if ($bestMoneyline === null) {
                return ['bet' => null, 'reason' => 'Ni +EV stave.', 'method_eligible' => true];
            }
            $bestMoneyline['reason'] = 'Moneyline — metoda je ziher, a pravilo vzame method samo 1/' . $everyN . '.';
            return ['bet' => $bestMoneyline, 'reason' => $bestMoneyline['reason'], 'method_eligible' => true];
        }
        $percent = (int) round($bestMethod['p'] * 100);
        $bestMethod['reason'] = 'Winning method: ' . $bestMethod['method_label'] . ' · Jev ' . $percent . '% — stavi to, ne samo zmagovalca.';
        return ['bet' => $bestMethod, 'reason' => $bestMethod['reason'], 'method_eligible' => true];
    }
}
