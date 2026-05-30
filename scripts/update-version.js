#!/usr/bin/env node

/**
 * Update version in src/Application.php
 * Called by semantic-release during the prepare step
 */

const fs = require('fs');
const path = require('path');

const version = process.env.NEXT_VERSION || process.argv[2];

if (!version) {
  console.error('Error: No version provided');
  console.error('Usage: node update-version.js <version>');
  console.error('   or: NEXT_VERSION=<version> node update-version.js');
  process.exit(1);
}

const applicationPath = path.join(__dirname, '..', 'src', 'Application.php');

try {
  let content = fs.readFileSync(applicationPath, 'utf8');
  
  // Replace version in: parent::__construct('Ngramx CLI', '1.0.6');
  const versionRegex = /(parent::__construct\(['"]Ngramx CLI['"],\s*['"]).+?(['"])/;
  
  if (!versionRegex.test(content)) {
    console.error('Error: Could not find version pattern in Application.php');
    process.exit(1);
  }
  
  const newContent = content.replace(versionRegex, `$1${version}$2`);
  
  fs.writeFileSync(applicationPath, newContent, 'utf8');
  
  console.log(`✓ Updated Application.php version to ${version}`);
} catch (error) {
  console.error('Error updating version:', error.message);
  process.exit(1);
}

