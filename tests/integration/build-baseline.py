"""Repackage immutable historical source for disposable upgrade tests only."""
import importlib.util
import hashlib
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("builder", root / "scripts/build-plugin.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
commit = "830975c6fa00049708d5549f438b1fabc9822259"
prefix = "h5p-sensei-bridge/"
names = subprocess.check_output(["git", "ls-tree", "-r", "--name-only", commit, "--", prefix], cwd=root).decode().splitlines()
if not names or prefix + "h5p-sensei-bridge.php" not in names:
    raise SystemExit("Historical commit unavailable: fetch full history before package integration")
files = {name: subprocess.check_output(["git", "show", commit + ":" + name], cwd=root) for name in names}
path = Path(sys.argv[1]) / "historical-develop-test-only.zip"
builder.write_zip(path, files)
path.with_suffix(".zip.sha256").write_text(hashlib.sha256(path.read_bytes()).hexdigest() + "  " + path.name + "\n")
print("Historical source: " + commit + " → " + str(path))
