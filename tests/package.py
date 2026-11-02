#!/usr/bin/env python3
"""Verify shell hooks and update preservation in a temporary fake LoxBerry tree."""
import configparser
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import zipfile

root = Path(__file__).resolve().parents[1]
php = shutil.which("php")
assert php, "PHP CLI is required for packaging tests"
shell_files = [root / name for name in ("preinstall.sh", "preupgrade.sh", "postinstall.sh", "bin/start.sh",
                                       "cron/cron.reboot", "cron/cron.01min", "uninstall/uninstall")]
for path in shell_files:
    subprocess.run(["bash", "-n", str(path)], check=True)

with tempfile.TemporaryDirectory(prefix="pandora-package-") as directory:
    base = Path(directory)
    config = base / "config/plugins/pandoraconnect"
    data = base / "data/plugins/pandoraconnect"
    binary = base / "bin/plugins/pandoraconnect"
    extracted = base / "upload"
    for path in (config, data, binary, extracted):
        path.mkdir(parents=True)
    replacements = {"REPLACELBHOMEDIR": str(base), "REPLACELBPPLUGINDIR": "pandoraconnect",
                    "REPLACELBPCONFIGDIR": str(config), "REPLACELBPDATADIR": str(data),
                    "REPLACELBPBINDIR": str(binary)}

    def prepare(source):
        text = source.read_text().replace("/usr/bin/php", php)
        for token, value in replacements.items():
            text = text.replace(token, value)
        target = extracted / source.relative_to(root)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)
        return target

    prepared = {path.relative_to(root).as_posix(): prepare(path) for path in shell_files}
    worker = binary / "worker.php"
    worker.write_text("""<?php
$data = __DIR__ . '/../../../data/plugins/pandoraconnect';
if (($argv[1] ?? '') === '--stop') { file_put_contents($data . '/maintenance', '1'); exit; }
if (($argv[1] ?? '') === '--resume') { @unlink($data . '/maintenance'); exit; }
file_put_contents($data . '/worker-started', '1');
""")
    (config / "secret.key").write_bytes(bytes(range(32)))
    (config / "vehicles.json").write_text('{"fixture":"encrypted-fixture"}')
    (data / "state-fahrzeug_1.json").write_text('{"fixture":"state-fixture"}')

    args = ["upload", "pandoraconnect", "pandoraconnect", "0.1.0", str(base), str(extracted)]
    subprocess.run(["bash", str(prepared["preupgrade.sh"]), *args], check=True, capture_output=True)
    assert (data / "maintenance").exists(), "Upgrade must stop the old worker"
    shutil.rmtree(config)
    shutil.rmtree(data)
    shutil.copyfile(prepared["bin/start.sh"], binary / "start.sh")
    subprocess.run(["bash", str(prepared["postinstall.sh"]), *args], check=True, capture_output=True)
    assert (config / "secret.key").read_bytes() == bytes(range(32)), "Update lost encryption key"
    assert "encrypted-fixture" in (config / "vehicles.json").read_text(), "Update lost configuration"
    assert "state-fixture" in (data / "state-fahrzeug_1.json").read_text(), "Update lost state"
    assert not (data / "maintenance").exists(), "Update did not resume worker"
    deadline = time.monotonic() + 2
    while not (data / "worker-started").exists() and time.monotonic() < deadline:
        time.sleep(0.02)
    assert (data / "worker-started").exists(), "Postinstall did not start worker"
    assert config.stat().st_mode & 0o777 == 0o700
    assert data.stat().st_mode & 0o777 == 0o700

cfg = configparser.ConfigParser()
cfg.read(root / "plugin.cfg")
archive = root / "dist" / ("loxdora-" + cfg["PLUGIN"]["VERSION"] + ".zip")
if archive.exists():
    with zipfile.ZipFile(archive) as package:
        assert package.testzip() is None
        names = package.namelist()
        assert "plugin.cfg" in names and "webfrontend/htmlauth/index.php" in names
        assert "LICENSE" in names and "NOTICE.md" in names
        assert "webfrontend/htmlauth/dashboard.php" in names and "webfrontend/htmlauth/dashboard.css" in names
        assert "bin/lib/VehicleProfile.php" in names and "bin/lib/VehicleArtwork.php" in names
        assert not any(name.startswith(("tests/", "tools/", "config/", "data/")) for name in names)
        assert not any("secret.key" in name or "vehicles.json" in name for name in names)
        for name in ("cron/cron.01min", "cron/cron.reboot", "bin/start.sh", "uninstall/uninstall"):
            assert (package.getinfo(name).external_attr >> 16) & 0o111
print("PASS package: shell syntax, stop/restore/resume/start hooks, key/config/state preservation and private directories" + (", ZIP manifest and permissions" if archive.exists() else " (ZIP not built yet)"))
