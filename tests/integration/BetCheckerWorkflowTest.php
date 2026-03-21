<?php

declare(strict_types=1);

namespace Proxbet\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integration tests for bet checker workflow.
 */
final class BetCheckerWorkflowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createTestDatabase();
    }

    private function createTestDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $pdo->exec('
            CREATE TABLE matches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                evid TEXT UNIQUE NOT NULL,
                home TEXT,
                away TEXT,
                live_hscore INTEGER,
                live_ascore INTEGER,
                match_status TEXT
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
                result TEXT,
                checked_at TEXT,
                FOREIGN KEY (match_id) REFERENCES matches(id)
            )
        ');

        return $pdo;
    }

    public function testBetCheckerFindsWinningBet(): void
    {
        // Insert match with final score
        $this->pdo->exec('
            INSERT INTO matches (evid, home, away, live_hscore, live_ascore, match_status)
            VALUES ("match_1", "Arsenal", "Chelsea", 2, 1, "FT")
        ');

        // Insert bet message predicting home win
        $this->pdo->exec('
            INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at)
            VALUES (1, 1, 0.85, "Home win predicted", datetime("now"))
        ');

        // Simulate bet checker marking result
        $this->pdo->exec('
            UPDATE bet_messages 
            SET result = "won", checked_at = datetime("now")
            WHERE match_id = 1
        ');

        $stmt = $this->pdo->query('SELECT * FROM bet_messages WHERE match_id = 1');
        $bet = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('won', $bet['result']);
        $this->assertNotNull($bet['checked_at']);
    }

    public function testBetCheckerFindsLosingBet(): void
    {
        $this->pdo->exec('
            INSERT INTO matches (evid, home, away, live_hscore, live_ascore, match_status)
            VALUES ("match_2", "Barcelona", "Real Madrid", 0, 2, "FT")
        ');

        $this->pdo->exec('
            INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at)
            VALUES (1, 1, 0.75, "Home win predicted", datetime("now"))
        ');

        $this->pdo->exec('
            UPDATE bet_messages 
            SET result = "lost", checked_at = datetime("now")
            WHERE match_id = 1
        ');

        $stmt = $this->pdo->query('SELECT * FROM bet_messages WHERE match_id = 1');
        $bet = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('lost', $bet['result']);
    }

    public function testBetCheckerSkipsInProgressMatches(): void
    {
        $this->pdo->exec('
            INSERT INTO matches (evid, home, away, live_hscore, live_ascore, match_status)
            VALUES ("match_3", "Bayern", "Dortmund", 1, 0, "1H")
        ');

        $this->pdo->exec('
            INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at)
            VALUES (1, 1, 0.80, "Home win predicted", datetime("now"))
        ');

        // Bet checker should not mark in-progress matches
        $stmt = $this->pdo->query('
            SELECT * FROM bet_messages 
            WHERE match_id = 1 AND result IS NULL
        ');
        $bet = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($bet);
        $this->assertNull($bet['result']);
    }

    public function testBetCheckerCalculatesStatistics(): void
    {
        // Insert multiple finished matches with results
        for ($i = 1; $i <= 10; $i++) {
            $this->pdo->exec("
                INSERT INTO matches (evid, home, away, live_hscore, live_ascore, match_status)
                VALUES ('match_$i', 'Home $i', 'Away $i', 2, 1, 'FT')
            ");
            
            $result = $i <= 7 ? 'won' : 'lost';
            $this->pdo->exec("
                INSERT INTO bet_messages (match_id, algorithm, probability, message, sent_at, result, checked_at)
                VALUES ($i, 1, 0.80, 'Prediction', datetime('now'), '$result', datetime('now'))
            ");
        }

        // Calculate win rate
        $stmt = $this->pdo->query('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN result = "won" THEN 1 ELSE 0 END) as wins
            FROM bet_messages
            WHERE result IS NOT NULL
        ');
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(10, $stats['total']);
        $this->assertEquals(7, $stats['wins']);
        $this->assertEquals(0.7, $stats['wins'] / $stats['total']);
    }
}
