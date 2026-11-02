#!/bin/bash
set -eu
trap 'echo "<FAIL> Loxdora konnte nicht vollständig eingerichtet werden."; exit 2' ERR

task_temp="${6:?LoxBerry-Installationspfad fehlt}"
task_config='REPLACELBPCONFIGDIR'
task_data='REPLACELBPDATADIR'
task_bin='REPLACELBPBINDIR'

umask 077
mkdir -p "$task_config" "$task_data"
if [ -d "$task_temp/.pandora-backup/config" ]; then
    cp -a "$task_temp/.pandora-backup/config/." "$task_config/"
fi
if [ -d "$task_temp/.pandora-backup/data" ]; then
    cp -a "$task_temp/.pandora-backup/data/." "$task_data/"
fi
chmod 700 "$task_config" "$task_data"
chmod 755 "$task_bin/start.sh"
/usr/bin/php "$task_bin/worker.php" --resume
/bin/bash "$task_bin/start.sh"
echo "<OK> Loxdora eingerichtet. Fahrzeuge unter Meine Fahrzeuge anlegen."
exit 0
