#!/usr/bin/env bash

# Post-packacing script

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

# ########## temma-lib ##########
# check if the directory exists
if [ ! -d /opt/temma-lib ]; then
	echo "No directory '/opt/temma-lib' found."
else
	echo "== Update 'temma-lib'"
	# copy files
	pushd /opt/Temma > /dev/null
	cp -a lib/*         /opt/temma-lib/lib/
	mkdir -p /opt/temma-lib/skills
	cp -a skills/*      /opt/temma-lib/skills/
	popd > /dev/null
	# commit files
	pushd /opt/temma-lib > /dev/null
	if [[ -n $(git status --porcelain) ]]; then
		git add .
		git commit -m "Update from Temma main repository version '$PKG_TAG'."
		git push
	fi
	# create tag
	/opt/Dispak/dpk pkg --tag=$PKG_TAG
	echo "   done"
	popd > /dev/null
fi

# ########## temma-project-web ##########
# check if the directory exists
if [ ! -d /opt/temma-project-web ]; then
	echo "No directory '/opt/temma-project-web' found."
else
	echo "== Update 'temma-project-web'"
	# copy files
	pushd /opt/Temma > /dev/null
	cp    bin/comma          /opt/temma-project-web/bin/
	cp    etc/apache.conf    /opt/temma-project-web/etc/
	cp    etc/nginx.conf     /opt/temma-project-web/etc/
	cp    etc/temma.php      /opt/temma-project-web/etc/
	cp    etc/temma-full.php /opt/temma-project-web/etc/
	cp -a etc/asynk          /opt/temma-project-web/etc/
	cp -a templates/*        /opt/temma-project-web/templates/
	cp    tests/autoload.php /opt/temma-project-web/tests/
	cp    www/index.php      /opt/temma-project-web/www/
	cp    www/*.html         /opt/temma-project-web/www/
	cp    www/.htaccess      /opt/temma-project-web/www/
	cp    .htaccess          /opt/temma-project-web/
	popd > /dev/null
	# manage composer.json
	pushd /opt/temma-project-web > /dev/null
	sed "s/\"digicreon\/temma-lib\": \"[^\"]*\"/\"digicreon\/temma-lib\": \"^$PKG_TAG\"/" composer.json > composer.json.tmp
	mv composer.json.tmp composer.json
	# commit files
	if [[ -n $(git status --porcelain) ]]; then
		git add .
		git commit -m "Update from Temma main repository version '$PKG_TAG'."
		git push
	fi
	# create tag
	/opt/Dispak/dpk pkg --tag=$PKG_TAG
	echo "   done"
	popd > /dev/null
fi

# ########## temma-project-api ##########
# check if the directory exists
if [ ! -d /opt/temma-project-api ]; then
	echo "No directory '/opt/temma-project-api' found."
else
	echo "== Update 'temma-project-api'"
	# copy files
	pushd /opt/Temma > /dev/null
	cp    bin/comma              /opt/temma-project-api/bin/
	cp    etc/apache.conf        /opt/temma-project-api/etc/
	cp    etc/nginx.conf         /opt/temma-project-api/etc/
	cp    etc/temma-api.php      /opt/temma-project-api/etc/temma.php
	cp    etc/temma-full.php     /opt/temma-project-api/etc/
	cp -a etc/asynk              /opt/temma-project-api/etc/
	cp    tests/autoload.php     /opt/temma-project-api/tests/
	cp    www/index.php          /opt/temma-project-api/www/
	cp    www/.htaccess          /opt/temma-project-api/www/
	cp    .htaccess              /opt/temma-project-api/
	popd > /dev/null
	# manage composer.json
	pushd /opt/temma-project-api > /dev/null
	sed "s/\"digicreon\/temma-lib\": \"[^\"]*\"/\"digicreon\/temma-lib\": \"^$PKG_TAG\"/" composer.json > composer.json.tmp
	mv composer.json.tmp composer.json
	# commit files
	if [[ -n $(git status --porcelain) ]]; then
		git add .
		git commit -m "Update from Temma main repository version '$PKG_TAG'."
		git push
	fi
	# create tag
	/opt/Dispak/dpk pkg --tag=$PKG_TAG
	echo "   done"
	popd > /dev/null
fi

# ########## temma.net ##########
# check if directory exists
if [ ! -d /home/http/Digicreon/www.temma.net ]; then
	echo "No directory '/home/http/Digicreon/www.temma.net' found."
else
	echo "== Update 'temma.net'"
	# copy files
	pushd /opt/Temma > /dev/null
	rm -rf /home/http/Digicreon/www.temma.net/lib/Temma
	cp -a lib/Temma /home/http/Digicreon/www.temma.net/lib/
	cp llms-install.md /home/http/Digicreon/www.temma.net/www/
	popd > /dev/null
	# commit files
	pushd /home/http/Digicreon/www.temma.net > /dev/null
	if [[ -n $(git status --porcelain lib/Temma www/llms-install.md) ]]; then
		git add lib/Temma www/llms-install.md
		git commit -m "Update Temma version '$PKG_TAG'."
		git push
	fi
	echo "   done"
	popd > /dev/null
fi

