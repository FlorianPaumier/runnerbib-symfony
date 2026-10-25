#!/bin/sh
set -eu
child=''
trap 'if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || true; wait "$child" 2>/dev/null || true; fi; exit 0' TERM INT
run() {
    php bin/console "$@" &
    child=$!
    # A failing job is reported and retried on the next cycle; it never stops the scheduler.
    wait "$child" || echo "scheduled job failed: $*"
}
while :; do
    run app:availability:run --limit=100
    run app:outbox:dispatch --limit=100
    run app:checkout:reconcile --limit=100
    run app:provider-events:process --limit=100
    run app:sellers:reconcile --limit=50
    run app:retention:purge
    sleep 60 &
    child=$!
    wait "$child"
done
