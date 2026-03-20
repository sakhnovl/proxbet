---
name: refactoring-patterns
description: Safe refactoring patterns for legacy code, modernization, and incremental cleanup. Use when refactoring, restructuring, or reducing technical debt without changing behavior.
---

# Refactoring Patterns

## When To Use

- Legacy code modernization
- Large functions or classes that need splitting
- Introducing new abstractions safely
- Reducing coupling or improving testability

## Core Principles

- Preserve behavior first, improve structure second.
- Work in small, reversible steps.
- Add characterization tests before touching risky code.
- Prefer explicit, readable changes over cleverness.

## Safe Refactoring Flow

1. Identify the target behavior and boundaries.
2. Add or expand tests that lock current behavior.
3. Apply one refactor at a time.
4. Run tests after each step.
5. Re-check dependencies and update related code.

## Common Patterns

- Extract Method
- Extract Class
- Inline Method (remove unnecessary indirection)
- Replace Conditional with Polymorphism
- Introduce Parameter Object
- Split Command and Query
- Encapsulate Field
- Move Method to Better Owner
- Strangler Fig for gradual replacement

## Risk Controls

- Avoid refactors without tests in high-risk areas.
- Do not change public APIs without explicit approval.
- Keep diff size small and focused per change.

## Output Checklist

- Tests pass for all affected paths.
- No behavior changes unless explicitly requested.
- Naming is clearer and more consistent.
- Dependencies and imports are clean and minimal.
