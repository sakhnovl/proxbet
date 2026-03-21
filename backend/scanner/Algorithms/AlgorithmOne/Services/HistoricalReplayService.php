<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Services;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator as LegacyProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\CardFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\LeagueFactorCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\PdiCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\RedFlagChecker;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ShotQualityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TimePressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TrendCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\XgPressureCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;
use Proxbet\Scanner\Algorithms\AlgorithmOne\DataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Proxbet\Scanner\ProbabilityCalculator as CurrentV2ProbabilityCalculator;
use Proxbet\Statistic\HtMetricsCalculator;

final class HistoricalReplayService
{
    private const PROFILE_LEGACY = 'legacy';
    private const PROFILE_CURRENT_V2 = 'current_v2';
    private const PROFILE_FIXED_V2 = 'fixed_v2';
    private const PROFILE_TUNED_V2 = 'tuned_v2';

    /**
     * @var array<string,string>
     */
    private const TUNED_ENV = [
        'ALGORITHM1_V2_MIN_PROBABILITY' => '0.52',
        'ALGORITHM1_V2_THRESHOLD_CANDIDATES' => '0.55,0.52,0.50',
        'ALGORITHM1_V2_TIME_PRESSURE_EXPONENT' => '1.05',
        'ALGORITHM1_V2_TIME_PRESSURE_EARLY_WINDOW_END' => '19',
        'ALGORITHM1_V2_TIME_PRESSURE_EARLY_FLOOR' => '0.28',
    ];

    private DataExtractor $extractor;
    private WeightedFormService $weightedFormService;
    private LeagueProfileService $leagueProfileService;
    private LegacyProbabilityCalculator $legacyCalculator;
    private LegacyFilter $legacyFilter;
    private CurrentV2ProbabilityCalculator $currentV2Calculator;
    private ProbabilityCalculatorV2 $v2Calculator;

    public function __construct(
        ?DataExtractor $extractor = null,
        ?WeightedFormService $weightedFormService = null,
        ?LeagueProfileService $leagueProfileService = null,
        ?LegacyProbabilityCalculator $legacyCalculator = null,
        ?LegacyFilter $legacyFilter = null,
        ?CurrentV2ProbabilityCalculator $currentV2Calculator = null,
        ?ProbabilityCalculatorV2 $v2Calculator = null,
    ) {
        $this->extractor = $extractor ?? new DataExtractor();
        $this->weightedFormService = $weightedFormService ?? new WeightedFormService(new HtMetricsCalculator());
        $this->leagueProfileService = $leagueProfileService ?? new LeagueProfileService();
        $this->legacyCalculator = $legacyCalculator ?? new LegacyProbabilityCalculator(
            new FormScoreCalculator(),
            new H2hScoreCalculator(),
            new LiveScoreCalculator(),
        );
        $this->legacyFilter = $legacyFilter ?? new LegacyFilter();
        $this->currentV2Calculator = $currentV2Calculator ?? new CurrentV2ProbabilityCalculator();
        $this->currentV2Calculator->setAlgorithmVersion(Config::VERSION_V2);
        $this->v2Calculator = $v2Calculator ?? new ProbabilityCalculatorV2(
            new PdiCalculator(),
            new ShotQualityCalculator(),
            new TrendCalculator(),
            new TimePressureCalculator(),
            new LeagueFactorCalculator(),
            new CardFactorCalculator(),
            new XgPressureCalculator(),
            new RedFlagChecker(),
        );
    }

    /**
     * @param array<int,array{match:array<string,mixed>,snapshots:list<array<string,mixed>>}> $matches
     * @return array<string,mixed>
     */
    public function replay(array $matches): array
    {
        $profiles = [
            self::PROFILE_LEGACY,
            self::PROFILE_CURRENT_V2,
            self::PROFILE_FIXED_V2,
            self::PROFILE_TUNED_V2,
        ];

        $report = [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'profiles' => [],
            'comparisons' => [],
            'match_results' => [],
            'summary' => [
                'matches_input' => count($matches),
                'matches_replayed' => 0,
                'matches_skipped' => 0,
                'skipped_reasons' => [],
            ],
        ];

        foreach ($profiles as $profile) {
            $report['profiles'][$profile] = $this->createEmptyProfileSummary();
        }

        foreach ($matches as $entry) {
            $match = $entry['match'];
            $snapshots = $this->normalizeSnapshots($entry['snapshots']);
            $outcome = $this->resolveMatchOutcome($match);

            if ($snapshots === []) {
                $report['summary']['matches_skipped']++;
                $this->incrementCount($report['summary']['skipped_reasons'], 'no_snapshots_in_window');
                continue;
            }

            if ($outcome === null) {
                $report['summary']['matches_skipped']++;
                $this->incrementCount($report['summary']['skipped_reasons'], 'unresolved_outcome');
                continue;
            }

            $report['summary']['matches_replayed']++;
            $leagueCategory = $this->leagueProfileService->classify(
                (string) ($match['country'] ?? ''),
                (string) ($match['liga'] ?? ''),
            );
            $weightedForm = $this->extractWeightedForm($match);
            $perProfile = [];

            foreach ($profiles as $profile) {
                $perProfile[$profile] = $this->replayProfile($profile, $match, $snapshots, $weightedForm, $outcome);
                $this->appendProfileSummary($report['profiles'][$profile], $perProfile[$profile], $leagueCategory);
            }

            $report['match_results'][] = [
                'match_id' => (int) ($match['id'] ?? 0),
                'home' => (string) ($match['home'] ?? ''),
                'away' => (string) ($match['away'] ?? ''),
                'country' => (string) ($match['country'] ?? ''),
                'liga' => (string) ($match['liga'] ?? ''),
                'league_category' => $leagueCategory,
                'outcome' => $outcome,
                'profiles' => $perProfile,
            ];
        }

        foreach ($profiles as $profile) {
            $summary = &$report['profiles'][$profile];
            $summary['signal_rate'] = $summary['matches'] > 0
                ? round($summary['signals'] / $summary['matches'], 4)
                : 0.0;
            $summary['win_rate'] = $summary['signals'] > 0
                ? round($summary['wins'] / $summary['signals'], 4)
                : 0.0;
            arsort($summary['rejection_reasons']);
            ksort($summary['signal_league_coverage']);
        }

        $report['comparisons'] = $this->buildComparisons($report['match_results']);

        return $report;
    }

    /**
     * @param array<string,mixed> $report
     */
    public function buildMarkdownReport(array $report): string
    {
        $lines = [];
        $profiles = $report['profiles'] ?? [];

        $lines[] = '# Algorithm 1 Historical Validation';
        $lines[] = '';
        $lines[] = '- Generated at: ' . (string) ($report['generated_at'] ?? '');
        $lines[] = '- Matches replayed: ' . (string) (($report['summary']['matches_replayed'] ?? 0));
        $lines[] = '- Matches skipped: ' . (string) (($report['summary']['matches_skipped'] ?? 0));
        $lines[] = '';
        $lines[] = '## Comparison Table';
        $lines[] = '';
        $lines[] = '| Profile | Matches | Signals | Signal Rate | Wins | Losses | Win Rate |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: |';

        foreach ([self::PROFILE_LEGACY, self::PROFILE_CURRENT_V2, self::PROFILE_FIXED_V2, self::PROFILE_TUNED_V2] as $profile) {
            $summary = $profiles[$profile] ?? $this->createEmptyProfileSummary();
            $lines[] = sprintf(
                '| %s | %d | %d | %.2f%% | %d | %d | %.2f%% |',
                $profile,
                (int) $summary['matches'],
                (int) $summary['signals'],
                ((float) $summary['signal_rate']) * 100,
                (int) $summary['wins'],
                (int) $summary['losses'],
                ((float) $summary['win_rate']) * 100,
            );
        }

        $lines[] = '';
        $lines[] = '## Rejection Reasons';
        $lines[] = '';

        foreach ([self::PROFILE_LEGACY, self::PROFILE_CURRENT_V2, self::PROFILE_FIXED_V2, self::PROFILE_TUNED_V2] as $profile) {
            $summary = $profiles[$profile] ?? $this->createEmptyProfileSummary();
            $topReasons = array_slice((array) $summary['rejection_reasons'], 0, 5, true);

            $lines[] = '### ' . $profile;
            if ($topReasons === []) {
                $lines[] = '- no rejections';
            } else {
                foreach ($topReasons as $reason => $count) {
                    $lines[] = sprintf('- `%s`: %d', (string) $reason, (int) $count);
                }
            }

            $coverage = (array) ($summary['signal_league_coverage'] ?? []);
            if ($coverage !== []) {
                $lines[] = '- coverage: '
                    . implode(', ', array_map(
                        static fn (string $category, int $count): string => sprintf('%s=%d', $category, $count),
                        array_keys($coverage),
                        array_values($coverage),
                    ));
            }

            $lines[] = '';
        }

        $leaderBySignals = $this->findBestProfile($profiles, 'signals');
        $leaderByWinRate = $this->findBestProfile($profiles, 'win_rate');
        $lines[] = '## Short Analysis';
        $lines[] = '';

        if ($leaderBySignals !== null) {
            $summary = $profiles[$leaderBySignals];
            $lines[] = sprintf('- Highest signal volume: `%s` with %d signals from %d matches.', $leaderBySignals, (int) $summary['signals'], (int) $summary['matches']);
        }

        if ($leaderByWinRate !== null) {
            $summary = $profiles[$leaderByWinRate];
            $lines[] = sprintf('- Best win rate: `%s` at %.2f%%.', $leaderByWinRate, ((float) $summary['win_rate']) * 100);
        }

        $currentSummary = $profiles[self::PROFILE_CURRENT_V2] ?? $this->createEmptyProfileSummary();
        $fixedSummary = $profiles[self::PROFILE_FIXED_V2] ?? $this->createEmptyProfileSummary();
        $tunedSummary = $profiles[self::PROFILE_TUNED_V2] ?? $this->createEmptyProfileSummary();

        $lines[] = sprintf('- `current_v2` produced %d signals; `fixed_v2` produced %d; `tuned_v2` produced %d.', (int) $currentSummary['signals'], (int) $fixedSummary['signals'], (int) $tunedSummary['signals']);
        $lines[] = '- Replay limitation: trend deltas are reconstructed from stored snapshots; red-card history is only available when present on the match row.';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<string,mixed> $match
     * @return 'won'|'lost'|null
     */
    public function resolveMatchOutcome(array $match): ?string
    {
        $htGoals = (int) ($match['live_ht_hscore'] ?? 0) + (int) ($match['live_ht_ascore'] ?? 0);
        if ($htGoals > 0) {
            return 'won';
        }

        $status = mb_strtolower(trim((string) ($match['match_status'] ?? '')));
        $time = (string) ($match['time'] ?? '');
        $minute = $this->parseMinute($time);

        if ($minute >= 45 || str_contains($status, 'перерыв') || str_contains($status, 'заверш')) {
            return 'lost';
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     * @return list<array<string,mixed>>
     */
    public function normalizeSnapshots(array $snapshots): array
    {
        $normalized = array_values(array_filter(
            $snapshots,
            fn (array $snapshot): bool => $this->parseMinute((string) ($snapshot['time'] ?? '')) >= Config::MIN_MINUTE
                && $this->parseMinute((string) ($snapshot['time'] ?? '')) <= Config::MAX_MINUTE
        ));

        usort($normalized, static function (array $left, array $right): int {
            $leftMinute = (int) ($left['minute'] ?? 0);
            $rightMinute = (int) ($right['minute'] ?? 0);
            if ($leftMinute !== $rightMinute) {
                return $leftMinute <=> $rightMinute;
            }

            return strcmp((string) ($left['captured_at'] ?? ''), (string) ($right['captured_at'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param array<string,mixed> $match
     * @param list<array<string,mixed>> $snapshots
     * @param array<string,mixed>|null $weightedForm
     * @return array<string,mixed>
     */
    private function replayProfile(
        string $profile,
        array $match,
        array $snapshots,
        ?array $weightedForm,
        string $outcome,
    ): array {
        $lastEvaluation = null;

        foreach ($snapshots as $index => $snapshot) {
            $replayRow = $this->buildReplayRow($match, $snapshots, $index);
            $evaluation = match ($profile) {
                self::PROFILE_LEGACY => $this->evaluateLegacy($match, $replayRow),
                self::PROFILE_CURRENT_V2 => $this->evaluateCurrentV2($match, $replayRow),
                self::PROFILE_FIXED_V2 => $this->evaluateModernV2($match, $replayRow, $weightedForm, []),
                self::PROFILE_TUNED_V2 => $this->evaluateModernV2($match, $replayRow, $weightedForm, self::TUNED_ENV),
                default => throw new \InvalidArgumentException('Unknown replay profile: ' . $profile),
            };

            $lastEvaluation = $evaluation;

            if ($evaluation['bet']) {
                return [
                    'signal' => true,
                    'minute' => (int) ($snapshot['minute'] ?? 0),
                    'probability' => (float) $evaluation['probability'],
                    'reason' => (string) $evaluation['reason'],
                    'outcome' => $outcome,
                    'won' => $outcome === 'won',
                ];
            }
        }

        return [
            'signal' => false,
            'minute' => $lastEvaluation['minute'] ?? null,
            'probability' => (float) ($lastEvaluation['probability'] ?? 0.0),
            'reason' => (string) ($lastEvaluation['reason'] ?? 'no_signal_in_window'),
            'outcome' => $outcome,
            'won' => false,
        ];
    }

    /**
     * @param array<string,mixed> $match
     * @param array<string,mixed> $replayRow
     * @return array{bet:bool,reason:string,probability:float,minute:int}
     */
    private function evaluateLegacy(array $match, array $replayRow): array
    {
        $formData = $this->extractor->extractFormData($match);
        $h2hData = $this->extractor->extractH2hData($match);
        $liveData = $this->extractor->extractLiveData($replayRow);
        $result = $this->legacyCalculator->calculate($formData, $h2hData, $liveData);
        $decision = $this->legacyFilter->shouldBet($liveData, $result['probability'], $formData, $h2hData);

        return [
            'bet' => (bool) $decision['bet'],
            'reason' => (string) $decision['reason'],
            'probability' => (float) $result['probability'],
            'minute' => (int) $liveData['minute'],
        ];
    }

    /**
     * @param array<string,mixed> $match
     * @param array<string,mixed> $replayRow
     * @return array{bet:bool,reason:string,probability:float,minute:int}
     */
    private function evaluateCurrentV2(array $match, array $replayRow): array
    {
        $formData = $this->extractor->extractFormData($match);
        $h2hData = $this->extractor->extractH2hData($match);
        $liveData = $this->extractor->extractLiveData($replayRow);
        $result = $this->currentV2Calculator->calculateAll($formData, $h2hData, $liveData);

        return [
            'bet' => (bool) (($result['decision']['bet'] ?? false)),
            'reason' => (string) (($result['decision']['reason'] ?? 'unknown')),
            'probability' => (float) ($result['probability'] ?? 0.0),
            'minute' => (int) $liveData['minute'],
        ];
    }

    /**
     * @param array<string,mixed> $match
     * @param array<string,mixed> $replayRow
     * @param array<string,mixed>|null $weightedForm
     * @param array<string,string> $envOverrides
     * @return array{bet:bool,reason:string,probability:float,minute:int}
     */
    private function evaluateModernV2(
        array $match,
        array $replayRow,
        ?array $weightedForm,
        array $envOverrides,
    ): array {
        return $this->withEnvOverrides($envOverrides, function () use ($match, $replayRow, $weightedForm): array {
            $formData = $this->extractor->extractFormDataV2($match, $weightedForm);
            $h2hData = $this->extractor->extractH2hData($match);
            $liveData = $this->extractor->extractLiveDataV2($replayRow);
            $result = $this->v2Calculator->calculate($formData, $h2hData, $liveData, (int) $liveData['minute']);

            return [
                'bet' => (bool) (($result['decision']['bet'] ?? false)),
                'reason' => (string) (($result['decision']['reason'] ?? 'unknown')),
                'probability' => (float) ($result['probability'] ?? 0.0),
                'minute' => (int) $liveData['minute'],
            ];
        });
    }

    /**
     * @param array<string,mixed> $match
     * @param list<array<string,mixed>> $snapshots
     * @return array<string,mixed>
     */
    private function buildReplayRow(array $match, array $snapshots, int $index): array
    {
        $current = $snapshots[$index];
        $baseline = $this->findTrendBaseline($snapshots, $index);

        $row = $match;
        $row['time'] = $current['time'] ?? sprintf('%02d:00', (int) ($current['minute'] ?? 0));
        $row['match_status'] = $current['match_status'] ?? ($match['match_status'] ?? '');
        $row['live_ht_hscore'] = $current['live_ht_hscore'] ?? $match['live_ht_hscore'] ?? 0;
        $row['live_ht_ascore'] = $current['live_ht_ascore'] ?? $match['live_ht_ascore'] ?? 0;
        $row['live_hscore'] = $current['live_hscore'] ?? $match['live_hscore'] ?? 0;
        $row['live_ascore'] = $current['live_ascore'] ?? $match['live_ascore'] ?? 0;
        $row['live_xg_home'] = $current['live_xg_home'] ?? null;
        $row['live_xg_away'] = $current['live_xg_away'] ?? null;
        $row['live_att_home'] = $current['live_att_home'] ?? null;
        $row['live_att_away'] = $current['live_att_away'] ?? null;
        $row['live_danger_att_home'] = $current['live_danger_att_home'] ?? null;
        $row['live_danger_att_away'] = $current['live_danger_att_away'] ?? null;
        $row['live_shots_on_target_home'] = $current['live_shots_on_target_home'] ?? null;
        $row['live_shots_on_target_away'] = $current['live_shots_on_target_away'] ?? null;
        $row['live_shots_off_target_home'] = $current['live_shots_off_target_home'] ?? null;
        $row['live_shots_off_target_away'] = $current['live_shots_off_target_away'] ?? null;
        $row['live_yellow_cards_home'] = $current['live_yellow_cards_home'] ?? null;
        $row['live_yellow_cards_away'] = $current['live_yellow_cards_away'] ?? null;
        $row['live_safe_home'] = $current['live_safe_home'] ?? null;
        $row['live_safe_away'] = $current['live_safe_away'] ?? null;
        $row['live_corner_home'] = $current['live_corner_home'] ?? null;
        $row['live_corner_away'] = $current['live_corner_away'] ?? null;

        if ($baseline === null) {
            $row['live_trend_shots_total_delta'] = null;
            $row['live_trend_shots_on_target_delta'] = null;
            $row['live_trend_danger_attacks_delta'] = null;
            $row['live_trend_xg_delta'] = null;
            $row['live_trend_window_seconds'] = null;
            $row['live_trend_has_data'] = 0;

            return $row;
        }

        $row['live_trend_shots_total_delta'] = max(0, $this->totalShots($current) - $this->totalShots($baseline));
        $row['live_trend_shots_on_target_delta'] = max(0, $this->shotsOnTarget($current) - $this->shotsOnTarget($baseline));
        $row['live_trend_danger_attacks_delta'] = max(0, $this->dangerousAttacks($current) - $this->dangerousAttacks($baseline));
        $row['live_trend_xg_delta'] = $this->buildXgDelta($current, $baseline);
        $row['live_trend_window_seconds'] = max(
            60,
            ((int) ($current['minute'] ?? 0) - (int) ($baseline['minute'] ?? 0)) * 60,
        );
        $row['live_trend_has_data'] = 1;

        return $row;
    }

    /**
     * @param list<array<string,mixed>> $snapshots
     * @return array<string,mixed>|null
     */
    private function findTrendBaseline(array $snapshots, int $index): ?array
    {
        $currentMinute = (int) ($snapshots[$index]['minute'] ?? 0);
        $baseline = null;

        for ($cursor = 0; $cursor < $index; $cursor++) {
            $candidate = $snapshots[$cursor];
            $candidateMinute = (int) ($candidate['minute'] ?? 0);
            if ($candidateMinute < ($currentMinute - 5)) {
                continue;
            }

            $baseline = $candidate;
            break;
        }

        return $baseline;
    }

    /**
     * @param array<string,mixed> $match
     * @return array<string,mixed>|null
     */
    private function extractWeightedForm(array $match): ?array
    {
        $sgiJson = $match['sgi_json'] ?? null;
        if (!is_string($sgiJson) || trim($sgiJson) === '') {
            return null;
        }

        $decoded = json_decode($sgiJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $weighted = $this->weightedFormService->getWeightedForm(
            $decoded,
            (string) ($match['home'] ?? ''),
            (string) ($match['away'] ?? ''),
        );

        if (!$this->weightedFormService->hasValidData($weighted)) {
            return null;
        }

        return [
            'home' => $weighted['home'],
            'away' => $weighted['away'],
            'score' => (float) ($weighted['score'] ?? 0.0),
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $result
     */
    private function appendProfileSummary(array &$summary, array $result, string $leagueCategory): void
    {
        $summary['matches']++;

        if ($result['signal']) {
            $summary['signals']++;
            $summary['signal_league_coverage'][$leagueCategory] = ($summary['signal_league_coverage'][$leagueCategory] ?? 0) + 1;

            if ($result['won']) {
                $summary['wins']++;
            } else {
                $summary['losses']++;
            }

            return;
        }

        $summary['no_signals']++;
        $this->incrementCount($summary['rejection_reasons'], (string) $result['reason']);
    }

    /**
     * @return array<string,mixed>
     */
    private function createEmptyProfileSummary(): array
    {
        return [
            'matches' => 0,
            'signals' => 0,
            'no_signals' => 0,
            'wins' => 0,
            'losses' => 0,
            'signal_rate' => 0.0,
            'win_rate' => 0.0,
            'rejection_reasons' => [],
            'signal_league_coverage' => [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $matchResults
     * @return array<string,array<string,int>>
     */
    private function buildComparisons(array $matchResults): array
    {
        $pairs = [
            self::PROFILE_LEGACY . '_vs_' . self::PROFILE_CURRENT_V2 => [self::PROFILE_LEGACY, self::PROFILE_CURRENT_V2],
            self::PROFILE_LEGACY . '_vs_' . self::PROFILE_FIXED_V2 => [self::PROFILE_LEGACY, self::PROFILE_FIXED_V2],
            self::PROFILE_LEGACY . '_vs_' . self::PROFILE_TUNED_V2 => [self::PROFILE_LEGACY, self::PROFILE_TUNED_V2],
            self::PROFILE_CURRENT_V2 . '_vs_' . self::PROFILE_FIXED_V2 => [self::PROFILE_CURRENT_V2, self::PROFILE_FIXED_V2],
            self::PROFILE_CURRENT_V2 . '_vs_' . self::PROFILE_TUNED_V2 => [self::PROFILE_CURRENT_V2, self::PROFILE_TUNED_V2],
            self::PROFILE_FIXED_V2 . '_vs_' . self::PROFILE_TUNED_V2 => [self::PROFILE_FIXED_V2, self::PROFILE_TUNED_V2],
        ];

        $comparisons = [];
        foreach ($pairs as $label => [$left, $right]) {
            $comparisons[$label] = [
                'both_signal' => 0,
                'left_only' => 0,
                'right_only' => 0,
                'both_reject' => 0,
            ];
        }

        foreach ($matchResults as $matchResult) {
            $profiles = $matchResult['profiles'] ?? [];
            foreach ($pairs as $label => [$left, $right]) {
                $leftSignal = (bool) (($profiles[$left]['signal'] ?? false));
                $rightSignal = (bool) (($profiles[$right]['signal'] ?? false));

                if ($leftSignal && $rightSignal) {
                    $comparisons[$label]['both_signal']++;
                } elseif ($leftSignal) {
                    $comparisons[$label]['left_only']++;
                } elseif ($rightSignal) {
                    $comparisons[$label]['right_only']++;
                } else {
                    $comparisons[$label]['both_reject']++;
                }
            }
        }

        return $comparisons;
    }

    /**
     * @param array<string,mixed> $profiles
     */
    private function findBestProfile(array $profiles, string $metric): ?string
    {
        $winner = null;
        $winnerValue = null;

        foreach ($profiles as $profile => $summary) {
            $value = $summary[$metric] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            if ($winner === null || (float) $value > (float) $winnerValue) {
                $winner = (string) $profile;
                $winnerValue = (float) $value;
            }
        }

        return $winner;
    }

    /**
     * @param array<string,int> $counts
     */
    private function incrementCount(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,mixed>
     */
    private function withEnvOverrides(array $overrides, callable $callback): array
    {
        $previous = [];

        foreach ($overrides as $key => $value) {
            $previous[$key] = array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($overrides as $key => $_) {
                if ($previous[$key] === null) {
                    unset($_ENV[$key]);
                    putenv($key);
                    continue;
                }

                $_ENV[$key] = $previous[$key];
                putenv($key . '=' . $previous[$key]);
            }
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function totalShots(array $snapshot): int
    {
        return $this->shotsOnTarget($snapshot)
            + (int) (($snapshot['live_shots_off_target_home'] ?? 0))
            + (int) (($snapshot['live_shots_off_target_away'] ?? 0));
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function shotsOnTarget(array $snapshot): int
    {
        return (int) (($snapshot['live_shots_on_target_home'] ?? 0))
            + (int) (($snapshot['live_shots_on_target_away'] ?? 0));
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function dangerousAttacks(array $snapshot): int
    {
        return (int) (($snapshot['live_danger_att_home'] ?? 0))
            + (int) (($snapshot['live_danger_att_away'] ?? 0));
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $baseline
     */
    private function buildXgDelta(array $current, array $baseline): ?float
    {
        $currentXg = $this->snapshotTotalXg($current);
        $baselineXg = $this->snapshotTotalXg($baseline);

        if ($currentXg === null || $baselineXg === null) {
            return null;
        }

        return round(max(0.0, $currentXg - $baselineXg), 4);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function snapshotTotalXg(array $snapshot): ?float
    {
        $home = $snapshot['live_xg_home'] ?? null;
        $away = $snapshot['live_xg_away'] ?? null;

        if (!is_numeric($home) || !is_numeric($away)) {
            return null;
        }

        return (float) $home + (float) $away;
    }

    private function parseMinute(string $time): int
    {
        $parts = explode(':', $time);
        if ($parts === [] || !is_numeric($parts[0])) {
            return 0;
        }

        return max(0, (int) $parts[0]);
    }
}
