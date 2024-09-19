#!/usr/bin/env bash

# platform parameter
PKG_PLATFORM="$1"
# new tag parameter
PKG_TAG="$2"

if [ "$PKG_PLATFORM" = "" ] || [ "$PKG_TAG" = "" ]; then
	echo "Empty parameter."
	echo "Usage: $0 PLATFORM TAG"
	echo "    PLATFORM = dev|test|prod"
	echo "    TAG      = new tag number"
	exit 1
fi

# check if files are in Git's staging
if [[ -n $(git diff --cached --name-only) ]]; then
	echo "Unable to update Temma's version number in Framework.php: other files are staged and ready to commit."
	echo "Please, commit/restore/stash them and retry."
	exit 2
fi

# check if the file exist
if [ ! -f /opt/Temma/lib/Temma/Web/Framework.php ]; then
	echo "The file '/opt/Temma/lib/Temma/Web/Framework.php' doesn't exist."
	exit 3
fi

# update the file
sed -i "s/const TEMMA_VERSION = '[^']*';$/const TEMMA_VERSION = '$PKG_TAG';/" /opt/Temma/lib/Temma/Web/Framework.php

# commit the file
git add /opt/Temma/lib/Temma/Web/Framework.php
git commit -m "Update Temma's version to '$PKG_TAG'."
git push

