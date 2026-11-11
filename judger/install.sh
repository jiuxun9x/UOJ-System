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
    # what the pages of the site call this judger where several work for one account
    WORKER_LABEL="$(printf '%s' "$WORKER_LABEL" | tr -cd 'A-Za-z0-9_' | cut -c1-32)"
    SOCKET_PASSWORD="${SOCKET_PASSWORD:-$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
    # Check envs
    if [ -z "$UOJ_PROTOCOL" -o -z "$UOJ_HOST" -o -z "$JUDGER_NAME" -o -z "$JUDGER_PASSWORD" ]; then
        echo "!! Environment variables not set! Please edit config file by yourself!"
    else
        # Set judge_client config file: every value as JSON has it, whatever it is made of
        UOJ_PROTOCOL="$UOJ_PROTOCOL" UOJ_HOST="$UOJ_HOST" JUDGER_NAME="$JUDGER_NAME" JUDGER_PASSWORD="$JUDGER_PASSWORD" \
            SOCKET_PORT="$SOCKET_PORT" SOCKET_PASSWORD="$SOCKET_PASSWORD" WORKER_LABEL="$WORKER_LABEL" \
            MAX_JUDGING_SECONDS="$MAX_JUDGING_SECONDS" DATA_SYNC="$DATA_SYNC" DATA_CACHE_PROBLEMS="$DATA_CACHE_PROBLEMS" \
            LONG_POLL_SECONDS="$LONG_POLL_SECONDS" python3 /opt/uoj_judger/write_conf.py .conf.json || exit 1
        chmod 600 .conf.json && chown judger:judger .conf.json
        chown -R judger:judger ./log
        #Start services
        service ntpd restart
        # The passwords are in .conf.json, which only judger reads: they are not left in the
        # environment of judge_client, which every process it starts could read in /proc.
        env -u JUDGER_PASSWORD -u SOCKET_PASSWORD su judger -c '/opt/uoj_judger/judge_client start'
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
  env -u JUDGER_PASSWORD -u SOCKET_PASSWORD su judger -c \"/opt/uoj_judger/judge_client start\"
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
