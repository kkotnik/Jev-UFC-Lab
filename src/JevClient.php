<?php
declare(strict_types=1);

final class JevClient
{
    private const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    public function __construct(private readonly string $apiKey)
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('TYPESAFE_API_KEY ni nastavljen. Zaženi start.bat in vnesi ključ.');
        }
    }

    public function evaluate(string $state, array $questions): array
    {
        $payload = json_encode([
            'state' => $state,
            'model' => 'jev-latest',
            'questions' => $questions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Povezava z Jev API ni uspela: ' . $error);
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Jev API: ' . (is_string($message) ? $message : json_encode($message)));
        }
        if (!is_array($decoded) || !isset($decoded['answers'])) {
            throw new RuntimeException('Jev API je vrnil nepričakovan odgovor.');
        }
        return $decoded;
    }
}
