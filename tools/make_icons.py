#!/usr/bin/env python3
"""Export the Loxdora vector master; this developer tool never runs on LoxBerry."""
from pathlib import Path
import shutil
import subprocess

root = Path(__file__).resolve().parents[1]
source = root / 'assets/branding/Loxdora-Icon.svg'
renderer = shutil.which('rsvg-convert')
if not renderer:
    raise SystemExit('rsvg-convert is required only on the development computer to export PNG icons.')
if not source.is_file():
    raise SystemExit('The Loxdora SVG master is missing.')

output = root / 'icons'
output.mkdir(exist_ok=True)
shutil.copyfile(source, output / 'icon.svg')
for size in (64, 128, 256, 512):
    subprocess.run([renderer, '-w', str(size), '-h', str(size), str(source),
                    '-o', str(output / ('icon_%d.png' % size))], check=True)
for size in (512, 1024):
    subprocess.run([renderer, '-w', str(size), '-h', str(size), str(source),
                    '-o', str(source.with_name('Loxdora-Icon-%d.png' % size))], check=True)
print('Loxdora SVG and PNG icons exported (64, 128, 256, 512 and 1024 px).')
