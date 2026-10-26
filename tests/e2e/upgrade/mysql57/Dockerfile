# The database image of before MySQL 8.4, to start a site on MySQL 5.7 and upgrade it:
#   docker build -t uoj-db-mysql57 -f tests/e2e/upgrade/mysql57/Dockerfile .
FROM mysql:5.7
COPY db/app_uoj233.sql db/add_judger.sql tests/e2e/upgrade/mysql57/install.sh /opt/uoj_db/
WORKDIR /opt/uoj_db
RUN sh install.sh
ENV LANG=C.UTF-8 TZ=Asia/Shanghai
