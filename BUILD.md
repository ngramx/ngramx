# Building Ngramx CLI

## Prerequisites

1. **PHP 8.2+** with required extensions
2. **Composer** installed
3. **Box** (PHAR builder) installed globally

```bash
# Install Box globally
composer global require humbug/box
```

## Build Steps

### 1. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Build PHAR

```bash
box compile
```

This will create `ngramx.phar` in the project root.

### 3. Test the PHAR

```bash
php ngramx.phar --version
php ngramx.phar list
```

### 4. Make it Executable

```bash
chmod +x ngramx.phar
./ngramx.phar --version
```

## Distribution

### Option 1: GitHub Releases (Recommended)

Upload `ngramx.phar` and `install.sh` to GitHub Releases:

```bash
# Create release with both files
gh release create v1.0.0 ngramx.phar install.sh \
  --title "Ngramx CLI v1.0.0" \
  --notes "Initial release - Docker development environment orchestration"

# Users can then install with one command:
curl -fsSL https://github.com/ngramx/ngramx/releases/latest/download/install.sh | bash
```

### Option 2: Manual Download

```bash
# Download files
curl -L https://github.com/ngramx/ngramx/releases/latest/download/ngramx.phar -o ngramx.phar
curl -L https://github.com/ngramx/ngramx/releases/latest/download/install.sh -o install.sh

# Run installer
chmod +x install.sh
./install.sh
```

## Installation Script

The `install.sh` script:
1. Copies PHAR to `/usr/local/bin/ngramx`
2. Makes it executable
3. Uses Gigabyte brand colors for output
4. Shows quick start guide

**Note:** Tab completion setup is documented in COMPLETION.md for manual installation.

## Build for Multiple PHP Versions

```bash
# PHP 8.2
php8.2 $(which box) compile

# PHP 8.3
php8.3 $(which box) compile
```

## Troubleshooting

### Box not found

```bash
# Find your composer global bin directory
composer global config bin-dir --absolute

# Add to PATH (use the path from above, typically ~/.config/composer/vendor/bin)
export PATH="$PATH:$HOME/.config/composer/vendor/bin"

# Add to ~/.bashrc permanently
echo 'export PATH="$PATH:$HOME/.config/composer/vendor/bin"' >> ~/.bashrc

# Or use full path directly
~/.config/composer/vendor/bin/box compile
```

### Permission errors

```bash
# Use sudo for box compile if needed
sudo box compile
sudo chown $USER:$USER ngramx.phar
```

### PHAR readonly errors

Edit `php.ini`:
```ini
phar.readonly = Off
```

Or run with ini setting:
```bash
php -d phar.readonly=0 $(which box) compile
```

## Continuous Integration

### GitHub Actions

```yaml
name: Build PHAR

on:
  push:
    tags:
      - 'v*'

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer, box
          
      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader
        
      - name: Build PHAR
        run: box compile
        
      - name: Create Release
        uses: softprops/action-gh-release@v1
        with:
          files: |
            ngramx.phar
            install.sh
```

## Size Optimization

The PHAR is compressed with GZ. Typical size: **~2-3 MB**

To reduce size:
1. Remove dev dependencies: `--no-dev`
2. Optimize autoloader: `--optimize-autoloader`
3. Enable compression in box.json: `"compression": "GZ"`
4. Exclude unnecessary vendor files

## Security

### Signing (Optional)

```bash
# Generate key pair
box compile --with-sign

# Verify signature
box verify ngramx.phar
```

## Testing PHAR

```bash
# Test in clean environment
docker run --rm -it -v $(pwd)/ngramx.phar:/usr/local/bin/ngramx php:8.2-cli bash
ngramx --version
```

