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
      git vim ntp zip unzip curl wget xxd mysql-client rsync apache2 libapache2-mod-xsendfile \
      php7.4 libapache2-mod-php7.4 php7.4-dev php-pear php7.4-zip php7.4-mysql php7.4-mbstring php7.4-gd php7.4-intl php7.4-xsl \
      gcc-14 g++-14 make dpkg-dev re2c libyaml-dev libseccomp-dev && \
    update-alternatives --install /usr/bin/gcc gcc /usr/bin/gcc-14 100 && \
    update-alternatives --install /usr/bin/g++ g++ /usr/bin/g++-14 100 && \
    update-alternatives --install /usr/bin/cpp cpp-bin /usr/bin/cpp-14 100 && \
    yes | pecl install yaml && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# MathJax is served by the site itself, so that a page with formulas needs nothing from
# anywhere else. Only what the configuration TeX-AMS_HTML uses is kept: no SVG output and
# no fonts but the ones of TeX. Where the registry of npm is far away, build with
#   --build-arg MATHJAX_URL=https://registry.npmmirror.com/mathjax/-/mathjax-2.7.7.tgz
ARG MATHJAX_URL=https://registry.npmjs.org/mathjax/-/mathjax-2.7.7.tgz
RUN curl -fsSL "$MATHJAX_URL" -o /tmp/mathjax.tgz && \
    echo "f0054315d40f7288b2373b23520677fd1132a405702dfca05ee6ebe5cd0a8677  /tmp/mathjax.tgz" | sha256sum -c - && \
    mkdir -p /opt/mathjax && \
    tar xzf /tmp/mathjax.tgz -C /opt/mathjax --strip-components=1 \
      package/MathJax.js package/LICENSE package/config/TeX-AMS_HTML.js package/extensions package/localization \
      package/jax package/fonts/HTML-CSS/TeX/woff package/fonts/HTML-CSS/TeX/otf package/fonts/HTML-CSS/TeX/eot && \
    rm -rf /opt/mathjax/jax/output/SVG && \
    find /opt/mathjax/jax/output/HTML-CSS/fonts -mindepth 1 -maxdepth 1 ! -name TeX -exec rm -rf {} + && \
    rm /tmp/mathjax.tgz

# Add application files
ADD . /opt/uoj
WORKDIR /opt/uoj
RUN rm -rf /opt/uoj/web/js/mathjax && mv /opt/mathjax /opt/uoj/web/js/mathjax

# Install web environment and create startup script
RUN sh web/install.sh -p && \
    printf '%s\n' '#!/bin/sh' \
      'set -e' \
      'if [ ! -f "/var/uoj_data/.UOJSetupDone" ]; then' \
      '  cd /opt/uoj/web && sh install.sh --init-data' \
      'fi' \
      'php /opt/uoj/web/app/cli.php upgrade:latest' \
      'php /opt/uoj/web/app/cli.php user:finish-renames' \
      '(while sleep 60; do php /opt/uoj/web/app/cli.php site:tick > /dev/null 2>&1; done) &' \
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
