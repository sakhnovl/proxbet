<?php

declare(strict_types=1);

namespace Proxbet\Statistic\Interfaces;

interface EventsstatClientInterface
{
    /**
     * @return array{ok:bool, status:int, rawJson:string, error:?string, attempts:int}
     */
    public function fetchGameRawJson(string $sgi): array;
}
