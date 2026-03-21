<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

/**
 * Telegram Message Formatter - unified message formatting.
 * 
 * Provides consistent formatting for all Telegram bot messages.
 */
final class MessageFormatter
{
    /**
     * Format match information.
     * 
     * @param array<string,mixed> $match
     */
    public static function formatMatch(array $match): string
    {
        $parts = [];
        
        if (isset($match['liga'])) {
            $parts[] = "🏆 " . self::escape($match['liga']);
        }
        
        if (isset($match['home'], $match['away'])) {
            $parts[] = "⚽ " . self::escape($match['home']) . " vs " . self::escape($match['away']);
        }
        
        if (isset($match['start_time'])) {
            $parts[] = "🕐 " . self::escape($match['start_time']);
        }
        
        if (isset($match['country'])) {
            $parts[] = "🌍 " . self::escape($match['country']);
        }
        
        return implode("\n", $parts);
    }

    /**
     * Format signal/bet notification.
     * 
     * @param array<string,mixed> $signal
     */
    public static function formatSignal(array $signal): string
    {
        $parts = [];
        $parts[] = "🚨 <b>СИГНАЛ</b>";
        $parts[] = "";
        
        if (isset($signal['match'])) {
            $parts[] = self::formatMatch($signal['match']);
            $parts[] = "";
        }
        
        if (isset($signal['bet_type'])) {
            $parts[] = "📊 Ставка: <b>" . self::escape($signal['bet_type']) . "</b>";
        }
        
        if (isset($signal['probability'])) {
            $probability = round((float) $signal['probability'] * 100, 1);
            $parts[] = "📈 Вероятность: <b>" . $probability . "%</b>";
        }
        
        if (isset($signal['odds'])) {
            $parts[] = "💰 Коэффициент: <b>" . self::escape((string) $signal['odds']) . "</b>";
        }
        
        if (isset($signal['algorithm'])) {
            $parts[] = "🔬 Алгоритм: " . self::escape($signal['algorithm']);
        }
        
        return implode("\n", $parts);
    }

    /**
     * Format error message.
     */
    public static function formatError(string $message, ?string $details = null): string
    {
        $parts = [];
        $parts[] = "❌ <b>Ошибка</b>";
        $parts[] = "";
        $parts[] = self::escape($message);
        
        if ($details !== null) {
            $parts[] = "";
            $parts[] = "<i>" . self::escape($details) . "</i>";
        }
        
        return implode("\n", $parts);
    }

    /**
     * Format success message.
     */
    public static function formatSuccess(string $message): string
    {
        return "✅ " . self::escape($message);
    }

    /**
     * Format info message.
     */
    public static function formatInfo(string $message): string
    {
        return "ℹ️ " . self::escape($message);
    }

    /**
     * Format warning message.
     */
    public static function formatWarning(string $message): string
    {
        return "⚠️ " . self::escape($message);
    }

    /**
     * Format statistics.
     * 
     * @param array<string,mixed> $stats
     */
    public static function formatStats(array $stats): string
    {
        $parts = [];
        $parts[] = "📊 <b>Статистика</b>";
        $parts[] = "";
        
        foreach ($stats as $key => $value) {
            $label = self::formatStatLabel($key);
            $parts[] = "{$label}: <b>" . self::escape((string) $value) . "</b>";
        }
        
        return implode("\n", $parts);
    }

    /**
     * Format analysis result.
     * 
     * @param array<string,mixed> $analysis
     */
    public static function formatAnalysis(array $analysis): string
    {
        $parts = [];
        $parts[] = "🔍 <b>Анализ матча</b>";
        $parts[] = "";
        
        if (isset($analysis['match'])) {
            $parts[] = self::formatMatch($analysis['match']);
            $parts[] = "";
        }
        
        if (isset($analysis['form_score'])) {
            $parts[] = "📈 Форма: " . self::formatScore($analysis['form_score']);
        }
        
        if (isset($analysis['h2h_score'])) {
            $parts[] = "🤝 H2H: " . self::formatScore($analysis['h2h_score']);
        }
        
        if (isset($analysis['live_score'])) {
            $parts[] = "⚡ Live: " . self::formatScore($analysis['live_score']);
        }
        
        if (isset($analysis['probability'])) {
            $probability = round((float) $analysis['probability'] * 100, 1);
            $parts[] = "";
            $parts[] = "🎯 Итоговая вероятность: <b>" . $probability . "%</b>";
        }
        
        if (isset($analysis['recommendation'])) {
            $parts[] = "";
            $parts[] = "💡 " . self::escape($analysis['recommendation']);
        }
        
        return implode("\n", $parts);
    }

    /**
     * Format list of items.
     * 
     * @param array<int,string> $items
     */
    public static function formatList(array $items, string $title = ''): string
    {
        $parts = [];
        
        if ($title !== '') {
            $parts[] = "<b>" . self::escape($title) . "</b>";
            $parts[] = "";
        }
        
        foreach ($items as $index => $item) {
            $parts[] = ($index + 1) . ". " . self::escape($item);
        }
        
        return implode("\n", $parts);
    }

    /**
     * Format key-value pairs.
     * 
     * @param array<string,string> $data
     */
    public static function formatKeyValue(array $data, string $title = ''): string
    {
        $parts = [];
        
        if ($title !== '') {
            $parts[] = "<b>" . self::escape($title) . "</b>";
            $parts[] = "";
        }
        
        foreach ($data as $key => $value) {
            $parts[] = self::escape($key) . ": <b>" . self::escape($value) . "</b>";
        }
        
        return implode("\n", $parts);
    }

    /**
     * Escape HTML special characters for Telegram.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Format score value (0.0 to 1.0) as percentage.
     */
    private static function formatScore(float $score): string
    {
        $percentage = round($score * 100, 1);
        $bars = self::getProgressBars($score);
        return "{$bars} {$percentage}%";
    }

    /**
     * Get progress bars for visual representation.
     */
    private static function getProgressBars(float $score): string
    {
        $filled = (int) round($score * 10);
        $empty = 10 - $filled;
        return str_repeat('▰', $filled) . str_repeat('▱', $empty);
    }

    /**
     * Format stat label from key.
     */
    private static function formatStatLabel(string $key): string
    {
        $labels = [
            'total_matches' => 'Всего матчей',
            'signals_sent' => 'Сигналов отправлено',
            'bets_won' => 'Выигрышей',
            'bets_lost' => 'Проигрышей',
            'win_rate' => 'Процент побед',
            'total_profit' => 'Общая прибыль',
            'roi' => 'ROI',
        ];
        
        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
