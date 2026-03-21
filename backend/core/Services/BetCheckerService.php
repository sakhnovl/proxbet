<?php

declare(strict_types=1);

namespace Proxbet\Core\Services;

use Proxbet\Line\Logger;
use Proxbet\Scanner\BetChecker;
use Proxbet\Scanner\BetMessageRepository;

/**
 * Service for checking bet results and updating statistics.
 */
class BetCheckerService
{
    private BetMessageRepository $repository;
    private BetChecker $checker;

    public function __construct(BetMessageRepository $repository, string $botToken)
    {
        $this->repository = $repository;
        $this->checker = new BetChecker($repository, $botToken);
    }

    /**
     * Check all pending bets and update their status.
     *
     * @return array{checked: int, won: int, lost: int, pending: int, errors: int}
     */
    public function checkPendingBets(): array
    {
        $result = $this->checker->checkPendingBets();
        
        Logger::info('Bet checker finished', [
            'checked' => $result['checked'],
            'won' => $result['won'],
            'lost' => $result['lost'],
            'pending' => $result['pending'],
            'errors' => $result['errors'],
        ]);

        return $result;
    }

    /**
     * Get betting statistics.
     *
     * @return array{total: int, pending: int, won: int, lost: int, win_rate: float}
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * Display statistics in formatted output.
     */
    public function displayStatistics(): void
    {
        $stats = $this->getStatistics();
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "СТАТИСТИКА СТАВОК\n";
        echo str_repeat('=', 60) . "\n";
        echo sprintf("Всего ставок: %d\n", $stats['total']);
        echo sprintf("Ожидают: %d | Выиграно: %d | Проиграно: %d\n", 
            $stats['pending'], $stats['won'], $stats['lost']);
        
        $completed = $stats['won'] + $stats['lost'];
        if ($completed > 0) {
            echo sprintf("Процент выигрыша: %.2f%% (%d из %d)\n", 
                $stats['win_rate'], $stats['won'], $completed);
        }
        echo str_repeat('=', 60) . "\n\n";
    }
}
