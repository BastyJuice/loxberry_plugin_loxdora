#!/bin/bash
set -eu
trap 'echo "<FAIL> Loxdora konnte für das Update nicht gesichert werden."; exit 2' ERR

task_temp="${6:?LoxBerry-Installationspfad fehlt}"
task_config='REPLACELBPCONFIGDIR'
task_data='REPLACELBPDATADIR'
task_worker='REPLACELBPBINDIR/worker.php'

if [ -f "$task_worker" ]; then
    /usr/bin/php "$task_worker" --stop
fi
mkdir -p "$task_temp/.pandora-backup"
if [ -d "$task_config" ]; then
    cp -a "$task_config" "$task_temp/.pandora-backup/config"
fi
if [ -d "$task_data" ]; then
    cp -a "$task_data" "$task_temp/.pandora-backup/data"
fi
echo "<OK> Fahrzeugkonfiguration, Schlüssel und Status für das Update gesichert."
exit 0
