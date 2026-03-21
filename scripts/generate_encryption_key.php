<?php

/**
 * Generate encryption key for ENCRYPTION_KEY environment variable
 * 
 * Usage: php scripts/generate_encryption_key.php
 */

// Generate 32 random bytes
$randomBytes = random_bytes(32);

// Encode to base64
$encryptionKey = base64_encode($randomBytes);

echo "=================================================\n";
echo "ENCRYPTION KEY GENERATED\n";
echo "=================================================\n\n";
echo "Add this to your .env file:\n\n";
echo "ENCRYPTION_KEY=" . $encryptionKey . "\n\n";
echo "=================================================\n";
echo "Keep this key secure and never commit it to git!\n";
echo "=================================================\n";
