<?php
declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 8000');
        $this->migrate();
        $this->seed();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : json_decode((string) $value, true);
    }

    public function setSetting(string $key, mixed $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    event_date TEXT NOT NULL,
    venue TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'upcoming',
    source_url TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS fights (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES events(id),
    card_order INTEGER NOT NULL DEFAULT 0,
    card_section TEXT NOT NULL DEFAULT 'Prelims',
    weight_class TEXT NOT NULL DEFAULT '',
    fighter_a TEXT NOT NULL,
    fighter_b TEXT NOT NULL,
    odds_a REAL,
    odds_b REAL,
    winner TEXT,
    method TEXT,
    result_round INTEGER,
    completed INTEGER NOT NULL DEFAULT 0,
    UNIQUE(event_id, fighter_a, fighter_b)
);
CREATE TABLE IF NOT EXISTS historical_fights (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id TEXT NOT NULL UNIQUE,
    event_name TEXT NOT NULL,
    event_date TEXT NOT NULL,
    weight_class TEXT NOT NULL DEFAULT '',
    fighter_a TEXT NOT NULL,
    fighter_b TEXT NOT NULL,
    winner TEXT,
    method TEXT NOT NULL DEFAULT '',
    result_round INTEGER,
    kd_a INTEGER,
    kd_b INTEGER,
    sig_str_a INTEGER,
    sig_str_b INTEGER,
    td_a INTEGER,
    td_b INTEGER,
    sub_a INTEGER,
    sub_b INTEGER
);
CREATE INDEX IF NOT EXISTS idx_history_a ON historical_fights(fighter_a);
CREATE INDEX IF NOT EXISTS idx_history_b ON historical_fights(fighter_b);
CREATE TABLE IF NOT EXISTS predictions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES events(id),
    fight_id INTEGER NOT NULL REFERENCES fights(id),
    model TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked INTEGER NOT NULL DEFAULT 1,
    raw_json TEXT NOT NULL,
    winner_pick TEXT NOT NULL,
    p_a REAL NOT NULL,
    p_b REAL NOT NULL,
    confidence REAL NOT NULL,
    method_pick TEXT NOT NULL,
    method_probs_json TEXT NOT NULL,
    recommended_bet TEXT,
    recommended_odds REAL,
    edge REAL,
    stake REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open',
    profit REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS bets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES events(id),
    fight_id INTEGER REFERENCES fights(id),
    owner TEXT NOT NULL CHECK(owner IN ('jev', 'me')),
    selection TEXT NOT NULL,
    market TEXT NOT NULL DEFAULT 'moneyline',
    odds REAL NOT NULL,
    stake REAL NOT NULL,
    result TEXT NOT NULL DEFAULT 'open',
    profit REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS event_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES events(id),
    completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fights_settled INTEGER NOT NULL,
    correct_winner INTEGER NOT NULL,
    total_predictions INTEGER NOT NULL,
    correct_method INTEGER NOT NULL,
    profit REAL NOT NULL,
    roi REAL NOT NULL,
    brier REAL,
    report_json TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS backtest_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_name TEXT NOT NULL,
    event_date TEXT NOT NULL,
    model TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_fights INTEGER NOT NULL,
    correct_winner INTEGER NOT NULL,
    correct_method INTEGER NOT NULL,
    brier REAL NOT NULL,
    total_stake REAL NOT NULL,
    profit REAL NOT NULL,
    results_json TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS fight_prefight_data (
    fight_id INTEGER PRIMARY KEY REFERENCES fights(id),
    snapshot_date TEXT NOT NULL,
    fighter_a_json TEXT NOT NULL,
    fighter_b_json TEXT NOT NULL,
    event_context_json TEXT NOT NULL,
    source_summary TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $this->pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES ('min_event_stake','0')");
        $this->pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES ('max_event_fraction','0.35')");
        $this->pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES ('bankroll_unit','\"eur\"')");
        $futureEvents = [
            ['UFC Fight Night: Buckley vs Malott','2026-10-17T23:00:00+02:00','Rogers Place, Edmonton','https://www.ufc.com/event/ufc-fight-night-october-17-2026'],
            ['UFC 333: Volkanovski vs Evloev','2026-10-24T18:00:00+02:00','Etihad Arena, Abu Dhabi','https://www.ufc.com/event/ufc-333'],
            ['UFC Fight Night: Moicano vs Nolan','2026-10-31T22:00:00+01:00','Meta APEX, Las Vegas','https://www.ufc.com/event/ufc-fight-night-october-31-2026'],
        ];
        $futureStmt = $this->pdo->prepare('INSERT INTO events(name,event_date,venue,source_url) SELECT ?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM events WHERE source_url=?)');
        foreach ($futureEvents as $futureEvent) $futureStmt->execute([...$futureEvent,$futureEvent[3]]);
        $buckleyEventId = $this->pdo->query("SELECT id FROM events WHERE source_url='https://www.ufc.com/event/ufc-fight-night-october-17-2026' LIMIT 1")->fetchColumn();
        if ($buckleyEventId) {
            $buckleyCard = [
                ['Main','Welterweight','Joaquin Buckley','Mike Malott'],
                ['Main',"Women's Flyweight",'Erin Blanchfield','Jasmine Jasudavicius'],
                ['Main','Lightweight','Kyle Nelson','Cristian Perez Gonzalez'],
                ['Main','Middleweight','Marc-Andre Barriault','Kyle Daukaus'],
                ['Main','Bantamweight','Louis Jourdain','Timmy Cuamba'],
                ['Main','Lightweight','Mandel Nallo','Nate Landwehr'],
                ['Prelims','Heavyweight','Tanner Boser','Jhonata Diniz'],
                ['Prelims','Middleweight','Julien Leblanc','Gilbert Urbina'],
                ['Prelims','Heavyweight','Javad Mahjoub','Louie Sutherland'],
                ['Prelims',"Women's Flyweight",'Jamey-Lyn Horth','Katlyn Cerminara'],
                ['Prelims','Bantamweight','Chad Anheliger','Steven Koslow'],
                ['Prelims',"Women's Bantamweight",'Melissa Croden','Chelsea Chandler'],
                ['Prelims','Bantamweight','Cody Chovancek','SuYoung You'],
            ];
            $cardStmt=$this->pdo->prepare('INSERT OR IGNORE INTO fights(event_id,card_order,card_section,weight_class,fighter_a,fighter_b) VALUES(?,?,?,?,?,?)');
            foreach($buckleyCard as $index=>$fight)$cardStmt->execute([(int)$buckleyEventId,$index+1,...$fight]);
        }
        $scheduledCards = [
            'https://www.ufc.com/event/ufc-333' => [
                ['Main','Featherweight Title','Alexander Volkanovski','Movsar Evloev',1.952381,1.869565],
                ['Main','Bantamweight Title','Petr Yan','Merab Dvalishvili',null,null],
                ['Main','Flyweight',"Lone'er Kavanagh",'Ramazan Temirov',null,null],
                ['Main','Heavyweight','Alexander Volkov','Rizvan Kuniev',null,null],
                ['Main','Featherweight','Arnold Allen','Aaron Pico',null,null],
                ['Prelims','Light Heavyweight','Azamat Murzakanov','Dominick Reyes',null,null],
                ['Prelims','Light Heavyweight','Nikita Krylov','Abdul Rakhman Yakhyaev',null,null],
                ['Prelims','Middleweight','Abus Magomedov','Cam Rowston',null,null],
                ['Prelims','Lightweight','Grant Dawson','Nurullo Aliev',null,null],
            ],
            'https://www.ufc.com/event/ufc-fight-night-october-31-2026' => [
                ['Card','Lightweight','Renato Moicano','Tom Nolan',null,null],
                ['Card','Welterweight','Randy Brown','Carlos Leal',null,null],
                ['Card',"Women's Flyweight",'Lucia Szabova','Tainara Lisboa',null,null],
                ['Card',"Women's Bantamweight",'Yana Santos','Luana Santos',null,null],
                ['Card',"Women's Strawweight",'Talita Alencar','Piera Rodriguez',2.20,1.714286],
                ['Card','Middleweight','Nick Klein','Joseph Kropschot',null,null],
                ['Card','Welterweight','Rodrigo Sezinando','Theodor Berggren',null,null],
                ['Card','Welterweight','Jean-Paul Lebosnoyani','Farman Hasanov',null,null],
                ['Card','Middleweight','Azamat Bekoev','Andre Petroski',null,null],
                ['Card','Featherweight','Julian Erosa','JeongYeong Lee',null,null],
                ['Card','Featherweight','Francis Marshall','Gaston Bolanos',null,null],
            ],
        ];
        $scheduledEventStmt=$this->pdo->prepare('SELECT id FROM events WHERE source_url=? LIMIT 1');
        $scheduledFightStmt=$this->pdo->prepare('INSERT OR IGNORE INTO fights(event_id,card_order,card_section,weight_class,fighter_a,fighter_b,odds_a,odds_b) VALUES(?,?,?,?,?,?,?,?)');
        foreach($scheduledCards as $url=>$card){$scheduledEventStmt->execute([$url]);$scheduledEventId=$scheduledEventStmt->fetchColumn();if(!$scheduledEventId)continue;foreach($card as $index=>$fight)$scheduledFightStmt->execute([(int)$scheduledEventId,$index+1,...$fight]);}
    }

    private function seed(): void
    {
        if ((int) $this->pdo->query("SELECT COUNT(*) FROM events WHERE source_url='https://www.ufc.com/event/ufc-fight-night-october-10-2026'")->fetchColumn() > 0) {
            return;
        }

        $this->setSetting('starting_bankroll', 500.0);
        $this->setSetting('kelly_fraction', 0.50);
        $this->setSetting('max_bet_fraction', 0.15);
        $this->setSetting('max_event_fraction', 0.35);
        $this->setSetting('min_event_stake', 0.0);
        $this->setSetting('min_edge', 0.05);

        $event = $this->pdo->prepare(
            'INSERT INTO events (name, event_date, venue, source_url) VALUES (?, ?, ?, ?)'
        );
        $event->execute([
            'UFC Fight Night: Allen vs Duncan',
            '2026-10-10T23:00:00+02:00',
            'Meta APEX, Las Vegas',
            'https://www.ufc.com/event/ufc-fight-night-october-10-2026',
        ]);
        $eventId = (int) $this->pdo->lastInsertId();

        $fights = [
            ['Main', 'Middleweight', 'Brendan Allen', 'Christian Leroy Duncan', 1.704225, 2.22],
            ['Main', 'Lightweight', 'Matheus Camilo', 'Jai Herbert', null, null],
            ['Main', "Women's Strawweight", 'Loopy Godinez', 'Ketlen Souza', null, null],
            ['Main', 'Featherweight', 'Andre Fili', 'Kai Kamaka III', null, null],
            ['Main', 'Light Heavyweight', 'Julius Walker', 'Gerald Meerschaert', null, null],
            ['Main', 'Bantamweight', 'Malcolm Wellmaker', 'Otari Tanzilovi', null, null],
            ['Prelims', 'Lightweight', 'Francisco Prado', 'Ismael Bonfim', null, null],
            ['Prelims', 'Welterweight', 'Niko Price', 'Leon Shahbazyan', 2.20, 1.714286],
            ['Prelims', 'Light Heavyweight', 'Felipe Franco', 'Brendson Ribeiro', 1.322581, 3.52],
            ['Prelims', 'Heavyweight', 'Allen Frye Jr.', 'RJ Harris', 2.86, 1.454545],
            ['Prelims', "Women's Bantamweight", 'Alice Pereira', 'Daria Zhelezniakova', null, null],
            ['Prelims', "Women's Flyweight", 'Ernesta Kareckaite', 'Melissa Gatto', 2.40, 1.625],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO fights (event_id, card_order, card_section, weight_class, fighter_a, fighter_b, odds_a, odds_b) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($fights as $index => $fight) {
            $stmt->execute([$eventId, $index + 1, ...$fight]);
        }
    }
}
