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
            $message = '';
            if (is_array($events)) {
                if (isset($events['message'])) {
                    $message = (string) $events['message'];
                } elseif (isset($events['error'])) {
                    $message = (string) $events['error'];
                }
            }
            if ($message === '') {
                if ($error !== '') {
                    $message = $error;
                } else {
                    $message = 'HTTP ' . $status;
                }
            }
            throw new RuntimeException('Prenos kvot ni uspel: ' . $message);
        }
        $booksByFight = [];
        foreach ($events as $event) {
            foreach (($event['bookmakers'] ?? []) as $bookmaker) {
                foreach (($bookmaker['markets'] ?? []) as $bookMarket) {
                    if (($bookMarket['key'] ?? '') !== 'h2h') {
                        continue;
                    }
                    $prices = [];
                    foreach (($bookMarket['outcomes'] ?? []) as $outcome) {
                        $name = $this->normalize((string) ($outcome['name'] ?? ''));
                        $price = (float) ($outcome['price'] ?? 0);
                        if ($name !== '' && $price > 1) {
                            $prices[$name] = $price;
                        }
                    }
                    if (count($prices) >= 2) {
                        $booksByFight[] = $prices;
                    }
                }
            }
        }
        $fights = $this->pdo->query('SELECT f.* FROM fights f WHERE f.completed=0')->fetchAll();
        $update = $this->pdo->prepare('UPDATE fights SET odds_a=?,odds_b=? WHERE id=?');
        $updated = 0;
        foreach ($fights as $fight) {
            $pair = $this->sharpestPair($booksByFight, (string) $fight['fighter_a'], (string) $fight['fighter_b']);
            if ($pair === null) {
                continue;
            }
            $update->execute([$pair[0], $pair[1], $fight['id']]);
            $updated++;
        }
        return ['updated_fights' => $updated, 'market_events' => count($events), 'matched_prices' => count($booksByFight)];
    }

    private function sharpestPair(array $booksByFight, string $fighterA, string $fighterB): ?array
    {
        $normA = $this->normalize($fighterA);
        $normB = $this->normalize($fighterB);
        $chosenA = null;
        $chosenB = null;
        $chosenFav = null;
        foreach ($booksByFight as $prices) {
            if (!isset($prices[$normA], $prices[$normB])) {
                continue;
            }
            $oddsA = (float) $prices[$normA];
            $oddsB = (float) $prices[$normB];
            $fav = $oddsA;
            if ($oddsB < $fav) {
                $fav = $oddsB;
            }
            if ($chosenFav === null || $fav < $chosenFav) {
                $chosenA = $oddsA;
                $chosenB = $oddsB;
                $chosenFav = $fav;
            }
        }
        if ($chosenA === null || $chosenB === null) {
            return null;
        }
        return [round($chosenA, 6), round($chosenB, 6)];
    }

    private function normalize(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii));
    }
}
