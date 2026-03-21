#!/bin/bash

# OWASP ZAP security scanning script
# Performs automated security testing using OWASP ZAP

set -e

echo "==================================="
echo "OWASP ZAP Security Scan"
echo "==================================="
echo ""

# Configuration
TARGET_URL="${1:-http://localhost:8080}"
ZAP_PORT="${ZAP_PORT:-8090}"
REPORT_DIR="./security-reports"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Create report directory
mkdir -p "$REPORT_DIR"

echo "Target URL: $TARGET_URL"
echo "ZAP Port: $ZAP_PORT"
echo "Report Directory: $REPORT_DIR"
echo ""

# Check if ZAP is running
if ! curl -s "http://localhost:$ZAP_PORT" > /dev/null 2>&1; then
    echo -e "${YELLOW}Warning: OWASP ZAP is not running on port $ZAP_PORT${NC}"
    echo "Please start ZAP with: zap.sh -daemon -port $ZAP_PORT"
    echo ""
    echo "Alternative: Run ZAP Docker container:"
    echo "docker run -u zap -p $ZAP_PORT:$ZAP_PORT -i owasp/zap2docker-stable zap.sh -daemon -port $ZAP_PORT -host 0.0.0.0 -config api.disablekey=true"
    exit 1
fi

echo "1. Spider scan (discovering endpoints)..."
echo "-----------------------------------------"
curl -s "http://localhost:$ZAP_PORT/JSON/spider/action/scan/?url=$TARGET_URL" > /dev/null
sleep 5

echo -e "${GREEN}✓ Spider scan completed${NC}"
echo ""

echo "2. Active scan (vulnerability testing)..."
echo "-----------------------------------------"
curl -s "http://localhost:$ZAP_PORT/JSON/ascan/action/scan/?url=$TARGET_URL" > /dev/null
echo "Scanning in progress... This may take several minutes."

# Wait for scan to complete
while true; do
    STATUS=$(curl -s "http://localhost:$ZAP_PORT/JSON/ascan/view/status/" | grep -o '"status":"[0-9]*"' | grep -o '[0-9]*')
    if [ "$STATUS" = "100" ]; then
        break
    fi
    echo "Progress: $STATUS%"
    sleep 10
done

echo -e "${GREEN}✓ Active scan completed${NC}"
echo ""

echo "3. Generating reports..."
echo "------------------------"

# HTML Report
curl -s "http://localhost:$ZAP_PORT/OTHER/core/other/htmlreport/" > "$REPORT_DIR/zap-report-$(date +%Y%m%d-%H%M%S).html"
echo "HTML report: $REPORT_DIR/zap-report-*.html"

# JSON Report
curl -s "http://localhost:$ZAP_PORT/JSON/core/view/alerts/" > "$REPORT_DIR/zap-alerts-$(date +%Y%m%d-%H%M%S).json"
echo "JSON report: $REPORT_DIR/zap-alerts-*.json"

echo ""
echo "4. Summary of findings:"
echo "-----------------------"

# Parse and display high/medium alerts
HIGH_ALERTS=$(curl -s "http://localhost:$ZAP_PORT/JSON/core/view/alertsSummary/" | grep -o '"High":[0-9]*' | grep -o '[0-9]*' || echo "0")
MEDIUM_ALERTS=$(curl -s "http://localhost:$ZAP_PORT/JSON/core/view/alertsSummary/" | grep -o '"Medium":[0-9]*' | grep -o '[0-9]*' || echo "0")
LOW_ALERTS=$(curl -s "http://localhost:$ZAP_PORT/JSON/core/view/alertsSummary/" | grep -o '"Low":[0-9]*' | grep -o '[0-9]*' || echo "0")

echo -e "${RED}High severity: $HIGH_ALERTS${NC}"
echo -e "${YELLOW}Medium severity: $MEDIUM_ALERTS${NC}"
echo "Low severity: $LOW_ALERTS"

echo ""
echo "==================================="
echo "Scan completed"
echo "==================================="

# Exit with error if high severity issues found
if [ "$HIGH_ALERTS" -gt 0 ]; then
    echo -e "${RED}FAIL: High severity vulnerabilities detected!${NC}"
    exit 1
fi

echo -e "${GREEN}PASS: No high severity vulnerabilities detected${NC}"
exit 0
