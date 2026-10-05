#!/bin/bash

getAptPackage(){
    printf "\n\n==> Getting environment packages\n"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update && apt-get install -y vim ntp zip unzip curl wget build-essential fp-compiler python2.7 python3.8 python3-requests
}

setJudgeConf(){
    printf "\n\n==> Setting judger files\n"
    #specify environment
    cat > /etc/environment <<UOJEOF
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
UOJEOF
    #Add judger user
    adduser judger --gecos "" --disabled-password
    #Set uoj_data path
    mkdir /var/uoj_data_copy && chown judger:judger /var/uoj_data_copy
    #Compile uoj_judger and set runtime
    chown -R judger:judger /opt/uoj_judger
    su judger <<EOD
ln -s /var/uoj_data_copy /opt/uoj_judger/uoj_judger/data
cd /opt/uoj_judger && chmod +x judge_client
cd uoj_judger && make -j$(($(nproc) + 1))
EOD
}

initProgress(){
    printf "\n\n==> Doing initial config and start service\n"
    # A judger needs three things to work for a site: where the site is, and the name and the
    # password of the judging account the site gave it. The address can be given in one piece.
    if [ -n "$UOJ_SERVER_URL" ]; then
        UOJ_PROTOCOL="${UOJ_SERVER_URL%%://*}"
        UOJ_HOST="${UOJ_SERVER_URL#*://}"
        UOJ_HOST="${UOJ_HOST%/}"
    fi
    # The socket is for commands given on the judger's own machine, and is nothing the site
    # connects to: when nothing is said about it, it gets a password nobody knows.
    SOCKET_PORT="${SOCKET_PORT:-2333}"
    SOCKET_PASSWORD="${SOCKET_PASSWORD:-$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
    # Check envs
    if [ -z "$UOJ_PROTOCOL" -o -z "$UOJ_HOST" -o -z "$JUDGER_NAME" -o -z "$JUDGER_PASSWORD" ]; then
        echo "!! Environment variables not set! Please edit config file by yourself!"
    else
        # Set judge_client config file
        cat >.conf.json <<UOJEOF
{
    "uoj_protocol": "$UOJ_PROTOCOL",
    "uoj_host": "$UOJ_HOST",
    "judger_name": "$JUDGER_NAME",
    "judger_password": "$JUDGER_PASSWORD",
    "socket_port": $SOCKET_PORT,
    "socket_password": "$SOCKET_PASSWORD",
    "max_judging_seconds": ${MAX_JUDGING_SECONDS:-3600},
    "data_sync": ${DATA_SYNC:-true},
    "data_cache_problems": ${DATA_CACHE_PROBLEMS:-300}
}
UOJEOF
        chmod 600 .conf.json && chown judger:judger .conf.json
        chown -R judger:judger ./log
        #Start services
        service ntpd restart
        su judger -c '/opt/uoj_judger/judge_client start'
        echo "this judger works for $UOJ_PROTOCOL://$UOJ_HOST as the judging account $JUDGER_NAME;"
        echo "the account is made on the site: system management, judgers."
        printf "\n\n***Installation complete. Enjoy!***\n"
    fi
}

prepProgress(){
    setJudgeConf
}

dockerPrep(){
	echo "#!/bin/sh
if [ ! -f \"/opt/uoj_judger/.conf.json\" ]; then
  cd /opt/uoj_judger && sh install.sh -i
else
  service ntpd start
  su judger -c \"/opt/uoj_judger/judge_client start\"
fi
exec bash" >/opt/up
    chmod +x /opt/up
}

if [ $# -le 0 ]; then
    echo 'Installing UOJ System judger...'
    prepProgress;initProgress
fi
while [ $# -gt 0 ]; do
    case "$1" in
        -p | --prep)
            echo 'Preparing UOJ System judger environment...'
            prepProgress
        ;;
        -d | --docker)
            echo '[Docker] Preparing UOJ System judger environment...'
            dockerPrep
        ;;
        -i | --init)
            echo 'Initing UOJ System judger...'
            initProgress
        ;;
        -? | --*)
            echo "Illegal option $1"
        ;;
    esac
    shift $(( $#>0?1:0 ))
done
