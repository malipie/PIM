# ADR-0038: Shared Redis registry for multi-worker metrics

**Status:** Accepted

**Date:** 2026-08-28

**Ticket:** #3021 (`AUD-OBS-001`)

## Context

FrankenPHP runs several long-lived PHP workers behind one HTTP target. The old
query histogram and RBAC counters lived in PHP object memory, so `/api/metrics`
returned the state of whichever worker happened to receive the scrape. A rolling
maximum cannot reconstruct a counter sum or a valid histogram.

Every deployment topology already has a healthchecked Redis used for locks and
other shared coordination. Adding a separate exporter or Pushgateway would add a
new production dependency and another failure surface solely for telemetry.

## Decision

Prometheus counters, RBAC gauges and DB-query histograms use a versioned Redis
namespace (`pim:metrics:v1:<environment>:<test-token>`):

- counters use atomic `HINCRBY`;
- gauges use `HSET` with last-write-wins semantics;
- one histogram observation uses a Lua transaction to update `_count`, `_sum`
  and every matching cumulative bucket atomically;
- recording is fail-open for business operations; `/api/metrics` exposes
  `pim_shared_metrics_registry_up` so loss of the registry is visible;
- worker-local process gauges (`memory`, `peak memory`, `pid`) deliberately stay
  local and continue to use rolling maxima in alert rules.

The registry is cumulative for the Redis namespace. Recycling or replacing one
FrankenPHP worker does not reset it. A controlled reset means either changing
the namespace schema version or deleting that exact namespace during tenant
deprovisioning/testing. Removing the tenant Redis volume resets all counters as
part of deleting the tenant, not as a worker lifecycle event. Redis persistence
configuration governs survival of a Redis service restart.

## Consequences

- A scrape from any worker sees the same instance-wide counter and histogram.
- Query recording adds one local-network Redis command per Doctrine query. The
  operation does not recurse through DBAL and uses one persistent connection per
  worker.
- Redis unavailability creates a telemetry gap but does not break requests;
  dependency probes plus `pim_shared_metrics_registry_up` expose that gap.
- ParaTest workers receive separate namespaces through `TEST_TOKEN`, preserving
  deterministic query-budget deltas.

## Verification

The integration gate creates two independent Redis clients, records 100 events,
checks counter and histogram count/sum through three scrapes, reconstructs one
producer, and proves the global values do not decrease.
