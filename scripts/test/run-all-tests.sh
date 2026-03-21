#!/bin/bash

# Run all tests (unit, integration, E2E)
# Usage: ./run-all-tests.sh [--coverage]

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

COVERAGE=false
if [ "$1" = "--coverage" ]; then
    COVERAGE=true
fi

echo -e "${BLUE}==================================="
echo "Running All Tests"
echo "===================================${NC}"
echo ""

FAILED=0
PASSED=0

# Function to run tests
run_test_suite() {
    local name=$1
    local path=$2
    local coverage_flag=$3
    
    echo -e "${YELLOW}Running $name...${NC}"
    echo "-----------------------------------"
    
    if [ "$COVERAGE" = true ] && [ -n "$coverage_flag" ]; then
        if cd "$path" && ../../../vendor/bin/phpunit --testdox --coverage-text; then
            echo -e "${GREEN}✓ $name passed${NC}"
            ((PASSED++))
        else
            echo -e "${RED}✗ $name failed${NC}"
            ((FAILED++))
        fi
    else
        if cd "$path" && ../../../vendor/bin/phpunit --testdox; then
            echo -e "${GREEN}✓ $name passed${NC}"
            ((PASSED++))
        else
            echo -e "${RED}✗ $name failed${NC}"
            ((FAILED++))
        fi
    fi
    
    cd - > /dev/null
    echo ""
}

# Run unit tests
echo -e "${BLUE}=== Unit Tests ===${NC}"
echo ""

run_test_suite "Line Tests" "backend/line/tests" "--coverage-text"
run_test_suite "Core Tests" "backend/core/tests" "--coverage-text"
run_test_suite "Scanner Tests" "backend/scanner/tests" "--coverage-text"
run_test_suite "Statistic Tests" "backend/statistic/tests" "--coverage-text"

# Run integration tests
echo -e "${BLUE}=== Integration Tests ===${NC}"
echo ""

if [ -d "tests/integration" ]; then
    run_test_suite "Integration Tests" "tests/integration" ""
else
    echo -e "${YELLOW}⚠ Integration tests directory not found${NC}"
    echo ""
fi

# Run E2E tests (only if enabled)
if [ "$RUN_E2E_TESTS" = "1" ]; then
    echo -e "${BLUE}=== E2E Tests ===${NC}"
    echo ""
    
    if [ -d "tests/e2e" ]; then
        run_test_suite "E2E Tests" "tests/e2e" ""
    else
        echo -e "${YELLOW}⚠ E2E tests directory not found${NC}"
        echo ""
    fi
else
    echo -e "${YELLOW}⚠ E2E tests skipped (set RUN_E2E_TESTS=1 to enable)${NC}"
    echo ""
fi

# Summary
echo -e "${BLUE}==================================="
echo "Test Summary"
echo "===================================${NC}"
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo ""

if [ $FAILED -gt 0 ]; then
    echo -e "${RED}TESTS FAILED${NC}"
    exit 1
else
    echo -e "${GREEN}ALL TESTS PASSED${NC}"
    exit 0
fi
