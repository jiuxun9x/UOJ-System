# 部署与配置

这份文档讲从零把本系统部署起来的全过程，以及每一个可以改的设置：它是什么意思、有哪些取值、
改了会发生什么。相关的专题文档：[统一身份认证](sso.md)、[角色与权限](permissions.md)、[域](domains.md)。

目录：

1. [系统由什么组成](#1-系统由什么组成)
2. [第一次部署](#2-第一次部署)
3. [docker-compose.yml 逐项说明](#3-docker-composeyml-逐项说明)
4. [配置文件 .config.local.php 逐项说明](#4-配置文件-configlocalphp-逐项说明)
5. [网页上的设置](#5-网页上的设置)
6. [评测机](#6-评测机)
7. [HTTPS 与反向代理](#7-https-与反向代理)
8. [数据、备份与恢复](#8-数据备份与恢复)
9. [升级](#9-升级)
10. [日常运维](#10-日常运维)
11. [接口一览](#11-接口一览)
12. [常见问题](#12-常见问题)

---

## 1. 系统由什么组成

三个容器，由 `docker-compose.yml` 一起管理：

| 容器 | 作用 | 里面跑什么 |
|---|---|---|
| `uoj-db` | 数据库 | MySQL 5.7，库名 `app_uoj233` |
| `uoj-web` | 网站 | Apache + PHP 7.4。处理所有页面请求，保存题目数据和提交的代码，给评测机派任务 |
| `uoj-judger` | 评测机 | Python 写的 `judge_client` 和 C++ 写的评测程序。编译并运行选手的代码；可以有多台 |

它们之间怎么通信：

```text
浏览器 ──HTTP 80──▶ uoj-web ──MySQL 3306──▶ uoj-db
                      ▲
                      │ HTTP（评测机主动来取任务、下载数据、回报结果）
                      │
                 uoj-judger × N
```

要点：

- **评测机主动连网站**，网站不需要能连到评测机。所以评测机可以放在别的机器上，只要它能访问网站的地址。
- 评测机不连数据库。
- 网站容器不编译、不运行任何随题目上传的程序，这些都在评测机的沙箱里做。

机器要求：Linux x86_64（评测机的沙箱只支持这个平台），装好 Docker 和 Docker Compose v2。
内存建议 4 GB 以上；每台评测机建议独占 2 个 CPU 核。

---

## 2. 第一次部署

以下命令都在仓库根目录执行。

### 第 1 步：生成配置文件

```bash
bash prepare.sh
```

它做三件事：把 `web/app/.default-config.php` 复制为 `.config.local.php`；把里面的四个盐值
换成随机字符串；把站点地址设成“从请求里取”。**只在第一次部署时执行一次**，重复执行会覆盖配置
并换掉盐值，导致所有人的密码失效（见第 4 节 `security`）。

> `prepare.sh` 用的是 GNU sed，请在 Linux 上执行。

### 第 2 步：修改 `.config.local.php`

上线前至少要改这几项（含义见第 4 节）：

| 项 | 改成什么 |
|---|---|
| `profile.oj-name`、`oj-name-short` | 你们 OJ 的名字 |
| `profile.administrator`、`admin-email` | 帮助页上显示的联系人和邮箱 |
| `web.main.host`、`web.blog.host` | 固定的域名，例如 `'oj.example.edu.cn'` |
| `web.main.protocol`、`port`（blog 同） | 用 HTTPS 时是 `'https'` 和 `443` |
| `web.trusted-proxies` | 前面有反向代理时，填代理的地址 |
| `sso.providers` | 接统一身份认证时填，见 [sso.md](sso.md) |
| `mail.noreply` | 要用“找回密码”就填一个能发信的邮箱 |

### 第 3 步：修改 `docker-compose.yml` 里的密码和端口

上线前要改的（含义见第 3 节）：

- 评测机的密码 `JUDGER_PASSWORD`：默认值 `_judger_password_` 是公开的，做法见 [6.2](#62-更换默认评测机的密码)。
- 网站对外的端口：默认把容器的 80 映射到宿主机的 80。前面有反向代理时改成例如 `"127.0.0.1:8080:80"`。
- 数据库密码：见 [12.1](#121-怎么改数据库密码)。数据库端口默认不对外开放，只有同一个 compose 网络里的容器能连。

### 第 4 步：构建镜像

```bash
docker compose build
```

**这一步不能省。** `docker-compose.yml` 里写的镜像名是 `ghcr.io/universaloj/uoj-*`，如果不先构建，
`docker compose up` 会去下载上游的官方镜像，那里面没有本仓库的任何改动。构建完成后，本机就有了
同名的本地镜像。评测机镜像要编译 Python 2.7，第一次构建需要十几分钟。

### 第 5 步：启动

```bash
docker compose up -d
```

网站容器每次启动时会自动：初始化数据目录（仅第一次）、执行数据库升级、补完被中断的改名、
启动每分钟一次的作业定时任务，然后启动 Apache。看启动日志：

```bash
docker compose logs -f uoj-web
```

### 第 6 步：注册第一个用户

打开网站，注册一个账号。**第一个注册的用户自动成为系统管理员（超级管理员）。**
所以要在对外开放之前、在接入统一身份认证之前，先把管理员账号注册好。

### 第 7 步：确认评测机在线

用管理员登录 → 右上角“系统管理” → “评测机管理”。列表里应当有 `compose_judger`，
“最近心跳”在几秒之内。然后新建一道题、上传数据、提交一份代码，确认能评测出结果。

### 第 8 步：上线前检查

- [ ] 管理员账号已注册，密码足够强
- [ ] `web.main.host` 是固定域名，`protocol` 与实际访问方式一致
- [ ] 默认评测机密码已更换
- [ ] 数据库端口没有对外开放（默认如此）
- [ ] 自动备份开着，`uoj_data/backup/` 会同步到另一台机器，`.config.local.php` 另存了一份
- [ ] 做过一次恢复演练（`backup:verify`）
- [ ] 如果接了统一身份认证：用学校的测试环境走过首次登录、再次登录、同名账号绑定
- [ ] “系统管理 → 站点设置”里的开关是你想要的状态

---

## 3. docker-compose.yml 逐项说明

### 3.1 `uoj-db`（数据库）

| 项 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `volumes: ./uoj_data/db/mysql` | — | 数据库文件存在宿主机的这个目录 | 换目录等于换一个空库。迁移时把整个目录搬过去 |
| `MYSQL_DATABASE` | `app_uoj233` | 库名 | 不要改。初始化脚本和默认配置都用这个名字 |
| `MYSQL_ROOT_PASSWORD` | `root` | 健康检查用它登录数据库 | 只改这里**不会**改变实际密码，改密码的正确做法见 [12.1](#121-怎么改数据库密码) |
| `healthcheck` | 每 5 秒 | 数据库就绪后网站才启动 | 一般不用改 |
| （没有 `ports`） | — | 数据库端口不映射到宿主机 | 加上 `ports: "3306:3306"` 会把数据库暴露出去，不建议 |

数据库的字符集（utf8mb4）、时区（+8:00）、`max_allowed_packet=64M` 写在镜像里（`db/install.sh`）。
`max_allowed_packet` 决定单条评测结果的大小上限，不要调小。

### 3.2 `uoj-web`（网站）

| 项 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `ports: "80:80"` | 宿主机 80 → 容器 80 | 网站对外的端口 | 左边是宿主机端口，可以改，例如 `"8080:80"`；只让本机的反向代理访问就写 `"127.0.0.1:8080:80"`。改了端口记得同步改配置里的 `web.main.port` |
| `ports: "3690:3690"` | — | 上游遗留的 SVN 端口，本系统里没有服务监听它 | 可以直接删掉这一行 |
| `volumes: ./uoj_data/web/data` → `/var/uoj_data` | — | 题目数据：每道题的上传目录、已发布的数据和各版本的归档 | 最重要的数据之一，必须备份 |
| `volumes: ./uoj_data/web/storage` → `/opt/uoj/web/app/storage` | — | 所有提交的源代码、临时文件、Paste | 必须备份 |
| `volumes: ./uoj_data/backup` → `/var/uoj_backup` | — | 网站每天自动做的备份，见第 8 节 | 建议换成另一块盘上的路径 |
| `volumes: ./.config.local.php` → `.config.php` | — | 配置文件 | 改完配置一般不用重启容器，下一个请求就生效（例外见第 4 节开头） |
| `depends_on: uoj-db` | — | 等数据库健康后再启动 | — |
| `environment` 里的全部变量 | — | **运行时不起作用**（见下） | — |

关于 `uoj-web` 的环境变量：`DATABASE_HOST`、`DATABASE_PASSWORD`、`JUDGER_SOCKET_PORT`、
`JUDGER_SOCKET_PASSWORD`、`SALT_0…3`、`UOJ_PROTOCOL` 是上游留下的。网站只读配置文件
`.config.local.php`，不读这些环境变量，改它们没有任何效果。要改数据库地址、密码、盐值、协议，
请改配置文件里对应的项。

登录会话保存在容器内部（`/var/lib/php/uoj_sessions`），**重建网站容器会让所有人退出登录**
（勾了“记住我”的用户会自动重新登录）。

### 3.3 `uoj-judger`（评测机）

评测机第一次启动时，用下面的环境变量生成自己的配置文件 `/opt/uoj_judger/.conf.json`。
之后再启动同一个容器不会重新生成，所以**改了环境变量要重建容器**才生效：

```bash
docker compose up -d --force-recreate uoj-judger
```

| 环境变量 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `UOJ_PROTOCOL` | `http` | 评测机访问网站用的协议 | 评测机走公网访问 HTTPS 的网站时改成 `https` |
| `UOJ_HOST` | `uoj-web` | 网站的地址，可以带端口，如 `oj.example.edu.cn` 或 `10.0.0.5:8080` | 同一个 compose 里就用容器名 `uoj-web`；评测机在别的机器上时改成它能访问到的网站地址 |
| `JUDGER_NAME` | `compose_judger` | 评测机的名字，要和“评测机管理”里登记的一致 | 每台评测机名字不同。评测记录里会记下是哪台评测的 |
| `JUDGER_PASSWORD` | `_judger_password_` | 评测机的密码，要和登记时得到的一致 | 不一致时评测机取不到任务，日志里是 `judger authentication failed` |
| `SOCKET_PORT` | `2333` | 评测机本地的控制端口（见 6.6） | 一般不用改 |
| `SOCKET_PASSWORD` | `_judger_socket_password_` | 控制端口的口令 | 评测机不在可信网络里时换一个 |

其他项：

| 项 | 含义 | 改了意味着什么 |
|---|---|---|
| `cap_add: SYS_PTRACE` | 沙箱需要用 ptrace 监视选手程序 | 去掉后无法评测 |
| `volumes: ./uoj_data/judger/log` | 评测机日志 `judge.log` | 每台评测机用不同的目录 |
| `cpuset`（默认注释掉） | 把评测机固定在指定的 CPU 核上，如 `"2,3"` | 评测时间更稳定。不同评测机用不同的核，也不要和网站、数据库抢同一批核 |
| `mem_limit` / `memswap_limit`（默认注释掉） | 内存上限；两者相等表示不允许用交换分区 | 一旦用到交换分区，运行时间会严重失真。上限要大于题目的最大内存限制加上余量 |

评测机容器里下载的题目数据是缓存，不需要持久化。

---

## 4. 配置文件 .config.local.php 逐项说明

这是一个返回数组的 PHP 文件。**改完保存即生效**，不需要重启。文件里没写的项自动取
`web/app/.default-config.php` 里的默认值，所以升级后新增的设置不需要手动补进来，想改的时候再加。

> 如果改了却不生效：这个文件是单独挂载进容器的，有些编辑器（以及 `sed -i`）保存时会用新文件
> 替换旧文件，容器里看到的仍是旧文件。执行 `docker compose restart uoj-web` 即可。

写错语法会让整个网站打不开，改完可以先检查：

```bash
docker compose exec uoj-web php -l /opt/uoj/web/app/.config.php
```

### 4.1 `profile`：站点信息

| 键 | 默认 | 含义 |
|---|---|---|
| `oj-name` | `Universal Online Judge` | 站点全名，显示在页头和页脚 |
| `oj-name-short` | `UOJ` | 简称，显示在导航栏和页面标题里 |
| `administrator` | `root` | 帮助页“私信联系 XXX”里的用户名，填管理员的用户名 |
| `admin-email` | `admin@local_uoj.ac` | 帮助页显示的联系邮箱 |
| `QQ-group` | 空 | 填了就在帮助页显示 QQ 群号 |
| `ICP-license` | 空 | 填了就在页脚显示备案号 |

这些只影响显示，随时可以改。

### 4.2 `database`：数据库连接

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `database` | `app_uoj233` | 库名 | 不要改 |
| `username` | `root` | 数据库用户 | 可以换成只对该库有全部权限的专用用户 |
| `password` | `root` | 密码 | 必须和数据库里的实际密码一致，见 [12.1](#121-怎么改数据库密码) |
| `host` | `uoj-db` | 数据库地址。IPv6 地址不加方括号 | 用外部数据库时改成它的地址 |
| `port` | `3306` | 端口 | — |
| `socket` | 空 | 填 Unix socket 路径则走 socket，忽略 host 和 port | — |

使用外部 MySQL 时要求 5.7，并保证 `max_allowed_packet` 不小于 64M、字符集 utf8mb4、时区与网站一致。

### 4.3 `web`：站点地址

网站用这些来生成页面里的链接、登录 Cookie 的作用域，以及统一身份认证的回调地址。
**它们必须和用户在浏览器里实际访问的地址一致。**

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `main.protocol` | `http` | `http` 或 `https` | 设为 `https` 后登录 Cookie 带 `Secure`，只在 HTTPS 下发送。实际是 HTTP 却写了 `https` 会导致登录不上 |
| `main.host` | `UOJContext::httpHost()` | 主站域名。默认值表示“用请求里的 Host” | 测试时方便；**生产环境请写成固定域名字符串**，否则别人可以伪造 Host 让生成的链接指向别处。注意：写固定域名时要加引号，如 `'oj.example.edu.cn'` |
| `main.port` | `80` | 用户访问的端口 | 是 80（http）或 443（https）时链接里不带端口，否则带。写错会让页面链接和跳转指向错误的端口 |
| `blog.protocol` / `host` / `port` | 同 main | 博客的地址 | 只在 `switch.blog-domain-mode` 为 1 或 2 时使用。默认模式 3 下博客在主站的 `/blog/用户名`，这三项不起作用，保持和 main 一致即可 |
| `domain` | `null` | 登录 Cookie 的域。`null` 表示用 `main.host` | 只有博客用独立子域名、需要和主站共享登录时才要设成它们共同的上级域名 |
| `trusted-proxies` | `[]` | 反向代理的地址或网段列表，如 `['172.18.0.1', '10.0.0.0/8']` | 只有来自这些地址的请求，其 `X-Forwarded-For / -Host / -Proto` 才被采信。不填则一律不采信：审计日志和登录记录里的 IP 会是代理的 IP。**不要填得过宽**，否则任何人都能伪造来源 IP |

### 4.4 `security`：盐值

| 键 | 含义 | 改了意味着什么 |
|---|---|---|
| `user.client_salt` | 浏览器在发送密码前用它做一次哈希 | **部署后绝对不要改。** 一改，所有本站密码全部失效，只能逐个重置 |
| `cookie.checksum_salt`（三个） | “记住我”等 Cookie 的校验 | 改了之后所有人的“记住我”失效，需要重新登录。怀疑泄露时可以换 |

这四个值由 `prepare.sh` 随机生成。迁移服务器时必须把 `.config.local.php` 原样带走。

### 4.5 `mail`：发信

发信邮箱用于“找回密码”和告警邮件。**推荐在网页上设置**：系统管理 → 站点设置 → 发信邮箱，
填好后可以当场发一封测试邮件；在那里填了 SMTP 服务器之后，就以网页上的设置为准，下面这几项不再使用。
配置文件里的这几项是没在网页上设置时的后备。两处都不配置的话其他功能不受影响，只是邮件发不出去
（通过统一身份认证登录的用户没有密码，用不到找回密码）。

| 键 | 含义 |
|---|---|
| `noreply.username` | 发信邮箱地址，也是 SMTP 用户名 |
| `noreply.password` | SMTP 密码或授权码 |
| `noreply.host` | SMTP 服务器 |
| `noreply.secure` | `tls` 或 `ssl` |
| `noreply.port` | 端口，`tls` 一般 587，`ssl` 一般 465 |

### 4.6 `judger`：评测调度

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `task-timeout` | `300` | 评测机多少秒没有任何动静，就认为它失联，把它手上的任务交给别的评测机 | 调小：评测机宕机后恢复得更快，但网络抖动时可能误判，同一份提交被评两次（结果以后一次为准）。调大：反之。一份提交被回收 3 次后判为评测失败 |
| `socket.port` | `233` | 网站通知评测机“更新评测程序”时连接的端口 | 只在 6.6 节的场景用到；用到时要和评测机的 `SOCKET_PORT`（默认 2333）一致。注意默认值不一致，用到时要改 |
| `socket.password` | `_judger_socket_password_` | 上述通知的口令 | 要和评测机的 `SOCKET_PASSWORD` 一致 |

### 4.7 `data`：题目数据

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `kept-versions` | `5` | 每道题保留最近几个数据版本的归档文件（含当前版本） | 调大：占更多磁盘，但能取回更早的数据。调小：下次同步数据时会清掉多出的旧归档。每个版本的记录（谁在何时发布、哈希值）永久保留，不受影响 |

### 4.8 `user`：用户

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `username-change-interval` | `30` | 本站注册的用户改一次用户名后，要等多少天才能再改 | `0` 表示不限制。通过统一身份认证创建的用户不能自己改用户名，不受此项影响；管理员改名也不受限 |

### 4.9 `sso`：统一身份认证

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `providers` | `[]` | 认证提供方列表，支持 CAS 和 OAuth2 / OIDC | 为空时登录页只有用户名密码登录。配置方法和每个字段见 [sso.md](sso.md)。要在学校登记的回调地址是 `<本站地址>/login/sso/<键名>/callback` |
| `reserved-username-pattern` | `'/^[A-Za-z]{2}[0-9]{8}$/'` | 一个正则表达式。符合它的用户名只能由统一身份认证创建，本站注册和改名都不允许 | 默认值是“两位字母 + 8 位数字”，即学号的格式，防止有人抢注别人的学号。学号格式变了要同步改；设为 `''` 关闭这项保护。只在配置了 `providers` 之后生效 |

### 4.10 `homework`：作业

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `settle-grace` | `1800` | 作业截止时如果还有截止前交的提交没评完，最多等多少秒再结算 | 调大：更可能等到所有提交评完再出正式成绩，但评测机出故障时成绩出得晚。调小：成绩出得快，但可能有提交没算进去（页面会提示，教师可以重新结算） |

### 4.11 `backup`：备份位置

| 键 | 默认 | 含义 | 改了意味着什么 |
|---|---|---|---|
| `path` | `/var/uoj_backup` | 备份在**网站容器内**的目录 | 一般不用改：要换存放位置，改 `docker-compose.yml` 里挂载到这个目录的宿主机路径即可。备份的开关、时间、保留天数在网页上设置，见第 5 节 |

### 4.12 `switch` 和 `tools`

| 键 | 默认 | 含义 |
|---|---|---|
| `switch.blog-domain-mode` | `3` | 博客地址的形式。`3`：主站下的 `/blog/用户名`（推荐，不需要额外的域名）；`2`：独立的博客域名加路径 `blog域名/用户名`；`1`：每个用户一个子域名 `用户名.blog域名`（需要泛域名解析和泛域名证书） |
| `switch.web-analytics` | `false` | 是否在页面里加入统计代码。要先把 `web/app/views/page-header.php` 里的统计代码换成自己的再开启 |
| `tools.map-copy-enabled` | `false` | 工具页里的“复制”按钮。浏览器只允许在 HTTPS 下复制，所以只在 HTTPS 下开启 |

---

## 5. 网页上的设置

以下设置存在数据库里，在页面上改，立即生效。入口都在右上角用户名 → “系统管理”。

| 页面 | 谁能用 | 能做什么 |
|---|---|---|
| 用户操作 | 系统管理员、OJ 管理员（部分） | 授予 / 取消全站角色（OJ 管理员、教师、可创建域）、封禁和解封、修改用户名。角色的含义见 [permissions.md](permissions.md)。授予角色和改用户名只有系统管理员能做 |
| 评测机管理 | 系统管理员 | 添加、删除、启用 / 停用评测机，查看心跳和版本。见第 6 节 |
| 站点设置 | 系统管理员 | 运行时可以改的全站设置，保存即生效，每次修改都记入审计日志。见下表 |
| 运行状态 | 管理员 | 未恢复的告警、每台评测机是否在线、正在评什么、近一小时评了多少、评测队列长度、最近的告警记录。见 [10. 日常运维](#10-日常运维) |
| 审计日志 | 系统管理员 | 查看谁在什么时候改了什么，可按操作者、对象筛选 |
| 博客管理、提交记录、自定义测试、点赞管理、搜索管理、Paste 管理 | 管理员 | 原有的管理功能 |

“站点设置”里的项目：

| 分组 | 设置 | 默认 | 含义 |
|---|---|---|---|
| 域 | 允许所有登录用户创建域 | 关 | 关：只有管理员、教师和“可创建域”角色能创建域。开：任何登录用户都能创建，并在自己的域里新建题目、上传数据、布置作业和举办比赛，这会占用磁盘和评测机 |
| 发信邮箱 | SMTP 服务器 | 空 | 例如 `smtp.exmail.qq.com`。留空表示使用配置文件里的 `mail.noreply` |
| 发信邮箱 | 端口 | 465 | SSL 一般是 465，STARTTLS 一般是 587 |
| 发信邮箱 | 加密方式 | SSL | SSL、STARTTLS，或不加密（只应在内网的邮件服务器上使用） |
| 发信邮箱 | 邮箱地址 | 空 | 发件人地址，同时是登录 SMTP 的用户名 |
| 发信邮箱 | 密码或授权码 | 空 | 保存后不再显示，也不写入审计日志；留空表示不修改。它以明文存在数据库里（发信时要用），所以请用邮箱的“授权码”而不是登录密码 |
| 发信邮箱 | 发件人名称 | 空 | 留空则用站点简称 |
| 备份 | 每天自动备份 | 开 | 见 [8. 数据、备份与恢复](#8-数据备份与恢复) |
| 备份 | 每天几点开始备份 | 3 | 0–23。选一个没有比赛和作业截止的时间：备份期间数据库有几秒到几十秒不能写入 |
| 备份 | 备份保留多少天 | 7 | 1–365。更早的备份在每次备份成功后删除，最新的一份总是保留 |
| 告警 | 告警同时发邮件 | 关 | 告警出现和恢复时，系统管理员总会收到站内消息；开启后还会发邮件。需要先设好发信邮箱 |
| 告警 | 告警邮件的收件人 | 空 | 多个地址用逗号分隔。留空则发给所有系统管理员在个人资料里填的邮箱 |
| 告警 | 评测机多久没有响应算离线（秒） | 120 | 30–86400。评测机正常时每隔几秒联系一次网站；调得太小，网络抖动时会误报 |
| 告警 | 新提交等待评测多久算积压（秒） | 600 | 60–86400。最早的一份新提交等待超过这个时间就告警；重测的提交不计入 |

页面底部的“发一封测试邮件”用**已保存**的设置发送，失败时会显示邮件服务器返回的原因。

域内部的设置（成员、邀请、作业、训练等）在各个域自己的页面里，见 [domains.md](domains.md)。

---

## 6. 评测机

### 6.1 评测机怎么工作

每台评测机启动后不停地向网站要任务（`POST /judge/submit`），每个请求都带着自己的名字和密码。
拿到提交后，它按需下载这道题当前版本的数据（校验 SHA-256，本地缓存），在沙箱里编译、运行，
把进度和结果回报给网站。网站记下每份提交是哪台评测机、用哪个数据版本评的。

网站根据“最近心跳”判断评测机是否在线；失联超过 `judger.task-timeout` 秒，它手上的任务会被别的评测机接走。

### 6.2 更换默认评测机的密码

数据库初始化时登记了一台 `compose_judger`，密码是公开的默认值，上线前要换：

1. “系统管理 → 评测机管理” → 删除 `compose_judger`。
2. 添加一台新的（名字可以还叫 `compose_judger`）。页面会显示一个随机密码，**只显示这一次**，数据库里只存它的哈希。
3. 把名字和密码写进 `docker-compose.yml` 的 `JUDGER_NAME`、`JUDGER_PASSWORD`。
4. 重建评测机容器：

```bash
docker compose up -d --force-recreate uoj-judger
```

密码丢了没法找回，只能删除后重新添加。

### 6.3 再加一台评测机（同一台机器）

先在“评测机管理”里添加，记下密码。然后在 `docker-compose.yml` 里照着 `uoj-judger` 再写一个服务，
改四处：服务名、`container_name`、日志目录、`JUDGER_NAME` 和 `JUDGER_PASSWORD`。例如：

```yaml
  uoj-judger-2:
    image: ghcr.io/universaloj/uoj-judger:latest
    container_name: uoj-judger-2
    restart: always
    stdin_open: true
    tty: true
    cap_add:
      - SYS_PTRACE
    cpuset: "4,5"          # 和第一台用不同的核
    depends_on:
      - uoj-web
    volumes:
      - ./uoj_data/judger2/log:/opt/uoj_judger/log
    environment:
      - UOJ_PROTOCOL=http
      - UOJ_HOST=uoj-web
      - JUDGER_NAME=judger_2
      - JUDGER_PASSWORD=（添加时页面显示的密码）
      - SOCKET_PORT=2333
      - SOCKET_PASSWORD=_judger_socket_password_
```

然后 `docker compose up -d`。几秒后它会出现在评测机列表里并开始接任务。

多台评测机会让同一道题在不同机器上评测。**机器性能不同会导致同一份代码的运行时间不同**，
所以建议所有评测机用相同的硬件，并用 `cpuset` 让每台独占 CPU 核。

### 6.4 评测机放在另一台机器上

在那台机器上放一份本仓库，只构建和启动评测机：

```bash
docker compose build uoj-judger
```

把 `docker-compose.yml` 里 `uoj-judger` 的 `UOJ_HOST` 改成它能访问到的网站地址
（走 HTTPS 就把 `UOJ_PROTOCOL` 改成 `https`），填好名字和密码，然后只启动评测机
（`--no-deps` 表示不要顺带启动网站和数据库）：

```bash
docker compose up -d --no-deps uoj-judger
```

网络上只需要“评测机 → 网站”这一个方向通。评测机和网站之间传输题目数据和选手代码，
跨公网时务必走 HTTPS。

### 6.5 停用、维护

- **停用**：“评测机管理”里切换启用状态。停用的评测机会评完手上的任务，不再接新任务。适合维护前先排空。
- **看日志**：`uoj_data/judger/log/judge.log`，或 `docker compose logs uoj-judger`。
- **重启**：`docker compose restart uoj-judger`。正在评的提交会在超时后被回收重评。
- **版本**：评测机列表里有每台的版本。网站和评测机必须是同一个版本的代码，升级时一起升（见第 9 节）。

### 6.6 控制端口（`SOCKET_PORT`）是干什么的

评测机在这个端口上接受两个命令：`stop`（停止）和 `update`（从网站重新下载评测程序并重新编译）。
容器内可以这样用：

```bash
docker compose exec -u judger uoj-judger /opt/uoj_judger/judge_client stop
```

网站一侧的 `judger.socket.port / password` 只在一种情况下用到：名为 `main_judger` 的评测机更新后，
网站会去通知 `judger_info` 表里登记了 IP 的其他评测机也更新。用 Docker 部署时，更新评测机的方式是
重新构建镜像，用不到这套机制；通过“评测机管理”页面添加的评测机不登记 IP，也不会被通知。
所以默认情况下这个端口只在容器内部使用，不需要对外开放。

### 6.7 支持的语言

C（C89 / C99 / C11 / C17 / C23）、C++（98 / 03 / 11 / 14 / 17 / 20 / 23 / 26，GCC 14）、
Java（8 / 11 / 17 / 21 的语言级别，OpenJDK 21）、Pascal、Python 2、Python 3。
编译器装在评测机镜像里，要换版本需改 `judger/Dockerfile` 后重新构建。

---

## 7. HTTPS 与反向代理

网站容器只提供 HTTP。要用 HTTPS，在前面放一个反向代理（Nginx、Caddy 等）来终结 TLS。

1. 让网站只监听本机：`docker-compose.yml` 里把 `"80:80"` 改成 `"127.0.0.1:8080:80"`，然后 `docker compose up -d`。
2. 配置反向代理。Nginx 示例：

```nginx
server {
    listen 443 ssl;
    server_name oj.example.edu.cn;
    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    client_max_body_size 1000m;      # 题目数据包可能很大；网站自身的上限是 1000M

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_read_timeout 300s;
    }
}
server {
    listen 80;
    server_name oj.example.edu.cn;
    return 301 https://$host$request_uri;
}
```

3. 改 `.config.local.php`：

```php
'web' => [
	'domain' => null,
	// 网站容器看到的代理地址：代理在宿主机上时，是 compose 网络的网关，可以用下面的命令查
	'trusted-proxies' => ['172.18.0.1'],
	'main' => ['protocol' => 'https', 'host' => 'oj.example.edu.cn', 'port' => 443],
	'blog' => ['protocol' => 'https', 'host' => 'oj.example.edu.cn', 'port' => 443],
],
```

查代理在网站容器眼里的地址：访问一次网站，然后看访问日志的第一列。

```bash
docker compose exec uoj-web tail -n 3 /var/log/apache2/uoj_access.log
```

4. 同一台机器上的评测机继续用 `UOJ_PROTOCOL=http`、`UOJ_HOST=uoj-web` 直连网站容器，不经过代理。

---

## 8. 数据、备份与恢复

### 8.1 有哪些数据

全部在仓库目录下：

| 路径 | 内容 | 丢了会怎样 |
|---|---|---|
| `uoj_data/db/mysql/` | 整个数据库：用户、题目信息、提交记录、比赛、域、作业、成绩快照、审计日志 | 全部丢失 |
| `uoj_data/web/data/` | 题目的测试数据和各版本归档 | 所有题目无法评测 |
| `uoj_data/web/storage/` | 所有提交的源代码 | 提交记录还在，但看不到代码、不能重测 |
| `uoj_data/backup/` | 网站自己做的备份（见下） | — |
| `.config.local.php` | 配置和盐值 | 盐值丢了，所有本站密码失效 |
| `docker-compose.yml` | 评测机密码等 | 可以重建，评测机密码需重新生成 |

`uoj_data/judger*/log/` 是日志，不需要备份。

### 8.2 自动备份

网站每天自动备份一次（默认凌晨 3 点；那时网站没开就在当天开机后补做），放在 `uoj_data/backup/`，
每次一个目录：

```text
uoj_data/backup/uoj-20261004-030000/
    db.sql.gz       整个数据库
    data/           题目数据
    storage/        所有提交的代码（不含临时文件）
    manifest.json   清单：每张表的行数、文件个数和大小
    .complete       最后写入；没有它的目录是被中断的备份，会被自动清理
```

- **不重复占空间**：和上一次备份相比没有变化的文件是硬链接，一次备份只多占“变化的部分”加一份数据库导出。
- **保留天数**：默认 7 天，在“站点设置 → 备份”里改。
- **对网站的影响**：导出数据库期间所有表只读，写操作（提交、评测结果）会等几秒到几十秒。
- **失败会告警**：最近一次备份失败，或者开着自动备份却超过一天半没有成功备份，管理员会收到告警（站内消息、页面顶部提示、可选邮件）。
- **在哪看**：“系统管理 → 运行状态 → 备份”列出最近的备份、大小和状态，系统管理员可以点“立即备份”。

**备份和网站在同一台机器、默认还在同一块盘上，防不了硬盘损坏和机房事故。**
请把 `uoj_data/backup/` 定期同步到另一台机器，同步时保留硬链接，例如：

```bash
rsync -aH --delete uoj_data/backup/ backup-host:/srv/uoj-backup/
```

也可以把 `docker-compose.yml` 里 `./uoj_data/backup` 换成另一块盘上的路径。
另外请自己保存一份 `.config.local.php` 和 `docker-compose.yml`，它们不在自动备份里。

手动操作：

```bash
docker compose exec uoj-web php /opt/uoj/web/app/cli.php backup:run      # 立即备份
```

```bash
docker compose exec uoj-web php /opt/uoj/web/app/cli.php backup:list     # 列出现有的备份
```

### 8.3 恢复演练

```bash
docker compose exec uoj-web php /opt/uoj/web/app/cli.php backup:verify
```

它把最新的备份（或指定名字的备份）导入一个临时数据库 `app_uoj233_verify`，逐表核对行数是否和清单一致，
再核对文件个数和大小，最后删掉临时数据库。全过程不影响正在运行的网站。输出 `can be restored` 即通过，
通过的时间会显示在“运行状态”页的“演练”一栏。**建议每月做一次，升级之前也做一次。**

### 8.4 恢复

```bash
bash restore.sh                         # 列出备份
```

```bash
bash restore.sh uoj-20261004-030000     # 恢复到这个备份
```

脚本会要求再输入一遍备份名确认，然后：停掉评测机 → 用备份替换数据库和文件 → 执行数据库升级
（备份可能比当前代码旧）→ 重启网站和评测机。**备份之后发生的一切（新的提交、新用户、改过的题目）都会丢失。**

迁移到新机器：把仓库、`.config.local.php`、`docker-compose.yml` 和 `uoj_data/backup/` 里要用的那个备份目录
放到新机器，`docker compose build && docker compose up -d` 起一个空站，再 `bash restore.sh <备份名>`。
也可以像以前一样直接把整个 `uoj_data/` 原样搬过去。

---

## 9. 升级

```bash
git pull                       # 或切换到新的分支
docker compose build
docker compose up -d
```

- 网站容器启动时自动执行数据库升级（`web/app/upgrade/` 下按编号排列的迁移），已执行过的不会重复执行。
  升级失败时容器会退出，看 `docker compose logs uoj-web`。
- **网站和所有评测机要一起升级。** 评测协议变了的版本，旧评测机会被拒绝。别的机器上的评测机同样要重新构建。
- 升级前先备份数据库。有的迁移不可逆（例如把评测机密码改存哈希）。
- 提交很多时，改动 `submissions` 表的迁移会比较慢，请安排在没有比赛和作业截止的时段。
- `.config.local.php` 不需要改：新增的设置自动取默认值。

---

## 10. 日常运维

### 命令行工具

```bash
docker compose exec uoj-web php /opt/uoj/web/app/cli.php <命令>
```

| 命令 | 作用 |
|---|---|
| `upgrade:latest` | 执行所有还没执行的数据库升级。容器启动时会自动跑 |
| `upgrade:up <名字>` / `upgrade:down <名字>` | 单独执行 / 回退某一个升级（名字是 `web/app/upgrade/` 下的目录名）。回退会删表删列，仅用于开发 |
| `upgrade:refresh <名字>` / `upgrade:remove <名字>` / `upgrade:remove-all` | 开发用，会丢数据，生产环境不要用 |
| `user:finish-renames` | 补完被中断的改用户名操作。容器启动时会自动跑 |
| `site:tick` | 网站每分钟自己要做的事：推进作业的发布和结算、到点启动备份、检查评测机和评测队列并发告警。容器里每分钟自动跑一次；不用容器部署时请放进 crontab |
| `homework:tick` | 只做其中推进作业的那部分 |
| `backup:run` / `backup:list` | 立即备份 / 列出现有的备份 |
| `backup:verify [备份名]` | 恢复演练，见 8.3 |
| `backup:restore <备份名> --yes` | 用备份替换数据库和文件。请用仓库根目录的 `restore.sh`，它会先停评测机、之后升级数据库 |
| `help` | 列出所有命令 |

### 日志在哪

| 日志 | 位置 |
|---|---|
| 网站错误 | 容器内 `/var/log/apache2/uoj_error.log` |
| 网站访问 | 容器内 `/var/log/apache2/uoj_access.log` |
| 评测机的请求 | 容器内 `/var/log/apache2/uoj_judge.log` |
| 评测机 | 宿主机 `uoj_data/judger/log/judge.log` |
| 谁改了什么 | 系统管理 → 审计日志 |

```bash
docker compose exec uoj-web tail -f /var/log/apache2/uoj_error.log
```

### 监控与告警

网站每分钟检查一次，发现下面的情况就告警；情况消失后告警自动恢复：

| 告警 | 什么时候出现 |
|---|---|
| 评测机离线 | 某台启用的评测机超过设定时间没有联系网站（其他评测机还在线） |
| 评测机全部离线 | 没有任何一台启用的评测机在线，提交无法评测 |
| 评测积压 | 最早的一份新提交等待评测超过设定时间 |
| 备份失败 | 最近一次备份失败（下一次成功后恢复） |
| 备份过期 | 开着自动备份，却超过一天半没有成功备份 |

告警出现和恢复时：所有系统管理员收到一条站内消息；管理员登录后每个页面顶部有红色提示条；
在“站点设置 → 告警”里开启后还会发邮件。当前状态和历史在“系统管理 → 运行状态”。
停用的评测机和从未连接过的评测机不会触发“离线”告警。

告警邮件里带不带网站链接取决于 `web.main.host`：写成了固定域名才带（定时任务里没有请求可以取地址）。

### 时间

三个容器都用 `Asia/Shanghai`。比赛和作业的开始、截止以网站容器的时钟为准，请保证宿主机时间准确（开启 NTP）。

---

## 11. 接口一览

### 端口

| 端口 | 在哪 | 谁连它 | 是否需要对外开放 |
|---|---|---|---|
| 80 | `uoj-web` | 浏览器（或反向代理）、评测机 | 是（或只对反向代理开放） |
| 3306 | `uoj-db` | `uoj-web` | 否，默认只在 compose 内部网络可见 |
| 2333 | 每台评测机 | 评测机自己（`judge_client stop / update`） | 否 |
| 3690 | `uoj-web` | 无（遗留） | 否，可从 compose 文件里删掉 |

### 评测机调用的 HTTP 接口

都是 `POST`，都要带 `judger_name` 和 `password`，认证失败返回 403。不要在反向代理上拦截 `/judge/` 路径。

| 路径 | 作用 |
|---|---|
| `/judge/submit` | 评测机和网站的全部交互：心跳、领取任务、回报进度和结果。请求里带协议版本号，版本过低会被拒绝 |
| `/judge/download/problem/{题号}` 和 `/{版本}` | 下载题目数据 |
| `/judge/download/submission/{id}/{随机串}` | 下载一份提交的内容 |
| `/judge/download/tmp/{随机串}` | 下载自定义测试、hack 的临时文件 |
| `/judge/download/judger` | 下载评测程序（`update` 命令用） |
| `/judge/sync-judge-client` | 见 6.6 |

### 统一身份认证相关的地址

| 路径 | 作用 |
|---|---|
| `/login/sso/{键名}` | 跳转到学校的登录页 |
| `/login/sso/{键名}/callback` | 学校登录完跳回来的地址，**需要在学校那边登记** |
| `/login/sso/{键名}/bind` | 学号对应的用户名已被本站账号占用时，输入该账号密码进行绑定 |

---

## 12. 常见问题

### 12.1 怎么改数据库密码

数据库镜像在第一次初始化时把 root 密码固定设成了 `root`，只改 `MYSQL_ROOT_PASSWORD` 不起作用。
数据库端口默认不对外，同一个 compose 网络之外连不上；如果仍然要改：

1. 在数据库里改密码（把 `新密码` 换掉）：

```bash
docker compose exec uoj-db mysql -uroot -proot -e "ALTER USER 'root'@'%' IDENTIFIED BY '新密码'; ALTER USER 'root'@'localhost' IDENTIFIED BY '新密码';"
```

2. 把 `.config.local.php` 里的 `database.password` 改成新密码（立即生效）。
3. 把 `docker-compose.yml` 里 `uoj-db` 的 `MYSQL_ROOT_PASSWORD` 改成新密码（健康检查要用），然后 `docker compose up -d`。

### 12.2 页面里的链接、跳转指向了错误的地址或端口

`web.main` 的 `protocol / host / port` 和实际访问地址不一致。前面有反向代理时还要检查代理是否把
原始的 `Host` 传了过来，以及 `trusted-proxies` 是否包含代理的地址。

### 12.3 登录后马上又变成未登录

通常是 `web.main.protocol` 写了 `https`，但实际用 HTTP 访问（Cookie 带了 `Secure`，浏览器不发送）；
或者 `web.main.host` 写的域名和实际访问的不是同一个。

### 12.4 提交一直是 Waiting

依次检查：“评测机管理”里有没有启用的评测机、心跳是不是最近的；评测机日志里有没有
`judger authentication failed`（名字或密码不对，改了环境变量后有没有重建容器）；评测机能不能访问
`UOJ_HOST`；网站和评测机是不是同一个版本。

### 12.5 题目数据同步后一直显示“准备中”

带自定义校验器、标程等需要编译的题，数据由评测机负责构建，需要至少一台在线的评测机。
没有评测机在线时会一直等待。

### 12.6 上传大的数据包失败

网站自身允许 1000M。失败多半是反向代理的限制（Nginx 的 `client_max_body_size`）或超时。

### 12.7 重建网站容器后所有人都要重新登录

登录会话存在容器内部，重建容器会清空。这是正常现象；勾了“记住我”的用户不受影响。

### 12.8 第一个注册的人不是我，管理员被别人拿到了

在数据库里把自己设为系统管理员、把对方改回普通用户（`S` 是系统管理员，`U` 是普通用户）：

```bash
docker compose exec uoj-db mysql -uroot -proot app_uoj233 -e "UPDATE user_info SET usergroup='S' WHERE username='你的用户名'; UPDATE user_info SET usergroup='U' WHERE username='对方的用户名';"
```

### 12.9 能不能不用 Docker

仓库里的 `web/install.sh`、`judger/install.sh` 保留了上游的裸机安装步骤，但本仓库的所有改动只在
Docker 镜像里测试过。建议用 Docker 部署。不用容器时要自己加一条每分钟执行 `homework:tick` 的 cron。
