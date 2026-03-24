#!/bin/bash
# Build cloudscale-cleanup.zip from the repo directory
# Creates a zip with cloudscale-cleanup/ as the top level folder
# which is the structure WordPress expects for plugin upload
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

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
ESC_VER=$(echo "$CURRENT_VER" | sed 's/\./\./g')
echo "Version bump: $CURRENT_VER → $NEW_VER"
while IFS= read -r vfile; do
  sed -i '' "s/$ESC_VER/$NEW_VER/g" "$vfile"
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
  "$REPO_DIR/" "$TEMP_DIR/$PLUGIN_NAME/"

# Create versioned copies of admin.js and admin.css for cache busting
VERSION=$(grep "^ \* Version:" "$REPO_DIR/cloudscale-cleanup.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
VER_SLUG=$(echo "$VERSION" | tr '.' '-')
cp "$TEMP_DIR/$PLUGIN_NAME/admin.js" "$TEMP_DIR/$PLUGIN_NAME/admin-${VER_SLUG}.js"
cp "$TEMP_DIR/$PLUGIN_NAME/admin.css" "$TEMP_DIR/$PLUGIN_NAME/admin-${VER_SLUG}.css"
echo "Created versioned assets: admin-${VER_SLUG}.js, admin-${VER_SLUG}.css"

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
