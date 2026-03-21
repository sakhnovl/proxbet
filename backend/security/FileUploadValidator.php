<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * File upload validator
 * Validates file uploads for security threats
 */
class FileUploadValidator
{
    private const MAX_FILE_SIZE = 5242880; // 5MB
    
    /** @var array<string> */
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
    ];

    /** @var array<string> */
    private array $allowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'txt', 'csv',
    ];

    private int $maxFileSize;

    /**
     * @param int $maxFileSize Maximum file size in bytes
     * @param array<string> $allowedMimeTypes Allowed MIME types
     * @param array<string> $allowedExtensions Allowed file extensions
     */
    public function __construct(
        int $maxFileSize = self::MAX_FILE_SIZE,
        array $allowedMimeTypes = [],
        array $allowedExtensions = []
    ) {
        $this->maxFileSize = $maxFileSize;
        
        if (!empty($allowedMimeTypes)) {
            $this->allowedMimeTypes = $allowedMimeTypes;
        }
        
        if (!empty($allowedExtensions)) {
            $this->allowedExtensions = $allowedExtensions;
        }
    }

    /**
     * Validate uploaded file
     * 
     * @param array<string,mixed> $file $_FILES array element
     * @return array{valid: bool, error: string|null}
     */
    public function validate(array $file): array
    {
        // Check if file was uploaded
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['valid' => false, 'error' => 'Invalid file upload'];
        }

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => $this->getUploadErrorMessage($file['error'])];
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            return [
                'valid' => false,
                'error' => 'File too large. Max size: ' . $this->formatBytes($this->maxFileSize)
            ];
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $this->allowedMimeTypes, true)) {
            return ['valid' => false, 'error' => 'File type not allowed: ' . $mimeType];
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions, true)) {
            return ['valid' => false, 'error' => 'File extension not allowed: ' . $extension];
        }

        // Check for malicious content
        if ($this->containsMaliciousContent($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'File contains malicious content'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Generate safe filename
     */
    public function generateSafeFilename(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        
        // Remove special characters
        $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
        $basename = substr($basename, 0, 50);
        
        // Add timestamp and random string
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        
        return $basename . '_' . $timestamp . '_' . $random . '.' . $extension;
    }

    /**
     * Check for malicious content in file
     */
    private function containsMaliciousContent(string $filePath): bool
    {
        $content = file_get_contents($filePath, false, null, 0, 8192);
        
        if ($content === false) {
            return true;
        }

        // Check for PHP tags
        if (preg_match('/<\?php|<\?=/i', $content)) {
            return true;
        }

        // Check for script tags
        if (preg_match('/<script/i', $content)) {
            return true;
        }

        // Check for executable signatures
        $signatures = [
            "\x4D\x5A", // PE executable
            "\x7F\x45\x4C\x46", // ELF executable
            "#!", // Shell script
        ];

        foreach ($signatures as $signature) {
            if (str_starts_with($content, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
            default => 'Unknown upload error',
        };
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
