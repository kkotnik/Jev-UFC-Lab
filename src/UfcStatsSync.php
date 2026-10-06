<?php
declare(strict_types=1);

final class UfcStatsSync
{
    private const EVENTS_URL = 'http://ufcstats.com/statistics/events/completed?page=all';
    private const FALLBACK_CSV = 'https://raw.githubusercontent.com/DanMcInerney/mma-ai/main/data/raw/ufcstats/competitions.csv';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sync(int $eventLimit = 80): array
    {
        $html = $this->download(self::EVENTS_URL);
        $xpath = $this->xpath($html);
        $links = [];
        foreach ($xpath->query('//tr[contains(@class,"b-statistics__table-row")]//a[contains(@class,"b-link")]') as $node) {
            $url = trim((string) $node->getAttribute('href'));
            if ($url !== '' && str_contains($url, 'event-details')) {
                $links[] = $url;
            }
        }
        $links = array_slice(array_values(array_unique($links)), 0, max(1, min(300, $eventLimit)));
        if ($links === []) {
            return $this->syncFallbackCsv();
        }
        $inserted = 0;
        $events = 0;

        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO historical_fights (
    source_id, event_name, event_date, weight_class, fighter_a, fighter_b, winner, method,
    result_round, kd_a, kd_b, sig_str_a, sig_str_b, td_a, td_b, sub_a, sub_b
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON CONFLICT(source_id) DO UPDATE SET
    event_name=excluded.event_name, event_date=excluded.event_date, winner=excluded.winner,
    method=excluded.method, result_round=excluded.result_round, kd_a=excluded.kd_a,
    kd_b=excluded.kd_b, sig_str_a=excluded.sig_str_a, sig_str_b=excluded.sig_str_b,
    td_a=excluded.td_a, td_b=excluded.td_b, sub_a=excluded.sub_a, sub_b=excluded.sub_b
SQL);

        foreach ($links as $eventUrl) {
            $eventHtml = $this->download($eventUrl);
            $eventXpath = $this->xpath($eventHtml);
            $name = $this->text($eventXpath, '(//h2[contains(@class,"b-content__title")]/span)[1]');
            $dateText = $this->text($eventXpath, '(//li[contains(@class,"b-list__box-list-item")][contains(.,"Date:")])[1]');
            $dateText = trim((string) preg_replace('/^\s*Date:\s*/i', '', $dateText));
            $date = DateTimeImmutable::createFromFormat('F d, Y', $dateText);
            if ($date === false) {
                continue;
            }

            foreach ($eventXpath->query('//tr[contains(@class,"b-fight-details__table-row")][@data-link]') as $row) {
                $fightUrl = trim((string) $row->getAttribute('data-link'));
                $columns = [];
                foreach ((new DOMXPath($row->ownerDocument))->query('./td', $row) as $cell) {
                    $values = [];
                    foreach ((new DOMXPath($row->ownerDocument))->query('.//p', $cell) as $p) {
                        $value = preg_replace('/\s+/', ' ', trim($p->textContent));
                        if ($value !== '') {
                            $values[] = $value;
                        }
                    }
                    $columns[] = $values;
                }
                if (count($columns) < 10 || count($columns[1] ?? []) < 2) {
                    continue;
                }
                $fighterA = $columns[1][0];
                $fighterB = $columns[1][1];
                $resultA = strtoupper($columns[0][0] ?? '');
                $winner = $resultA === 'W' ? $fighterA : ($resultA === 'L' ? $fighterB : null);
                $sourceId = $fightUrl !== '' ? sha1($fightUrl) : sha1($name . $fighterA . $fighterB);
                $stmt->execute([
                    $sourceId,
                    $name,
                    $date->format('Y-m-d'),
                    implode(' ', $columns[6] ?? []),
                    $fighterA,
                    $fighterB,
                    $winner,
                    implode(' ', $columns[7] ?? []),
                    $this->number($columns[8][0] ?? null),
                    $this->number($columns[2][0] ?? null),
                    $this->number($columns[2][1] ?? null),
                    $this->number($columns[3][0] ?? null),
                    $this->number($columns[3][1] ?? null),
                    $this->number($columns[4][0] ?? null),
                    $this->number($columns[4][1] ?? null),
                    $this->number($columns[5][0] ?? null),
                    $this->number($columns[5][1] ?? null),
                ]);
                $inserted += $stmt->rowCount() > 0 ? 1 : 0;
            }
            $events++;
        }

        return ['events_scanned' => $events, 'fights_written' => $inserted, 'source' => self::EVENTS_URL];
    }

    private function syncFallbackCsv(): array
    {
        $csv = $this->download(self::FALLBACK_CSV);
        $stream = fopen('php://temp/maxmemory:52428800', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Za CSV uvoz ni bilo mogoče odpreti začasnega pomnilnika.');
        }
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!is_array($header)) {
            throw new RuntimeException('Rezervni UFC CSV nima glave.');
        }
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO historical_fights (
 source_id,event_name,event_date,weight_class,fighter_a,fighter_b,winner,method,result_round,
 kd_a,kd_b,sig_str_a,sig_str_b,td_a,td_b,sub_a,sub_b
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
ON CONFLICT(source_id) DO UPDATE SET event_date=excluded.event_date,winner=excluded.winner,
 method=excluded.method,result_round=excluded.result_round,kd_a=excluded.kd_a,kd_b=excluded.kd_b,
 sig_str_a=excluded.sig_str_a,sig_str_b=excluded.sig_str_b,td_a=excluded.td_a,td_b=excluded.td_b,
 sub_a=excluded.sub_a,sub_b=excluded.sub_b
SQL);
        $written = 0;
        $dates = [];
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $values);
            if (!is_array($row) || trim((string) ($row['player1'] ?? '')) === '' || trim((string) ($row['player2'] ?? '')) === '') {
                continue;
            }
            $timestamp = strtotime((string) ($row['event_date'] ?? ''));
            if ($timestamp === false) {
                continue;
            }
            $date = date('Y-m-d', $timestamp);
            $dates[$date] = true;
            $result = strtoupper(trim((string) ($row['result'] ?? '')));
            $winner = $result === 'W' ? $row['player1'] : ($result === 'L' ? $row['player2'] : null);
            $eventUrl = (string) ($row['event_url'] ?? '');
            $sourceId = sha1($eventUrl . '|' . $row['player1'] . '|' . $row['player2'] . '|' . $date);
            $insert->execute([
                $sourceId,
                'UFC event ' . $date,
                $date,
                trim((string) ($row['weightclass'] ?? '')),
                trim((string) $row['player1']),
                trim((string) $row['player2']),
                $winner,
                trim((string) ($row['method'] ?? '')),
                $this->number($row['round'] ?? null),
                $this->sumRounds($row, 'p1_rd', '_KD'),
                $this->sumRounds($row, 'p2_rd', '_KD'),
                $this->sumRounds($row, 'p1_rd', '_Sig_str'),
                $this->sumRounds($row, 'p2_rd', '_Sig_str'),
                $this->sumRounds($row, 'p1_rd', '_Td'),
                $this->sumRounds($row, 'p2_rd', '_Td'),
                $this->sumRounds($row, 'p1_rd', '_Sub_att'),
                $this->sumRounds($row, 'p2_rd', '_Sub_att'),
            ]);
            $written++;
        }
        fclose($stream);
        return [
            'events_scanned' => count($dates),
            'fights_written' => $written,
            'source' => self::FALLBACK_CSV,
            'note' => 'UFCStats je zahteval JavaScript, zato je bil uporabljen javni UFCStats CSV posnetek.',
        ];
    }

    private function sumRounds(array $row, string $prefix, string $suffix): ?int
    {
        $sum = 0;
        $seen = false;
        for ($round = 1; $round <= 5; $round++) {
            $value = $this->number($row[$prefix . $round . $suffix] ?? null);
            if ($value !== null) {
                $sum += $value;
                $seen = true;
            }
        }
        return $seen ? $sum : null;
    }

    private function download(string $url): string
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Jev-UFC-Lab/1.0 (local research project)',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status >= 400 || $error !== '') {
            throw new RuntimeException("UFCStats prenos ni uspel (HTTP {$status}): {$error}");
        }
        return $body;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        return new DOMXPath($document);
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        return $node ? trim((string) preg_replace('/\s+/', ' ', $node->textContent)) : '';
    }

    private function number(?string $value): ?int
    {
        if ($value === null || !preg_match('/-?\d+/', $value, $match)) {
            return null;
        }
        return (int) $match[0];
    }
}
