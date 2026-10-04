# 统一身份认证（SSO）

UOJ 可以同时接入多个统一身份认证服务，支持 CAS 2.0/3.0、OAuth 2.0（授权码模式）和 OpenID Connect。
本站自己的账号密码登录和注册不受影响，两种方式并存。

## 账号规则

一个用户有三样东西：

| | 说明 |
|---|---|
| ID | 数字编号，注册时自动分配，永不改变 |
| 用户名 | 登录名，也是提交记录、排行榜里显示的名字 |
| 别名 | 用户自己取的称呼，总是显示为“别名（用户名）” |

- 通过统一身份认证首次登录时，自动创建一个**以学号为用户名**的账号。这类账号的用户名不能自己修改
  （系统管理员可以在“系统管理 → 用户操作 → 修改用户名”里更正），别名可以随意修改。
- 本站注册的账号，用户名和别名都可以在个人信息页修改。默认 30 天内只能改一次用户名
  （`user.username-change-interval`），旧用户名会为本人保留，别人不能注册。
- 学校返回的用户标识、学号、姓名、邮箱保存在 `external_identities` 表里，与用户分开。
  学号和姓名只有本人、教师和管理员能在个人信息页看到，导出比赛排名时也只对他们输出。
- 配置了统一身份认证之后，本站不能再注册或改成“看起来像学号”的用户名
  （`sso.reserved-username-pattern`，默认 6–20 位纯数字），避免有人抢注别人的学号。
- 如果学号对应的用户名已经被本站账号占用（例如接入之前学生就用学号注册过），
  首次登录时会要求输入该账号的密码，验证通过后把它和统一身份认证绑定；
  密码不对就不会绑定，需要联系管理员。系统不会按邮箱自动合并账号。
- 通过统一身份认证创建的账号没有密码，不能用密码登录。
- 被封禁的用户同样不能通过统一身份认证登录。
- 第一个注册的用户会成为系统管理员，这条规则只对本站注册生效。
  请先在本站注册好管理员账号，再开放统一身份认证。

## 配置

在 `.config.php` 的 `sso.providers` 里添加，键名会出现在登录地址中（只能用字母、数字、`_`、`-`）。

需要在学校那边登记的回调地址是：

```text
<本站地址>/login/sso/<键名>/callback
```

本站地址取自配置里的 `web.main`（protocol、host、port），请确认它和用户实际访问的地址一致。

### CAS

```php
'sso' => [
	'providers' => [
		'cas' => [
			'type' => 'cas',
			'name' => '统一身份认证',                      // 登录页按钮上的名字
			'server' => 'https://cas.example.edu.cn/cas', // 不带 /login
			'version' => 3,                               // 2 或 3，默认 3
			'attributes' => [
				'student_id' => 'employeeNumber',         // 学号所在的属性
				'real_name' => 'cn',
				'email' => 'mail',
			],
		],
	],
],
```

- 默认以 CAS 返回的 `user` 作为用户标识和学号；如果 `user` 是登录名而学号在属性里，像上面那样指定。
- UOJ 会在回调地址后面加一个 `state` 参数（`.../callback?state=...`），用来保证票据只能在发起登录的
  那个浏览器里使用。如果学校只接受完全一致的回调地址，加上 `'service_state' => false`。

### OAuth 2.0

```php
'school' => [
	'type' => 'oauth2',
	'name' => '校园账号',
	'client_id' => '...',
	'client_secret' => '...',
	'authorize_url' => 'https://id.example.edu.cn/oauth/authorize',
	'token_url' => 'https://id.example.edu.cn/oauth/token',
	'userinfo_url' => 'https://id.example.edu.cn/oauth/userinfo',
	'scope' => 'profile',
	'attributes' => [
		'external_id' => 'data.uid',     // 用 . 取嵌套的字段
		'student_id' => 'data.number',
		'real_name' => 'data.name',
		'email' => 'data.email',
	],
],
```

可选项：

| 配置 | 默认 | 说明 |
|---|---|---|
| `pkce` | `true` | 学校拒绝多余参数时设为 `false` |
| `token_auth` | `'post'` | `'basic'` 表示用 HTTP Basic 传 client secret |
| `userinfo_auth` | `'header'` | `'query'` 表示用 `access_token` 参数传令牌 |

### OpenID Connect

```php
'school' => [
	'type' => 'oidc',
	'name' => '校园账号',
	'issuer' => 'https://id.example.edu.cn',
	'client_id' => '...',
	'client_secret' => '...',
],
```

三个地址从 `<issuer>/.well-known/openid-configuration` 读取（缓存一天），也可以像 OAuth 2.0 那样直接写。
默认 `scope` 为 `openid profile email`，默认属性为 `sub`（用户标识）、`preferred_username`（学号）、
`name`、`email`。用户信息由 UOJ 服务器直接向学校的 userinfo 接口请求，不使用浏览器带回来的任何内容，
因此不校验 ID Token 的签名。

### 属性映射

`attributes` 里可以指定：

| 键 | 含义 |
|---|---|
| `external_id` | 学校对用户的唯一标识，必须有，且不会被回收给别人 |
| `student_id` | 学号，缺省时等于 `external_id` |
| `username` | 新用户的用户名，缺省时等于学号 |
| `real_name`、`email` | 姓名、邮箱，可以没有 |

用户名只能包含字母、数字和下划线，最长 20 位。学号不符合这个规则时会拒绝登录并提示联系管理员。

## 安全说明

- UOJ 服务器访问学校的接口时校验 TLS 证书，请使用 `https` 地址。
- CAS 票据和 OAuth 授权码都绑定到发起登录的浏览器会话，只能用一次，10 分钟内有效。
- 退出登录只退出本站，不会退出学校的统一身份认证。
- 上线前请先用学校提供的测试环境完整走一遍：首次登录、再次登录、已有同名账号的绑定。
  本仓库的自动化测试用的是模拟的认证服务器（`tests/e2e/mock_idp.py`），不能代替和真实系统的联调。
