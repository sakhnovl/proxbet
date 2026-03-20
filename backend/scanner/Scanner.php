<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;

/**
 * Main scanner orchestrator for first half goal probability analysis.
 */
final class Scanner
{
    public function __construct(
        private DataExtractor $extractor,
        private ProbabilityCalculator $calculator,
        private MatchFilter $filter,
    ) {
    }

    /**
     * Scan all active matches and return analysis results.
     *
     * @return array{total:int,analyzed:int,signals:int,results:array<int,array<string,mixed>>}
     */
    public function scan(): array
    {
        $matches = $this->extractor->getActiveMatches();
        $total = count($matches);
        $analyzed = 0;
        $signals = 0;
        $results = [];

        Logger::info('Scanner started', ['total_matches' => $total]);

        foreach ($matches as $match) {
            try {
                $result = $this->scanMatch($match);
                if ($result !== null) {
                    $results[] = $result;
                    $analyzed++;

                    if ($result['decision']['bet']) {
                        $signals++;
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('Failed to scan match', [
                    'match_id' => $match['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Logger::info('Scanner completed', [
            'total' => $total,
            'analyzed' => $analyzed,
            'signals' => $signals,
        ]);

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'signals' => $signals,
            'results' => $results,
        ];
    }

    /**
     * Scan a single match and return analysis result.
     *
     * @param array<string,mixed> $match
     * @return array<string,mixed>|null
     */
    private function scanMatch(array $match): ?array
    {
        $matchId = (int) ($match['id'] ?? 0);
        $home = (string) ($match['home'] ?? '');
        $away = (string) ($match['away'] ?? '');
        $country = (string) ($match['country'] ?? '');
        $liga = (string) ($match['liga'] ?? '');

        // Extract data
        $formData = $this->extractor->extractFormData($match);
        $h2hData = $this->extractor->extractH2hData($match);
        $liveData = $this->extractor->extractLiveData($match);

        // Skip if no live data
        if ($liveData['minute'] === 0) {
            return null;
        }

        // Skip if match is beyond first half (only analyze first 45 minutes)
        if ($liveData['minute'] > 45) {
            return null;
        }

        // Calculate probabilities
        $scores = $this->calculator->calculateAll($formData, $h2hData, $liveData);

        // Apply filters and get decision
        $decision = $this->filter->shouldBet(
            $liveData,
            $scores['probability'],
            $formData,
            $h2hData
        );

        if (!$formData['has_data'] || !$h2hData['has_data']) {
            Logger::info('Scanner skipped match because statistics are incomplete', [
                'match_id' => $matchId,
                'home' => $home,
                'away' => $away,
                'has_form' => $formData['has_data'],
                'has_h2h' => $h2hData['has_data'],
                'reason' => $decision['reason'],
            ]);
        }

        return [
            'match_id' => $matchId,
            'country' => $country,
            'liga' => $liga,
            'home' => $home,
            'away' => $away,
            'minute' => $liveData['minute'],
            'time' => $liveData['time_str'],
            'score_home' => $liveData['ht_hscore'],
            'score_away' => $liveData['ht_ascore'],
            'probability' => $scores['probability'],
            'form_score' => $scores['form_score'],
            'h2h_score' => $scores['h2h_score'],
            'live_score' => $scores['live_score'],
            'decision' => $decision,
            'stats' => [
                'shots_total' => $liveData['shots_total'],
                'shots_on_target' => $liveData['shots_on_target'],
                'dangerous_attacks' => $liveData['dangerous_attacks'],
                'corners' => $liveData['corners'],
            ],
            'form_data' => [
                'home_goals' => $formData['home_goals'],
                'away_goals' => $formData['away_goals'],
            ],
            'h2h_data' => [
                'home_goals' => $h2hData['home_goals'],
                'away_goals' => $h2hData['away_goals'],
            ],
        ];
    }
}
