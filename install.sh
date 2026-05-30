#!/bin/bash

set -e

# Gigabyte Brand Colors (for terminals that support it)
PURPLE='\033[38;2;125;85;199m'
TEAL='\033[38;2;46;217;195m'
SMOKE='\033[38;2;210;220;229m'
RED='\033[38;2;255;68;68m'
GREEN='\033[38;2;80;250;123m'
NC='\033[0m' # No Color

echo ""
echo -e "${PURPLE}──────────────────────────────────────────────────${NC}"
echo -e "${PURPLE} Installing Ngramx CLI${NC}"
echo -e "${PURPLE}──────────────────────────────────────────────────${NC}"
echo ""

# Check if ngramx.phar exists
if [ ! -f "ngramx.phar" ]; then
    echo -e "${RED}Error: ngramx.phar not found in current directory${NC}"
    echo ""
    echo "Download it first:"
    echo "  curl -L https://github.com/ngramx/ngramx/releases/latest/download/ngramx.phar -o ngramx.phar"
    echo ""
    exit 1
fi

# Install PHAR to /usr/local/bin
echo -e "${TEAL}▸ Installing Ngramx CLI${NC}"
if sudo cp ngramx.phar /usr/local/bin/ngramx; then
    sudo chmod +x /usr/local/bin/ngramx
    echo -e "${SMOKE}  Installed to /usr/local/bin/ngramx${NC}"
else
    echo -e "${RED}  Failed to install. Try running with sudo${NC}"
    exit 1
fi

echo ""

# Detect shell and install completion
SHELL_NAME=$(basename "$SHELL")

case "$SHELL_NAME" in
    bash)
        echo -e "${TEAL}▸ Installing Bash completion${NC}"
        # Skip completion due to PHAR issue - will document workaround
        echo -e "${SMOKE}  Skipping auto-completion (install manually if needed)${NC}"
        RELOAD_CMD=""
        ;;
        
    zsh)
        echo -e "${TEAL}▸ Installing Zsh completion${NC}"
        # Skip completion due to PHAR issue - will document workaround
        echo -e "${SMOKE}  Skipping auto-completion (install manually if needed)${NC}"
        RELOAD_CMD=""
        ;;
        
    *)
        RELOAD_CMD=""
        ;;
esac

echo ""
echo -e "${PURPLE}──────────────────────────────────────────────────${NC}"
echo -e "${PURPLE} Installation Complete${NC}"
echo -e "${PURPLE}──────────────────────────────────────────────────${NC}"
echo ""
echo -e "${SMOKE}Verify installation:${NC}"
echo -e "${TEAL}  ngramx --version${NC}"
echo ""
echo -e "${SMOKE}Get started:${NC}"
echo -e "${TEAL}  cd your-project/${NC}"
echo -e "${TEAL}  ngramx up${NC}"
echo ""
echo -e "${SMOKE}Documentation: ${TEAL}https://github.com/ngramx/ngramx${NC}"
echo ""

