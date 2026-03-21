<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;
use Proxbet\Scanner\DataExtractor;
use PDO;

/**
 * Unit tests for DataExtractor - data extraction logic.
 */
final class DataExtractorTest extends TestCase
{
    private PDO $pdo;
    private DataExtractor $extractor;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTestSchema();
        $this->extractor = new DataExtractor($this->pdo);
    }

    private function createTestSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE matches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                evid TEXT UNIQUE NOT NULL,
                time TEXT,
                match_status TEXT,
                home TEXT,
                away TEXT,
                ht_match_goals_1 INTEGER,
                ht_match_goals_2 INTEGER,
                h2h_ht_match_goals_1 INTEGER,
                h2h_ht_match_goals_2 INTEGER,
                home_cf REAL,
                total_line REAL,
                total_line_tb REAL,
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
                live_yellow_cards_home INTEGER,
                live_yellow_cards_away INTEGER,
                live_red_cards_home INTEGER,
                live_red_cards_away INTEGER,
                live_ht_hscore INTEGER,
                live_ht_ascore INTEGER,
                live_hscore INTEGER,
                live_ascore INTEGER,
                live_trend_shots_total_delta INTEGER,
                live_trend_shots_on_target_delta INTEGER,
                live_trend_danger_attacks_delta INTEGER,
                live_trend_xg_delta REAL,
                live_trend_window_seconds INTEGER,
                live_trend_has_data INTEGER,
                table_games_1 INTEGER,
                table_goals_1 INTEGER,
                table_missed_1 INTEGER,
                table_games_2 INTEGER,
                table_goals_2 INTEGER,
                table_missed_2 INTEGER,
                table_avg REAL,
                algorithm_version INTEGER,
                live_score_components TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                live_updated_at TEXT
            )
        ');
    }

    public function testGetActiveMatches(): void
    {
        $this->pdo->exec('
            INSERT INTO matches (evid, time, match_status, home, away)
            VALUES 
                ("match1", "45:00", "1H", "Arsenal", "Chelsea"),
                ("match2", "60:00", "2H", "Barcelona", "Real Madrid"),
                ("match3", NULL, NULL, "Bayern", "Dortmund")
        ');

        $matches = $this->extractor->getActiveMatches();

        $this->assertCount(2, $matches);
        $this->assertEquals('match1', $matches[0]['evid']);
        $this->assertEquals('match2', $matches[1]['evid']);
    }

    public function testExtractFormData(): void
    {
        $match = [
            'ht_match_goals_1' => 5,
            'ht_match_goals_2' => 3,
        ];

        $result = $this->extractor->extractFormData($match);

        $this->assertTrue($result['has_data']);
        $this->assertEquals(5, $result['home_goals']);
        $this->assertEquals(3, $result['away_goals']);
    }

    public function testExtractFormDataMissing(): void
    {
        $match = [];

        $result = $this->extractor->extractFormData($match);

        $this->assertFalse($result['has_data']);
        $this->assertEquals(0, $result['home_goals']);
        $this->assertEquals(0, $result['away_goals']);
    }

    public function testExtractH2hData(): void
    {
        $match = [
            'h2h_ht_match_goals_1' => 4,
            'h2h_ht_match_goals_2' => 2,
        ];

        $result = $this->extractor->extractH2hData($match);

        $this->assertTrue($result['has_data']);
        $this->assertEquals(4, $result['home_goals']);
        $this->assertEquals(2, $result['away_goals']);
    }

    public function testExtractFormDataV2SupportsCurrentHtMetricsFormat(): void
    {
        $match = [
            'ht_match_goals_1' => 4,
            'ht_match_goals_2' => 3,
        ];

        $weightedMetrics = [
            'home' => ['attack' => 0.8, 'defense' => 0.3],
            'away' => ['attack' => 0.6, 'defense' => 0.4],
            'weighted_score' => 0.75,
        ];

        $result = $this->extractor->extractFormDataV2($match, $weightedMetrics);

        $this->assertTrue($result['has_data']);
        $this->assertNotNull($result['weighted']);
        $this->assertSame(0.75, $result['weighted']['score']);
        $this->assertSame(0.8, $result['weighted']['home']['attack']);
        $this->assertSame(0.4, $result['weighted']['away']['defense']);
    }

    public function testExtractFormDataV2SupportsLegacyWeightedFormWrapper(): void
    {
        $match = [
            'ht_match_goals_1' => 2,
            'ht_match_goals_2' => 1,
        ];

        $weightedMetrics = [
            'weighted_form' => [
                'home' => ['attack' => 0.7, 'defense' => 0.2],
                'away' => ['attack' => 0.5, 'defense' => 0.6],
                'score' => 0.68,
            ],
        ];

        $result = $this->extractor->extractFormDataV2($match, $weightedMetrics);

        $this->assertTrue($result['has_data']);
        $this->assertNotNull($result['weighted']);
        $this->assertSame(0.68, $result['weighted']['score']);
        $this->assertSame(0.7, $result['weighted']['home']['attack']);
        $this->assertSame(0.6, $result['weighted']['away']['defense']);
    }

    public function testExtractAlgorithmTwoData(): void
    {
        $match = [
            'home_cf' => 2.5,
            'total_line' => 2.5,
            'total_line_tb' => 1.8,
            'ht_match_goals_1' => 3,
        ];

        $result = $this->extractor->extractAlgorithmTwoData($match);

        $this->assertEquals(2.5, $result['home_win_odd']);
        $this->assertEquals(1.8, $result['over_25_odd']);
        $this->assertEquals(2.5, $result['total_line']);
        $this->assertFalse($result['over_25_odd_check_skipped']);
        $this->assertEquals(3, $result['home_first_half_goals_in_last_5']);
    }

    public function testExtractAlgorithmTwoDataSkipsOver25Check(): void
    {
        $match = [
            'home_cf' => 2.5,
            'total_line' => 3.5, // > 2.5
            'ht_match_goals_1' => 3,
        ];

        $result = $this->extractor->extractAlgorithmTwoData($match);

        $this->assertTrue($result['over_25_odd_check_skipped']);
        $this->assertNull($result['over_25_odd']);
    }

    public function testExtractLiveData(): void
    {
        $match = [
            'time' => '45:30',
            'match_status' => '1H',
            'live_shots_on_target_home' => 5,
            'live_shots_on_target_away' => 3,
            'live_shots_off_target_home' => 4,
            'live_shots_off_target_away' => 2,
            'live_danger_att_home' => 15,
            'live_danger_att_away' => 10,
            'live_corner_home' => 6,
            'live_corner_away' => 4,
            'live_xg_home' => 1.5,
            'live_xg_away' => 0.8,
            'live_yellow_cards_home' => 2,
            'live_yellow_cards_away' => 1,
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 0,
            'live_hscore' => 1,
            'live_ascore' => 0,
        ];

        $result = $this->extractor->extractLiveData($match);

        $this->assertEquals(45, $result['minute']);
        $this->assertEquals(14, $result['shots_total']);
        $this->assertEquals(8, $result['shots_on_target']);
        $this->assertEquals(25, $result['dangerous_attacks']);
        $this->assertEquals(10, $result['corners']);
        $this->assertEquals(1.5, $result['xg_home']);
        $this->assertEquals(0.8, $result['xg_away']);
        $this->assertEquals(2, $result['yellow_cards_home']);
        $this->assertEquals(1, $result['yellow_cards_away']);
        $this->assertEquals('45:30', $result['time_str']);
        $this->assertEquals('1H', $result['match_status']);
    }

    public function testExtractLiveDataV2WithTableAvg(): void
    {
        $match = [
            'time' => '60:00',
            'match_status' => '2H',
            'country' => 'England',
            'liga' => 'Premier League',
            'live_shots_on_target_home' => 8,
            'live_shots_on_target_away' => 5,
            'live_shots_off_target_home' => 6,
            'live_shots_off_target_away' => 4,
            'live_danger_att_home' => 20,
            'live_danger_att_away' => 15,
            'live_corner_home' => 8,
            'live_corner_away' => 6,
            'live_xg_home' => 2.1,
            'live_xg_away' => 1.3,
            'live_yellow_cards_home' => 3,
            'live_yellow_cards_away' => 2,
            'live_red_cards_home' => 1,
            'live_red_cards_away' => 0,
            'live_ht_hscore' => 1,
            'live_ht_ascore' => 1,
            'live_hscore' => 2,
            'live_ascore' => 1,
            'table_avg' => 2.75,
        ];

        $result = $this->extractor->extractLiveDataV2($match);

        $this->assertEquals(60, $result['minute']);
        $this->assertEquals(23, $result['shots_total']);
        $this->assertEquals(13, $result['shots_on_target']);
        $this->assertEqualsWithDelta(3.4, $result['xg_total'], 0.001);
        $this->assertEquals(2.75, $result['table_avg']);
        $this->assertSame(1, $result['red_cards_home']);
        $this->assertSame(0, $result['red_cards_away']);
        $this->assertSame('top-tier', $result['league_category']);
        $this->assertSame(0.55, $result['league_profile']['probability_threshold']);
    }

    public function testCardFactorCalculatorWorksWithExtractedLiveDataV2(): void
    {
        $match = [
            'time' => '25:00',
            'match_status' => '1H',
            'country' => 'Italy',
            'liga' => 'Primavera U20',
            'live_yellow_cards_home' => 1,
            'live_yellow_cards_away' => 0,
            'live_red_cards_home' => 0,
            'live_red_cards_away' => 1,
        ];

        $liveData = $this->extractor->extractLiveDataV2($match);
        $result = (new CardFactorCalculator())->calculate($liveData);

        $this->assertSame(0.03, $result);
        $this->assertSame('youth', $liveData['league_category']);
    }

    public function testExtractAlgorithmThreeData(): void
    {
        $match = [
            'table_games_1' => 10,
            'table_goals_1' => 25,
            'table_missed_1' => 15,
            'table_games_2' => 10,
            'table_goals_2' => 20,
            'table_missed_2' => 18,
            'live_hscore' => 2,
            'live_ascore' => 1,
            'match_status' => '2H',
            'home' => 'Arsenal',
            'away' => 'Chelsea',
        ];

        $result = $this->extractor->extractAlgorithmThreeData($match);

        $this->assertTrue($result['has_data']);
        $this->assertEquals(10, $result['table_games_1']);
        $this->assertEquals(25, $result['table_goals_1']);
        $this->assertEquals(15, $result['table_missed_1']);
        $this->assertEquals(10, $result['table_games_2']);
        $this->assertEquals(20, $result['table_goals_2']);
        $this->assertEquals(18, $result['table_missed_2']);
        $this->assertEquals(2, $result['live_hscore']);
        $this->assertEquals(1, $result['live_ascore']);
        $this->assertEquals('Arsenal', $result['home']);
        $this->assertEquals('Chelsea', $result['away']);
    }

    public function testUpdateAlgorithmData(): void
    {
        $this->pdo->exec('
            INSERT INTO matches (evid, time, match_status, home, away)
            VALUES ("test123", "45:00", "1H", "Arsenal", "Chelsea")
        ');

        $components = [
            'form_score' => 0.75,
            'live_score' => 0.85,
            'h2h_score' => 0.65,
        ];

        $result = $this->extractor->updateAlgorithmData(1, 2, $components);

        $this->assertTrue($result);

        $stmt = $this->pdo->query('SELECT algorithm_version, live_score_components FROM matches WHERE id = 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(2, $row['algorithm_version']);
        $this->assertNotNull($row['live_score_components']);

        $decoded = json_decode($row['live_score_components'], true);
        $this->assertEquals(0.75, $decoded['form_score']);
    }

    public function testUpdateAlgorithmDataPersistsDualRunPayload(): void
    {
        $this->pdo->exec('
            INSERT INTO matches (evid, time, match_status, home, away)
            VALUES ("test456", "27:00", "1H", "Milan", "Inter")
        ');

        $payload = [
            'algorithm_version' => 1,
            'probability' => 0.64,
            'dual_run' => [
                'legacy_probability' => 0.64,
                'legacy_decision' => 'bet',
                'v2_probability' => 0.58,
                'v2_decision' => 'no_bet',
                'divergence_level' => 'high',
            ],
        ];

        $result = $this->extractor->updateAlgorithmData(1, 1, $payload);

        $this->assertTrue($result);

        $stmt = $this->pdo->query('SELECT algorithm_version, live_score_components FROM matches WHERE id = 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(1, $row['algorithm_version']);
        $decoded = json_decode((string) $row['live_score_components'], true);

        $this->assertSame('bet', $decoded['dual_run']['legacy_decision']);
        $this->assertSame('no_bet', $decoded['dual_run']['v2_decision']);
        $this->assertSame('high', $decoded['dual_run']['divergence_level']);
    }
}
