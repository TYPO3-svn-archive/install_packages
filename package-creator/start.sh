#!/bin/sh

# We need to download the source backup from Kaspers server
cd incoming/source/

# wget -c http://130.228.0.33/t3dl/src/typo3_src-3.6.0.tgz
# tar xzf typo3_src-3.6.0.tgz

cd ../../

# Optionally, you can just continue with the pacakge-creator now
./package-creator.php
