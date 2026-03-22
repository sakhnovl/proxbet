#!/bin/bash

# Database backup script for Proxbet
# Usage: ./backup_database.sh [backup_dir]

set -e

# Configuration
BACKUP_DIR="${1:-/var/backups/proxbet}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Load environment variables
if [ -f "/var/www/html/.env" ]; then
    export $(grep -v '^#' /var/www/html/.env | xargs)
fi

# Validate required variables
if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
    echo "Error: Missing required environment variables (DB_HOST, DB_USER, DB_NAME)"
    exit 1
fi

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Backup filename
BACKUP_FILE="$BACKUP_DIR/proxbet_${DB_NAME}_${TIMESTAMP}.sql.gz"

echo "Starting database backup..."
echo "Database: $DB_NAME"
echo "Host: $DB_HOST"
echo "Backup file: $BACKUP_FILE"

# Perform backup with compression
if [ -n "$DB_PASS" ]; then
    mysqldump --host="$DB_HOST" \
              --user="$DB_USER" \
              -p"$DB_PASS" \
              --single-transaction \
              --routines \
              --triggers \
              --events \
              "$DB_NAME" | gzip > "$BACKUP_FILE"
else
    mysqldump -h "$DB_HOST" \
              -u "$DB_USER" \
              --single-transaction \
              --routines \
              --triggers \
              --events \
              "$DB_NAME" | gzip > "$BACKUP_FILE"
fi

# Check if backup was successful
if [ $? -eq 0 ]; then
    BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo "Backup completed successfully: $BACKUP_FILE ($BACKUP_SIZE)"
else
    echo "Error: Backup failed"
    exit 1
fi

# Remove old backups
echo "Cleaning up old backups (retention: $RETENTION_DAYS days)..."
find "$BACKUP_DIR" -name "proxbet_*.sql.gz" -type f -mtime +$RETENTION_DAYS -delete

# List remaining backups
echo "Current backups:"
ls -lh "$BACKUP_DIR"/proxbet_*.sql.gz 2>/dev/null || echo "No backups found"

echo "Backup process completed"
