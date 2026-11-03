# 0005. Evaluation items as opaque handles for scoring

Status: proposed (2026-11-03)

## Context

The scoring screen needs a stable order grouped by case, a "next answer to score", idempotent saves and, in blind
mode, no trace of which run produced an answer. The UX asks that blind mode leaves nothing in the URL or the HTML.
Answer ids would not reveal the run on their own, but a predictable order or an embedded run id would.

## Decision

- When an evaluation is created, insert one `evaluation_item` per answer of its runs:
  - a **random v4 UUID**;
  - a `position` that groups items by case;
  - in blind mode, runs **shuffled within each case**.
- Scoring endpoints address items only (`…/items/{itemId}/scores/{criterionId}`).
- In blind mode, item responses omit the run id, label and letter.
- Scores reference the item, with a unique (`evaluation_item_id`, `criterion_id`) index, so a save is an upsert.

## Consequences

- Blind mode is enforced by the API, not just hidden by the UI.
- "Next" and progress are simple indexed queries on (`evaluation_id`, `position`).
- About 25,000 extra rows per evaluation at the MVP limits, which is negligible.
- Results join items back to runs, which only result endpoints do, and those check the blind rules for evaluators.
