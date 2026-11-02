#!/usr/bin/env python3
"""Build the native LoxBerry ZIP using only Python's standard library (developer tool)."""
import configparser
from pathlib import Path
import stat
import zipfile

root = Path(__file__).resolve().parents[1]
cfg = configparser.ConfigParser()
cfg.read(root / "plugin.cfg", encoding="utf-8")
version = cfg["PLUGIN"]["VERSION"]
output = root / "dist" / ("loxdora-" + version + ".zip")
output.parent.mkdir(exist_ok=True)

required = ["plugin.cfg", "preinstall.sh", "preupgrade.sh", "postinstall.sh", "bin/worker.php",
            "bin/start.sh", "webfrontend/htmlauth/index.php", "webfrontend/htmlauth/dashboard.php",
            "webfrontend/htmlauth/dashboard.css", "bin/lib/VehicleProfile.php", "bin/lib/VehicleArtwork.php",
            "cron/cron.01min", "cron/cron.reboot",
            "uninstall/uninstall"]
for size in (64, 128, 256, 512):
    required.append("icons/icon_" + str(size) + ".png")
missing = [name for name in required if not (root / name).is_file()]
if missing:
    raise SystemExit("Missing package files: " + ", ".join(missing))

files = [root / name for name in ("plugin.cfg", "preinstall.sh", "preupgrade.sh", "postinstall.sh")]
for directory in ("bin", "cron", "icons", "templates", "webfrontend", "uninstall"):
    files += [path for path in (root / directory).rglob("*") if path.is_file() and not path.name.startswith(".")]
for name in ("README.md", "MQTT.md", "LICENSE", "NOTICE.md", "docs/DEVELOPMENT.md"):
    if (root / name).is_file():
        files.append(root / name)

# An explicit allowlist excludes credentials, runtime data, tests, and developer files.
with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(set(files)):
        relative = path.relative_to(root).as_posix()
        info = zipfile.ZipInfo(relative, (2026, 10, 31, 0, 0, 0))
        executable = path.suffix == ".sh" or relative.startswith("cron/") or relative == "uninstall/uninstall"
        info.external_attr = (stat.S_IFREG | (0o755 if executable else 0o644)) << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, path.read_bytes())
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
print(output)
