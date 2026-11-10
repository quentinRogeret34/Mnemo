# Store everything in Postgres

- Date: 2026-06-01
- Status: Accepted
- Supersedes: use-mysql.md

## Context

Vectors are needed for semantic search.

## Decision

Postgres with pgvector.

## Alternatives

A separate vector database: one more service to run.

## Consequences

Migration of the existing data.
