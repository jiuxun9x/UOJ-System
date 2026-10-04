#!/bin/bash
# Puts a backup of the site back: its database, the data of its problems and what was
# submitted. Everything that happened since the backup was made is lost.
#
#   bash restore.sh                    lists the backups
#   bash restore.sh uoj-20261004-030000
set -e
cd "$(dirname "$0")"
cli="docker compose exec -T uoj-web php /opt/uoj/web/app/cli.php"

if [ -z "$1" ]; then
	echo "The backups there are:"
	$cli backup:list
	echo
	echo "Run: bash restore.sh <name of the backup>"
	exit 0
fi

echo "This replaces the database and the files of the site with the backup $1."
echo "Everything that happened since it was made will be lost."
read -r -p "Type the name of the backup again to go on: " answer
if [ "$answer" != "$1" ]; then
	echo "Nothing was changed."
	exit 1
fi

judgers=$(docker compose ps --services | grep judger || true)
echo "==> Stopping the judgers"
[ -n "$judgers" ] && docker compose stop $judgers
echo "==> Restoring $1"
$cli backup:restore "$1" --yes
echo "==> Bringing the database up to date"
$cli upgrade:latest
echo "==> Restarting the web server and the judgers"
docker compose restart uoj-web
[ -n "$judgers" ] && docker compose start $judgers
echo "==> Done."
