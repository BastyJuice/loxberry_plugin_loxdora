#!/bin/bash
set -eu
umask 077
# A duplicate launch exits immediately through worker.php's flock lock.
# The LoxBerry cron supervisor restarts a failed worker within one minute.
# Worker diagnostics are bounded JSON state files, never an unbounded log.
nohup /usr/bin/php 'REPLACELBPBINDIR/worker.php' </dev/null >/dev/null 2>&1 &
exit 0
