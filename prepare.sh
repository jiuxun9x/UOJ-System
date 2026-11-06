#!/bin/bash

echo "==> Preparing config file"

genRandStr(){
	cat /dev/urandom | tr -dc [:alnum:] | head -c $1
}

# 复制配置文件
cp web/app/.default-config.php .config.local.php
echo "==> Copied default config file"

# The file holds the password of the database and the salts of the site: it is for its owner and
# the web server (the group www-data, 33, in the image of the site), not for every user of the
# machine. Giving it that group needs root; without it the file stays readable to all, and the
# way to keep it is told.
if chgrp 33 .config.local.php 2>/dev/null; then
	chmod 640 .config.local.php
	echo "==> .config.local.php is readable by you and the web server only"
else
	chmod 644 .config.local.php
	echo "!! .config.local.php is readable by every user of this machine. To keep it to you and the web server:"
	echo "!!   sudo chgrp 33 .config.local.php && sudo chmod 640 .config.local.php"
fi

# 替换配置文件中的 salt
sed -i -e "s/salt0/$(genRandStr 32)/g" \
	-e "s/salt1/$(genRandStr 16)/g" \
	-e "s/salt2/$(genRandStr 16)/g" \
	-e "s/salt3/$(genRandStr 16)/g" \
	.config.local.php
echo "==> Replaced salt in config file"

# 替换配置文件中的 _httpHost_
sed -i -e "s/'_httpHost_'/UOJContext::httpHost()/g" .config.local.php
echo "==> Replaced _httpHost_ in config file"

echo ""
echo "==> Done, config file is '.config.local.php'"
echo "==> Please modify the config file according to your needs"
echo "==> Then run 'docker-compose up -d' to start the service"
