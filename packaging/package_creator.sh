#!/bin/bash

#  Copyright notice
#
#  (c) 2007 Michael Stucki (michael@typo3.org)
#  All rights reserved
#
#  This script is part of the TYPO3 project. The TYPO3 project is
#  free software; you can redistribute it and/or modify
#  it under the terms of the GNU General Public License as published by
#  the Free Software Foundation; either version 2 of the License, or
#  (at your option) any later version.
#
#  The GNU General Public License can be found at
#  http://www.gnu.org/copyleft/gpl.html.
#  A copy is found in the textfile GPL.txt and important notices to the license
#  from the author is found in LICENSE.txt distributed with these scripts.
#
#  This script is distributed in the hope that it will be useful,
#  but WITHOUT ANY WARRANTY; without even the implied warranty of
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  GNU General Public License for more details.
#
# This copyright notice MUST APPEAR in all copies of the script!

#######################################################
# Package-builder for TYPO3
#
# This script takes care of building the final archives for TYPO3 releases.
# It copies the contents from incoming/source/ and writes the final archives to target/
#
# How to use:
#
# 1. Download the SVN module "TYPO3core" from http://sourceforge.net/projects/typo3/
# 2. Copy the contents to incoming/source/
# 3. Run this script
#
# Notice: This script must be run as root. This is neccessary for making "root" the owner
# of the archive contents. If you don't like this, you can use a tool called "fakeroot".
#
# $Id:$
# Author: Michael Stucki <michael@typo3.org>

#######################################################
# Define global settings

ORIG_DIR="$(pwd)"

cd "$(dirname $0)"
ROOT_DIR="$(pwd)"

SOURCE_DIR="$ROOT_DIR/incoming/source";
SITE_DIR="$ROOT_DIR/incoming/site";
DOC_DIR="$ROOT_DIR/incoming/doc";
TEMP_DIR="$ROOT_DIR/temp"
TARGET_DIR="$ROOT_DIR/target"

# Print messages?
DEBUG=1

# Remove the temp directory before and after the procedure?
CLEANUP_TEMP=1

# Remove the target directory before the procedure?
CLEANUP_TARGET=1

# Create tar.gz archives?
DO_TGZ=1

# Create zip archives?
DO_ZIP=1

# If you set this value this will override the version number (instead of using the version found in t3lib/config_default.php)
#FORCE_VERSION=4.2.0alpha3

# Files that should be removed from the result:
REMOVE_FILES="src CVS SVNreadme.txt .svn \*.webprj \*.orig \*~"

# Files that must be made executable:
EXEC_FILES="\*.phpcron \*.phpsh \*.sh \*.pl"

# Files that contain documentation (only searched in $DOC_DIR)
DOC_FILES="\*.txt"


#######################################################
# Begin of program. There is nothing else to configure below this line.

# TODO: description
function init {
	test $(whoami) != "root" && echo "Must run as root" && exit

	cleanup

	mkdir "$TEMP_DIR"
	mkdir "$TARGET_DIR"

	detect_version

	# Force the version number
	if [ -n "$FORCE_VERSION" ]; then
		VERSION="$FORCE_VERSION"
	fi
}

# TODO: description
function debug {
	if [ "$DEBUG" = 1 ]; then
		echo -n "$MESSAGE... "
	fi
}

# TODO: description
function debug_done {
	echo "OK."
}

# TODO: description
function cleanup {
	if [ "$CLEANUP_TEMP" = 1 ]; then
		test -d "$TEMP_DIR" && MESSAGE="Removing temporary directory" && debug && rm -rf "$TEMP_DIR" && debug_done
	fi

	if [ "$CLEANUP_TARGET" = 1 ]; then
		test -d "$TARGET_DIR" && MESSAGE="Removing target directory" && debug && rm -rf "$TARGET_DIR" && debug_done
	fi
}

# Detect the version number
function detect_version {
	VERSION=$(grep "^\$TYPO_VERSION" "$SOURCE_DIR/t3lib/config_default.php" | sed "s/.*'\(.*\)'.*/\1/")
}

# TODO: description
function copy_source {
	MESSAGE="Copying source directory" && debug
	test -e "$SOURCE_DIR/index.php" && cp -R "$SOURCE_DIR/" "$TEMP_DIR/typo3_src-$VERSION" && debug_done && return
	echo "Error: Source directory does not contain TYPO3 (index.php is missing)" && exit 1
}

# TODO: description
function copy_site {
	MESSAGE="Copying site directory" && debug
	test -e "$SITE_DIR/_.htaccess" && cp -R "$SITE_DIR" "$TEMP_DIR/dummy-$VERSION" && debug_done && return
	echo $SITE_DIR
	echo "Error: Site directory does not contain _.htaccess" && exit 1
}

# TODO: description
function create_symlinks {
	cd "$TEMP_DIR/dummy-$VERSION"

	ln -s "../typo3_src-$VERSION" "typo3_src"
	ln -s "typo3_src/t3lib" "t3lib"
	ln -s "typo3_src/typo3" "typo3"
	ln -s "typo3_src/index.php" "index.php"

	cd ..
}

# TODO: description
function create_doc {
	FILES=$(echo $DOC_FILES | sed 's/^\\//')

	for i in dummy typo3_src; do
		find "$DOC_DIR" -name "$FILES" -a ! -wholename "*/.svn*" -exec cp {} "$TEMP_DIR/$i-$VERSION/" \;
		rm "$TEMP_DIR/$i-$VERSION/INSTALL_zip.txt"
		mv "$TEMP_DIR/$i-$VERSION/INSTALL_tgz.txt" "$TEMP_DIR/$i-$VERSION/INSTALL.txt"
	done
}

# Remove unused files/directories
function remove_unused {
	MESSAGE="Removing unused files and directories" && debug

	for i in $REMOVE_FILES; do
		i=$(echo $i | sed 's/^\\//')
		LIST=$(find "$TEMP_DIR/" -name "$i")
		test -n "$LIST" && echo $LIST | xargs rm -rf
	done

	debug_done
}

# TODO: description
function fix_permissions {
	MESSAGE="Fixing file permissions" && debug

	# Change ownership
	chown -R root.root "$TEMP_DIR"

	# Set readonly permissions for everyone except the owner
	chmod -R 755 "$TEMP_DIR"

	# Files should not remain executable
	find "$TEMP_DIR" -type f | xargs chmod a-x

	# ... except those listed in EXEC_FILES
	for i in $EXEC_FILES; do
		i=$(echo $i | sed 's/^\\//')
 		LIST=$(find "$TEMP_DIR/" -name "$i")
 		test -n "$LIST" && echo $LIST | xargs chmod a+x
	done

	debug_done
}

# Create the archives
function create_archives {
	if [ "$DO_TGZ" = 1 ]; then
		MESSAGE="Creating tar.gz archives" && debug

		ARCHIVE_NAME="$TARGET_DIR/typo3_src-$VERSION.tar.gz"
		cd "$TEMP_DIR" && tar czf "$ARCHIVE_NAME" "typo3_src-$VERSION" && cd ..

		ARCHIVE_NAME="$TARGET_DIR/dummy-$VERSION.tar.gz"
		cd "$TEMP_DIR" && tar czf "$ARCHIVE_NAME" "dummy-$VERSION" && cd ..

		debug_done
	fi

	if [ "$DO_ZIP" = 1 ]; then
		MESSAGE="Creating zip archives" && debug

		# Modify the dummy directory contents (removing symlinks)
		patch_zip

		ARCHIVE_NAME="$TARGET_DIR/typo3_src-$VERSION.zip"
		cd "$TEMP_DIR" && zip -9qr "$ARCHIVE_NAME" "typo3_src-$VERSION" && cd ..

		ARCHIVE_NAME="$TARGET_DIR/dummy-$VERSION.zip"
		cd "$TEMP_DIR" && zip -9qr "$ARCHIVE_NAME" "dummy-$VERSION" && cd ..

		create_zip_and_dummy

		debug_done
	fi
}

# TODO: description
function patch_zip {
	cd "$TEMP_DIR"

	# Remove symlinks
	cp -L "dummy-$VERSION/index.php" "dummy-$VERSION/__index.php"
	find "dummy-$VERSION" -type l | xargs rm
	mv "dummy-$VERSION/__index.php" "dummy-$VERSION/index.php"

	# Replace INSTALL.txt with the zip version
	for i in dummy typo3_src; do
		rm "$TEMP_DIR/$i-$VERSION/INSTALL.txt"
		cp "$DOC_DIR/INSTALL_zip.txt" "$TEMP_DIR/$i-$VERSION/INSTALL.txt"
	done

	cd ..
}

# TODO: description
function create_zip_and_dummy {
	cd "$TEMP_DIR"

	rm "dummy-$VERSION/index.php"

	# Merge directories
	cp -R "typo3_src-$VERSION/" "typo3_src+dummy-$VERSION"
	find "dummy-$VERSION/" -mindepth 1 -maxdepth 1 -exec cp -R {} "typo3_src+dummy-$VERSION/" \;

	ARCHIVE_NAME="$TARGET_DIR/typo3_src+dummy-$VERSION.zip"
	zip -9qr "$ARCHIVE_NAME" "typo3_src+dummy-$VERSION"

	cd ..
}

# TODO: description
function create_md5sum {
	cd $TARGET_DIR
	md5sum * > md5sums.txt
	cd ..
}



# Initialize environment
init

# Copy source and site directories
copy_site
copy_source

# Create symlinks in site directory
create_symlinks

# Move documentation files to the right place
create_doc

# Remove unused files and change the permissions
remove_unused
fix_permissions

# Create the archives
create_archives

# Clean up but skip the target directory
CLEANUP_TARGET=0
cleanup

# Create md5sum.txt
create_md5sum

# Go back to where we came from
cd "$ORIG_DIR"

exit
