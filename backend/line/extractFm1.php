<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/normalize.php';

use Proxbet\Line\Normalize;

/**
 * Extract home handicap line (fm1) from events.
 * Rule: T=7, G=2 => take P.
 * Extra rule: if C is not null and P is null => treat P as 0.
 *
 * @param array<int,array<string,mixed>> $events
 */
function extractFm1(array $events): ?float
{
    $value = null;

    // If duplicates occur, prefer the last seen value.
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }

        $t = Normalize::getInt($e, 'T');
        $g = Normalize::getInt($e, 'G');
        if ($t !== 7 || $g !== 2) {
            continue;
        }

        $p = Normalize::getDecimal($e, 'P');
        if ($p === null) {
            $c = Normalize::getDecimal($e, 'C');
            $value = $c === null ? null : 0.0;
            continue;
        }

        $value = $p;
    }

    return $value;
}

/**
 * Extract home handicap coefficient (fm1cf) from events.
 * Rule: T=7, G=2 => take C.
 *
 * @param array<int,array<string,mixed>> $events
 */
function extractFm1cf(array $events): ?float
{
    $value = null;

    // If duplicates occur, prefer the last seen value.
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }

        $t = Normalize::getInt($e, 'T');
        $g = Normalize::getInt($e, 'G');
        if ($t !== 7 || $g !== 2) {
            continue;
        }

        $value = Normalize::getDecimal($e, 'C');
    }

    return $value;
}

/**
 * Extract away handicap line (fm2) from events.
 * Rule: T=8, G=2 => take P.
 * Extra rule: if C is not null and P is null => treat P as 0.
 *
 * @param array<int,array<string,mixed>> $events
 */
function extractFm2(array $events): ?float
{
    $value = null;

    // If duplicates occur, prefer the last seen value.
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }

        $t = Normalize::getInt($e, 'T');
        $g = Normalize::getInt($e, 'G');
        if ($t !== 8 || $g !== 2) {
            continue;
        }

        $p = Normalize::getDecimal($e, 'P');
        if ($p === null) {
            $c = Normalize::getDecimal($e, 'C');
            $value = $c === null ? null : 0.0;
            continue;
        }

        $value = $p;
    }

    return $value;
}

/**
 * Extract away handicap coefficient (fm2cf) from events.
 * Rule: T=8, G=2 => take C.
 *
 * @param array<int,array<string,mixed>> $events
 */
function extractFm2cf(array $events): ?float
{
    $value = null;

    // If duplicates occur, prefer the last seen value.
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }

        $t = Normalize::getInt($e, 'T');
        $g = Normalize::getInt($e, 'G');
        if ($t !== 8 || $g !== 2) {
            continue;
        }

        $value = Normalize::getDecimal($e, 'C');
    }

    return $value;
}
