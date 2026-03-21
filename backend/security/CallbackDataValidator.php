<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Telegram callback data validator with whitelist
 * Prevents malicious callback_data injection
 */
class CallbackDataValidator
{
    /** @var array<string> */
    private array $allowedPrefixes = [
        'ban_',
        'unban_',
        'stats_',
        'analysis_',
        'confirm_',
        'cancel_',
        'page_',
        'filter_',
        'sort_',
    ];

    /** @var array<string> */
    private array $allowedActions = [
        'ban_add',
        'ban_remove',
        'ban_list',
        'unban_confirm',
        'stats_show',
        'stats_refresh',
        'analysis_request',
        'analysis_view',
        'confirm_yes',
        'confirm_no',
        'cancel_operation',
        'page_next',
        'page_prev',
        'filter_apply',
        'sort_asc',
        'sort_desc',
    ];

    private int $maxLength = 64;

    /**
     * @param array<string> $customPrefixes Additional allowed prefixes
     * @param array<string> $customActions Additional allowed actions
     */
    public function __construct(array $customPrefixes = [], array $customActions = [])
    {
        $this->allowedPrefixes = array_merge($this->allowedPrefixes, $customPrefixes);
        $this->allowedActions = array_merge($this->allowedActions, $customActions);
    }

    /**
     * Validate callback data
     * 
     * @param string $callbackData Callback data from Telegram
     * @return bool True if valid
     */
    public function isValid(string $callbackData): bool
    {
        // Check length
        if (strlen($callbackData) > $this->maxLength) {
            return false;
        }

        // Check for malicious patterns
        if ($this->containsMaliciousPattern($callbackData)) {
            return false;
        }

        // Check if starts with allowed prefix
        foreach ($this->allowedPrefixes as $prefix) {
            if (str_starts_with($callbackData, $prefix)) {
                return true;
            }
        }

        // Check if exact match with allowed action
        if (in_array($callbackData, $this->allowedActions, true)) {
            return true;
        }

        return false;
    }

    /**
     * Parse callback data into action and parameters
     * 
     * @param string $callbackData Callback data
     * @return array{action: string, params: array<string,string>}|null Parsed data or null if invalid
     */
    public function parse(string $callbackData): ?array
    {
        if (!$this->isValid($callbackData)) {
            return null;
        }

        // Format: action:param1=value1:param2=value2
        $parts = explode(':', $callbackData);
        $action = $parts[0];
        $params = [];

        for ($i = 1; $i < count($parts); $i++) {
            if (strpos($parts[$i], '=') !== false) {
                [$key, $value] = explode('=', $parts[$i], 2);
                $params[$key] = $value;
            }
        }

        return [
            'action' => $action,
            'params' => $params,
        ];
    }

    /**
     * Build callback data from action and parameters
     * 
     * @param string $action Action name
     * @param array<string,string|int> $params Parameters
     * @return string Callback data
     * @throws \InvalidArgumentException If data is invalid
     */
    public function build(string $action, array $params = []): string
    {
        $parts = [$action];

        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        $callbackData = implode(':', $parts);

        if (!$this->isValid($callbackData)) {
            throw new \InvalidArgumentException('Invalid callback data: ' . $callbackData);
        }

        return $callbackData;
    }

    /**
     * Check for malicious patterns
     */
    private function containsMaliciousPattern(string $data): bool
    {
        $maliciousPatterns = [
            '/[<>]/',           // HTML tags
            '/javascript:/i',   // JavaScript protocol
            '/on\w+=/i',        // Event handlers
            '/\.\.\//i',        // Path traversal
            '/[;\'"\\\\]/',     // SQL/command injection chars
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get allowed prefixes
     * 
     * @return array<string>
     */
    public function getAllowedPrefixes(): array
    {
        return $this->allowedPrefixes;
    }

    /**
     * Get allowed actions
     * 
     * @return array<string>
     */
    public function getAllowedActions(): array
    {
        return $this->allowedActions;
    }
}
