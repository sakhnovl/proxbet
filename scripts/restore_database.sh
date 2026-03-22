#!/bin/bash

# Database restore script for Proxbet
# Usage: ./restore_database.sh <backup_file>

set -e

# Check if backup file is provided
if [ -z "$1" ]; then
    echo "Usage: $0 <backup_file>"
    echo "Example: $0 /var/backups/proxbet/proxbet_db_20260321_120000.sql.gz"
    exit 1
fi

BACKUP_FILE="$1"

# Check if backup file exists
if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: Backup file not found: $BACKUP_FILE"
    exit 1
fi

# Load environment variables
if [ -f "/var/www/html/.env" ]; then
    export $(grep -v '^#' /var/www/html/.env | xargs)
fi

# Validate required variables
if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
    echo "Error: Missing required environment variables (DB_HOST, DB_USER, DB_NAME)"
    exit 1
fi

echo "WARNING: This will restore the database and overwrite existing data!"
echo "Database: $DB_NAME"
echo "Host: $DB_HOST"
echo "Backup file: $BACKUP_FILE"
echo ""
read -p "Are you sure you want to continue? (yes/no): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
    echo "Restore cancelled"
    exit 0
fi

echo "Starting database restore..."

# Restore from compressed backup
if [ -n "$DB_PASS" ]; then
    mysql --host="$DB_HOST" \
          --user="$DB_USER" \
                                    -p"$DB_PASS" \
                                    "$DB_NAME"
else
    gunzip < "$BACKUP_FILE" | mysql -h "$DB_HOST" \
                                    -u "$DB_USER" \
                                    "$DB_NAME"
fi

# Check if restore was successful
if [ $? -eq 0 ]; then
    echo "Database restored successfully from: $BACKUP_FILE"
else
    echo "Error: Restore failed"
    exit 1
fi

echo "Restore process completed"
