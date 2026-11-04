# 0003. Messenger on the Doctrine transport, no Redis

Status: accepted (approved 2026-11-04)

## Context

Automatic checks run as background jobs that must be retryable and idempotent. The original plan named Redis or
Doctrine as the Messenger transport. At the MVP limits, a full check run is at most about 25,000 answers × a few
automatic criteria.

## Decision

- Use Messenger's **Doctrine transport** on the existing PostgreSQL database. PostgreSQL `LISTEN/NOTIFY`
  (`use_notify`) wakes the worker without polling.
- The `messenger_messages` table is created by a migration (`auto_setup: false`).
- A `failed` transport keeps the messages that exhausted their retries.
- Job progress lives in the `check_job` table, not in the queue.

## Consequences

- No Redis service, extension or configuration: one fewer container and fewer moving parts.
- Queue throughput is limited by PostgreSQL. That is ample for this load; switching to Redis later only changes the
  transport DSN and the Compose file, because handlers do not depend on the transport.
- Handlers must be idempotent anyway (retries), which the unique score index guarantees.
