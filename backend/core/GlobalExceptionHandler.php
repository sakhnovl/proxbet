<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Core\Exceptions\ProxbetException;
use Proxbet\Line\Logger;
use Proxbet\Security\RequestValidator;

/**
 * Global exception handler for the application.
 * Handles uncaught exceptions and provides unified error responses.
 */
final class GlobalExceptionHandler
{
    private static bool $registered = false;

    /**
     * Register global exception and error handlers.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    /**
     * Handle uncaught exceptions.
     * 
     * @param \Throwable $exception The uncaught exception
     */
    public static function handleException(\Throwable $exception): void
    {
        $isDevelopment = self::isDevelopment();
        
        // Log the exception with full context
        Logger::error('Uncaught exception', [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $isDevelopment ? $exception->getTraceAsString() : 'hidden',
            'code' => $exception->getCode(),
        ]);

        // Send appropriate response
        if (self::isApiRequest()) {
            self::sendJsonErrorResponse($exception, $isDevelopment);
        } else {
            self::sendHtmlErrorResponse($exception, $isDevelopment);
        }

        exit(1);
    }

    /**
     * Handle PHP errors and convert them to exceptions.
     * 
     * @param int $errno Error level
     * @param string $errstr Error message
     * @param string $errfile File where error occurred
     * @param int $errline Line where error occurred
     * @return bool True to prevent default PHP error handler
     * @throws \ErrorException
     */
    public static function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline
    ): bool {
        // Don't throw exception if error reporting is turned off
        if (!(error_reporting() & $errno)) {
            return false;
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Handle fatal errors during shutdown.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error === null) {
            return;
        }

        $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        
        if (!in_array($error['type'], $fatalErrors, true)) {
            return;
        }

        Logger::error('Fatal error', [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        if (self::isApiRequest()) {
            self::sendJsonErrorResponse(
                new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']),
                self::isDevelopment()
            );
        }
    }

    /**
     * Send JSON error response.
     * 
     * @param \Throwable $exception The exception
     * @param bool $isDevelopment Whether in development mode
     */
    private static function sendJsonErrorResponse(\Throwable $exception, bool $isDevelopment): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(self::getHttpStatusCode($exception));
        }

        $response = ErrorResponse::fromException($exception, $isDevelopment);
        
        echo json_encode($response->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send HTML error response.
     * 
     * @param \Throwable $exception The exception
     * @param bool $isDevelopment Whether in development mode
     */
    private static function sendHtmlErrorResponse(\Throwable $exception, bool $isDevelopment): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            http_response_code(self::getHttpStatusCode($exception));
        }

        $message = $isDevelopment 
            ? htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
            : 'An error occurred. Please try again later.';

        echo "<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 4px; }
        h1 { color: #721c24; margin: 0 0 10px 0; }
        p { color: #721c24; margin: 0; }
    </style>
</head>
<body>
    <div class='error'>
        <h1>Error</h1>
        <p>{$message}</p>
    </div>
</body>
</html>";
    }

    /**
     * Get HTTP status code from exception.
     * 
     * @param \Throwable $exception The exception
     * @return int HTTP status code
     */
    private static function getHttpStatusCode(\Throwable $exception): int
    {
        if ($exception instanceof ProxbetException) {
            return $exception->getHttpStatusCode();
        }

        return 500;
    }

    /**
     * Check if current request is an API request.
     * 
     * @return bool True if API request
     */
    private static function isApiRequest(): bool
    {
        $contentType = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        return str_contains($contentType, 'application/json') 
            || str_contains($requestUri, '/api/');
    }

    /**
     * Check if running in development mode.
     * 
     * @return bool True if development mode
     */
    private static function isDevelopment(): bool
    {
        $env = getenv('APP_ENV') ?: 'production';
        return in_array($env, ['development', 'dev', 'local'], true);
    }
}
