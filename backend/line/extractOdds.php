<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/extractFm1.php';

use Proxbet\Line\Normalize;
use function Proxbet\Line\extractFm1;
use function Proxbet\Line\extractFm1cf;
use function Proxbet\Line\extractFm2;
use function Proxbet\Line\extractFm2cf;

/**
 * @param array<int,array<string,mixed>> $events
 * @return array<string, float|null>
 */
function extractOdds(array $events): array
{
    $out = [
        'home_cf' => null,
        'draw_cf' => null,
        'away_cf' => null,
        'total_line' => null,
        'total_line_tb' => null,
        'total_line_tm' => null,
        'btts_yes' => null,
        'btts_no' => null,
        'itb1' => null,
        'itb1cf' => null,
        'itb2' => null,
        'itb2cf' => null,
        'fm1' => null,
        'fm1cf' => null,
        'fm2' => null,
        'fm2cf' => null,
    ];

    // If duplicates occur, prefer the last seen value.
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }

        $t = Normalize::getInt($e, 'T');
        $g = Normalize::getInt($e, 'G');
        $c = Normalize::getDecimal($e, 'C');
        $p = Normalize::getDecimal($e, 'P');

        if ($t === null || $g === null) {
            continue;
        }

        if ($t === 1 && $g === 1) {
            $out['home_cf'] = $c;
            continue;
        }

        if ($t === 2 && $g === 1) {
            $out['draw_cf'] = $c;
            continue;
        }

        if ($t === 3 && $g === 1) {
            $out['away_cf'] = $c;
            continue;
        }

        if ($t === 10 && $g === 17) {
            $out['total_line'] = $p;
            $out['total_line_tb'] = $c;
            continue;
        }

        if ($t === 9 && $g === 17) {
            $out['total_line_tm'] = $c;
            continue;
        }

        if ($t === 180 && $g === 19) {
            $out['btts_yes'] = $c;
            continue;
        }

        if ($t === 181 && $g === 19) {
            $out['btts_no'] = $c;
            continue;
        }

        if ($t === 11 && $g === 15) {
            $out['itb1'] = $p;
            $out['itb1cf'] = $c;
            continue;
        }

        if ($t === 13 && $g === 62) {
            $out['itb2'] = $p;
            $out['itb2cf'] = $c;
            continue;
        }
    }

    // Parse handicaps separately for clarity and easier future expansion.
    $out['fm1'] = extractFm1($events);
    $out['fm1cf'] = extractFm1cf($events);
    $out['fm2'] = extractFm2($events);
    $out['fm2cf'] = extractFm2cf($events);

    return $out;
}
