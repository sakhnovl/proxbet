<?php

declare(strict_types=1);

namespace Proxbet\Core\Helpers;

use Proxbet\Core\MemoryMonitor;

/**
 * Helper for processing large datasets with memory management.
 */
class DataProcessor
{
    /**
     * Process array in batches using generator.
     *
     * @template T
     * @param array<int, T> $items
     * @param int $batchSize
     * @return \Generator<int, array<int, T>>
     */
    public static function batch(array $items, int $batchSize = 100): \Generator
    {
        $batch = [];
        $count = 0;

        foreach ($items as $item) {
            $batch[] = $item;
            $count++;

            if ($count >= $batchSize) {
                yield $batch;
                $batch = [];
                $count = 0;
                
                // Free memory
                if ($count % 1000 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        if (!empty($batch)) {
            yield $batch;
        }
    }

    /**
     * Process items with callback and memory monitoring.
     *
     * @template T
     * @template R
     * @param iterable<T> $items
     * @param callable(T): R $callback
     * @param int $memoryThresholdMb Memory threshold in MB to trigger GC
     * @return \Generator<int, R>
     */
    public static function processWithMemoryManagement(
        iterable $items,
        callable $callback,
        int $memoryThresholdMb = 100
    ): \Generator {
        $thresholdBytes = $memoryThresholdMb * 1024 * 1024;
        $processedCount = 0;

        foreach ($items as $item) {
            $result = $callback($item);
            yield $result;
            
            $processedCount++;
            
            // Check memory every 100 items
            if ($processedCount % 100 === 0) {
                if (MemoryMonitor::isThresholdExceeded($thresholdBytes)) {
                    MemoryMonitor::gc("After processing {$processedCount} items");
                }
            }
        }
    }

    /**
     * Chunk large array into smaller arrays.
     *
     * @template T
     * @param array<int, T> $array
     * @param int $size
     * @return array<int, array<int, T>>
     */
    public static function chunk(array $array, int $size): array
    {
        return array_chunk($array, $size);
    }

    /**
     * Filter items using generator for memory efficiency.
     *
     * @template T
     * @param iterable<T> $items
     * @param callable(T): bool $predicate
     * @return \Generator<int, T>
     */
    public static function filter(iterable $items, callable $predicate): \Generator
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                yield $item;
            }
        }
    }

    /**
     * Map items using generator for memory efficiency.
     *
     * @template T
     * @template R
     * @param iterable<T> $items
     * @param callable(T): R $mapper
     * @return \Generator<int, R>
     */
    public static function map(iterable $items, callable $mapper): \Generator
    {
        foreach ($items as $item) {
            yield $mapper($item);
        }
    }

    /**
     * Reduce items with memory management.
     *
     * @template T
     * @template R
     * @param iterable<T> $items
     * @param callable(R, T): R $reducer
     * @param R $initial
     * @return R
     */
    public static function reduce(iterable $items, callable $reducer, $initial)
    {
        $accumulator = $initial;
        $count = 0;

        foreach ($items as $item) {
            $accumulator = $reducer($accumulator, $item);
            $count++;

            // Periodic GC for long-running reductions
            if ($count % 1000 === 0) {
                gc_collect_cycles();
            }
        }

        return $accumulator;
    }
}
