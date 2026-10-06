<?php
declare(strict_types=1);

final class OddsClient
{
    private const ENDPOINT = 'https://api.the-odds-api.com/v4/sports/mma_mixed_martial_arts/odds/';

    public function __construct(private readonly PDO $pdo, private readonly string $apiKey)
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('The Odds API ključ ni nastavljen. Dodaj ga v Nastavitvah ali kvote vpiši ročno.');
        }
    }

    public function sync(): array
    {
        $query = http_build_query(['apiKey' => $this->apiKey, 'regions' => 'eu,uk,us', 'markets' => 'h2h', 'oddsFormat' => 'decimal', 'dateFormat' => 'iso']);
        $curl = curl_init(self::ENDPOINT . '?' . $query);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $events = is_string($body) ? json_decode($body, true) : null;
        if ($error !== '' || $status < 200 || $status >= 300 || !is_array($events)) {
            $message = is_array($events) ? ($events['message'] ?? $events['error'] ?? '') : '';
            throw new RuntimeException('Prenos kvot ni uspel: ' . ($message ?: $error ?: 'HTTP ' . $status));
        }
        $best = [];
        foreach ($events as $event) {
            foreach (($event['bookmakers'] ?? []) as $bookmaker) {
                foreach (($bookmaker['markets'] ?? []) as $market) {
                    if (($market['key'] ?? '') !== 'h2h') continue;
                    foreach (($market['outcomes'] ?? []) as $outcome) {
                        $name = $this->normalize((string) ($outcome['name'] ?? ''));
                        $price = (float) ($outcome['price'] ?? 0);
                        if ($name !== '' && $price > 1 && (!isset($best[$name]) || $price > $best[$name])) $best[$name] = $price;
                    }
                }
            }
        }
        $fights = $this->pdo->query('SELECT f.* FROM fights f WHERE f.completed=0 AND NOT EXISTS(SELECT 1 FROM predictions p WHERE p.fight_id=f.id)')->fetchAll();
        $update = $this->pdo->prepare('UPDATE fights SET odds_a=?,odds_b=? WHERE id=?');
        $updated = 0;
        foreach ($fights as $fight) {
            $a = $best[$this->normalize($fight['fighter_a'])] ?? null;
            $b = $best[$this->normalize($fight['fighter_b'])] ?? null;
            if ($a !== null || $b !== null) {
                $update->execute([$a ?? $fight['odds_a'], $b ?? $fight['odds_b'], $fight['id']]);
                $updated++;
            }
        }
        return ['updated_fights' => $updated, 'market_events' => count($events), 'matched_prices' => count($best)];
    }

    private function normalize(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii === false ? $name : $ascii));
    }
}
