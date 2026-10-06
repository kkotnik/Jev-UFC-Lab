<?php
declare(strict_types=1);

final class UfcEventSync
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function syncSchedule(int $focusEventId = 0): array
    {
        $html = $this->download('https://www.ufc.com/events');
        $upcoming = $this->parseUpcomingEvents($html);
        if ($upcoming === []) {
            throw new RuntimeException('UFC events stran je dosegljiva, vendar prihajajočih dogodkov ni bilo mogoče razbrati.');
        }
        $created = 0;
        $updated = 0;
        $eventIds = [];
        foreach ($upcoming as $row) {
            $result = $this->upsertEvent($row);
            $eventIds[] = $result['id'];
            if ($result['created']) {
                $created++;
            } else {
                $updated++;
            }
        }
        $cardTargets = [];
        $emptyRows = $this->pdo->query("SELECT e.id FROM events e WHERE e.status='upcoming' AND substr(e.event_date,1,10)>=date('now') AND (SELECT COUNT(*) FROM fights f WHERE f.event_id=e.id)=0 ORDER BY e.event_date ASC")->fetchAll();
        foreach ($emptyRows as $row) {
            $cardTargets[] = (int) $row['id'];
        }
        $soonRows = $this->pdo->query("SELECT id FROM events WHERE status='upcoming' AND substr(event_date,1,10)>=date('now') ORDER BY event_date ASC LIMIT 2")->fetchAll();
        foreach ($soonRows as $row) {
            $id = (int) $row['id'];
            if (!in_array($id, $cardTargets, true)) {
                $cardTargets[] = $id;
            }
        }
        if ($focusEventId > 0 && !in_array($focusEventId, $cardTargets, true)) {
            $cardTargets[] = $focusEventId;
        }
        $cards = [];
        $fightsWritten = 0;
        $fightsAdded = 0;
        foreach ($cardTargets as $eventId) {
            $attempts = 0;
            $saved = false;
            while ($attempts < 4 && $saved === false) {
                try {
                    $card = $this->syncCard($eventId);
                    $cards[] = $card;
                    $fightsWritten += (int) $card['fights_written'];
                    $fightsAdded += (int) $card['fights_added'];
                    $saved = true;
                } catch (PDOException $exception) {
                    $attempts++;
                    if (!str_contains($exception->getMessage(), 'database is locked') || $attempts >= 4) {
                        $cards[] = ['event_id' => $eventId, 'error' => $exception->getMessage()];
                        $saved = true;
                    } else {
                        usleep(400000);
                    }
                } catch (Throwable $throwable) {
                    $cards[] = ['event_id' => $eventId, 'error' => $throwable->getMessage()];
                    $saved = true;
                }
            }
        }
        $next = $this->pdo->query("SELECT id FROM events WHERE substr(event_date,1,10)>=date('now') AND status='upcoming' ORDER BY event_date ASC LIMIT 1")->fetchColumn();
        return [
            'events_found' => count($upcoming),
            'events_created' => $created,
            'events_updated' => $updated,
            'event_ids' => $eventIds,
            'cards' => $cards,
            'fights_written' => $fightsWritten,
            'fights_added' => $fightsAdded,
            'next_event_id' => $next === false ? 0 : (int) $next,
        ];
    }

    public function syncCard(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id=?');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        if (!$event || trim((string) $event['source_url']) === '') {
            throw new RuntimeException('Dogodek nima uradne UFC povezave.');
        }
        $html = $this->download((string) $event['source_url']);
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $containers = $xpath->query('//div[contains(@class,"c-listing-fight__content")]');
        $existingStmt = $this->pdo->prepare('SELECT * FROM fights WHERE event_id=? ORDER BY card_order');
        $existingStmt->execute([$eventId]);
        $existingFights = $existingStmt->fetchAll();
        $insert = $this->pdo->prepare('INSERT INTO fights(event_id,card_order,card_section,weight_class,fighter_a,fighter_b,odds_a,odds_b) VALUES(?,?,?,?,?,?,?,?)');
        $update = $this->pdo->prepare('UPDATE fights SET card_order=?,card_section=?,weight_class=?,odds_a=COALESCE(?,odds_a),odds_b=COALESCE(?,odds_b) WHERE id=? AND completed=0');
        $seen = [];
        $order = 0;
        $written = 0;
        $added = 0;
        $oddsUpdated = 0;
        foreach ($containers as $container) {
            $names = [];
            foreach ($xpath->query('.//*[contains(@class,"c-listing-fight__corner-name")]', $container) as $node) {
                $name = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            if (count($names) < 2) {
                continue;
            }
            $pairKey = $this->normalizeName($names[0]) . '|' . $this->normalizeName($names[1]);
            $pairKeySwap = $this->normalizeName($names[1]) . '|' . $this->normalizeName($names[0]);
            if (isset($seen[$pairKey]) || isset($seen[$pairKeySwap])) {
                continue;
            }
            $seen[$pairKey] = true;
            $weightNode = $xpath->query('.//*[contains(@class,"c-listing-fight__class-text")]', $container)->item(0);
            $weight = '';
            if ($weightNode) {
                $weight = trim((string) preg_replace('/\s+/', ' ', $weightNode->textContent));
            }
            $odds = [];
            foreach ($xpath->query('.//*[contains(@class,"c-listing-fight__odds-amount")]', $container) as $node) {
                $decimal = $this->americanToDecimal(trim($node->textContent));
                if ($decimal !== null) {
                    $odds[] = $decimal;
                }
            }
            $oddsA = null;
            $oddsB = null;
            if (count($odds) >= 2) {
                $oddsA = $odds[0];
                $oddsB = $odds[1];
            }
            $sectionNode = $xpath->query('ancestor::*[contains(@class,"l-listing")][1]/preceding-sibling::*[self::h2 or self::h3][1]', $container)->item(0);
            $section = 'Card';
            if ($sectionNode) {
                $sectionText = trim($sectionNode->textContent);
                if ($sectionText !== '') {
                    $section = $sectionText;
                }
            }
            $order++;
            $existing = $this->matchExistingFight($existingFights, $names[0], $names[1]);
            if ($existing !== null) {
                $beforeA = $existing['odds_a'];
                $beforeB = $existing['odds_b'];
                if ($existing['fighter_a'] === $names[1] && $existing['fighter_b'] === $names[0]) {
                    $update->execute([$order, $section, $weight, $oddsB, $oddsA, $existing['id']]);
                } else {
                    $update->execute([$order, $section, $weight, $oddsA, $oddsB, $existing['id']]);
                }
                if ($oddsA !== null && (string) $beforeA !== (string) $oddsA) {
                    $oddsUpdated++;
                } elseif ($oddsB !== null && (string) $beforeB !== (string) $oddsB) {
                    $oddsUpdated++;
                }
                $written++;
                continue;
            }
            $insert->execute([$eventId, $order, $section, $weight, $names[0], $names[1], $oddsA, $oddsB]);
            $written++;
            $added++;
        }
        if ($written === 0) {
            throw new RuntimeException('UFC stran je dosegljiva, vendar carda ni bilo mogoče razbrati. Dodaj borbe ročno ali poskusi pozneje.');
        }
        return [
            'event_id' => $eventId,
            'fights_written' => $written,
            'fights_added' => $added,
            'odds_updated' => $oddsUpdated,
            'source' => $event['source_url'],
        ];
    }

    private function parseUpcomingEvents(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $scope = $xpath->query('//details[@id="events-list-upcoming"]')->item(0);
        if ($scope) {
            $articles = $xpath->query('.//article[contains(@class,"c-card-event--result")]', $scope);
        } else {
            $articles = $xpath->query('//article[contains(@class,"c-card-event--result")]');
        }
        $events = [];
        $seen = [];
        foreach ($articles as $article) {
            $link = $xpath->query('.//h3[contains(@class,"c-card-event--result__headline")]/a', $article)->item(0);
            if (!$link) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            $headline = trim((string) preg_replace('/\s+/', ' ', $link->textContent));
            if ($href === '' || $headline === '') {
                continue;
            }
            if (str_starts_with($href, '/')) {
                $url = 'https://www.ufc.com' . $href;
            } else {
                $url = $href;
            }
            $queryPos = strpos($url, '?');
            if ($queryPos !== false) {
                $url = substr($url, 0, $queryPos);
            }
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $dateNode = $xpath->query('.//*[contains(@class,"c-card-event--result__date")]', $article)->item(0);
            $dateText = '';
            if ($dateNode) {
                $dateText = trim((string) preg_replace('/\s+/', ' ', $dateNode->textContent));
            }
            $locNode = $xpath->query('.//*[contains(@class,"c-card-event--result__location")]', $article)->item(0);
            $venue = '';
            if ($locNode) {
                $venue = trim((string) preg_replace('/\s+/', ' ', $locNode->textContent));
            }
            $eventDate = $this->parseEventDate($dateText, $url);
            if ($eventDate === null) {
                continue;
            }
            $seen[$url] = true;
            $events[] = [
                'name' => $this->eventNameFromUrl($url, $headline),
                'event_date' => $eventDate,
                'venue' => $venue,
                'source_url' => $url,
            ];
        }
        return $events;
    }

    private function upsertEvent(array $row): array
    {
        $find = $this->pdo->prepare('SELECT id,status FROM events WHERE source_url=? LIMIT 1');
        $find->execute([$row['source_url']]);
        $existing = $find->fetch();
        if ($existing) {
            if ((string) $existing['status'] === 'upcoming') {
                $update = $this->pdo->prepare('UPDATE events SET name=?,event_date=?,venue=? WHERE id=?');
                $update->execute([$row['name'], $row['event_date'], $row['venue'], $existing['id']]);
            }
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $insert = $this->pdo->prepare('INSERT INTO events(name,event_date,venue,source_url) VALUES(?,?,?,?)');
        $insert->execute([$row['name'], $row['event_date'], $row['venue'], $row['source_url']]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true];
    }

    private function matchExistingFight(array $existingFights, string $fighterA, string $fighterB): ?array
    {
        $normA = $this->normalizeName($fighterA);
        $normB = $this->normalizeName($fighterB);
        foreach ($existingFights as $fight) {
            $fa = $this->normalizeName((string) $fight['fighter_a']);
            $fb = $this->normalizeName((string) $fight['fighter_b']);
            if ($fa === $normA && $fb === $normB) {
                return $fight;
            }
            if ($fa === $normB && $fb === $normA) {
                return $fight;
            }
        }
        return null;
    }

    private function eventNameFromUrl(string $url, string $headline): string
    {
        $slug = strtolower((string) basename(parse_url($url, PHP_URL_PATH) ?: ''));
        if (preg_match('/^ufc-(\d+)$/', $slug, $match)) {
            return 'UFC ' . $match[1] . ': ' . $headline;
        }
        if (str_starts_with($slug, 'ufc-fight-night')) {
            return 'UFC Fight Night: ' . $headline;
        }
        return 'UFC: ' . $headline;
    }

    private function parseEventDate(string $text, string $url): ?string
    {
        if (!preg_match('/([A-Za-z]{3})\s+(\d{1,2})\s*\/\s*(\d{1,2}:\d{2}\s*[AP]M)\s+([A-Z]{2,4})/i', $text, $match)) {
            return null;
        }
        $year = $this->yearFromUrl($url);
        if ($year === null) {
            $year = (int) date('Y');
        }
        $timezoneName = $this->timezoneFromAbbrev($match[4]);
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Exception $exception) {
            $timezone = new DateTimeZone('UTC');
        }
        $date = DateTime::createFromFormat('M j Y g:i A', $match[1] . ' ' . $match[2] . ' ' . $year . ' ' . $match[3], $timezone);
        if (!$date instanceof DateTime) {
            return null;
        }
        $floor = new DateTime('-3 days');
        if ($this->yearFromUrl($url) === null && $date < $floor) {
            $date->modify('+1 year');
        }
        return $date->format(DATE_ATOM);
    }

    private function yearFromUrl(string $url): ?int
    {
        if (preg_match('/-(\d{4})(?:\/|$)/', $url, $match)) {
            return (int) $match[1];
        }
        return null;
    }

    private function timezoneFromAbbrev(string $abbrev): string
    {
        $key = strtoupper($abbrev);
        if ($key === 'EDT' || $key === 'EST' || $key === 'ET') {
            return 'America/New_York';
        }
        if ($key === 'PDT' || $key === 'PST' || $key === 'PT') {
            return 'America/Los_Angeles';
        }
        if ($key === 'CDT' || $key === 'CST' || $key === 'CT') {
            return 'America/Chicago';
        }
        if ($key === 'MDT' || $key === 'MST' || $key === 'MT') {
            return 'America/Denver';
        }
        if ($key === 'BST' || $key === 'GMT' || $key === 'WEST') {
            return 'Europe/London';
        }
        if ($key === 'CEST' || $key === 'CET') {
            return 'Europe/Ljubljana';
        }
        return 'UTC';
    }

    private function normalizeName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii === false) {
            $ascii = $name;
        }
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii));
    }

    private function download(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: en-US,en;q=0.9\r\n",
                'follow_location' => 1,
                'timeout' => 40,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }
        if (is_string($body) && $body !== '' && ($status === 0 || $status < 400)) {
            return $body;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Referer: https://www.ufc.com/',
            ],
        ]);
        $curlBody = curl_exec($curl);
        $curlStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (is_string($curlBody) && $curlBody !== '' && $curlStatus < 400 && $error === '') {
            return $curlBody;
        }
        $shown = $curlStatus;
        if ($shown === 0) {
            $shown = $status;
        }
        throw new RuntimeException('Uradni UFC card ni dosegljiv (HTTP ' . $shown . '). ' . $error);
    }

    private function americanToDecimal(string $value): ?float
    {
        if (!preg_match('/([+-]\d+)/', $value, $m)) {
            return null;
        }
        $american = (int) $m[1];
        if ($american > 0) {
            return round(1 + $american / 100, 6);
        }
        return round(1 + 100 / abs($american), 6);
    }
}
