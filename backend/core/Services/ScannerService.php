<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\DataExtractor;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\ResultFormatter;
use Proxbet\Scanner\Scanner;
use Proxbet\Scanner\TelegramNotifier;

/**
 * Service for scanning matches and generating betting signals.
 */
class ScannerService
{
    private Scanner $scanner;
    private ?TelegramNotifier $notifier;

    public function __construct(
        DataExtractor $extractor,
        ProbabilityCalculator $calculator,
        MatchFilter $filter,
        ResultFormatter $formatter,
        AlgorithmOne $algorithmOne,
        AlgorithmX $algorithmX,
        ?TelegramNotifier $notifier = null
    ) {
        $this->scanner = new Scanner($extractor, $calculator, $filter, $formatter, $algorithmOne, $algorithmX);
        $this->notifier = $notifier;
    }

    /**
     * Scan matches and return results.
     *
     * @return array{total: int, analyzed: int, signals: int, results: array<int, array<string, mixed>>}
     */
    public function scan(): array
    {
        return $this->scanner->scan();
    }

    /**
     * Scan and notify signals via Telegram.
     *
     * @return array{total: int, analyzed: int, signals: int, results: array<int, array<string, mixed>>}
     */
    public function scanAndNotify(): array
    {
        $result = $this->scan();

        if ($this->notifier !== null && !empty($result['results'])) {
            foreach ($result['results'] as $match) {
                if ($match['decision']['bet']) {
                    $this->notifier->notifySignal($match);
                }
            }
        }

        return $result;
    }
}
