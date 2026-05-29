#!/bin/bash
# Build cloudscale-cleanup.zip from the repo directory
# Creates a zip with cloudscale-cleanup/ as the top level folder
# which is the structure WordPress expects for plugin upload
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# Guard against diverged history caused by parallel sessions on the same machine.
if git -C "$SCRIPT_DIR" fetch origin main --quiet 2>/dev/null; then
    LOCAL=$(git -C "$SCRIPT_DIR" rev-parse HEAD)
    REMOTE=$(git -C "$SCRIPT_DIR" rev-parse origin/main)
    if [ "$LOCAL" != "$REMOTE" ] && git -C "$SCRIPT_DIR" merge-base --is-ancestor "$LOCAL" "$REMOTE" 2>/dev/null; then
        echo "⚠ Remote is ahead of local — pulling before build to avoid drift..."
        git -C "$SCRIPT_DIR" pull --ff-only origin main
        echo "✓ Pulled. Continuing build."
    fi
fi

# Load shared Claude model config
GITHUB_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck source=../.claude-config.sh
source "$GITHUB_DIR/.claude-config.sh"
REPO_DIR="$SCRIPT_DIR"
ZIP_FILE="$SCRIPT_DIR/cloudscale-cleanup.zip"
PLUGIN_NAME="cloudscale-cleanup"
TEMP_DIR=$(mktemp -d)

echo "Building plugin zip from $REPO_DIR..."
# ── Auto-increment patch version ─────────────────────────────────────────────
MAIN_PHP=$(grep -rl "^ \* Version:" "$REPO_DIR" --include="*.php" 2>/dev/null | grep -v "repo/" | head -1)
if [ -z "$MAIN_PHP" ]; then
  echo "ERROR: Could not find main plugin PHP file with Version header."
  exit 1
fi
CURRENT_VER=$(grep "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ -z "$CURRENT_VER" ]; then
  echo "ERROR: Could not extract version from $MAIN_PHP"
  exit 1
fi
VER_MAJOR=$(echo "$CURRENT_VER" | cut -d. -f1)
VER_MINOR=$(echo "$CURRENT_VER" | cut -d. -f2)
VER_PATCH=$(echo "$CURRENT_VER" | cut -d. -f3)
NEW_VER="$VER_MAJOR.$VER_MINOR.$((VER_PATCH + 1))"
# Escape dots so the sed pattern is a literal version string, not a regex with
# wildcards. Without this, "s/2.5.65/.../g" matches "255,255,255,0.15" inside
# inline CSS rgba() values and mangles them (see commit 623474a, v2.5.28 and
# v2.5.65 — both fixed the same recurring CSS corruption).
ESC_VER=$(printf '%s\n' "$CURRENT_VER" | sed 's/\./\\./g')
# Word-boundary anchors prevent matching version-like substrings inside
# longer numeric runs (e.g. the "2.5.65" inside a hypothetical "12.5.654").
echo "Version bump: $CURRENT_VER → $NEW_VER"
while IFS= read -r vfile; do
  sed -i '' "s/[[:<:]]${ESC_VER}[[:>:]]/$NEW_VER/g" "$vfile"
done < <(grep -rl "$CURRENT_VER" "$REPO_DIR" --include="*.php" --include="*.js" --include="*.txt" 2>/dev/null | grep -v "\.git" | grep -v "/repo/")
# Sync readme.txt and main PHP into repo/ so SVN trunk always has correct version.
cp "$REPO_DIR/readme.txt" "$REPO_DIR/repo/readme.txt"
sed -i '' "s/^ \* Version:.*/ * Version:     $NEW_VER/" "$REPO_DIR/repo/cloudscale-cleanup.php"
# ─────────────────────────────────────────────────────────────────────────────

# PHP syntax check — abort before packaging if any file has a parse error
echo "Checking PHP syntax..."
LINT_ERRORS=0
while IFS= read -r -d '' phpfile; do
  result=$(php -l "$phpfile" 2>&1)
  if [ $? -ne 0 ]; then
    echo "$result"
    LINT_ERRORS=1
  fi
done < <(find "$REPO_DIR" -name "*.php" -print0)
if [ "$LINT_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP syntax errors found above. Fix before deploying."
  exit 1
fi
echo "PHP syntax: OK"
echo ""
# PHP runtime include test — catches TypeError/fatal that php -l misses.
echo "Checking PHP runtime includes..."
RUNTIME_ERRORS=0
while IFS= read -r -d '' phpfile; do
  basename=$(basename "$phpfile")
  [[ "$basename" == "uninstall.php" ]] && continue
  result=$(php -r "
define('ABSPATH', '/tmp/');
\$code = file_get_contents('$phpfile');
if (strpos(\$code, 'class ') !== false || strpos(\$code, 'function ') !== false) {
    if (strpos(\$code, 'require') === false && strpos(\$code, 'wp_') === false) {
        @include '$phpfile';
    }
}
" 2>&1 | grep -i "TypeError\|ParseError\|Fatal" || true)
  if [ -n "$result" ]; then echo "  RUNTIME ERROR in $phpfile: $result"; RUNTIME_ERRORS=1; fi
done < <(find "$(dirname "$0")/includes" -name "*.php" -print0 2>/dev/null)
find "$(dirname "$0")" -maxdepth 1 -name "*.php" -print0 2>/dev/null | while IFS= read -r -d '' phpfile; do
  basename=$(basename "$phpfile"); [[ "$basename" == "uninstall.php" ]] && continue
  result=$(php -r "@include '$phpfile';" 2>&1 | grep -i "TypeError\|ParseError\|Fatal" || true)
  if [ -n "$result" ]; then echo "  RUNTIME ERROR in $phpfile: $result"; RUNTIME_ERRORS=1; fi
done
if [ "$RUNTIME_ERRORS" -ne 0 ]; then
  echo "ERROR: PHP runtime errors found — crashes on first HTTP request."; exit 1
fi
echo "PHP runtime: OK"
echo ""

# ── Cross-file PHP method existence check ──────────────────────────────────
# Catches ClassName::method() calls where the method is not defined in the
# plugin — passes php -l but causes fatal errors at runtime (e.g. after an
# OPcache serves a stale class that is missing a newly added method).
echo "Checking cross-file method calls..."
XFILE_ERRORS=0
XFILE_PHP=()
while IFS= read -r -d '' f; do
    XFILE_PHP+=("$f")
done < <(find "$REPO_DIR" -name "*.php" \
    ! -path "*/repo/*" ! -path "*/vendor/*" ! -path "*/tests/*" \
    ! -path "*/node_modules/*" -print0 2>/dev/null)
if [[ ${#XFILE_PHP[@]} -gt 0 ]]; then
    PLUGIN_CLASSES=$(grep -hE "^(abstract |final )?class [A-Z_]" \
        "${XFILE_PHP[@]}" 2>/dev/null | \
        sed -E 's/^(abstract |final )?class ([A-Z_][a-zA-Z_0-9]+).*/\2/' | sort -u)
    while IFS= read -r class; do
        [[ -z "$class" ]] && continue
        while IFS= read -r method; do
            [[ -z "$method" ]] && continue
            if ! grep -qh "function ${method}(" "${XFILE_PHP[@]}" 2>/dev/null; then
                echo "  UNDEFINED: ${class}::${method}() — not found in plugin files"
                XFILE_ERRORS=1
            fi
        done < <(grep -h "${class}::" "${XFILE_PHP[@]}" 2>/dev/null \
            | grep -v '^\s*//' | grep -v '^\s*\*' \
            | grep -oh "${class}::[a-zA-Z_][a-zA-Z_0-9]*(" \
            | cut -d: -f3 | tr -d '(' | sort -u)
    done <<< "$PLUGIN_CLASSES"
fi
if [[ "$XFILE_ERRORS" -ne 0 ]]; then
    echo ""
    echo "ERROR: Undefined method calls found — fix before deploying."
    exit 1
fi
echo "Cross-file methods: OK"
echo ""


# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
rsync -a \
  --exclude='.git' --exclude='.gitignore' --exclude='*.zip' \
  --exclude='.DS_Store' --exclude='._*' \
  --exclude='.claude-flow' --exclude='.claude' \
  --exclude='build.sh' --exclude='deploy-wordpress.sh' \
  --exclude='backup-s3.sh' --exclude='purge-cloudflare.sh' \
  --exclude='rollback-wordpress.sh' \
  --exclude='node_modules' --exclude='package.json' --exclude='package-lock.json' \
  --exclude='playwright.config.js' --exclude='tests' --exclude='test-results' --exclude='playwright-report' \
  --exclude='terraclaim' \
  --exclude='docs' \
  --exclude='generate-help-docs.sh' \
  --exclude='build-review.sh' \
  --exclude='setup-playwright-test-account.sh' \
  --exclude='delete-playwright-test-account.sh' \
  --exclude='archive' \
  --exclude='CloudScaleCleanup.jpg' \
  --exclude='repo' \
  "$REPO_DIR/" "$TEMP_DIR/$PLUGIN_NAME/"

# Build zip with correct structure
rm -f "$ZIP_FILE"
cd "$TEMP_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_NAME/"

# Cleanup
rm -rf "$TEMP_DIR"

echo ""
echo "Zip built: $ZIP_FILE"
echo ""
echo "Contents:"
unzip -l "$ZIP_FILE" | head -25
echo ""

# Show version
VERSION=$(grep "^ \* Version:" "$REPO_DIR/cloudscale-cleanup.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
echo "Plugin version: $VERSION"
echo ""
echo "To deploy to S3, run:"
  echo "  bash $SCRIPT_DIR/backup-s3.sh"
echo ""
echo "Then on the server:"
echo "  sudo aws s3 cp s3://andrewninjawordpress/cloudscale-cleanup.zip /tmp/plugin.zip && sudo rm -rf /var/www/html/wp-content/plugins/cloudscale-cleanup && sudo unzip -q /tmp/plugin.zip -d /var/www/html/wp-content/plugins/ && sudo chown -R apache:apache /var/www/html/wp-content/plugins/cloudscale-cleanup && php -r \"if(function_exists('opcache_reset'))opcache_reset();\""
