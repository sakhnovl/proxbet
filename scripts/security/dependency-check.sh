#!/bin/bash

# Dependency vulnerability scanning script
# Checks for known vulnerabilities in PHP dependencies

set -e

echo "==================================="
echo "Dependency Vulnerability Scanning"
echo "==================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if composer is installed
if ! command -v composer &> /dev/null; then
    echo -e "${RED}Error: Composer is not installed${NC}"
    exit 1
fi

echo "1. Running Composer Audit..."
echo "----------------------------"
if composer audit --format=json > /tmp/composer-audit.json 2>&1; then
    echo -e "${GREEN}✓ No known vulnerabilities found${NC}"
else
    echo -e "${YELLOW}⚠ Vulnerabilities detected!${NC}"
    cat /tmp/composer-audit.json
    echo ""
fi

echo ""
echo "2. Checking for outdated packages..."
echo "------------------------------------"
composer outdated --direct --strict || true

echo ""
echo "3. Generating dependency report..."
echo "----------------------------------"
composer show --tree > /tmp/dependency-tree.txt
echo "Dependency tree saved to /tmp/dependency-tree.txt"

echo ""
echo "4. Security recommendations:"
echo "---------------------------"
echo "- Run 'composer update' regularly to get security patches"
echo "- Review composer.lock for any suspicious changes"
echo "- Use 'composer audit' before each deployment"
echo "- Consider using Snyk or Dependabot for automated scanning"

echo ""
echo "==================================="
echo "Scan completed"
echo "==================================="
