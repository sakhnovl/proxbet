<?php

declare(strict_types=1);

namespace Proxbet\Core;

/**
 * Streaming JSON parser for processing large JSON files without loading entire content into memory.
 */
class StreamingJsonParser
{
    private const BUFFER_SIZE = 8192;

    /**
     * Parse JSON array from file using streaming approach.
     * 
     * @param string $filePath Path to JSON file
     * @return \Generator<int, mixed> Yields each array element
     * @throws \RuntimeException If file cannot be opened or JSON is invalid
     */
    public static function parseArrayFromFile(string $filePath): \Generator
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        try {
            yield from self::parseArrayFromStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse JSON array from stream.
     *
     * @param resource $stream
     * @return \Generator<int, mixed>
     */
    public static function parseArrayFromStream($stream): \Generator
    {
        $buffer = '';
        $depth = 0;
        $inString = false;
        $escape = false;
        $elementStart = -1;
        $foundArrayStart = false;

        while (!feof($stream)) {
            $chunk = fread($stream, self::BUFFER_SIZE);
            if ($chunk === false) {
                break;
            }

            $buffer .= $chunk;
            $len = strlen($buffer);

            for ($i = 0; $i < $len; $i++) {
                $char = $buffer[$i];

                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($char === '\\' && $inString) {
                    $escape = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = !$inString;
                    continue;
                }

                if ($inString) {
                    continue;
                }

                if ($char === '[') {
                    if (!$foundArrayStart) {
                        $foundArrayStart = true;
                        $elementStart = $i + 1;
                    } else {
                        $depth++;
                    }
                } elseif ($char === '{') {
                    if ($elementStart === -1 && $foundArrayStart) {
                        $elementStart = $i;
                    }
                    $depth++;
                } elseif ($char === ']' || $char === '}') {
                    $depth--;
                    
                    if ($depth === 0 && $elementStart !== -1) {
                        $element = substr($buffer, $elementStart, $i - $elementStart + 1);
                        $decoded = json_decode($element, true);
                        
                        if ($decoded !== null) {
                            yield $decoded;
                        }
                        
                        $buffer = substr($buffer, $i + 1);
                        $len = strlen($buffer);
                        $i = -1;
                        $elementStart = -1;
                    }
                } elseif ($char === ',' && $depth === 0 && $elementStart !== -1) {
                    $element = substr($buffer, $elementStart, $i - $elementStart);
                    $decoded = json_decode($element, true);
                    
                    if ($decoded !== null) {
                        yield $decoded;
                    }
                    
                    $buffer = substr($buffer, $i + 1);
                    $len = strlen($buffer);
                    $i = -1;
                    $elementStart = -1;
                }
            }
        }
    }

    /**
     * Parse JSON array from string in chunks.
     *
     * @param string $json JSON string
     * @param int $chunkSize Size of each chunk to process
     * @return \Generator<int, mixed>
     */
    public static function parseArrayFromString(string $json, int $chunkSize = 8192): \Generator
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create memory stream');
        }

        fwrite($stream, $json);
        rewind($stream);

        try {
            yield from self::parseArrayFromStream($stream);
        } finally {
            fclose($stream);
        }
    }
}
