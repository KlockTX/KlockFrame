# KlockFrame

> 极轻量、高性能的 PHP 8+ 函数式网站框架

KlockFrame 基于 XiunoPHP 4.1 核心，延续其极致轻量与极致性能的函数式设计语言：路由分发器、PurePHP 风格组件化模板引擎、配置加载器、内建局部刷新，让开发者用最少的代码搭建完整的网站。

## 特性

- **极致轻量** — 内核 + 6 个功能模块，无 autoload，无 Composer 依赖
- **单一前缀** — 应用侧只有 `kf_`：`kf_param()`、`kf_log()`、`kf_is_htmx()`，核心 `xn_*` 降为底层实现
- **内建局部刷新** — 同一条路由既出整页也出片段，运行时由框架自己的路由直出，不写一行 JS
- **极致性能** — 函数式调用，对 OPCache 友好，静态可分析
- **PurePHP 风格模板** — 用 PHP 函数构建 HTML，组件化开发，链式属性
- **路由系统** — 路径参数捕获、路由分组、闭包/控制器双模式
- **PDO 预处理** — 参数化查询，杜绝 SQL 注入
- **安全工具** — CSRF 防护、数据验证器、HTML 白名单过滤
- **PHP 8+ 原生** — 类型声明、match 表达式、联合类型

## 快速开始

### 1. 目录结构

**本仓库**（框架本体就在仓库根目录）：

```
.
├── klockframe.php       # 内核装载链
├── config.func.php      # 配置
├── request.func.php     # 请求门面（参数/头/CSRF/校验/加密/局部刷新识别）
├── router.func.php      # 路由
├── view.func.php        # 模板、片段、组件、交互运行时装载与直出
├── response.func.php    # 响应、重定向、错误页、控制头
├── util.func.php        # 工具门面
├── js/htmx.min.js       # 内置交互运行时（由框架路由直出）
├── xiunophp/            # 底层核心（第三方，MIT）
├── docs/                # 文档
└── tests/               # 回归测试与夹具应用
```

**放进你的站点**（整个目录拷进去即可，无 Composer）：

```
your-app/
├── KlockFrame/          # 即本仓库内容
├── index.php            # 应用入口
├── config.php           # 配置文件
└── views/               # 视图目录
    ├── layout.php       # 布局
    └── home.php         # 页面
```

> `xiunophp/` 已随框架一起提供；若你想与多个项目共用一份核心，把框架放到
> `KlockFrame/` 之外、同级放 `xiunophp/` 也会被自动探测到。

### 2. 创建入口文件

```php
<?php
// index.php
require_once __DIR__ . '/KlockFrame/klockframe.php';

kf_get('/', function() {
    kf_view('home', ['title' => 'Hello KlockFrame']);
});

kf_get('/user/{id:\d+}', function($id) {
    kf_view('user', ['id' => $id]);
});
```

### 3. 创建视图

```php
<?php
// views/home.php
kf_layout('layout');

echo kf_div(
    kf_h1($title),
    kf_p('Welcome to KlockFrame!'),
    kf_a('View User →')->href('/user/1')
)->class('container')->render();
```

### 4. 启动

```bash
php -S localhost:8000
```

访问 `http://localhost:8000` 即可。

### 5. 局部刷新（不用装任何东西）

布局 head 放一行，运行时与 CSRF 注入自动就位；脚本由框架内置路由 `/kf/htmx.js` 直出，
带 ETag 与一年期 immutable 缓存，覆盖安装新框架即同步升级：

```php
<!-- views/layout.php -->
<head>
    <?= kf_runtime() ?>
</head>
```

同一条路由既出整页也出片段，模板里把交互写成元素方法：

```php
kf_get('/todo', fn() => kf_view('todo', ['items' => $items], 'todo_list'));
```

```php
kf_button('加载')->get('/todo')->hx_target('#todo-list');
kf_button('删除')->delete('/todo/5')->hx_target('#todo-list')->confirm('确定？');
```

提交完成后 `kf_redirect()` 会按请求来源自动选择真实 302 还是客户端软跳转。
详见 [局部刷新](docs/fragments.md)。

## 文档

| 文档 | 说明 |
|------|------|
| [快速开始](docs/getting-started.md) | 安装、配置、第一个页面 |
| [路由系统](docs/routing.md) | 路由定义、参数捕获、分组、控制器 |
| [模板引擎](docs/views.md) | KF_Element、标签函数、组件、布局 |
| [局部刷新](docs/fragments.md) | 片段、OOB、响应控制头、CSRF、坑清单 |
| [门面函数](docs/facade.md) | `kf_` 单前缀的应用侧 API 总览 |
| [数据库](docs/database.md) | CRUD、条件、事务、读写分离 |
| [API 参考](docs/api-reference.md) | 完整函数列表与底层对照 |
| [从 1.x 迁移到 2.0](docs/migration-2.0.md) | 改名对照、运行时交付变化、行为差异 |

## 核心 API 速览

```php
// 路由
kf_get('/path', fn() => ...);
kf_post('/path', 'Controller@action');
kf_group('/api', fn() => ...);

// 请求（应用侧不再有 param()/xn_* 直呼）
$name  = kf_param('name');
$page  = kf_param_int('page', 1);
$ip    = kf_ip();
$from  = kf_request_header('HX-Current-URL');
$isFrag = kf_is_fragment();

// 视图：整页/片段自动判定
kf_view('home', ['title' => 'Hello']);
kf_view('todo', $data, 'todo_list');        // 片段请求只渲染 todo_list
echo kf_capture('row', $data);              // 拿片段字符串
echo kf_oob('count', '#count', $data);      // 一次响应换多个位置
kf_layout('app');

// 模板引擎（含交互一等方法）
echo kf_div(kf_h1('Title'), kf_p('Body'))->class('box')->render();
kf_button('删除')->delete('/todo/5')->hx_target('#list')->swap('outerHTML');

// 响应
kf_json($data);
kf_redirect('/done');                        // 片段请求 → 204 + 软跳转；其余 → 302
kf_abort(404, 'Not Found');                  // 片段请求回无布局错误片段
kf_reswap('innerHTML'); kf_trigger('toast:show', ['msg' => '已保存']);

// 安全
if (!kf_csrf_ok()) kf_abort(403, 'CSRF');
$errors = kf_validate($_POST, ['nickname' => ['required' => true, 'max' => 20]]);

// 数据库 / 缓存（模块边界，保持原前缀）
$users = db_find('users', ['status' => 1], ['id' => -1], 1, 10);
db_insert('users', ['name' => 'Alice']);
cache_set('key', $data, 3600);

// 工具
kf_log('已处理', 'job');
kf_hook_register('klockframe_boot', fn() => ...);
$total = kf_pagination(95, 2, 10, '/p/{page}');
```

## 设计哲学

KlockFrame 不"框"住你。它只是对 PHP 做了增强：

1. 不使用 autoload — 直接 `include`，对 OPCache 最优
2. 不使用 eval — 安全可审计
3. 不使用 `$$var` — 静态可分析
4. 显式优于隐式 — 交互能力是一等方法（`->delete()`、`kf_is_fragment()`），不靠字符串属性名
5. **应用侧单一前缀** — `kf_`；`db_` / `cache_` 是带连接语义的模块 API，`xn_*` 只作底层实现

## 测试

框架自带两套回归，共 155 项断言，不依赖 PHPUnit：

```bash
php tests/unit.php     # CLI 单元：门面转发、片段、OOB、元素方法、响应头校验、运行时
php tests/http.php     # 起 php -S 跑 tests/app 夹具，断言状态码、控制头、304、CSRF 头通道
```

`tests/app/` 是一个只使用 `kf_` 前缀的最小应用，也是活的用法示例：

```bash
php -S 127.0.0.1:8080 -t tests/app tests/app/router.php
```

CI（GitHub Actions）在 PHP 8.1–8.4 × Linux 以及 PHP 8.4 × Windows 上跑这两套。

## 贡献

改动前请读 [CONTRIBUTING.md](CONTRIBUTING.md)：模块地图、七条硬规则（无 autoload、无 eval、
应用侧只出现 `kf_` 前缀、显式优于隐式…）、版本与变更日志纪律、PR 检查清单。

变更历史见 [CHANGELOG.md](CHANGELOG.md)；从 1.x 升级看 [docs/migration-2.0.md](docs/migration-2.0.md)。

## 安全

漏洞请走私有报告，不要开公开 issue —— 见 [SECURITY.md](SECURITY.md)。
里面同时列出了框架已内建挡掉的十类风险（SQL 注入、XSS、属性注入、响应拆分、CSRF、
zip 路径穿越、运行时被替换、控制器名注入、日志直下、IP 伪造）与部署侧仍需你负责的部分。

## 环境要求

- PHP 8.0+
- PDO MySQL 或 PDO SQLite（数据库功能）
- Redis / Memcached / APCu（缓存功能，可选）

## 许可

MIT —— 见 [LICENSE](LICENSE)。

第三方组件随仓库一并分发，各自保留原许可：

| 组件 | 许可 |
|------|------|
| `xiunophp/`（底层核心） | MIT |
| `js/htmx.min.js`（交互运行时 2.0.11） | Zero-Clause BSD，见 `js/LICENSE-htmx.txt` |
