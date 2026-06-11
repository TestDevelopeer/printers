import { mkdtempSync, rmSync } from "node:fs";
import { cpSync } from "node:fs";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";
import { spawnSync } from "node:child_process";

const requiredEnv = [
  "DEPLOY_HOST",
  "DEPLOY_PORT",
  "DEPLOY_USER",
  "DEPLOY_REMOTE_DIR",
  "DEPLOY_SSH_KEY_PATH",
];

for (const key of requiredEnv) {
  if (!process.env[key]) {
    console.error(`Missing required environment variable: ${key}`);
    process.exit(1);
  }
}

const rootDir = resolve(process.cwd());
const tempDir = mkdtempSync(join(tmpdir(), "printers-deploy-"));
const stageDir = join(tempDir, "stage");
const codeArchivePath = join(tempDir, "release.tar.gz");
const imageArchivePath = join(tempDir, "images.tar");
const remoteCodeTmp = `/tmp/printers-deploy-${Date.now()}.tar.gz`;
const remoteImageTmp = `/tmp/printers-images-${Date.now()}.tar`;
const appImage = "asp-printers:latest";
const runtimeImages = [
  appImage,
  "nginx:1.29-alpine",
  "redis:8-alpine",
  "postgres:17-alpine",
];

const excludeNames = new Set([
  ".git",
  ".github",
  "node_modules",
  "vendor",
  "deploy-build",
  ".playwright-mcp",
  "storage",
  "tests",
]);

const excludeFiles = new Set([
  ".env",
  ".env.docker",
  ".env.server",
  ".phpunit.result.cache",
]);

const run = (command, args, options = {}) => {
  const result = spawnSync(command, args, {
    stdio: "inherit",
    shell: false,
    ...options,
  });

  if (result.status !== 0) {
    throw new Error(`${command} ${args.join(" ")} failed with code ${result.status}`);
  }
};

try {
  run("docker", ["build", "-t", appImage, "."]);

  for (const image of runtimeImages.slice(1)) {
    const inspect = spawnSync("docker", ["image", "inspect", image], {
      stdio: "ignore",
      shell: false,
    });

    if (inspect.status !== 0) {
      run("docker", ["pull", image]);
    }
  }

  cpSync(rootDir, stageDir, {
    recursive: true,
    force: true,
    filter: (src) => {
      const rel = resolve(src).replace(`${rootDir}\\`, "").replace(`${rootDir}/`, "");
      if (rel === "") {
        return true;
      }
      const parts = rel.split(/[\\/]/).filter(Boolean);
      if (parts.some((part) => excludeNames.has(part))) {
        return false;
      }
      const base = parts[parts.length - 1];
      if (base && excludeFiles.has(base)) {
        return false;
      }
      if (base === "storage" || base === ".github") {
        return false;
      }
      return true;
    },
  });

  run("tar", ["-czf", codeArchivePath, "-C", stageDir, "."]);
  run("docker", ["save", "-o", imageArchivePath, ...runtimeImages]);

  const sshArgs = [
    "-i",
    process.env.DEPLOY_SSH_KEY_PATH,
    "-p",
    process.env.DEPLOY_PORT,
    "-o",
    "StrictHostKeyChecking=yes",
  ];

  if (process.env.DEPLOY_KNOWN_HOSTS_PATH) {
    sshArgs.push("-o", `UserKnownHostsFile=${process.env.DEPLOY_KNOWN_HOSTS_PATH}`);
  }

  const scpArgs = [
    "-i",
    process.env.DEPLOY_SSH_KEY_PATH,
    "-P",
    process.env.DEPLOY_PORT,
    "-o",
    "StrictHostKeyChecking=yes",
  ];

  if (process.env.DEPLOY_KNOWN_HOSTS_PATH) {
    scpArgs.push("-o", `UserKnownHostsFile=${process.env.DEPLOY_KNOWN_HOSTS_PATH}`);
  }

  run("scp", [...scpArgs, codeArchivePath, `${process.env.DEPLOY_USER}@${process.env.DEPLOY_HOST}:${remoteCodeTmp}`]);
  run("scp", [...scpArgs, imageArchivePath, `${process.env.DEPLOY_USER}@${process.env.DEPLOY_HOST}:${remoteImageTmp}`]);

  const remoteScript = `
set -euo pipefail
REMOTE_DIR="${process.env.DEPLOY_REMOTE_DIR}"
TMP_DIR="$(mktemp -d /tmp/printers-release.XXXXXX)"
cleanup() {
  rm -rf "$TMP_DIR"
  rm -f "${remoteCodeTmp}"
  rm -f "${remoteImageTmp}"
}
trap cleanup EXIT

mkdir -p "$REMOTE_DIR"
mkdir -p "$REMOTE_DIR/storage"
docker load -i "${remoteImageTmp}"
tar -xzf "${remoteCodeTmp}" -C "$TMP_DIR"

shopt -s dotglob nullglob
for entry in "$TMP_DIR"/*; do
  name="$(basename "$entry")"
  case "$name" in
    .env.server|storage)
      continue
      ;;
  esac
  target="$REMOTE_DIR/$name"

  if [ -d "$entry" ]; then
    mkdir -p "$target"
    find "$target" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    cp -a "$entry"/. "$target"/
  else
    rm -f "$target"
    cp -a "$entry" "$target"
  fi
done

cd "$REMOTE_DIR"
docker compose --env-file .env.server -f docker-compose.prod.yml up -d --remove-orphans
docker compose --env-file .env.server -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose --env-file .env.server -f docker-compose.prod.yml exec -T app php artisan optimize:clear
docker compose --env-file .env.server -f docker-compose.prod.yml ps
curl -fsS http://127.0.0.1:1265/admin/login >/dev/null
`;

  run("ssh", [
    ...sshArgs,
    `${process.env.DEPLOY_USER}@${process.env.DEPLOY_HOST}`,
    remoteScript,
  ]);
} finally {
  rmSync(tempDir, { recursive: true, force: true });
}
