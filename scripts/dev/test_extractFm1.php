<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/line/extractFm1.php';

use function Proxbet\Line\extractFm1;
use function Proxbet\Line\extractFm1cf;
use function Proxbet\Line\extractFm2;
use function Proxbet\Line\extractFm2cf;

function assertSame($expected, $actual, string $msg): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "ASSERT FAIL: {$msg}. Expected=" . var_export($expected, true) . ' actual=' . var_export($actual, true) . "\n");
        exit(1);
    }
}

// T=7,G=2 => fm1=P, fm1cf=C
$events = [
    ['T' => 7, 'G' => 2, 'P' => '-1.5', 'C' => '2.35'],
];
assertSame(-1.5, extractFm1($events), 'fm1 should be extracted from P');
assertSame(2.35, extractFm1cf($events), 'fm1cf should be extracted from C');

// T=8,G=2 => fm2=P, fm2cf=C
$events = [
    ['T' => 8, 'G' => 2, 'P' => '1.5', 'C' => '1.62'],
];
assertSame(1.5, extractFm2($events), 'fm2 should be extracted from P');
assertSame(1.62, extractFm2cf($events), 'fm2cf should be extracted from C');

// P missing but C exists => line should be treated as 0
$events = [
    ['T' => 7, 'G' => 2, 'C' => '2.35'],
    ['T' => 8, 'G' => 2, 'P' => '1.5'],
];
assertSame(0.0, extractFm1($events), 'fm1 should be 0 when P missing but C exists');
assertSame(2.35, extractFm1cf($events), 'fm1cf should be extracted when C exists');
assertSame(1.5, extractFm2($events), 'fm2 should be extracted when P exists');
assertSame(null, extractFm2cf($events), 'fm2cf should be null when C missing');

$events = [
    ['T' => 8, 'G' => 2, 'C' => '1.62'],
];
assertSame(0.0, extractFm2($events), 'fm2 should be 0 when P missing but C exists');
assertSame(1.62, extractFm2cf($events), 'fm2cf should be extracted when C exists');

// Not matching T/G => nulls
$events = [
    ['T' => 7, 'G' => 1, 'P' => '-1.5', 'C' => '2.35'],
    ['T' => 8, 'G' => 3, 'P' => '1.5', 'C' => '1.62'],
];
assertSame(null, extractFm1($events), 'fm1 should be null when not matching');
assertSame(null, extractFm1cf($events), 'fm1cf should be null when not matching');
assertSame(null, extractFm2($events), 'fm2 should be null when not matching');
assertSame(null, extractFm2cf($events), 'fm2cf should be null when not matching');

// Duplicates => last wins
$events = [
    ['T' => 7, 'G' => 2, 'P' => '-0.5', 'C' => '1.90'],
    ['T' => 7, 'G' => 2, 'P' => '-1.0', 'C' => '2.10'],
    ['T' => 8, 'G' => 2, 'P' => '0.5', 'C' => '1.80'],
    ['T' => 8, 'G' => 2, 'P' => '1.0', 'C' => '1.95'],
];
assertSame(-1.0, extractFm1($events), 'fm1 should prefer last value');
assertSame(2.10, extractFm1cf($events), 'fm1cf should prefer last value');
assertSame(1.0, extractFm2($events), 'fm2 should prefer last value');
assertSame(1.95, extractFm2cf($events), 'fm2cf should prefer last value');

fwrite(STDOUT, "OK\n");
