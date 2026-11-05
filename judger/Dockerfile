FROM ubuntu:24.04
MAINTAINER Baoshuo <i@baoshuo.ren>

LABEL org.opencontainers.image.source="https://github.com/UniversalOJ/UOJ-System"
LABEL org.opencontainers.image.description="UOJ Judger"
LABEL org.opencontainers.image.licenses="MIT"

ARG CLONE_ADDFLAG

ENV DEBIAN_FRONTEND=noninteractive
ENV LANG=C.UTF-8
ENV TZ=Asia/Shanghai

RUN \
    apt-get update && \
    apt-get install -y --no-install-recommends \
    git vim ntp zip unzip curl wget make dpkg-dev ca-certificates \
    fp-compiler \
    python3 python3-requests \
    openjdk-21-jdk-headless \
    gcc-14 g++-14 \
    libseccomp-dev \
    tzdata && \
    update-alternatives --install /usr/bin/gcc gcc /usr/bin/gcc-14 100 && \
    update-alternatives --install /usr/bin/g++ g++ /usr/bin/g++-14 100 && \
    update-alternatives --install /usr/bin/cpp cpp-bin /usr/bin/cpp-14 100 && \
    cd /usr/src && \
    wget https://www.python.org/ftp/python/2.7.18/Python-2.7.18.tgz && \
    tar xzf Python-2.7.18.tgz && \
    cd Python-2.7.18 && \
    ./configure && \
    make -j"$(nproc)" && \
    make install && \
    cd / && \
    rm -rf /usr/src/Python-2.7.18.tgz /usr/src/Python-2.7.18 && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

ADD . /opt/uoj_judger
WORKDIR /opt/uoj_judger

# Install environment and set startup script
RUN sh install.sh -p && sh install.sh -d

# <bits/stdc++.h> compiled ahead, once for every standard of C++, by run/compile itself with the
# options it compiles programs with: gcc takes a precompiled header only where they are the same
RUN /opt/uoj_judger/uoj_judger/run/compile --make-pch /usr/local/lib/uoj-pch \
      "$(echo '#include <bits/stdc++.h>' | g++ -x c++ -H -fsyntax-only - 2>&1 >/dev/null | sed -n 's/^\. //p' | head -n 1)" && \
    chmod -R a+rX /usr/local/lib/uoj-pch && \
    du -sh /usr/local/lib/uoj-pch/*

EXPOSE 2333
CMD ["/opt/up"]
