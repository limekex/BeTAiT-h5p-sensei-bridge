"""Distribution boundaries and release metadata must fail closed."""
import importlib.util
from pathlib import Path
import shutil
import subprocess
import struct
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("builder", ROOT / "scripts/build-plugin.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        shutil.copytree(ROOT / builder.SLUG, self.root / builder.SLUG)
        shutil.copyfile(ROOT / "CHANGELOG.md", self.root / "CHANGELOG.md")
        shutil.copyfile(ROOT / "LICENSE", self.root / "LICENSE")
        subprocess.run(["git", "init", "-q", str(self.root)], check=True)
        self.git("add", ".")
        self.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "commit", "-qm", "fixture")

    def git(self, *args):
        subprocess.run(["git", *args], cwd=self.root, check=True, stdout=subprocess.DEVNULL)

    def test_installable_reproducible_archive(self):
        (self.root / builder.SLUG / "credentials.php").write_text("secret")
        first = builder.build(self.root, self.root / "one").read_bytes()
        second = builder.build(self.root, self.root / "two")
        self.assertEqual(first, second.read_bytes())
        with zipfile.ZipFile(second) as archive:
            names = archive.namelist()
            self.assertIn("h5p-sensei-bridge/h5p-sensei-bridge.php", names)
            self.assertIn("h5p-sensei-bridge/LICENSE", names)
            self.assertTrue(all(name.startswith("h5p-sensei-bridge/") for name in names))
            self.assertFalse(any("credentials" in name or "tests/" in name for name in names))
            for name in names:
                self.assertEqual(archive.read(name), (self.root / name).read_bytes())

    def test_tracked_unexpected_file_refused(self):
        (self.root / builder.SLUG / ".env").write_text("fixture-secret")
        self.git("add", ".")
        with self.assertRaisesRegex(ValueError, "Unexpected"):
            builder.build(self.root, self.root / "out")

    def test_symlink_refused(self):
        path = self.root / builder.SLUG / "uninstall.php"
        path.unlink()
        path.symlink_to("../CHANGELOG.md")
        with self.assertRaisesRegex(ValueError, "Unexpected"):
            builder.build(self.root, self.root / "out")

    def test_metadata_mismatch_refused(self):
        version = builder.metadata(self.root)
        path = self.root / builder.SLUG / (builder.SLUG + ".php")
        path.write_text(path.read_text().replace("define( 'FKHS_VER', '" + version + "' )", "define( 'FKHS_VER', '9.0.0' )"))
        with self.assertRaisesRegex(ValueError, "differ"):
            builder.build(self.root, self.root / "out")

    def test_missing_translation_refused(self):
        (self.root / builder.SLUG / "languages/h5p-sensei-bridge-nb_NO.mo").unlink()
        with self.assertRaises(ValueError):
            builder.build(self.root, self.root / "out")

    def test_dev_and_mismatched_tags_refused(self):
        for tag in ("v1.0.0", "v1.2.0-dev", "../invalid"):
            with self.assertRaisesRegex(ValueError, "tag"):
                builder.build(self.root, self.root / "out", tag)

    def test_release_requires_dated_notes_and_clean_tree(self):
        version = builder.metadata(self.root)
        for relative in (builder.SLUG + "/" + builder.SLUG + ".php", builder.SLUG + "/readme.txt", builder.SLUG + "/languages/h5p-sensei-bridge.pot", builder.SLUG + "/languages/h5p-sensei-bridge-nb_NO.po"):
            path = self.root / relative
            path.write_text(path.read_text().replace(version, "9.9.9"))
        with self.assertRaisesRegex(ValueError, "compiled translation"):
            builder.build(self.root, self.root / "out", "v9.9.9")
        # Minimal valid MO for this isolated version-transition fixture.
        header = "Project-Id-Version: BeTAiT H5P → Sensei Bridge 9.9.9\nContent-Type: text/plain; charset=UTF-8\n".encode()
        mo = struct.pack("<7I", 0x950412de, 0, 1, 28, 36, 0, 0) + struct.pack("<4I", 0, 44, len(header), 45) + b"\0" + header + b"\0"
        (self.root / builder.SLUG / "languages/h5p-sensei-bridge-nb_NO.mo").write_bytes(mo)
        with self.assertRaisesRegex(ValueError, "changelog"):
            builder.build(self.root, self.root / "out", "v9.9.9")
        notes = self.root / "CHANGELOG.md"
        notes.write_text("## [9.9.9] - 2026-11-03\n" + notes.read_text())
        with self.assertRaisesRegex(ValueError, "clean"):
            builder.build(self.root, self.root / "out", "v9.9.9")
        self.git("add", ".")
        self.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "commit", "-qm", "release fixture")
        self.assertTrue(builder.build(self.root, self.root / "out", "v9.9.9").is_file())


if __name__ == "__main__":
    unittest.main()
