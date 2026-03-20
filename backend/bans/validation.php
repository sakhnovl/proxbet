<?php

declare(strict_types=1);

function cleanField(?string $s, int $maxLen): ?string
{
    if ($s === null) {
        return null;
    }

    $s = trim($s);
    if ($s === '') {
        return null;
    }

    // Telegram can send weird whitespace
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = trim($s);

    if (mb_strlen($s, 'UTF-8') > $maxLen) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    }

    return $s;
}

function normalizeWizardInput(string $textTrim): string
{
    return $textTrim === '-' ? '' : $textTrim;
}
