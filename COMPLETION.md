# Tab Completion Setup

`install.sh` sets up tab completion automatically for Bash and Zsh. If you
installed the PHAR another way, or the automatic setup did not run, you can
install completion manually using the commands below.

Completion works directly from the installed PHAR — the Symfony Console
completion templates are packaged into `ngramx.phar`, so there is no need to
run anything from source.

## Bash Completion

```bash
# System-wide (requires sudo)
ngramx completion bash | sudo tee /etc/bash_completion.d/ngramx
source ~/.bashrc
```

```bash
# Per-user (no sudo)
mkdir -p ~/.config/ngramx
ngramx completion bash > ~/.config/ngramx/completion.bash
echo 'source ~/.config/ngramx/completion.bash' >> ~/.bashrc
source ~/.bashrc
```

> Bash completion relies on the `bash-completion` package (it provides the
> `_get_comp_words_by_ref` helper). It is preinstalled on most distributions;
> install it via your package manager if completion does nothing.

## Zsh Completion

```bash
# System-wide (requires sudo) — pick a directory already on your $fpath
ngramx completion zsh | sudo tee /usr/local/share/zsh/site-functions/_ngramx
source ~/.zshrc
```

```bash
# Per-user (no sudo)
mkdir -p ~/.zsh/completion
ngramx completion zsh > ~/.zsh/completion/_ngramx

# Add to ~/.zshrc if not already there:
echo 'fpath=(~/.zsh/completion $fpath)' >> ~/.zshrc
echo 'autoload -U compinit && compinit' >> ~/.zshrc

source ~/.zshrc
```

## Test It

```bash
# Type this and hit TAB
ngramx <TAB>

# Should show: up, down, status, and your custom commands
```

## How It Works

`ngramx completion bash|zsh` prints a small shell script that registers a
completion function. At completion time that function calls the hidden
`ngramx _complete` command, which inspects the current command line and returns
matching commands, options, and argument values — including custom commands
defined in your project's `ngramx.yml`.
