# UOJ 学校版改造：代码审计结论与实施计划（第一轮）

## Context

依据 `uoj_school_oj_plan_prompt.md`，对 `UniversalOJ/UOJ-System` master（`1fd7777`，2026-03-19）做完整代码审计，并给出分阶段实施计划。目标是保留 UOJ 的 Problem / Contest / Submission / Judger 核心，补齐学校需要的判题可靠性、多评测机、权限、统一认证、课程训练与运维能力。

本轮已与你确认的三点约束：

- **本轮只交付审计与计划，不改任何代码。**
- **本轮不运行验证。** 本机是 Apple Silicon，无 Docker / PHP / MySQL；Judger 只支持 Linux x86_64（`run_program_sandbox.h:87` 有 `#ifndef __x86_64__`）。因此下文所有结论均为**静态审计结论**，凡依赖运行时行为的都单独标注「需运行复现」。
- **修复独立实现**，不 cherry-pick 上游未合并的 PR #153；下文会指出我们的方案与它有意不同的地方。

批准本计划后的唯一动作：把本文档原样存入仓库 `docs/school-oj-audit-plan.md`（不提交、不改代码），便于和提示词放在一起。

---

## A. 当前架构

| 模块 | 主要文件 | 说明 |
|---|---|---|
| Web 入口 | `web/index.php` → `web/app/libs/uoj-lib.php` → `web/app/route.php` → `web/app/controllers/*.php` → `web/app/views/` | PHP 7.4 + Apache。`uoj-lib.php` 依次初始化 Session、UOJTime、DB、Auth |
| DB | `db/app_uoj233.sql`、`web/app/models/DB.php` | MySQL 5.7，23 张表，几乎全是 MyISAM；`user_info` 主键是 `username`，**没有数字 user id**；sql_mode 非 strict（`db/install.sh:15`），超长字段会被静默截断 |
| Auth | `models/Auth.php`、`Session.php`、`Cookie.php`、`controllers/login.php`、`register.php`、`libs/uoj-security-lib.php` | session + remember token；密码 `md5(username . 前端HMAC)`；`usergroup` 只有 U / S / B |
| Permission | `libs/uoj-utility-lib.php:132`（`isSuperUser`）、`libs/uoj-query-lib.php`（`hasProblemPermission`、`hasContestPermission`、各 `is*VisibleToUser`） | 二元模型：全站 superuser + 按题/按比赛的 `*_permissions` 表 |
| Problem | `controllers/problem*.php`、`problem_set.php` | 表 `problems`、`problems_contents`、`problems_tags`、`problems_permissions` |
| Problem Data | `libs/uoj-data-lib.php`（`SyncProblemDataHandler`）、`controllers/problem_data_manage.php` | `/var/uoj_data/upload/{id}`（源）→ `prepare_{id}` → `/var/uoj_data/{id}`（发布）→ `/var/uoj_data/{id}.zip`（给 Judge）；`{id}/download.zip` 是给用户的附件包 |
| Contest | `controllers/contest_*.php`、`add_contest.php`、`libs/uoj-contest-lib.php` | 表 `contests`、`contests_*`；**社区版没有任何 VP 代码** |
| Submission | `controllers/problem.php`（提交）、`submission.php`、`hack.php`、`libs/uoj-html-lib.php` | 表 `submissions`、`custom_test_submissions`、`hacks`；状态机 Waiting → Judging → Judged，比赛另有 `Judged, Waiting` / `Judged, Judging` |
| Judge API | `controllers/judge/submit.php`、`judge/download.php`、`judge/sync_judge_client.php`、`libs/uoj-judger-lib.php` | Judge 每 2 秒 POST `/judge/submit` 轮询领任务并回传结果；认证为 `judger_info` 表明文密码 |
| Judge Client | `judger/judge_client`（Python，单线程） | 一次只评一个 submission；`update_problem_data` 按 mtime 比较决定是否重新下载数据 |
| Judger | `judger/uoj_judger/main_judger.cpp`（V2 头）→ `builtin/judger/judger.cpp`（**V1 头**）或题目自带 `judger` → `run/run_program`（ptrace + seccomp）、`run/compile`、`run/run_interaction`、`builtin/checker/*` | `include/uoj_judger.h` 是 V1，`uoj_judger_v2.h` 是 V2，两套并存 |
| Migration | `models/Upgrader.php`、`app/cli.php`、`app/upgrade/` | 机制存在，但目录里只有 `create_table_upgrades.sql`，没有任何 migration |
| 测试 / CI | `.github/workflows/build.yml`、`lint.yml` | 只构建镜像和跑 php-cs-fixer；**仓库没有任何测试**，`Makefile:33,52-54` 引用的 `tests/` 目录不存在 |

关键调用链：

```text
提交: problem.php handleUpload → insert submissions(status=Waiting)
领取: judge_client send_and_fetch → POST /judge/submit → findSubmissionToJudge（UPDATE ... WHERE status='Waiting' 抢占）
数据: judge_client update_problem_data → POST /judge/download/problem/{id} → /var/uoj_data/{id}.zip → unzip
评测: main_judger(V2) → judger(V1/自定义) → run_program → result.txt
回传: POST /judge/submit (submit=1) → submissionJudged / customTestSubmissionJudged / hackJudged
Hack 成功: hackJudged → dataAddExtraTest → dataSyncProblemData → rejudgeProblemAC
```

---

## B. Bug 确认表

### 汇总

| # | 问题 | 现状 | 上游 |
|---|---|---|---|
| 2.1 | V2 `stack_limit` 默认值 | **存在**（潜伏，只影响 V2 自定义 judger） | PR #153 未合并 |
| 2.2 | Hack 开启/成功后丢权限上下文 | **存在**（仅 `use_builtin_judger` 关闭的题） | PR #153 未合并，其修法有提权隐患 |
| 2.3 | 远程 Judge 下载错数据包 | **不存在**，master 下载的就是完整 `{id}.zip`；但下载链路有别的缺陷 | PR #153 只是改名；issue #134 未解决 |
| 2.4 | Hack 数据文本模式读取 | **存在**，且重试时会上传空文件 | PR #153 未合并 |
| 2.5 | 详情超过 BLOB 上限 | **存在** | PR #153 未合并 |
| 2.6 | migration 不支持远程 MySQL | **存在**，默认 docker-compose 下首步就失败 | PR #153 只补了 `-h` |
| 2.7 | 多评测机数据同步 | **存在多处缺陷** | issue #134 未解决 |
| 2.8 | Custom Test 内存限制 | **代码上未见差异，需运行复现** | issue #132，称已随 #151 修复 |
| 3.1 | ZIP 目录处理 | **存在** | issue #106，称已修但逻辑仍在 |
| 3.2 | 过度依赖 `isSuperUser()` | **存在** | issue #47 未解决 |
| 3.3 | 相对 URL / 反代 / 子路径 | **子路径不支持**，反代部分可用 | issue #54 未解决 |
| 3.4 | Web 容器编译不可信代码 | **存在** | issue #142 未解决 |

### 逐项说明

**2.1 `stack_limit`**
- 位置：`judger/uoj_judger/include/uoj_judger_v2.h:613`，`conf_int(pre + "stack_limit", num, val.real_time)`。V1 的 `uoj_judger.h:831` 是对的。
- 触发：`val.real_time != -1` 的嵌套默认值路径，即题目配置了 `real_time_limit` 且走 `v2.h:1832-1864`（hack 跑 std）或 `v2.h:2000`（`program["std"]`）。`main_judger.cpp:11` 虽用 V2，但 `RL_JUDGER_DEFAULT.real_time = -1`，不受影响；builtin judger 用 V1，不受影响。
- 影响：std 的栈被设成 `(int)real_time_limit` MiB（`run_program.cpp:156` 再与内存取小），深递归 std 直接 SIGSEGV，表现为「Standard Program Runtime Error」。
- 修复：一行改为 `val.stack`。

**2.2 Hack 权限上下文**
- 位置：`problem_data_manage.php:470`（开关 Hack 时没传 `$myUser`）、`uoj-data-lib.php:374`（`dataAddExtraTest` 没传 user）、`uoj-data-lib.php:311`（`isSuperUser($this->user)` 判定）。
- 触发：题目 `use_builtin_judger` 不是 on。此时 user 为 null → 抛「use_builtin_judger must be on」。
- 影响：管理员开不了 Hack；Hack 成功后同步失败，只写一条 `error_log`，历史 AC 不重测。builtin judger 题不受影响。
- 修复：引入显式同步上下文（用户触发 / 系统触发）。**与 PR #153 不同**：它在系统上下文里无条件放行自定义 judger，这会让非 superuser 的题目管理者先上传恶意 Makefile、再靠一次成功 Hack 触发系统同步，而自定义 judger 在评测机上以 `--unsafe` 运行（`main_judger.cpp:12`）。我们的做法是：superuser 同步成功时记录「judger 相关文件」的 SHA256 批准指纹，系统上下文只在指纹未变时沿用自定义 judger；失败要写入 Hack 详情并给管理员发系统消息。

**2.3 Judge 下载的数据包**
- 结论：`route.php:81` → `judge/download.php:17-24` 返回 `/var/uoj_data/$id.zip`；用户附件走另一条路由 `download.php:26`。两者没有混淆，**不要重复修改**。
- 实际缺陷归入 2.7。issue #134 的「额外 Judge 无数据、串行评测」更可能是认证失败被静默吞掉（见 2.7 第 4 点），需运行复现确认。

**2.4 Hack 二进制数据**
- 位置：`judger/judge_client:336-339` 用 `"r"` 打开。
- 影响：非 UTF-8 字节抛 `UnicodeDecodeError`；CRLF 被改写；`:355-364` 的重试循环复用已读到 EOF 的句柄，**重试时上传空文件**；句柄不关闭。
- 另一个相关点：同步时 `uoj-data-lib.php:71-75` 会用 `run/formatter` 改写数据（去行末空格和 CR、补换行），除非题目 `extra_config` 设了 `dont_use_formatter`。所以二进制 Hack 数据端到端保真还需要这个开关。
- 修复：成功 Hack 时一次性以 `"rb"` 读成 bytes，重试复用同一份 bytes，并记录 SHA256。

**2.5 详情超 BLOB**
- 位置：`db/app_uoj233.sql` 中 `submissions.result`、`custom_test_submissions.result`、`hacks.details` 均为 `blob`。
- 影响：非 strict 模式下静默截断成非法 JSON，详情页无法显示；比赛的 `first_test_config` 路径（`judge/submit.php:19-30`）会 `json_decode` 失败。约 700 个测试点即触发。
- 同类隐患：`contests.extra_config varchar(200)`、`problems.extra_config varchar(500)` 也存 JSON，同样会被截断。
- 改成 LONGBLOB 后的下一个上限：MySQL 5.7 默认 `max_allowed_packet` 4MB，超过后 UPDATE 失败，而 `judge/submit.php:36-38` 不检查返回值，提交会**永远卡在 Judging**。
- 修复：migration 扩字段；DB 配置 `max_allowed_packet=64M`；Web 检查 UPDATE 结果，失败时写入降级结果。

**2.6 migration 远程 MySQL**
- 位置：`Upgrader.php:9-12` 调 `mysql` 命令行，不带 host / port / socket；`DB.php:6` 把 `:3306` 写死。
- 影响：默认 docker-compose（DB 在 `uoj-db`）下，`cli.php upgrade:latest` 第一步建 `upgrades` 表就失败。失败用 `die()`，退出码是 0，`web/install.sh:72` 也不检查，安装继续。
- 其他：密码出现在命令行参数里；`Upgrader.php:31` 的 `LOCK TABLES upgrades WRITE` 会让 `upgrade.php` 无法访问其他表；`Upgrader.php:119` 报错信息用错了变量。
- 修复：**与 PR #153 不同**（它只补 `-h`）。改用 mysqli 直接执行 SQL 文件，配置增加 `port` / `socket`，支持 IPv6；锁改为 `GET_LOCK`；失败以非 0 退出；容器每次启动都跑 `upgrade:latest`，失败则不启动 Apache。

**2.7 多评测机数据同步**（静态审计确认的缺陷）
1. `judge_client:301-307` 下载不检查 HTTP 状态，404 页面会被当成 zip 存盘。
2. `judge_client:226-239` 先删旧目录再下载解压，非原子；解压中途失败会留下 mtime 较新的残缺目录，之后被当作最新数据**静默使用**。
3. 版本判断靠 mtime（`judge/submit.php:201` 对比 `judge_client:222`），依赖两端时钟，同一秒内两次同步无法区分，没有内容校验。
4. 认证失败与「没有任务」无法区分：`judge_client:366-372` 把任何非 JSON 响应都当作 Nothing to judge；`uoj-judger-lib.php:15` 用 `==` 比较密码。
5. Web 端发布非原子：`uoj-data-lib.php:344-347` 删目录 → rename → 删 zip → 重新 zip，中间窗口内 Judge 会拿到 404 或不存在的目录。
6. 没有记录是哪台 Judge 领走了任务，Judge 崩溃后提交永远停在 Judging，没有超时回收。
7. `submissions` 表没有 `status` 索引，每台 Judge 每 2 秒一次全表扫描。

**2.8 Custom Test 内存**
- 静态结论：正式提交（`uoj_judger.h:1409`）、Custom Test（`:1558-1566`）、Extra Test（`test_point(-i)`）最终都走 `run_submission_program` → `run_program --ml`，内存语义一致；差别只有 Custom Test 的时间限制 +2 秒。
- 上游 issue #132 称已随 #151 修复。按提示词要求，实施时先用 64 MiB 题 + 持续申请内存的程序跑三种模式复现，再决定是否需要修；无论结果如何都加入 conformance 用例防回归。
- 三者共有的语义风险：MLE 靠 `ru_maxrss` 判定（`run_program.cpp:467`），评测机有 swap 时可被绕过；只申请不触碰内存会撞 `RLIMIT_AS`（2×ML+64MiB）表现为 RE 而非 MLE。

**3.1 ZIP 处理**（`problem_data_manage.php:47-72`）
- flatten 逻辑（`:60`）：判断条件看的是整个 upload 目录而非本次 zip；未加引号的 `find` 输出遇到空格会分词；会把合法的 `require/`、`download/` 也拍平；`__MACOSX` 会混入根目录。
- 解压直接叠加到已有目录，旧文件不清理；无文件数、总大小、压缩比限制（`post_max_size` 1000M）；类型判断信任客户端 MIME；临时文件名用 `rand()`。
- 这两个原生表单（`:47` 上传、`:75` 写配置）**没有 CSRF 校验**；写 `problem.conf` 时未过滤换行，可注入任意配置项。
- `../`、绝对路径、symlink：PHP `ZipArchive::extractTo` 会规范化路径且不创建符号链接，预期安全，需运行确认。

**3.2 `isSuperUser` 依赖**（共约 30 处）
- 必须是全站 superuser 才能做的事：建比赛（`add_contest.php:4`）、进比赛管理页（`contest_manage.php:9`，尽管 `contests_permissions` 表已存在）、开始终测与公布成绩（`contest_inside.php:81`）、发比赛公告（`contest_inside.php:193`）、新建题目（`problem_set.php:6`）、删除提交（`submission.php:79`）、自定义 judger（`uoj-data-lib.php:311`）。
- 源码可见性：非比赛提交默认 `view_content_type = ALL`（`uoj-utility-lib.php:139`），即学生默认能看别人的作业源码。

**3.3 URL / 反代**
- `HTML::url()`（`models/HTML.php:79`）只由配置的 protocol / host / port 拼接，没有 base path 概念；另有约 78 处根相对 `href/action/src`、22 处跳转、9 处 JS 端点写死以 `/` 开头。**子路径部署目前不可行。**
- 反代：`UOJContext::httpHost()` 无条件信任 `X-Forwarded-Host`；不处理 `X-Forwarded-Proto`；`route.php:10` 要求 Host 等于配置的 host；Cookie 未设 Secure / SameSite。HTTPS 终止只能靠把配置里的 protocol 改成 https。

**3.4 Web 编译边界**
- `uoj-data-lib.php:87-134`（chk / std / val / interactor）和 `:135-161`（自定义 judger 的 `make`）在 Web 容器内执行，外面套了 `run_program --type=compiler` 沙箱；Web 容器因此需要 `SYS_PTRACE`（`docker-compose.yml`）并安装 gcc（`web/Dockerfile`）。
- 编出的二进制随 `{id}.zip` 分发给评测机，隐含要求 Web 与 Judge 镜像 ABI 一致。

### 审计中额外发现（提示词未列出）

- **负载相关的 TLE**：真实时间上限默认是 `time + 2` 秒（`run_program.cpp:153-155`），CPU 被抢占时 CPU 未超限的程序也会被判 TLE，直接违反「负载不能改变 Verdict」。另外 CPU 时间只算 user time（`:461-463`）。
- `judge/submit.php:36-38,67,77` 把 Judge 回传的 `status`、`error`、`score` 直接拼进 SQL。
- 登录不重置 session id（`Auth.php:16`，session fixation）；密码是 md5（issue #84）。
- `index.php` 用 `$_GET += $vars` 合并路由固定参数，查询串可以覆盖路由里写死的 `type` / `tab`。
- `problem_data_manage.php:586,592` 的「检验数据正确性」写死 `std.cpp` 和语言 `C++`。
- MySQL 5.7 与 PHP 7.4 均已停止维护；`web/install.sh` 的非 Docker 路径仍在装 libv8，已过时。
- Markdown 已由 Parsedown 取代 V8Js（#152 已合并），**不再改动**。

---

## C. 实施阶段

每个阶段是一个 PR，内部按下列 commit 拆分；每个修复 commit 自带回归测试。

**Phase 1 — Judger 正确性（P0）**
1. 测试骨架：`judger/uoj_judger/tests/`（Catch2，Makefile 已预留）、`judger/tests/`（judge_client 的 Python 单测）、`tests/e2e/`（docker compose 起 Web + DB + 2 个 Judge），CI 新增 Linux x86_64 job。
2. 修 2.1。
3. 修 2.2（同步上下文 + 批准指纹 + 失败可见）。
4. 修 2.4（rb、重试、SHA256 日志）。
5. 修 2.5（migration `1001`、`max_allowed_packet`、UPDATE 失败降级）。
6. 修 2.6（mysqli 执行、port / socket、`GET_LOCK`、非 0 退出、启动时自动迁移）。
7. 修 2.7 的 1、2、4、5 点（状态码检查、临时目录解压后原子替换、认证失败明确报错并用 `hash_equals`、Web 端 zip 与目录原子发布）。
8. 2.8 复现用例；若复现再单独修。
9. 修 3.1 中的 CSRF 与 conf 注入，以及 `judge/submit.php` 的 SQL 拼接。

**Phase 2 — 多 Judge 与数据版本化（P0）**
1. `problem_data_versions` + `problems.data_version`；同步时生成版本、SHA256、manifest。
2. Judge 协议：任务携带 version 与 sha256；Judge 按版本目录缓存并校验；按提示词 2.7 的字段打结构化日志。
3. `submission_judgements`：每次评测记录 judger、数据版本、sha256、judger 版本、编译器版本、时间。
4. 任务归属与回收：记录 `judger_name`、心跳，超时把 Judging 退回队列；`submissions(status, id)` 索引。
5. Conformance 套件（提示词第 4 节全部用例），含高 CPU 负载、内存压力、单 / 多 Judge 矩阵。
6. 负载无关性：真实时间超限时读取调度等待时间，判定为被饿死则重排而不是给 TLE；评测机部署要求关闭 swap、绑核。
7. 编译边界（3.4）：把 chk / std / val / interactor / Makefile 的编译改成由 Judge 执行的「题目准备任务」，Web 镜像去掉 gcc 和 `SYS_PTRACE`。同步因此变为异步，需要「准备中 / 失败 / 已发布」状态。

**Phase 3 — RBAC 与 SSO（P0）**
1. 统一入口 `can($user, $ability, $resource)`，先把现有判断原样搬进去，行为不变。
2. 角色表与回填（`usergroup = 'S'` → System Admin），再逐个替换 3.2 列出的调用点；比赛负责人不再需要全站 superuser。
3. 源码可见性默认值收紧，覆盖提示词第 6 节的重点审计项。
4. `external_identities` 与 provider 适配层（CAS、OAuth2，OIDC 评估后复用 OAuth2 路径）；state / ticket 校验；首次登录自动建号或绑定；冲突处理。
5. 会话加固：登录后重置 session id，Cookie Secure / SameSite，可信代理白名单后才认 `X-Forwarded-*`。
6. 基础审计日志表与写入点。

**Phase 4 — Group / Course / Training（P0）**
1. 班级与成员。2. 课程、课程-班级、教师与 TA。3. Training 与题目列表（不建模成 Contest）。4. 完成度统计（从 `submissions` 聚合，不复制判题结果）。5. 成绩导出。

**Phase 5 — 运维与增强（P1）**
题目上传向导与校验、VP（**从零实现**，含预约）、Judge 节点监控、Homework Snapshot、题目历史 UI、MathJax 等静态资源本地化、备份恢复自动化、checker / validator 编译缓存、Contest ACL、Run Twice 泄漏测试、3.1 其余项（大小 / 数量 / 压缩比限制、flatten 重写）、3.3 反代与子路径。

**Phase 6 — 现代化（P2）**
PHP 8.x、MySQL 8 与 InnoDB、前端渐进替换、查重、高级统计、API。

---

## D. 数据库变更

沿用现有 `web/app/upgrade/<编号>_<名称>/{up.sql,down.sql}` 与 `upgrades` 表作为 schema version，不另起一套。编号从 `1001` 起，避开上游将来的 `0001…`；所有 migration 写成幂等。新表一律 InnoDB + utf8mb4。因为没有数字 user id，外键列统一用 `username varchar(20)`。

| Migration | 阶段 | 内容 | 回滚 |
|---|---|---|---|
| `1001_expand_judgement_storage` | 1 | `submissions.result`、`custom_test_submissions.result`、`hacks.details` → LONGBLOB；`contests.extra_config`、`problems.extra_config` → TEXT | **不可逆**（缩回会截断），down 为空操作并注明 |
| `1002_problem_data_versions` | 2 | 新表 `problem_data_versions(id, problem_id, version, sha256, size, manifest, created_at, created_by, reason, custom_judger_fingerprint)`，UNIQUE(`problem_id`,`version`)；`problems` 加 `data_version` | 可逆 |
| `1003_judge_tracking` | 2 | 新表 `submission_judgements(id, submission_id, kind, judger_name, problem_data_version, data_sha256, judger_version, toolchain, started_at, finished_at, status, score)`，KEY(`submission_id`)、KEY(`judger_name`,`started_at`)；`submissions` 加 `judger_name` 与 KEY(`status`,`id`)、KEY(`submitter`,`problem_id`,`id`)；`judger_info` 加 `token_hash`、`last_heartbeat_at`、`version`、`enabled` | 可逆 |
| `1004_rbac` | 3 | 新表 `user_roles(username, role, granted_by, granted_at)` PK(`username`,`role`)；`problems_permissions`、`contests_permissions` 加 `role`；回填 superuser | 可逆（`usergroup` 保留不动） |
| `1005_external_identities` | 3 | `external_identities(id, provider, external_id, username, student_id, real_name, email, created_at, last_login_at)`，UNIQUE(`provider`,`external_id`)，KEY(`username`)、KEY(`student_id`) | 可逆 |
| `1006_audit_logs` | 3 | `audit_logs(id, actor, actor_type, action, resource_type, resource_id, before_json, after_json, ip, created_at)`，KEY(`resource_type`,`resource_id`,`created_at`)、KEY(`actor`,`created_at`) | 可逆 |
| `1007_user_groups` | 4 | `user_groups(id, name, type, owner, created_at, archived)`、`user_group_members(group_id, username, role, joined_at)` PK(`group_id`,`username`)，KEY(`username`)。不用 `groups` 这个名字，它在 MySQL 8 是保留字 | 可逆 |
| `1008_courses` | 4 | `courses`、`course_groups` PK(`course_id`,`group_id`)、`course_staff` PK(`course_id`,`username`) | 可逆 |
| `1009_trainings` | 4 | `trainings(id, course_id, title, description, start_time, deadline, type, visibility, created_by, created_at)`、`training_problems` PK(`training_id`,`problem_id`) + UNIQUE(`training_id`,`position`) | 可逆 |
| `1010+` | 5 | `training_snapshots`、Contest ACL 相关表、`virtual_participations` | 可逆 |

配置变更（非 migration）：`db/install.sh` 增加 `max_allowed_packet=64M`；`.default-config.php` 的 `database` 增加 `port`、`socket`。

---

## E. 测试计划

所有测试在 Linux x86_64 的 CI 上运行（GitHub Actions ubuntu runner 支持 ptrace、seccomp 和 Docker）。

| Bug | 回归测试 | 层级 |
|---|---|---|
| 2.1 | 未设 / 显式设 `stack_limit`、改 `real_time_limit` 后 stack 不变；深递归与大局部数组程序 | Catch2 单测 + e2e |
| 2.2 | builtin 与自定义 judger 两种题：管理员开 Hack → Hack 成功 → Extra Test 落盘 → 同步 → 两台 Judge 拿到新数据 → 历史 AC 重测；非 superuser 改了 Makefile 时系统同步必须拒绝 | e2e |
| 2.3 | 断言 Judge 下载内容与 `/var/uoj_data/{id}.zip` 的 SHA256 相同，且不是 `download.zip` | e2e |
| 2.4 | 含 `0x00`、`0xff`、非 UTF-8、CRLF、大文件的 Hack 输入，上传前后比 SHA256；模拟首次 POST 失败后的重试 | Python 单测 + e2e |
| 2.5 | 100 / 1000 / 5000 测试点和超长 checker 信息，结果可读回且是合法 JSON；超过 packet 上限时提交不卡 Judging | e2e |
| 2.6 | 远程 host、非默认端口、unix socket、IPv6、含引号和 `$` 的密码；故意写错的 SQL 必须让 CLI 非 0 退出且容器不启动 | PHP 集成测试 |
| 2.7 | 2 台和 4 台 Judge 并发提交；改数据后重测，各 Judge 的数据 SHA256 一致；下载中途断开后不得使用残缺目录；杀掉一台 Judge 后任务被回收 | e2e |
| 2.8 | 64 MiB 题，持续申请内存的程序在正式提交、Custom Test、Extra Test 三种模式下 verdict 与 RSS 一致 | e2e |
| 3.1 | 含 `../`、绝对路径、symlink、同名文件、超大压缩比的 zip；无 token 的上传请求被拒 | PHP 集成测试 |
| 3.2 | 比赛负责人（非 superuser）可管理比赛；普通用户访问各管理端点返回 403 | PHP 集成测试 |
| 负载 | 同一份代码在空载与 CPU 打满下 verdict 相同 | e2e 压测 |

Conformance 套件覆盖提示词第 4 节的全部 verdict 与题型，在「低负载 / 高 CPU / 内存压力」×「单 Judge / 多 Judge」矩阵下运行。

---

## F. 兼容性

- **现有用户**：`usergroup` 与 md5 密码保留，本地登录与 SSO 并存；superuser 自动回填为 System Admin。
- **现有题目**：数据目录结构不变；提供一次性 CLI 任务为已有题目补 `data_version = 1` 与 SHA256。
- **现有比赛**：表结构只增不改，ACL 默认 public，行为不变。
- **现有 submission**：字段加宽无损；新增列均可空，历史记录没有评测溯源信息属预期。
- **现有 Judge**：协议只增字段，旧 `judge_client` 在滚动升级期间仍可工作；升级顺序固定为先 Web 后 Judge，可用现有的 `sync-judge-client` 推送更新。Judge 认证改为哈希后保留一段明文兼容期。
- **与上游的关系**：独立实现会与 PR #153 在 `uoj_judger_v2.h`、`judge_client`、`uoj-data-lib.php`、`Upgrader.php` 产生重复改动，日后合并上游时这几处需要手工解决冲突。

---

## 待你确认的问题（不阻塞本轮）

1. 学校实际提供的是 CAS、OAuth2 还是 OIDC，以及能拿到哪些属性（学号、姓名、邮箱）。
2. SSO 用户的 UOJ 用户名策略：自动生成、首次登录自选，还是另定规则（提示词要求不等于学号）。
3. 后续阶段的验证环境：自己的 GitHub fork 跑 Actions，还是一台 Linux x86_64 服务器。
4. 评测机数量与硬件，是否允许绑核和关闭 swap。
5. 是否需要子路径部署（`https://domain/oj/`）；需要的话要改约 110 处 URL，建议放到 Phase 5。

---

## Verification

- 本轮没有可运行的验证，也没有代码变更。
- 审计结论可按文中的 `文件:行号` 逐条核对；上游状态可用 `gh pr view 153 --repo UniversalOJ/UOJ-System` 和对应 issue 号核对。
- 需运行复现后才能下结论的项：2.8、2.3 中 issue #134 的根因、3.1 的路径穿越与 symlink 行为。
- 后续每个阶段的验收方式：CI 中 `docker compose up` 起 Web + DB + 多个 Judge，跑 `tests/e2e` 与 conformance 套件，全部通过才合并。
