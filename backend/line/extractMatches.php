<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/time.php';
require_once __DIR__ . '/extractOdds.php';

use Proxbet\Line\Logger;
use Proxbet\Line\Normalize;
use Proxbet\Line\Time;
use function Proxbet\Line\extractOdds;

/**
 * @param array<string,mixed> $payload
 * @return array<int,array<string,mixed>>
 */
function extractMatches(array $payload): array
{
    $value = $payload['Value'] ?? null;
    if (!is_array($value)) {
        Logger::error('Payload.Value is missing or not an array');
        return [];
    }

    /**
     * Extract SGI (game statistics id) from nested structures like:
     * Payload['Value'][<digits>]['Value'][<digits>]...['SGI']
     *
     * @param mixed $node
     */
    $findSgi = static function ($node) use (&$findSgi): ?string {
        if (!is_array($node)) {
            return null;
        }

        if (isset($node['SGI']) && is_string($node['SGI']) && $node['SGI'] !== '') {
            return $node['SGI'];
        }

        // Prefer walking through nested "Value" containers (often keyed by digits).
        if (isset($node['Value']) && is_array($node['Value'])) {
            foreach ($node['Value'] as $child) {
                $sgi = $findSgi($child);
                if ($sgi !== null) {
                    return $sgi;
                }
            }
        }

        // Defensive fallback: scan other nested arrays.
        foreach ($node as $k => $v) {
            if ($k === 'Value') {
                continue;
            }
            if (is_array($v)) {
                $sgi = $findSgi($v);
                if ($sgi !== null) {
                    return $sgi;
                }
            }
        }

        return null;
    };

    $out = [];

    foreach (array_values($value) as $idx => $item) {
        if (!is_array($item)) {
            Logger::error('Value item is not an object', ['index' => $idx]);
            continue;
        }

        $evid = Normalize::getString($item, 'I');
        if ($evid === null) {
            Logger::error('Missing I (evid), skipping', ['index' => $idx]);
            continue;
        }

        $s = Normalize::getInt($item, 'S');
        $startTime = Time::startTimeMoscow($s);

        $country = Normalize::getString($item, 'CN');
        $liga = Normalize::getString($item, 'L');
        $home = Normalize::getString($item, 'O1');
        $away = Normalize::getString($item, 'O2');

        $sgi = $findSgi($item);
        if ($sgi === null) {
            // Requirement: do not write matches without SGI into DB.
            Logger::info('Missing SGI, skipping match', ['index' => $idx, 'evid' => $evid]);
            continue;
        }

        $events = Normalize::getArray($item, 'E');
        $odds = extractOdds($events);

        $out[] = [
            'evid' => $evid,
            'sgi' => $sgi,
            'start_time' => $startTime,
            'country' => $country,
            'liga' => $liga,
            'home' => $home,
            'away' => $away,
            // odds
            'home_cf' => $odds['home_cf'],
            'draw_cf' => $odds['draw_cf'],
            'away_cf' => $odds['away_cf'],
            'total_line' => $odds['total_line'],
            'total_line_tb' => $odds['total_line_tb'],
            'total_line_tm' => $odds['total_line_tm'],
            'btts_yes' => $odds['btts_yes'],
            'btts_no' => $odds['btts_no'],
            'itb1' => $odds['itb1'],
            'itb1cf' => $odds['itb1cf'],
            'itb2' => $odds['itb2'],
            'itb2cf' => $odds['itb2cf'],
            'fm1' => $odds['fm1'],
            'fm1cf' => $odds['fm1cf'],
            'fm2' => $odds['fm2'],
            'fm2cf' => $odds['fm2cf'],
        ];
    }

    return $out;
}
