<?php

declare(strict_types=1);

namespace Proxbet\Line\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Line\Db;
use PDO;

/**
 * Unit tests for Db class - critical upsert logic and ban operations.
 */
final class DbTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // Use in-memory SQLite for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create test schema
        $this->createTestSchema();
    }

    private function createTestSchema(): void
    {
        // Create matches table
        $this->pdo->exec('
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
                total_line REAL,
                total_line_tb REAL,
                total_line_tm REAL,
                btts_yes REAL,
                btts_no REAL,
                itb1 TEXT,
                itb1cf REAL,
                itb2 TEXT,
                itb2cf REAL,
                fm1 TEXT,
                fm1cf REAL,
                fm2 TEXT,
                fm2cf REAL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Create bans table
        $this->pdo->exec('
            CREATE TABLE bans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                country TEXT,
                liga TEXT,
                home TEXT,
                away TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    public function testUpsertMatchesInsertNew(): void
    {
        $matches = [
            [
                'evid' => 'test123',
                'country' => 'England',
                'liga' => 'Premier League',
                'home' => 'Arsenal',
                'away' => 'Chelsea',
                'home_cf' => 2.5,
                'draw_cf' => 3.2,
                'away_cf' => 2.8,
            ],
        ];

        $result = Db::upsertMatches($this->pdo, $matches);

        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals(0, $result['updated']);
        $this->assertEquals(0, $result['skipped']);

        // Verify data was inserted
        $stmt = $this->pdo->query('SELECT * FROM matches WHERE evid = "test123"');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertIsArray($row);
        $this->assertEquals('Arsenal', $row['home']);
        $this->assertEquals('Chelsea', $row['away']);
    }

    public function testUpsertMatchesUpdateExisting(): void
    {
        // Insert initial match
        $this->pdo->exec('
            INSERT INTO matches (evid, country, liga, home, away, home_cf)
            VALUES ("test456", "Spain", "La Liga", "Barcelona", "Real Madrid", 2.0)
        ');

        // Update with new odds
        $matches = [
            [
                'evid' => 'test456',
                'country' => 'Spain',
                'liga' => 'La Liga',
                'home' => 'Barcelona',
                'away' => 'Real Madrid',
                'home_cf' => 2.3,
                'draw_cf' => 3.5,
            ],
        ];

        $result = Db::upsertMatches($this->pdo, $matches);

        $this->assertEquals(0, $result['inserted']);
        $this->assertEquals(1, $result['updated']);

        // Verify odds were updated
        $stmt = $this->pdo->query('SELECT home_cf, draw_cf FROM matches WHERE evid = "test456"');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(2.3, $row['home_cf']);
        $this->assertEquals(3.5, $row['draw_cf']);
    }

    public function testUpsertMatchesBatchInsert(): void
    {
        $matches = [];
        for ($i = 1; $i <= 150; $i++) {
            $matches[] = [
                'evid' => "batch_$i",
                'country' => 'Test Country',
                'liga' => 'Test League',
                'home' => "Home $i",
                'away' => "Away $i",
            ];
        }

        $result = Db::upsertMatches($this->pdo, $matches);

        $this->assertEquals(150, $result['inserted']);
        $this->assertEquals(0, $result['updated']);

        // Verify all were inserted
        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM matches');
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $this->assertEquals(150, $count);
    }

    public function testUpsertMatchesSkipsInvalidData(): void
    {
        $matches = [
            ['evid' => 'valid1', 'home' => 'Team A', 'away' => 'Team B'],
            ['home' => 'Team C', 'away' => 'Team D'], // Missing evid
            ['evid' => 'valid2', 'home' => 'Team E', 'away' => 'Team F'],
        ];

        $result = Db::upsertMatches($this->pdo, $matches);

        $this->assertEquals(2, $result['inserted']);
        $this->assertEquals(1, $result['skipped']);
    }

    public function testGetActiveBans(): void
    {
        // Insert test bans
        $this->pdo->exec('
            INSERT INTO bans (country, liga, home, away, is_active)
            VALUES 
                ("England", "Premier League", "Arsenal", NULL, 1),
                ("Spain", "La Liga", NULL, "Barcelona", 1),
                ("Germany", "Bundesliga", "Bayern", "Dortmund", 0)
        ');

        $bans = Db::getActiveBans($this->pdo);

        $this->assertCount(2, $bans);
        $this->assertEquals('Arsenal', $bans[0]['home']);
        $this->assertEquals('Barcelona', $bans[1]['away']);
    }

    public function testAddBan(): void
    {
        $data = [
            'country' => 'Italy',
            'liga' => 'Serie A',
            'home' => 'Juventus',
            'away' => 'Inter',
            'is_active' => true,
        ];

        $id = Db::addBan($this->pdo, $data);

        $this->assertGreaterThan(0, $id);

        $ban = Db::getBanById($this->pdo, $id);
        $this->assertIsArray($ban);
        $this->assertEquals('Juventus', $ban['home']);
        $this->assertEquals(1, $ban['is_active']);
    }

    public function testUpdateBan(): void
    {
        $id = $this->pdo->exec('
            INSERT INTO bans (country, liga, home, away)
            VALUES ("France", "Ligue 1", "PSG", "Lyon")
        ');

        $updated = Db::updateBan($this->pdo, 1, [
            'country' => 'France',
            'liga' => 'Ligue 1',
            'home' => 'PSG',
            'away' => 'Marseille',
        ]);

        $this->assertTrue($updated);

        $ban = Db::getBanById($this->pdo, 1);
        $this->assertEquals('Marseille', $ban['away']);
    }

    public function testDeleteBan(): void
    {
        $this->pdo->exec('
            INSERT INTO bans (country, liga, home, away)
            VALUES ("Portugal", "Primeira Liga", "Porto", "Benfica")
        ');

        $deleted = Db::deleteBan($this->pdo, 1);
        $this->assertTrue($deleted);

        $ban = Db::getBanById($this->pdo, 1);
        $this->assertNull($ban);
    }

    public function testListBansWithPagination(): void
    {
        // Insert 25 test bans
        for ($i = 1; $i <= 25; $i++) {
            $this->pdo->exec("
                INSERT INTO bans (country, liga, home, away)
                VALUES ('Country$i', 'League$i', 'Home$i', 'Away$i')
            ");
        }

        $result = Db::listBans($this->pdo, 10, 0);
        $this->assertEquals(25, $result['total']);
        $this->assertCount(10, $result['rows']);

        $result = Db::listBans($this->pdo, 10, 10);
        $this->assertCount(10, $result['rows']);

        $result = Db::listBans($this->pdo, 10, 20);
        $this->assertCount(5, $result['rows']);
    }
}
