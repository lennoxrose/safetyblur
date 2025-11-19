#!/bin/bash

# -------------------------
# Centering functions
# -------------------------
center() {
    local term_width text_width spaces
    term_width=$(tput cols)
    text_width=${#1}
    spaces=$(( (term_width - text_width) / 2 ))
    # Avoid negative width which would break printf on very small terminals
    if (( spaces < 0 )); then
        spaces=0
    fi
    printf "%*s%s\n" "$spaces" "" "$1"
}

center_block() {
    while IFS= read -r line; do
        center "$line"
    done <<< "$1"
}

# -------------------------
# Banner
# -------------------------
banner() {
    center_block "$(cat << 'EOF'
 ▄█████  ▄▄▄  ▄▄▄▄▄ ▄▄▄▄▄ ▄▄▄▄▄▄ ▄▄ ▄▄ 
 ▀▀▀▄▄▄ ██▀██ ██▄▄  ██▄▄    ██   ▀███▀ 
█████▀ ██▀██ ██    ██▄▄▄   ██     █ 
EOF
)"
    echo ""
}

# -------------------------
# Centered Progress Bar
# -------------------------
progress_bar() {
    local total=20
    local bar=""
    local term_width line line_width spaces percent
    term_width=$(tput cols)

    # Precompute the final line when the bar is full (100%).
    # Centering will be based on this width so the bar expands to the right
    # from a fixed starting column instead of shifting each frame.
    local final_bar=""
    for ((j=0; j<total; j++)); do
        final_bar+="█"
    done
    local final_line
    final_line="$(printf '%-20s %3d%%' "$final_bar" 100)"
    local final_line_width=${#final_line}
    spaces=$(( (term_width - final_line_width) / 2 ))
    if (( spaces < 0 )); then
        spaces=0
    fi

    for ((i=1; i<=total; i++)); do
        bar="${bar}█"
        percent=$((i * 100 / total))

        # Print left padding to starting column
        printf "\r%*s" "$spaces" ""

        # Print the current bar
        printf "%s" "$bar"

        # Compute padding so the percent field is printed at the final location
        # final_line_width includes the percent field (4 chars due to '%3d%%')
        local bar_len=${#bar}
        local pad=$(( final_line_width - 4 - bar_len ))
        if (( pad < 1 )); then
            pad=1
        fi
        printf "%*s" "$pad" ""

        # Print the percent at the fixed location, then clear to end of line
        printf "%3d%%\033[K" "$percent"

        sleep 0.08
    done
    echo ""
}

# -------------------------
# Error Logging
# -------------------------
log_error() {
    local output="$1"
    local timestamp logfile
    timestamp=$(date +"%Y-%m-%d_%H-%M-%S")
    logfile="error-${timestamp}.txt"
    echo ""
    center "[ x ] An error occurred. Output saved to: $logfile"
    echo "$output" > "$logfile"
}

# -------------------------
# CLI flags
# -------------------------
# Support a dry-run mode so users can preview actions without making changes.
DRY_RUN=0
if [[ "$1" == "--dry-run" || "$1" == "-n" ]]; then
    DRY_RUN=1
    shift
fi

# -------------------------
# Helpers
# -------------------------
# Run a command or print it when in dry-run. Returns command exit code (0 for dry-run).
do_cmd() {
    if [[ $DRY_RUN -eq 1 ]]; then
        # center each line for nicer output in the terminal UI
        center "[ dry-run ] $*"
        echo "> $*"
        return 0
    else
        eval "$@"
        return $?
    fi
}

# -------------------------
# Menu
# -------------------------
clear
banner
center "Select Blueprint export version"
echo ""
center "1) Export v1.0 (beta-2025-09)"
center "2) Export v1.1 (beta-2025-10)"
center "3) Export v0.9 (beta-2024-12)"
echo ""
# Print a centered prompt line, then read from a simple prompt to avoid mixed output from center() in a command substitution
center "Enter choice:"
read -r -p "> " choice

case "$choice" in
    1)
        VERSION="1.0"
        # Use the script directory to build reliable paths (avoid duplicating /var/www/pterodactyl/$PWD)
        SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        CONF_FROM="$SCRIPT_DIR/1.0-conf.yml"
        CONF_TO="$SCRIPT_DIR/conf.yml"
        BLUEPRINT_PATH="$SCRIPT_DIR/release/beta-2025-09"
        ;;
    2)
        VERSION="1.1"
        SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        CONF_FROM="$SCRIPT_DIR/1.1-conf.yml"
        CONF_TO="$SCRIPT_DIR/conf.yml"
        BLUEPRINT_PATH="$SCRIPT_DIR/release/beta-2025-10"
        ;;
    3)
        VERSION="0.9"
        SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
        CONF_FROM="$SCRIPT_DIR/0.9-conf.yml"
        CONF_TO="$SCRIPT_DIR/conf.yml"
        BLUEPRINT_PATH="$SCRIPT_DIR/release/beta-2024-12"
        ;;
    *)
        center "Invalid choice."
        exit 1
        ;;
esac

# -------------------------
# Show version banner
# -------------------------
clear
banner
center "[ v$VERSION ] Exporting for Version ${BLUEPRINT_PATH##*/} of Blueprint"
echo ""

# -------------------------
# Execute (or preview) actions
# -------------------------
OUTPUT=""

# Resolve release directory: allow BLUEPRINT_PATH to be either a full path
# or a short name (e.g. 'beta-2025-09'). If it's not absolute, assume
# it lives under /var/www/pterodactyl/.blueprint/dev/release/$BLUEPRINT_PATH
if [[ "$BLUEPRINT_PATH" == /* ]]; then
    RELEASE_DIR="$BLUEPRINT_PATH"
else
    RELEASE_DIR="/var/www/pterodactyl/.blueprint/dev/release/$BLUEPRINT_PATH"
fi

# Ensure the release directory exists and remove any existing blueprint file
do_cmd "mkdir -p \"$RELEASE_DIR\"" || OUTPUT="Failed to create $RELEASE_DIR"
do_cmd "rm -f \"$RELEASE_DIR/safetyblur.blueprint\"" || true

# Move the conf into place
do_cmd "mv \"$CONF_FROM\" \"$CONF_TO\"" || OUTPUT="Failed to rename $CONF_FROM to $CONF_TO"

# Run blueprint -e in the project root
do_cmd "cd /var/www/pterodactyl && blueprint -e >/dev/null 2>&1" || OUTPUT="Blueprint export failed"

# Move the generated blueprint into the release folder (explicit filename to avoid ambiguity)
do_cmd "mv -f /var/www/pterodactyl/safetyblur.blueprint \"$RELEASE_DIR/safetyblur.blueprint\"" || OUTPUT="Failed to move blueprint"

# Remove any leftover blueprint in the project root (cleanup)
do_cmd "rm -f /var/www/pterodactyl/.blueprint/dev/release/$BLUEPRINT_PATH/safetyblur.blueprint" || true

# Restore the original conf
do_cmd "mv \"$CONF_TO\" \"$CONF_FROM\"" || OUTPUT="Failed to rename $CONF_TO back to $CONF_FROM"

if [[ $DRY_RUN -eq 1 ]]; then
    center "[ dry-run ] Completed preview of actions for v$VERSION"
fi

# -------------------------
# Progress bar
# -------------------------
center "Processing..."
progress_bar

# -------------------------
# Check result
# -------------------------
if [[ -n "$OUTPUT" ]]; then
    log_error "$OUTPUT"
else
    center "[ ✔ ] Export for version v$VERSION completed successfully!"
fi

exit 0
