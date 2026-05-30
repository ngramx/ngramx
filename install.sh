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
RELOAD_CMD=""

case "$SHELL_NAME" in
    bash)
        echo -e "${TEAL}▸ Installing Bash completion${NC}"
        if [ -d /etc/bash_completion.d ]; then
            if ngramx completion bash 2>/dev/null | sudo tee /etc/bash_completion.d/ngramx >/dev/null; then
                echo -e "${SMOKE}  Installed to /etc/bash_completion.d/ngramx${NC}"
                RELOAD_CMD="source ~/.bashrc"
            else
                echo -e "${SMOKE}  Could not install system-wide; see COMPLETION.md${NC}"
            fi
        else
            # Fall back to a per-user completion file sourced from ~/.bashrc.
            mkdir -p "$HOME/.config/ngramx"
            if ngramx completion bash > "$HOME/.config/ngramx/completion.bash" 2>/dev/null; then
                SOURCE_LINE='[ -f "$HOME/.config/ngramx/completion.bash" ] && source "$HOME/.config/ngramx/completion.bash"'
                if ! grep -qF "$HOME/.config/ngramx/completion.bash" "$HOME/.bashrc" 2>/dev/null; then
                    echo "$SOURCE_LINE" >> "$HOME/.bashrc"
                fi
                echo -e "${SMOKE}  Installed to ~/.config/ngramx/completion.bash${NC}"
                RELOAD_CMD="source ~/.bashrc"
            else
                echo -e "${SMOKE}  Could not install completion; see COMPLETION.md${NC}"
            fi
        fi
        ;;

    zsh)
        echo -e "${TEAL}▸ Installing Zsh completion${NC}"
        # Pick the first existing zsh completion directory on the system fpath.
        ZSH_COMP_DIR=""
        for dir in /usr/local/share/zsh/site-functions /usr/share/zsh/site-functions /usr/share/zsh/vendor-completions; do
            if [ -d "$dir" ]; then
                ZSH_COMP_DIR="$dir"
                break
            fi
        done

        if [ -n "$ZSH_COMP_DIR" ]; then
            if ngramx completion zsh 2>/dev/null | sudo tee "$ZSH_COMP_DIR/_ngramx" >/dev/null; then
                echo -e "${SMOKE}  Installed to $ZSH_COMP_DIR/_ngramx${NC}"
                RELOAD_CMD="source ~/.zshrc"
            else
                echo -e "${SMOKE}  Could not install system-wide; see COMPLETION.md${NC}"
            fi
        else
            # Fall back to a per-user completion directory added to fpath.
            mkdir -p "$HOME/.zsh/completion"
            if ngramx completion zsh > "$HOME/.zsh/completion/_ngramx" 2>/dev/null; then
                if ! grep -qF 'fpath=(~/.zsh/completion $fpath)' "$HOME/.zshrc" 2>/dev/null; then
                    {
                        echo 'fpath=(~/.zsh/completion $fpath)'
                        echo 'autoload -U compinit && compinit'
                    } >> "$HOME/.zshrc"
                fi
                echo -e "${SMOKE}  Installed to ~/.zsh/completion/_ngramx${NC}"
                RELOAD_CMD="source ~/.zshrc"
            else
                echo -e "${SMOKE}  Could not install completion; see COMPLETION.md${NC}"
            fi
        fi
        ;;

    *)
        echo -e "${SMOKE}  Shell '$SHELL_NAME' has no auto-completion installer; see COMPLETION.md${NC}"
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

if [ -n "$RELOAD_CMD" ]; then
    echo -e "${SMOKE}Enable tab completion in your current shell:${NC}"
    echo -e "${TEAL}  $RELOAD_CMD${NC}"
    echo ""
fi
echo -e "${SMOKE}Get started:${NC}"
echo -e "${TEAL}  cd your-project/${NC}"
echo -e "${TEAL}  ngramx up${NC}"
echo ""
echo -e "${SMOKE}Documentation: ${TEAL}https://github.com/ngramx/ngramx${NC}"
echo ""

