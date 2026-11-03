# 0007. Results computed on read with SQL aggregates

Status: proposed (2026-11-03)

## Context

Dashboards need averages and distributions per criterion, run and tag, a two-run comparison and the cases with the
largest disagreement. The requirement is under 500 ms at the demo limits (5,000 cases, 5 runs). Scores change while
an evaluation is open.

## Decision

- Compute results at request time with SQL (`GROUP BY`, `FILTER`, `unnest(tags)`) through DBAL, in the Analytics
  module.
- Return the computed tables, not raw scores.
- Do not use summary tables or caches in the MVP.

## Consequences

- Results are always current, and there is no cache invalidation to get wrong.
- Query time grows with the number of scores. If the seeded-data timing (slice 9) misses 500 ms, add a materialized
  view per evaluation, refreshed after scoring and check jobs, or a summary table maintained by the job handler.
