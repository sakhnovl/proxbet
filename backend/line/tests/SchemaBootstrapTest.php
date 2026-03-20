<?php

declare(strict_types=1);

namespace Proxbet\Line\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Line\SchemaBootstrap;

final class SchemaBootstrapTest extends TestCase
{
    public function testRequiredTablesContainCoreRuntimeTables(): void
    {
        $tables = SchemaBootstrap::requiredTables();

        $this->assertContains('matches', $tables);
        $this->assertContains('live_match_snapshots', $tables);
        $this->assertContains('bet_messages', $tables);
        $this->assertContains('telegram_users', $tables);
    }

    public function testRequiredMatchesColumnsContainStatisticsAndLiveFields(): void
    {
        $columns = SchemaBootstrap::requiredMatchesColumns();

        $this->assertSame('VARCHAR(16) NULL', $columns['time']);
        $this->assertSame('INT NULL', $columns['ht_match_goals_1']);
        $this->assertSame('DOUBLE NULL', $columns['live_xg_home']);
        $this->assertSame('INT NULL', $columns['live_trend_shots_total_delta']);
        $this->assertSame('TINYINT(1) NOT NULL DEFAULT 0', $columns['live_trend_has_data']);
        $this->assertSame('TINYINT(1) NOT NULL DEFAULT 0', $columns['stats_refresh_needed']);
    }

    public function testRequiredMatchesColumnsPreserveContractForCriticalFields(): void
    {
        $columns = SchemaBootstrap::requiredMatchesColumns();

        $this->assertArrayHasKey('match_status', $columns);
        $this->assertArrayHasKey('home_cf', $columns);
        $this->assertArrayHasKey('away_cf', $columns);
        $this->assertArrayHasKey('live_ht_hscore', $columns);
        $this->assertArrayHasKey('updated_at', $columns);
    }
}
