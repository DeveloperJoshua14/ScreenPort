"""Create a deployment archive from a positive allowlist; exclude all private state."""
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[1]
folders = ["app", "public", "bin", "resources", "deploy", "vendor"]
names = ["composer.json", "composer.lock", "Dockerfile", "compose.yaml", "compose.https.yaml", ".env.example", ".htaccess", ".gitignore", ".dockerignore", "README.md"]
paths = [root / name for name in names]
for folder in folders:
    paths.extend(p for p in (root / folder).rglob("*") if p.is_file())
secrets = []
for line in (root / ".env").read_text(encoding="utf-8-sig").splitlines():
    match = re.match(r"\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)", line)
    if match and any(word in match[1].upper() for word in ["PASSWORD", "APIKEY", "API_KEY", "ACCESS_TOKEN", "APP_KEY"]):
        value = match[2].strip().strip("\"'")
        if len(value) >= 8:
            secrets.append(value.encode())
for path in paths:
    data = path.read_bytes()
    if any(secret in data for secret in secrets):
        raise RuntimeError("A private credential was detected in a release file; packaging stopped.")
output = root / "ScreenPort-deploy.zip"
with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
    for path in paths:
        archive.write(path, path.relative_to(root).as_posix())
with zipfile.ZipFile(output) as archive:
    assert not any(name == ".env" or name.startswith(("storage/", ".test-output/", ".tools/")) for name in archive.namelist())
print(f"Deployment archive verified: {len(paths)} files, {output.stat().st_size // 1024} KiB. Credentials and private storage excluded.")
