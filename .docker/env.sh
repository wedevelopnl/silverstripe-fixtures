#!/bin/sh
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WORKTREE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DIR_NAME="$(basename "$WORKTREE_DIR")"
ENV_FILE="$SCRIPT_DIR/.env"

# Ports derive from the checkout's directory name, so worktrees of this module
# and other modules' testbeds run side by side without colliding.
HASH=$(printf '%s' "$DIR_NAME" | cksum | awk '{print $1}')
OFFSET=$((HASH % 1000))

cat > "$ENV_FILE" <<EOF
COMPOSE_PROJECT_NAME=${DIR_NAME}
WEB_PORT=$((8000 + OFFSET))
MODULES_WEB_PORT=$((18000 + OFFSET))
DB_PORT=$((13000 + OFFSET))
EOF

echo "Generated .docker/.env:"
sed 's/^/  /' "$ENV_FILE"
