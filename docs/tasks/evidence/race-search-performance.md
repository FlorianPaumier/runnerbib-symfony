# RACE-05 diagnostic — local synthetic dataset

An isolated `search_test_*` PostgreSQL database was migrated, loaded with 10,000 entirely synthetic published races, analyzed and dropped after measurements. Application data and volumes were preserved. [Full EXPLAIN ANALYZE BUFFERS plans](race-query-plans.json).

| Query | Execution time |
|---|---:|
| Broad ordered first page | 0.903 ms |
| Selective literal name substring | 7.705 ms |
| Sport and date first page | 0.067 ms |
| City and distance first page | 2.650 ms |

These are single local Docker observations while other work was active, not a latency guarantee or acceptance budget. The existing public start/sport/name indexes are available to the planner; the exact plan choice is in the linked report. No additional index was added on these observations alone. Search remains bounded to 21 fetched records for a 20-result page and uses one joined query, with no per-result query loop.

RACE-05 remains open pending DEC-08 representative volumes, latency/concurrency budgets and repeated measurements in the accepted environment. Trigram indexes do not imply geospatial/typo-search functionality.
