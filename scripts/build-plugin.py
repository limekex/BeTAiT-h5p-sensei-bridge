#!/usr/bin/env python3
"""Build a deterministic, allowlisted WordPress ZIP. No dependency download."""
import argparse
import hashlib
import gettext
import json
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = "h5p-sensei-bridge"


def metadata(root):
    if (root / "LICENSE").read_bytes() != (root / SLUG / "LICENSE").read_bytes():
        raise ValueError("Repository and package licenses differ")
    source = (root / SLUG / (SLUG + ".php")).read_text()
    def header(name):
        match = re.search(r"^ \* " + re.escape(name) + r":\s*(.+)$", source, re.M)
        if not match:
            raise ValueError("Missing header: " + name)
        return match.group(1).strip()
    version = header("Version")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:-(?:dev|rc\.[0-9]+))?", version):
        raise ValueError("Unsupported version: " + version)
    if not re.search(r"define\( 'FKHS_VER', '" + re.escape(version) + r"' \)", source):
        raise ValueError("Header and FKHS_VER differ")
    readme = (root / SLUG / "readme.txt").read_text()
    for name, value in (("Stable tag", version), ("Requires at least", header("Requires at least")), ("Requires PHP", header("Requires PHP")), ("License", header("License"))):
        if not re.search(r"^" + re.escape(name + ": " + value) + r"$", readme, re.M):
            raise ValueError("readme.txt mismatch: " + name)
    for catalog in (root / SLUG / "languages").glob("*.po*"):
        if "Project-Id-Version: BeTAiT H5P → Sensei Bridge " + version + "\\n" not in catalog.read_text():
            raise ValueError("Stale translation version: " + catalog.name)
    for catalog in (root / SLUG / "languages").glob("*.mo"):
        with catalog.open("rb") as stream:
            project = gettext.GNUTranslations(stream).info().get("project-id-version")
        if project != "BeTAiT H5P → Sensei Bridge " + version:
            raise ValueError("Stale compiled translation version: " + catalog.name)
    if header("Requires Plugins") != "sensei-lms, h5p":
        raise ValueError("Expected Sensei LMS and H5P dependency metadata")
    return version


def package_files(root):
    """Only tracked runtime files; refuse unexpected files rather than ship them."""
    tracked = subprocess.check_output(["git", "ls-files", "-z", "--", SLUG], cwd=root).decode().split("\0")
    result = {}
    for name in filter(None, tracked):
        path = Path(name)
        parts = path.parts[1:]
        allowed = (
            len(parts) == 1 and parts[0] in (SLUG + ".php", "uninstall.php", "readme.txt", "LICENSE")
            or len(parts) == 2 and parts[0] == "includes" and re.fullmatch(r"class-fkhs-[a-z-]+\.php", parts[1])
            or len(parts) == 3 and parts[:2] == ("assets", "js") and re.fullmatch(r"h5p-sensei-[a-z-]+\.js", parts[2])
            or len(parts) == 3 and parts[:2] == ("assets", "css") and parts[2] in ("h5p-sensei-editor.css", "h5p-sensei-progress.css")
            or len(parts) == 2 and parts[0] == "languages" and re.fullmatch(r"h5p-sensei-bridge(?:-[A-Za-z0-9_-]+)?\.(?:po|mo|pot|json)", parts[1])
        )
        if not allowed or (root / path).is_symlink() or not (root / path).is_file():
            raise ValueError("Unexpected package entry: " + name)
        result[name] = (root / path).read_bytes()
    for name in (SLUG + ".php", "uninstall.php", "readme.txt", "LICENSE", "includes/class-fkhs-plugin.php", "includes/class-fkhs-privacy.php", "includes/class-fkhs-editor.php", "includes/class-fkhs-progress-view.php", "includes/class-fkhs-course-progress.php", "assets/js/h5p-sensei-course-progress.js", "assets/css/h5p-sensei-progress.css", "assets/css/h5p-sensei-editor.css", "assets/js/h5p-sensei-editor.js", "assets/js/h5p-sensei-progress-editor.js", "assets/js/h5p-sensei-bridge.js", "languages/h5p-sensei-bridge-nb_NO.mo"):
        if SLUG + "/" + name not in result:
            raise ValueError("Missing tracked runtime file: " + name)
    return result


def write_zip(path, files):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, content in sorted(files.items()):
            info = zipfile.ZipInfo(name, (2020, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, content)


def build(root, output, tag=None):
    version = metadata(root)
    if tag is not None:
        if tag != "v" + version or version.endswith("-dev"):
            raise ValueError("Release tag must match the header; dev builds cannot release")
        changelog = (root / "CHANGELOG.md").read_text()
        if not re.search(r"^## \[" + re.escape(version) + r"\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$", changelog, re.M):
            raise ValueError("Missing dated changelog entry for release version")
        if subprocess.check_output(["git", "status", "--porcelain", "--untracked-files=normal"], cwd=root).strip():
            raise ValueError("Release builds require a clean checkout")
    files = package_files(root)
    output.mkdir(parents=True, exist_ok=True)
    path = output / (SLUG + "-" + version + ".zip")
    write_zip(path, files)
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    path.with_suffix(".zip.sha256").write_text(digest + "  " + path.name + "\n")
    manifest = {"version": version, "commit": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root).decode().strip(), "dirty": bool(subprocess.check_output(["git", "status", "--porcelain"], cwd=root).strip()), "sha256": digest, "files": {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())}}
    path.with_suffix(".manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    return path


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=ROOT / "dist")
    parser.add_argument("--tag", help="Release mode: verify tag/header/changelog and clean checkout")
    args = parser.parse_args()
    try:
        print(build(ROOT, args.output.resolve(), args.tag))
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + "\n")
