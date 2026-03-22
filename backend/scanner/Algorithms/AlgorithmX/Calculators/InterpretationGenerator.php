<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Calculators;

/**
 * Interpretation Generator.
 * 
 * Generates human-readable text interpretation of the calculated probability.
 * Helps understand what the probability means in practical terms.
 */
final class InterpretationGenerator
{
    /**
     * Generate interpretation based on probability.
     * 
     * Probability ranges:
     * - < 20%: Low activity, goal unlikely
     * - 20-40%: Moderate activity, goal possible but teams cautious
     * - 40-60%: Medium intensity, roughly equal chances
     * - 60-80%: High pressure, goal expected with good probability
     * - > 80%: Very high activity, goal very likely soon
     * 
     * @param float $probability Probability value (0.0-1.0)
     * @return string Human-readable interpretation
     */
    public function generate(float $probability): string
    {
        $percentage = $probability * 100;
        
        return match (true) {
            $percentage < 20 => 'Низкая активность. Матч закрытый, гол маловероятен.',
            $percentage < 40 => 'Умеренная активность. Гол возможен, но команды осторожны.',
            $percentage < 60 => 'Средняя интенсивность. Примерно равные шансы на гол.',
            $percentage < 80 => 'Высокое давление. Гол ожидается с хорошей вероятностью.',
            default => 'Очень высокая активность! Гол в ближайшее время весьма вероятен.',
        };
    }
}
