#!/bin/sh

# How this works:
#
# 1. Download the CVS module TYPO3core from http://sourceforge.net/projects/typo3/
# 2. Copy it to incoming/source/
# 3. If needed, add the global typo3/ext/ directory from an existing TYPO3 release (because its contents are missing in CVS)
# 4. Run the script below

/usr/bin/php4 -q package-creator.php
