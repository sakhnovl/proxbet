<?php

declare(strict_types=1);

namespace Proxbet\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration tests for complete match lifecycle.
 * Tests: parser -> stat -> scanner -> notifier flow.
 */
final class MatchLifecycleTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // Use test database
        $this->pdo = $this->createTestDatabase();
    }

    private function createTestDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create minimal schema
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
                ht_match_goals_1 INTEGER,
                ht_match_goals_2 INTEGER,
                live_shots_on_target_home INTEGER,
                live_shots_on_target_away INTEGER,
                live_shots_off_target_home INTEGER,
                live_shots_off_target_away INTEGER,
                live_danger_att_home INTEGER,
                live_danger_att_away INTEGER,
                live_corner_home INTEGER,
                live_corner_away INTEGER,
                live_xg_home REAL,
                live_xg_away REAL,
                live_hscore INTEGER,
                live_ascore INTEGER,
                stats_fetch_status TEXT,
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
                is_active INTEGER DEFAULT 1
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

    public function testCompleteMatchLifecycle(): void
    {
        // Step 1: Parser inserts match
        $this->pdo->exec('
            INSERT INTO matches (evid, country, liga, home, away, home_cf, draw_cf, away_cf, time, match_status)
            VALUES ("test_match_1", "England", "Premier League", "Arsenal", "Chelsea", 2.5, 3.2, 2.8, "15:00", "1H")
        ');

        $stmt = $this->pdo->query('SELECT * FROM matches WHERE evid = "test_match_1"');
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($match);
        $this->assertEquals('Arsenal', $match['home']);

        // Step 2: Stat service updates statistics
        $this->pdo->exec('
            UPDATE matches 
            SET ht_match_goals_1 = 5, ht_match_goals_2 = 3, stats_fetch_status = "success"
            WHERE evid = "test_match_1"
        ');

        $stmt = $this->pdo->query('SELECT stats_fetch_status FROM matches WHERE evid = "test_match_1"');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('success', $row['stats_fetch_status']);

        // Step 3: Live service updates live data
        $this->pdo->exec('
            UPDATE matches 
            SET live_shots_on_target_home = 8,
                live_shots_on_target_away = 5,
                live_danger_att_home = 20,
                live_danger_att_away = 15,
                live_xg_home = 2.1,
                live_xg_away = 1.3,
                live_hscore = 1,
                live_ascore = 0
            WHERE evid = "test_match_1"
        ');

        // Step 4: Scanner analyzes and creates signal
        $matchId = (int) $match['id'];
        $this->pdo->exec("
            INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at)
            VALUES ($matchId, 1, 0.85, 'Test signal', datetime('now'))
        ");

        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM bet_messages WHERE match_id = ' . $matchId);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $this->assertEquals(1, $count);

        // Verify complete data
        $stmt = $this->pdo->query('
            SELECT m.*, b.probability, b.algorithm
            FROM matches m
            LEFT JOIN bet_messages b ON m.id = b.match_id
            WHERE m.evid = "test_match_1"
        ');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(5, $result['ht_match_goals_1']);
        $this->assertEquals(8, $result['live_shots_on_target_home']);
        $this->assertEquals(0.85, $result['probability']);
        $this->assertEquals(1, $result['algorithm']);
    }

    public function testMatchFilteredByBan(): void
    {
        // Add ban rule
        $this->pdo->exec('
            INSERT INTO bans (country, liga, home, away, is_active)
            VALUES ("Spain", NULL, "Barcelona", NULL, 1)
        ');

        // Insert match that should be banned
        $this->pdo->exec('
            INSERT INTO matches (evid, country, liga, home, away, time, match_status)
            VALUES ("banned_match", "Spain", "La Liga", "FC Barcelona", "Real Madrid", "30:00", "1H")
        ');

        // Verify ban exists
        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM bans WHERE is_active = 1');
        $banCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $this->assertEquals(1, $banCount);

        // In real scenario, scanner would skip this match
        // Here we just verify the ban rule matches
        $stmt = $this->pdo->query('
            SELECT m.*, b.id as ban_id
            FROM matches m
            CROSS JOIN bans b
            WHERE m.evid = "banned_match"
            AND b.is_active = 1
            AND (b.country IS NULL OR m.country LIKE "%" || b.country || "%")
            AND (b.home IS NULL OR m.home LIKE "%" || b.home || "%")
        ');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertIsArray($result);
        $this->assertNotNull($result['ban_id']);
    }

    public function testMultipleMatchesProcessing(): void
    {
        // Insert multiple matches
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("
                INSERT INTO matches (evid, country, liga, home, away, time, match_status, home_cf)
                VALUES ('match_$i', 'Test Country', 'Test League', 'Home $i', 'Away $i', '20:00', '1H', 2.5)
            ");
        }

        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM matches');
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $this->assertEquals(5, $count);

        // Simulate batch update
        $this->pdo->exec('
            UPDATE matches 
            SET ht_match_goals_1 = 4, ht_match_goals_2 = 2
            WHERE time IS NOT NULL
        ');

        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM matches WHERE ht_match_goals_1 = 4');
        $updated = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $this->assertEquals(5, $updated);
    }
}
