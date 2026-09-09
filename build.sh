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
# Targeted bump ONLY. The old blanket replace-everywhere sed rewrote EVERY
# occurrence of the previous version — historical @since/@deprecated docblock
# tags and past readme.txt changelog headings included — so release history
# was silently rewritten on every build.
sed -i '' "s/^\( \* Version:[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$MAIN_PHP"
sed -i '' "s/\(define([[:space:]]*'CLOUDSCALE_CLEANUP_VERSION',[[:space:]]*'\)${ESC_VER}'/\1${NEW_VER}'/" "$REPO_DIR/cloudscale-cleanup.php"
sed -i '' "s/^\(Stable tag:[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$REPO_DIR/readme.txt"
# Promote ONLY the topmost changelog heading written for the pre-bump version;
# headings for past releases are never dragged forward.
# A new changelog entry is written as "= Unreleased =" and this stamps it with the
# version the build produces. No other heading is ever relabelled.
#
# This used to promote the topmost heading matching the PRE-bump version, assuming
# such a heading could only be a freshly written entry. It cannot tell that apart
# from the previous release's own heading, which is legitimately labelled with that
# version, so every build that added no entry dragged the last release's heading
# forward by one. In the SEO plugin a narration entry travelled 4.21.459 -> .460 ->
# .461 -> .462 that way, and the published changelog credited the current release
# with a change that had shipped three releases earlier.
if grep -q '^= Unreleased =$' "$REPO_DIR/readme.txt"; then
  sed -i '' "1,/^= Unreleased =\$/ s/^= Unreleased =\$/= ${NEW_VER} =/" "$REPO_DIR/readme.txt"
  echo "  readme.txt changelog: promoted '= Unreleased =' to '= ${NEW_VER} ='"
else
  echo "  readme.txt changelog: no '= Unreleased =' entry, headings left untouched"
fi
# JS @version headers.
while IFS= read -r vfile; do
  sed -i '' "s/\(@version[[:space:]]*\)${ESC_VER}\$/\1${NEW_VER}/" "$vfile"
done < <(grep -rl "@version[[:space:]]*$CURRENT_VER" "$REPO_DIR" --include="*.js" 2>/dev/null | grep -v "\.git" | grep -v "/repo/")
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

# --- WordPress plugin standards review (opt-in) --------------------------------
# Off by default for speed; `bash build-review.sh` sets SKIP_REVIEW=0 to enable it.
#
# This gate was removed in c0af24b and its wrapper left behind, so build-review.sh
# silently ran an ordinary build for months. The version restored here differs from
# the original in the ways that made the original worthless:
#   - the old code printed "ERROR: ... CRITICAL or HIGH issues" and then carried on
#     without exit 1, and printed "Standards review: OK" unconditionally afterwards;
#   - its file lists were hardcoded and had gone stale, so it reviewed files that no
#     longer existed and never saw the ones that replaced them.
# Every failure path below exits non-zero, buckets are derived from the shipped tree,
# and a section returning no BUILD_STATUS is fatal rather than ignored: a review that
# did not run must never read as a pass.
SKIP_REVIEW="${SKIP_REVIEW:-1}"
if [ "$SKIP_REVIEW" != "1" ]; then
  # Self-contained: the other four build.sh do not define CLAUDE themselves.
  CLAUDE="${CLAUDE:-${CLAUDE_CLI:-claude}}"
  echo "Running WordPress plugin standards review..."

  if ! command -v "$CLAUDE" >/dev/null 2>&1 && [ ! -x "$CLAUDE" ]; then
    echo "ERROR: standards review is enabled but the claude CLI ('$CLAUDE') was not found."
    echo "       Set CLAUDE_CLI to its path, or build without SKIP_REVIEW=0."
    exit 1
  fi
  if [ -z "${CLAUDE_REVIEW_MODEL:-}" ]; then
    echo "ERROR: CLAUDE_REVIEW_MODEL is unset — check .claude-config.sh."
    exit 1
  fi

  REVIEW_TIMEOUT=""
  if command -v timeout >/dev/null 2>&1; then REVIEW_TIMEOUT="timeout 900"
  elif command -v gtimeout >/dev/null 2>&1; then REVIEW_TIMEOUT="gtimeout 900"; fi

  REVIEW_TMPDIR=$(mktemp -d)
  REVIEW_SECTIONS="${REVIEW_SECTIONS:-6}"

  REVIEW_RULES='BLOCKING RULES — only these trigger BUILD_STATUS: FAIL:
1. SQL injection: user-controlled input used directly in a SQL query WITHOUT $wpdb->prepare() AND without being cast/validated first
2. XSS: user-controlled data echoed into HTML WITHOUT esc_html/esc_attr/esc_url/wp_kses
3. CSRF: an AJAX/form handler that modifies data WITHOUT check_ajax_referer or wp_verify_nonce
4. Missing ABSPATH guard at the top of a PHP file

NON-BLOCKING (never trigger FAIL, report as informational only):
- SQL with a phpcs:ignore annotation — already acknowledged, skip entirely
- Table names via $wpdb->prefix, $wpdb->posts, $wpdb->postmeta — always safe
- Unicode, em dashes or emoji used as display/placeholder values
- wp_unslash() + esc_url_raw() on $_SERVER — the correct WP pattern
- $wpdb->get_results( $wpdb->prepare(...) ) — the correct WP pattern, not redundant
- implode of integer-cast IDs for IN clauses
- Missing @since or DocBlock tags — documentation only

End your response with EXACTLY one line reading BUILD_STATUS: PASS or BUILD_STATUS: FAIL'

  # Buckets come from the shipped tree. A hardcoded list is what went stale last time.
  REVIEW_FILE_COUNT=0
  while IFS= read -r rel; do
    [ -n "$rel" ] || continue
    idx=$(( REVIEW_FILE_COUNT % REVIEW_SECTIONS ))
    eval "REVIEW_BUCKET_${idx}=\"\${REVIEW_BUCKET_${idx}:-} \$rel\""
    REVIEW_FILE_COUNT=$(( REVIEW_FILE_COUNT + 1 ))
  done < <(
    find "$REPO_DIR" -name '*.php' \
      -not -path '*/.*/*' -not -path '*/repo/*' -not -path '*/tests/*' \
      -not -path '*/archive/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' -print0 2>/dev/null |
    while IFS= read -r -d '' f; do
      printf '%s\t%s\n' "$(wc -c < "$f" | tr -d ' ')" "${f#$REPO_DIR/}"
    done | sort -rn | cut -f2
  )

  if [ "$REVIEW_FILE_COUNT" -eq 0 ]; then
    echo "ERROR: standards review found no PHP files to review under $REPO_DIR."
    rm -rf "$REVIEW_TMPDIR"; exit 1
  fi

  _review_section() {
    local label="$1"; shift
    local files="$*"
    local rc=0
    ( cd "$REPO_DIR" && $REVIEW_TIMEOUT "$CLAUDE" --dangerously-skip-permissions \
        --model "$CLAUDE_REVIEW_MODEL" --print -p \
        "/wp-plugin-standards-review Review ONLY these files (read no others): ${files}, readme.txt.

${REVIEW_RULES}" ) > "$REVIEW_TMPDIR/$label.out" 2>&1 || rc=$?
    echo "$rc" > "$REVIEW_TMPDIR/$label.rc"
  }

  REVIEW_RAN=0
  i=0
  while [ "$i" -lt "$REVIEW_SECTIONS" ]; do
    eval "bucket=\"\${REVIEW_BUCKET_${i}:-}\""
    if [ -n "$bucket" ]; then
      _review_section "s$i" $bucket &
      REVIEW_RAN=$(( REVIEW_RAN + 1 ))
    fi
    i=$(( i + 1 ))
  done
  wait

  echo "  $REVIEW_RAN section(s) over $REVIEW_FILE_COUNT PHP file(s), model $CLAUDE_REVIEW_MODEL."

  # The review body is informational and is always shown, as it was before the gate
  # was removed: the blocking rules are a floor, and the MEDIUM/LOW notes below them
  # are the part a human still has to read before a WordPress.org submission.
  i=0
  while [ "$i" -lt "$REVIEW_SECTIONS" ]; do
    [ -f "$REVIEW_TMPDIR/s$i.out" ] && { echo "--- Section s$i ---"; cat "$REVIEW_TMPDIR/s$i.out"; echo ""; }
    i=$(( i + 1 ))
  done

  REVIEW_ALL=$(cat "$REVIEW_TMPDIR"/*.out 2>/dev/null || true)

  REVIEW_FAILED=0
  i=0
  while [ "$i" -lt "$REVIEW_SECTIONS" ]; do
    out="$REVIEW_TMPDIR/s$i.out"
    if [ -f "$out" ]; then
      rc=$(cat "$REVIEW_TMPDIR/s$i.rc" 2>/dev/null || echo 1)
      body=$(cat "$out")
      statuses=$(printf '%s\n' "$body" | grep -c 'BUILD_STATUS: \(PASS\|FAIL\)' || true)

      if [ "$rc" -ne 0 ]; then
        echo "ERROR: review section s$i exited $rc (timeout or CLI failure)."
        printf '%s\n' "$body" | tail -15
        REVIEW_FAILED=1
      elif printf '%s\n' "$body" | grep -qiE 'API Error|invalid.*model|model.*invalid'; then
        echo "ERROR: review section s$i hit a model/API error — the review did not run."
        printf '%s\n' "$body" | tail -15
        REVIEW_FAILED=1
      elif [ "$statuses" -eq 0 ]; then
        echo "ERROR: review section s$i returned no BUILD_STATUS — output incomplete."
        printf '%s\n' "$body" | tail -15
        REVIEW_FAILED=1
      elif printf '%s\n' "$body" | grep -q 'BUILD_STATUS: FAIL'; then
        echo "ERROR: review section s$i reported BUILD_STATUS: FAIL."
        printf '%s\n' "$body"
        REVIEW_FAILED=1
      fi
    fi
    i=$(( i + 1 ))
  done

  rm -rf "$REVIEW_TMPDIR"
  if [ "$REVIEW_FAILED" -ne 0 ]; then
    echo ""
    echo "Standards review: FAILED — fix the issues above before building."
    exit 1
  fi
  if printf '%s\n' "$REVIEW_ALL" | grep -qiE '[1-9][0-9]* medium'; then
    echo "WARNING: standards review noted MEDIUM issues — read them before submitting to WordPress.org."
  fi
  echo "  standards review: OK ($REVIEW_RAN section(s), $REVIEW_FILE_COUNT file(s), 0 blocking issue(s))"
  echo ""
fi

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

# ── Static call visibility ───────────────────────────────────────────────────
# The cross-file check above answers "does a method with this name exist?" by grepping
# for `function name(`, which a PRIVATE method satisfies perfectly. So
# OtherClass::private_helper() passes it, passes php -l, passes PHPCS, and fatals the
# first time that line runs. Surfaced on 2026-08-09 by
#   "Uncaught Error: Call to private method CSDT_Auto_Block::admin_session_ips()"
# which came from an operator's `wp eval-file` rather than shipped code — so nothing
# was broken, and nothing would have caught it if it had been.
_VIS_CHECK="$GITHUB_DIR/shared-build-tools/check-static-call-visibility.php"
echo "Checking static call visibility..."
if [ ! -f "$_VIS_CHECK" ]; then
    echo "ERROR: static call visibility checker not found at $_VIS_CHECK"
    exit 1
fi
if ! php "$_VIS_CHECK" "$REPO_DIR"; then
    echo "ERROR: a ClassName::method() call cannot reach its target (details above)."
    exit 1
fi
echo ""
echo ""

# ── WP bootstrap safety check ────────────────────────────────────────────────
# Catches calls to functions that require a bootstrapped WordPress environment
# (user auth, DB, etc.) made at global scope in the main plugin PHP file.
# These pass php -l but silently misbehave or fatal-crash on real page loads —
# e.g. current_user_can() called before wp_set_current_user() always returns false,
# and in some WP versions can trigger a PHP fatal that causes a 503.
# ── admin.js must speak in one voice ────────────────────────────────────────
# The plugin has cscShowModal()/cscConfirmModal(), and 15 native alert()/confirm() calls were left
# behind anyway — so modal-smoke.spec.js failed on a premise that was true of most of the file.
# This also guards the conversion hazard: confirm() is synchronous, a modal is not, so a handler
# that reads $(this) inside the callback gets the modal's own button and posts an undefined id —
# deleting nothing while reporting success. Five of the six confirms guard permanent deletions.
echo "Checking admin.js uses styled modals, not native dialogs..."
if ! php "$SCRIPT_DIR/tests/no-native-dialogs-test.php"; then
    echo "ERROR: a native dialog returned, or a confirm callback lost its subject — build blocked."
    exit 1
fi
echo ""

echo "Checking WP bootstrap safety..."
BOOTSTRAP_ERRORS=0
_BOOTSTRAP_FORBIDDEN='current_user_can,is_user_logged_in,wp_get_current_user,get_current_user_id,check_admin_referer,check_ajax_referer,is_multisite,switch_to_blog,restore_current_blog'
while IFS= read -r -d '' phpfile; do
    _result=$(php -r "
\$file = '$phpfile';
\$tokens = token_get_all(file_get_contents(\$file));
// Scan only the leading global-scope preamble — tokens before the first
// top-level class/function/interface/trait declaration. Everything after that
// is inside a declaration body and is safe to call WP bootstrap functions.
\$forbidden = explode(',', '$_BOOTSTRAP_FORBIDDEN');
\$errors    = [];
foreach (\$tokens as \$tok) {
    if (!is_array(\$tok)) continue;
    \$type = \$tok[0];
    // Stop scanning at the first top-level declaration.
    if (in_array(\$type, [T_CLASS, T_FUNCTION, T_INTERFACE, T_TRAIT])) break;
    if (\$type === T_STRING && in_array(\$tok[1], \$forbidden)) {
        \$errors[] = 'BOOTSTRAP: ' . basename(\$file) . ':' . \$tok[2]
            . ': ' . \$tok[1] . '() in plugin preamble — requires bootstrapped WP, '
            . 'use a hook (add_action) or check WP_ADMIN/REST_REQUEST/\$_COOKIE instead';
    }
}
foreach (\$errors as \$e) echo \$e . PHP_EOL;
exit(empty(\$errors) ? 0 : 1);
" 2>&1 || true)
    if [ -n "$_result" ]; then
        echo "$_result"
        BOOTSTRAP_ERRORS=1
    fi
done < <(find "$REPO_DIR" -maxdepth 1 -name "*.php" -print0 2>/dev/null)
if [ "$BOOTSTRAP_ERRORS" -ne 0 ]; then
    echo ""
    echo "ERROR: WP bootstrap safety violations — fix before building."
    exit 1
fi
echo "WP bootstrap safety: OK"
echo ""

# ── PHPCS WordPress standards check ──────────────────────────────────────────
_PHPCS=""
for _candidate in \
    "$REPO_DIR/vendor/bin/phpcs" \
    "$HOME/.config/composer/vendor/bin/phpcs" \
    "$HOME/.composer/vendor/bin/phpcs" \
    "$(command -v phpcs 2>/dev/null || true)"; do
    [ -x "$_candidate" ] && { _PHPCS="$_candidate"; break; }
done

if [ -z "$_PHPCS" ]; then
    echo "phpcs not found — attempting auto-install..."
    if ! command -v composer &>/dev/null && command -v brew &>/dev/null; then
        brew install --quiet composer && hash -r
    fi
    if command -v composer &>/dev/null; then
        composer global require --quiet \
            squizlabs/php_codesniffer \
            wp-coding-standards/wpcs \
            dealerdirect/phpcodesniffer-composer-installer 2>&1 | tail -3
        _PHPCS="$(composer global config home 2>/dev/null)/vendor/bin/phpcs"
    fi
fi

if [ -z "$_PHPCS" ] || [ ! -x "$_PHPCS" ]; then
    echo "ERROR: phpcs not found and could not be installed automatically."
    echo "  Install: composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer"
    exit 1
fi

# ── readme.txt section limits ────────────────────────────────────────────────
# WordPress.org TRUNCATES an over-long readme section instead of rejecting it, so
# the plugin page silently loses content and nothing in the build complains. This
# runs after the version bump above, because that rewrites readme.txt.
#
# Two earlier hand-rolled versions of this check passed while the section was in
# fact being truncated, because they encoded the rule wrongly: the limit is counted
# in WORDS (Plugin Check's "2500 characters" message is misleading), and every
# section wordpress.org does not recognise -- "External services", "Credits" -- is
# folded into other_notes and then added onto DESCRIPTION before trimming. The
# shared script is the single source of truth; do not re-implement the rule here.
# ── The emergency brake must not make the emergency worse ────────────────────
# deploy-wordpress.sh and rollback-wordpress.sh are gitignored, so they cannot be
# fixed centrally — which is exactly why this gate is here instead. It is tracked,
# so a checkout still carrying the old scripts fails the build with the reason.
#
# All five plugins took their pre-deploy backup with
# `docker cp SRC /tmp/<plugin>-rollback 2>/dev/null || true`. docker cp copies INTO
# an existing directory, so every deploy after the first nested the real backup one
# level deeper and left the first backup ever taken at the top — the path rollback
# restores. Found 2026-08-14: the cyber-devtools Pi had 1.10.418 from 2026-08-06 at
# the top with 1.10.601 nested inside, so `bash rollback-wordpress.sh` would have
# gone back 184 patch versions and printed success, because it grepped the version
# only after restoring it.
# ── The documented artefact archive must actually be written ─────────────────
# CLAUDE.md promised a date-stamped copy of every deployed zip in archive/ and no deploy
# script wrote one, so the documented recovery path did not exist. That matters more than
# a missing convenience because build.sh rsyncs the WORKING TREE: a deploy can ship code
# that is in no commit, and on 2026-08-14 and 2026-08-17 it did. Git does not always hold
# what production runs; the archived zip does. deploy-wordpress.sh is gitignored, so this
# tracked gate is the only way the promise survives a fresh checkout.
_ARCHIVE_CHECK="$GITHUB_DIR/shared-build-tools/check-deploy-archive.php"
echo "Checking the deploy archives what it ships..."
if [ ! -f "$_ARCHIVE_CHECK" ]; then
    echo "ERROR: deploy archive checker not found at $_ARCHIVE_CHECK"
    exit 1
fi
if ! php "$_ARCHIVE_CHECK" "$SCRIPT_DIR"; then
    echo ""
    echo "ERROR: deploy archive check failed — build blocked."
    echo "  Your local deploy-wordpress.sh does not keep a copy of what it deploys."
    exit 1
fi
echo ""

_ROLLBACK_CHECK="$GITHUB_DIR/shared-build-tools/check-rollback-safety.php"
echo "Checking deploy/rollback cannot silently restore the wrong version..."
if [ ! -f "$_ROLLBACK_CHECK" ]; then
    echo "ERROR: rollback safety checker not found at $_ROLLBACK_CHECK"
    exit 1
fi
if ! php "$_ROLLBACK_CHECK" "$SCRIPT_DIR"; then
    echo ""
    echo "ERROR: deploy/rollback safety check failed — build blocked."
    echo "  Your local deploy-wordpress.sh / rollback-wordpress.sh predate the"
    echo "  2026-08-14 fix. Details above say which guard is missing."
    exit 1
fi
echo ""

_README_CHECK="$GITHUB_DIR/shared-build-tools/check-readme-limits.php"
echo "Checking readme.txt section limits..."
if [ ! -f "$_README_CHECK" ]; then
    # Fail loudly: a silently-missing checker would recreate the exact hole this closes.
    echo "ERROR: readme limit checker not found at $_README_CHECK"
    exit 1
fi
if ! php "$_README_CHECK" "$REPO_DIR/readme.txt"; then
    echo "ERROR: readme.txt would be truncated on WordPress.org (details above)."
    exit 1
fi
echo "readme.txt section limits: OK"
echo ""

# ── WordPress.org submission gates ──────────────────────────────────────────
# The six classes round R (18Aug26) rejected cyber-devtools on, checked against the
# STAGED tree. This ran in NO plugin but cyber-devtools until 2026-08-18, which is how
# this plugin's own set_time_limit()/prefix problems went unmeasured. The checker is
# marker-immune on purpose: seven submissions were refused while csdt-submission-ok
# markers sat on the offending lines and the reviewer never saw one.
_WPORG_VALIDATE="$GITHUB_DIR/shared-build-tools/validate-wp-submission.sh"
echo "Running WordPress.org submission gates..."
if [ ! -f "$_WPORG_VALIDATE" ]; then
    # Fail loudly: a silently-missing validator would recreate the exact hole this closes.
    echo "ERROR: submission validator not found at $_WPORG_VALIDATE"
    exit 1
fi
if ! bash "$_WPORG_VALIDATE" "$REPO_DIR" --offline; then
    echo ""
    echo "ERROR: WordPress.org submission gates failed — build blocked."
    echo "  Fix the findings above, or record a deliberate exemption with a written"
    echo "  reason in .wporg-prefixes.json. There is no inline marker that silences them."
    exit 1
fi
echo ""

# ── The emoji resource hint is removed, and nothing else is ─────────────────
# All five plugins strip core's emoji-CDN dns-prefetch hint with the same wp_resource_hints
# filter, and that filter has now been wrong in BOTH directions. array_diff() against the
# single literal 'https://s.w.org' removed nothing once core used the '//s.w.org' form it has
# also shipped. Replacing it with strpos() then over-matched: 'ps.w.org' contains 's.w.org',
# and ps.w.org is a real WordPress host. Neither mistake produces an error anywhere — it
# changes one <link> in <head> — so both were found by reading, not by anything reporting.
# The checker executes each plugin's own closure rather than restating the rule.
_EMOJI_HINT_CHECK="$GITHUB_DIR/shared-build-tools/check-emoji-hint-filter.php"
if [ ! -f "$_EMOJI_HINT_CHECK" ]; then
    echo "ERROR: emoji-hint checker not found at $_EMOJI_HINT_CHECK"
    exit 1
fi
if ! php "$_EMOJI_HINT_CHECK" "wordpress-database-cleanup-plugin"; then
    echo ""
    echo "ERROR: the emoji resource-hint filter is wrong — build blocked."
    echo "  Either the hint is no longer removed, or the filter is stripping hints it should not."
    exit 1
fi
echo ""

# ── Shared class copies must match their canonical source ────────────────────
# CloudScale_Telegram and the model-name map are shared by all five plugins and guarded by
# class_exists(), so exactly ONE copy loads at runtime. On the live install that copy belongs
# to cloudscale-backup — so a change to any other plugin's copy is invisible at runtime and
# would deploy as a feature that quietly does nothing. Edit shared-admin-ui/<file> and run
# shared-admin-ui/sync-admin-css.sh; never edit a plugin's copy directly.
_SHARED_COPIES_CHECK="$GITHUB_DIR/shared-build-tools/check-shared-copies.php"
if [ ! -f "$_SHARED_COPIES_CHECK" ]; then
    echo "ERROR: shared-copy checker not found at $_SHARED_COPIES_CHECK"
    exit 1
fi
if ! php "$_SHARED_COPIES_CHECK" "$GITHUB_DIR"; then
    echo ""
    echo "ERROR: a shared class copy has drifted from shared-admin-ui/ — build blocked."
    exit 1
fi
echo ""

# ── Chunk-size option survived its prefix rename ────────────────────────────
# 'cspj_chunk_mb' was a third prefix alongside this plugin's own cscc_, which WordPress.org
# rejects, so the live key is now 'cscc_chunk_mb'. It holds a user setting, so reads fall back
# to the old name and nothing writes or deletes it before uninstall. Breaking that chain does
# not throw — the user's chunk size silently reverts to the default — so it is asserted here,
# negative cases included. Verified to fail when the fallback is removed.
_CSCC_OPT_TEST="$GITHUB_DIR/shared-build-tools/test-cleanup-option-migration.php"
if [ ! -f "$_CSCC_OPT_TEST" ]; then
    echo "ERROR: chunk-size option migration test not found at $_CSCC_OPT_TEST"
    exit 1
fi
if ! php "$_CSCC_OPT_TEST"; then
    echo ""
    echo "ERROR: the chunk-size option migration is broken — a saved setting would be lost."
    exit 1
fi
echo ""

# ── Image chunks stay resumable and safe to be killed ───────────────────────
# The three image handlers used to raise the request time limit, which WordPress.org rejects.
# Removing it was only safe because each chunk is now bounded by the host's own clock AND the
# commit order was fixed so a killed request cannot land destructively — the recycle manifest is
# flushed before any row is deleted, and the optimiser renames over the original rather than
# deleting it first. Neither property throws when it regresses; it loses somebody's images on the
# one request that happens to be killed. Verified to fail on each injected regression.
_CSCC_CHUNK_TEST="$GITHUB_DIR/shared-build-tools/test-cleanup-resumable-chunks.php"
if [ ! -f "$_CSCC_CHUNK_TEST" ]; then
    echo "ERROR: resumable-chunk test not found at $_CSCC_CHUNK_TEST"
    exit 1
fi
if ! php "$_CSCC_CHUNK_TEST"; then
    echo ""
    echo "ERROR: an image chunk is no longer resumable or crash-ordered — build blocked."
    exit 1
fi
echo ""

# ── Error text in the units the timeouts are set in ─────────────────────────
# Every timeout in this plugin is SECONDS, and WordPress reports one in milliseconds because
# that is curl's wording: "cURL error 28: Operation timed out after 20000 milliseconds". The
# site owner reading that cannot match 20000 to anything in the code, and the proxy had the
# same fault on its own breaker alerts (240000 for a 240s ceiling, 2026-08-17). All 115 call
# sites across the five plugins were converted in one pass through
# CloudScale_Error_Text::in_seconds(); this gate exists for the 116th, which will be written
# by copying one of the other 115.
_ERRTXT_CHECK="$GITHUB_DIR/shared-build-tools/check-error-text-units.php"
if [ ! -f "$_ERRTXT_CHECK" ]; then
    echo "ERROR: error-text checker not found at $_ERRTXT_CHECK"
    exit 1
fi
if ! php "$_ERRTXT_CHECK" "$SCRIPT_DIR"; then
    echo ""
    echo "ERROR: a WP_Error message is rendered in milliseconds — build blocked."
    exit 1
fi
echo ""


# ── Telegram alerts carry local time ────────────────────────────────────────
# Alerts arrive on a phone, at night, read by someone in the site's own timezone —
# and they quoted UTC ("Last heartbeat: 2026-08-05 02:30:01 UTC" during a real
# outage), so the reader had to do arithmetic before judging how old a failure was.
# Stamped centrally in CloudScale_Telegram::send() so a new alert cannot ship
# without one; asserted against THIS plugin's synced copy so drift fails here.
_TG_TIME_CHECK="$GITHUB_DIR/shared-build-tools/check-telegram-local-time.php"
echo "Checking Telegram alerts carry local time..."
if [ ! -f "$_TG_TIME_CHECK" ]; then
    echo "ERROR: telegram local-time checker not found at $_TG_TIME_CHECK"
    exit 1
fi
if ! php "$_TG_TIME_CHECK" "$REPO_DIR"; then
    echo "ERROR: Telegram alerts would go out without local time (details above)."
    exit 1
fi
echo ""

# ── No alert path can flood the phone ───────────────────────────────────────
# Every throttle in these plugins was a transient with a 6-hour expiry, on an install with a
# persistent Redis object cache — so `wp cache flush`, which every deploy runs, deleted the quiet
# window and the next failure reported an ongoing incident as new. The ceiling now lives in
# CloudScale_Telegram::send() (an option, not a transient), ahead of every call site including the
# ones that never had a throttle. Asserted both ways: repeats and storms are quiet, and a different
# alert, a later hour and a held-message count all still arrive.
_TG_RATE_CHECK="$GITHUB_DIR/shared-build-tools/check-alert-rate-limit.php"
echo "Checking no alert path can flood the owner's phone..."
if [ ! -f "$_TG_RATE_CHECK" ]; then
    echo "ERROR: alert rate-limit checker not found at $_TG_RATE_CHECK"
    exit 1
fi
if ! php "$_TG_RATE_CHECK" "$REPO_DIR"; then
    echo "ERROR: alerts could be sent unthrottled (details above)."
    exit 1
fi
echo ""

echo "Running PHPCS (WordPress standard)..."
# memory_limit: PHP's 128M default is not enough to tokenise the whole tree — the
# main plugin file alone is several hundred KB and the run dies partway through it.
# Measured need across these plugins is ~256M today; 1024M leaves headroom to grow.
set +e
PHPCS_OUT=$("$_PHPCS" \
    -d memory_limit=1024M \
    --standard="$REPO_DIR/phpcs.xml" \
    --severity=5 \
    --extensions=php \
    -s \
    "$REPO_DIR" 2>&1)
_PHPCS_RC=$?
set -e
echo "$PHPCS_OUT"
echo ""

# A PHPCS run that DIED looks identical to a clean one further down: it emits a
# stack trace with no "| ERROR " lines, and the old `|| true` threw the exit code
# away — so the build announced "0 errors, 0 warnings" having checked only part of
# the tree. That is exactly how a real WPCS error survived every local build and
# was first reported by WordPress.org's Plugin Check.
# Exit codes: 0 = clean, 1/2 = issues found, 3 = processing error, 255 = PHP fatal.
if [ "$_PHPCS_RC" -gt 2 ]; then
    echo "ERROR: PHPCS did not complete (exit ${_PHPCS_RC}). Its output is truncated, NOT clean."
    if echo "$PHPCS_OUT" | grep -qi "out of memory"; then
        echo "  Cause: PHPCS ran out of memory — raise -d memory_limit above in this script."
    fi
    exit 1
fi

# Count violations. grep|wc pipeline always exits 0 — safe under set -e.
_PHPCS_ERRS=$(echo "$PHPCS_OUT" | grep -F "| ERROR " | wc -l | tr -d '[:space:]')
_PHPCS_WARNS=$(echo "$PHPCS_OUT" | grep -F "| WARNING " | wc -l | tr -d '[:space:]')

# Block on any ERROR — WordPress.org reviewers reject plugins with any PHPCS error.
# Rules already suppressed in phpcs.xml will not appear here.
if [ "${_PHPCS_ERRS:-0}" -gt 0 ]; then
    echo "ERROR: PHPCS found ${_PHPCS_ERRS} error(s) — fix before building (WP.org rejects all errors)."
    exit 1
fi

# Block on development/discouraged-function warnings — WP.org explicitly rejects
# var_dump(), error_log(), eval(), base64_decode() etc. even when flagged as warnings.
if echo "$PHPCS_OUT" | grep -qE "WordPress\.PHP\.(DevelopmentFunctions|DiscouragedPHPFunctions)"; then
    echo "ERROR: Development or discouraged PHP functions flagged — remove before WordPress.org submission."
    exit 1
fi

# Non-blocking warning summary — must be zero before WordPress.org submission.
if [ "${_PHPCS_WARNS:-0}" -gt 0 ]; then
    echo "PHPCS: OK — 0 errors, ${_PHPCS_WARNS} warning(s) (must be clean before WP.org submission)"
    echo "  Warning breakdown:"
    echo "$PHPCS_OUT" | grep -oE '\([A-Za-z]+\.[A-Za-z.]+\)' | tr -d '()' | sort | uniq -c | sort -rn | head -8 | sed 's/^/    /'
else
    echo "PHPCS: OK — 0 errors, 0 warnings"
fi
echo ""

# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
# EXCLUDE BY PATTERN, NOT BY NAME. This list used to enumerate each shell script and each
# dotfile individually, so every file added afterwards shipped by default — and two did:
# `.env.test`, whose own first line says "Never commit this file" and which holds the
# CloudScale test-account credentials, and `run-ui-tests.sh`. Both were inside the zip built
# for WordPress.org (found 2026-08-18). A name-by-name list cannot fail safe; a pattern can.
# The individual entries below are kept because they are not covered by a pattern.
rsync -a \
  --exclude='.*' \
  --exclude='*.sh' \
  --exclude='*.zip' \
  --exclude='._*' \
  --exclude='node_modules' --exclude='package.json' --exclude='package-lock.json' \
  --exclude='playwright.config.js' --exclude='tests' --exclude='test-results' --exclude='playwright-report' \
  --exclude='phpcs.xml' \
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

# ── Deterministic WordPress.org file-write standards guard ───────────────────
# Scans the STAGED plugin (exactly what ships) for disallowed file writes:
# executable code (.php/.sh) deployed at runtime, writes to the plugin dir,
# OS/system paths, or the /wp-content root. See standards-grep-guard.sh.
STD_GUARD="$GITHUB_DIR/standards-grep-guard.sh"
[ -f "$STD_GUARD" ] || STD_GUARD="$(dirname "$GITHUB_DIR")/standards-grep-guard.sh"
if [ -f "$STD_GUARD" ]; then
  bash "$STD_GUARD" "$TEMP_DIR/$PLUGIN_NAME" || { rm -rf "$TEMP_DIR"; exit 1; }
else
  echo "WARNING: standards-grep-guard.sh not found — file-write guard skipped."
fi

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
