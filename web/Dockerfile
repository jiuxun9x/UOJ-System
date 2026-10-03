FROM ubuntu:24.04
MAINTAINER Baoshuo <i@baoshuo.ren>

LABEL org.opencontainers.image.source="https://github.com/UniversalOJ/UOJ-System"
LABEL org.opencontainers.image.description="UOJ Web"
LABEL org.opencontainers.image.licenses="MIT"

ARG CLONE_ADDFLAG

ENV DEBIAN_FRONTEND=noninteractive
ENV LANG=C.UTF-8
ENV TZ=Asia/Shanghai

RUN \
    apt-get update && apt-get install -y --no-install-recommends software-properties-common gnupg curl ca-certificates && \
    add-apt-repository -y ppa:ondrej/php && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
      git vim ntp zip unzip curl wget xxd mysql-client apache2 libapache2-mod-xsendfile \
      php7.4 libapache2-mod-php7.4 php7.4-dev php-pear php7.4-zip php7.4-mysql php7.4-mbstring php7.4-gd php7.4-intl php7.4-xsl \
      gcc-14 g++-14 make dpkg-dev re2c libyaml-dev libseccomp-dev && \
    update-alternatives --install /usr/bin/gcc gcc /usr/bin/gcc-14 100 && \
    update-alternatives --install /usr/bin/g++ g++ /usr/bin/g++-14 100 && \
    update-alternatives --install /usr/bin/cpp cpp-bin /usr/bin/cpp-14 100 && \
    yes | pecl install yaml && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Add application files
ADD . /opt/uoj
WORKDIR /opt/uoj

# Install web environment and create startup script
RUN sh web/install.sh -p && \
    printf '%s\n' '#!/bin/sh' \
      'set -e' \
      'if [ ! -f "/var/uoj_data/.UOJSetupDone" ]; then' \
      '  cd /opt/uoj/web && sh install.sh --init-data' \
      'fi' \
      'php /opt/uoj/web/app/cli.php upgrade:latest' \
      'rm -f /var/run/apache2/apache2.pid' \
      'exec apache2ctl -D FOREGROUND' > /opt/up && \
    chmod +x /opt/up

# The web server never compiles or runs anything that comes with a problem, the judgers do.
# Once its own tools are built, nothing that could do so is left in the image.
RUN apt-mark manual libyaml-0-2 && \
    apt-get purge -y gcc-14 g++-14 make dpkg-dev re2c libyaml-dev libseccomp-dev php7.4-dev && \
    apt-get autoremove -y && \
    apt-get clean

EXPOSE 80
CMD ["/opt/up"]
