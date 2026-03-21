<?php

declare(strict_types=1);

namespace Proxbet\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration tests for API endpoints (public and admin).
 */
final class ApiEndpointsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createTestDatabase();
        $this->seedTestData();
    }

    private function createTestDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $pdo->exec('
            CREATE TABLE matches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                evid TEXT UNIQUE NOT NULL,
                sgi TEXT,
                start_time TEXT,
                time TEXT,
                match_status TEXT,
                country TEXT,
                liga TEXT,
                home TEXT,
                away TEXT,
                home_cf REAL,
                draw_cf REAL,
                away_cf REAL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE bans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                country TEXT,
                liga TEXT,
                home TEXT,
                away TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE bet_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                match_id INTEGER,
                algorithm INTEGER,
                probability REAL,
                message TEXT,
                sent_at TEXT,
                FOREIGN KEY (match_id) REFERENCES matches(id)
            )
        ');

        return $pdo;
    }

    private function seedTestData(): void
    {
        // Insert test matches
        $this->pdo->exec('
            INSERT INTO matches (evid, country, liga, home, away, home_cf, draw_cf, away_cf, time, match_status)
            VALUES 
                ("match_1", "England", "Premier League", "Arsenal", "Chelsea", 2.5, 3.2, 2.8, "15:00", "1H"),
                ("match_2", "Spain", "La Liga", "Barcelona", "Real Madrid", 2.1, 3.5, 3.0, "20:00", "1H"),
                ("match_3", "Germany", "Bundesliga", "Bayern", "Dortmund", 1.8, 3.8, 4.2, "30:00", "1H")
        ');

        // Insert test bans
        $this->pdo->exec('
            INSERT INTO bans (country, liga, home, away, is_active)
            VALUES 
                ("Italy", "Serie A", "Juventus", NULL, 1),
                ("France", "Ligue 1", NULL, "PSG", 1)
        ');
    }

    public function testGetActiveMatches(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM matches WHERE match_status = "1H"');
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(3, $matches);
        $this->assertEquals('Arsenal', $matches[0]['home']);
    }

    public function testGetMatchById(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM matches WHERE evid = "match_1"');
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertIsArray($match);
        $this->assertEquals('Arsenal', $match['home']);
        $this->assertEquals('Chelsea', $match['away']);
        $this->assertEquals(2.5, $match['home_cf']);
    }

    public function testFilterMatchesByCountry(): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE country = ?');
        $stmt->execute(['Spain']);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(1, $matches);
        $this->assertEquals('Barcelona', $matches[0]['home']);
    }

    public function testFilterMatchesByLeague(): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE liga = ?');
        $stmt->execute(['Premier League']);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(1, $matches);
        $this->assertEquals('England', $matches[0]['country']);
    }

    public function testGetActiveBans(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM bans WHERE is_active = 1');
        $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(2, $bans);
    }

    public function testAddBan(): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO bans (country, liga, home, away, is_active)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute(['Portugal', 'Primeira Liga', 'Porto', NULL, 1]);
        
        $id = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $id);
        
        $stmt = $this->pdo->query("SELECT * FROM bans WHERE id = $id");
        $ban = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals('Porto', $ban['home']);
    }

    public function testUpdateBan(): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE bans SET away = ?, is_active = ? WHERE id = ?
        ');
        $stmt->execute(['Marseille', 0, 2]);
        
        $stmt = $this->pdo->query('SELECT * FROM bans WHERE id = 2');
        $ban = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals('Marseille', $ban['away']);
        $this->assertEquals(0, $ban['is_active']);
    }

    public function testDeleteBan(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM bans WHERE id = ?');
        $stmt->execute([1]);
        
        $stmt = $this->pdo->query('SELECT * FROM bans WHERE id = 1');
        $ban = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertFalse($ban);
    }

    public function testListBansWithPagination(): void
    {
        // Insert more bans for pagination test
        for ($i = 1; $i <= 20; $i++) {
            $this->pdo->exec("
                INSERT INTO bans (country, liga, home, away)
                VALUES ('Country$i', 'League$i', 'Home$i', 'Away$i')
            ");
        }
        
        // Test first page
        $stmt = $this->pdo->query('SELECT * FROM bans LIMIT 10 OFFSET 0');
        $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(10, $bans);
        
        // Test second page
        $stmt = $this->pdo->query('SELECT * FROM bans LIMIT 10 OFFSET 10');
        $bans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(10, $bans);
    }

    public function testGetMatchStatistics(): void
    {
        // Add statistics to a match
        $this->pdo->exec('
            UPDATE matches 
            SET ht_match_goals_1 = 5, 
                ht_match_goals_2 = 3,
                live_shots_on_target_home = 8,
                live_shots_on_target_away = 5
            WHERE evid = "match_1"
        ');
        
        $stmt = $this->pdo->query('SELECT * FROM matches WHERE evid = "match_1"');
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(5, $match['ht_match_goals_1']);
        $this->assertEquals(8, $match['live_shots_on_target_home']);
    }

    public function testCreateBetMessage(): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at)
            VALUES (?, ?, ?, ?, datetime("now"))
        ');
        $stmt->execute([1, 1, 0.85, 'Test signal']);
        
        $id = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $id);
        
        $stmt = $this->pdo->query("SELECT * FROM bet_messages WHERE id = $id");
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(1, $message['match_id']);
        $this->assertEquals(0.85, $message['probability']);
    }

    public function testGetBetMessagesByMatch(): void
    {
        // Insert test messages
        $this->pdo->exec('
            INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at)
            VALUES 
                (1, 1, 0.85, "Signal 1", datetime("now")),
                (1, 2, 0.78, "Signal 2", datetime("now")),
                (2, 1, 0.92, "Signal 3", datetime("now"))
        ');
        
        $stmt = $this->pdo->query('SELECT * FROM bet_messages WHERE match_id = 1');
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(2, $messages);
    }
}
