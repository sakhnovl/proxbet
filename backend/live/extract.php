<?php

declare(strict_types=1);

namespace Proxbet\Live;

require_once __DIR__ . '/json.php';

final class Extract
{
    /**
     * Finds and returns the first node that contains key "Value" (at any depth).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public static function findNodeWithValue(array $payload): ?array
    {
        $queue = [$payload];

        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }

            if (array_key_exists('Value', $node) && is_array($node['Value'])) {
                /** @var array<string,mixed> $found */
                $found = $node;
                return $found;
            }

            foreach ($node as $v) {
                if (is_array($v)) {
                    $queue[] = $v;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $valueNode
     * @return array{O1:?string,O2:?string,I:?string}
     */
    public static function extractTeamsAndId(array $valueNode): array
    {
        $o1 = Json::get($valueNode, ['O1']);
        $o2 = Json::get($valueNode, ['O2']);
        $i = Json::get($valueNode, ['I']);

        return [
            'O1' => is_string($o1) ? $o1 : null,
            'O2' => is_string($o2) ? $o2 : null,
            'I' => is_string($i) || is_int($i) ? (string) $i : null,
        ];
    }

    /**
     * @param array<string,mixed> $valueNode
     * @return array{live_ht_hscore:int,live_ht_ascore:int,live_hscore:int,live_ascore:int}
     */
    public static function extractScore(array $valueNode): array
    {
        // SC -> PS[0] -> Value -> S1/S2
        $htS1 = Json::get($valueNode, ['SC', 'PS', 0, 'Value', 'S1']);
        $htS2 = Json::get($valueNode, ['SC', 'PS', 0, 'Value', 'S2']);

        // SC -> FS -> S1/S2
        $fsS1 = Json::get($valueNode, ['SC', 'FS', 'S1']);
        $fsS2 = Json::get($valueNode, ['SC', 'FS', 'S2']);

        return [
            'live_ht_hscore' => Json::intOrZero($htS1),
            'live_ht_ascore' => Json::intOrZero($htS2),
            'live_hscore' => Json::intOrZero($fsS1),
            'live_ascore' => Json::intOrZero($fsS2),
        ];
    }

    /**
     * SC.CPS -> matches.match_status (default "Игра скоро начнется")
     * SC.TS  -> matches.time ("mm:ss")
     *
     * @param array<string,mixed> $valueNode
     * @return array{time:string,match_status:string}
     */
    public static function extractTimeAndStatus(array $valueNode): array
    {
        $cps = Json::get($valueNode, ['SC', 'CPS']);
        $ts = Json::get($valueNode, ['SC', 'TS']);

        $matchStatus = is_string($cps) ? trim($cps) : '';
        if ($matchStatus === '') {
            $matchStatus = 'Игра скоро начнется';
        }

        $sec = max(0, Json::intOrZero($ts));
        $mm = intdiv($sec, 60);
        $ss = $sec % 60;

        return [
            'time' => sprintf('%02d:%02d', $mm, $ss),
            'match_status' => $matchStatus,
        ];
    }

    /**
     * @param array<string,mixed> $valueNode
     * @return array<string,?float> DB field => value
     */
    public static function extractStats(array $valueNode): array
    {
        $statsRoot = Json::get($valueNode, ['SC', 'ST', 0, 'Value']);
        $items = Json::children($statsRoot);

        $out = [];
        foreach ($items as $it) {
            $id = Json::get($it, ['ID']);
            $n = Json::get($it, ['N']);
            $s1 = Json::floatOrNull(Json::get($it, ['S1']));
            $s2 = Json::floatOrNull(Json::get($it, ['S2']));

            $idInt = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : null);
            $nStr = is_string($n) ? $n : null;
            if ($idInt === null || $nStr === null) {
                continue;
            }

            $mapped = self::mapStat($idInt, $nStr);
            if ($mapped === null) {
                continue;
            }

            $out[$mapped['home']] = $s1;
            $out[$mapped['away']] = $s2;
        }

        return $out;
    }

    /**
     * @return array{home:string,away:string}|null
     */
    private static function mapStat(int $id, string $name): ?array
    {
        return match (true) {
            $id === 93 && $name === 'xG' => ['home' => 'live_xg_home', 'away' => 'live_xg_away'],
            $id === 45 && $name === 'Атаки' => ['home' => 'live_att_home', 'away' => 'live_att_away'],
            $id === 58 && $name === 'Опасные атаки' => ['home' => 'live_danger_att_home', 'away' => 'live_danger_att_away'],
            $id === 59 && $name === 'Удары в створ' => ['home' => 'live_shots_on_target_home', 'away' => 'live_shots_on_target_away'],
            $id === 60 && $name === 'Удары в сторону ворот' => ['home' => 'live_shots_off_target_home', 'away' => 'live_shots_off_target_away'],
            $id === 26 && $name === 'Желтые карточки' => ['home' => 'live_yellow_cards_home', 'away' => 'live_yellow_cards_away'],
            $id === 47 && $name === 'Сейвы' => ['home' => 'live_safe_home', 'away' => 'live_safe_away'],
            $id === 70 && $name === 'Угловые' => ['home' => 'live_corner_home', 'away' => 'live_corner_away'],
            default => null,
        };
    }
}
