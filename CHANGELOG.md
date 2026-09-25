# 更新日志

遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 与 [语义化版本](https://semver.org/lang/zh-CN/)。

1.x 系列未纳入 git 历史（以 zip 分发），其变更明细保留在各模块文件头部注释中；
本文件从 2.0.0 开始记录 git 仓库内的版本。

## [2.1.0] - 2026-09-24

向后兼容，可直接覆盖安装 2.0.0。

### 新增

- **`kf_validate()` 支持字符串管道式规则**：`'text' => 'required|max:40|label:任务'`。
  `in:a,b,c` 会按逗号切成数组（底层 `in` 要求数组参数，直接写字符串会退化成整串比较）；
  数值参数 `max:40` 自动转数字；数组式规则原样透传，旧写法行为不变
- **`kf_form_data($source = 'post')`**：一次取整份表单原始值，应用侧不再直接读 `$_POST` / `$_GET`；
  返回值不转义，输出时交给模板引擎，避免双重转义
- **`kf_is_xhr()`**：收口 `$_SERVER['ajax']` 的直读（`X-Requested-With` 或 `?ajax=1`），
  与 `kf_is_htmx()` 是两条互不相干的通道
- **片段响应自动禁缓存**：`kf_view()` 走片段分支时附带 `Cache-Control: no-store, must-revalidate`，
  避免片段进浏览器缓存后污染回退历史与局部刷新结果；`app.htmx.no_cache => false` 可关

### 变更

- `kf_abort()` 内部改用 `kf_is_xhr()` 判断传统 XHR 通道，行为不变

### 文档

- 校验语义澄清并加进坑清单：`required` 只判空串、**不 trim**，纯空白会被判为已填 —— 表单文本要先 `trim()` 再校验
- 手工 `echo kf_capture()` 的分支需要禁缓存时自行调用 `kf_no_cache()`（只有 `kf_view()` 会自动加）

### 测试

- 断言总数 155 → **176**（`tests/unit.php` 121 项、`tests/http.php` 55 项）
- 新增覆盖：两种规则写法等价、`in` 逗号切分、`label` 改消息、纯空白 required 行为、
  `kf_form_data` 三种来源、片段响应 `no-store` 而整页不被改写

## [2.0.0] - 2026-09-24

局部刷新成为框架内建能力，应用侧统一为 `kf_` 单前缀。**破坏性版本**，
迁移对照见 [docs/migration-2.0.md](docs/migration-2.0.md)。

### 新增

- **应用侧单一前缀**：新增 `request.func.php` / `response.func.php` / `util.func.php` 三个门面模块，
  把应用日常要调的核心能力全部收进 `kf_`：
  `kf_param*`、`kf_ip`、`kf_method`、`kf_request_header`、`kf_cookie`、`kf_referer`、
  `kf_csrf_token/check/ok`、`kf_validate`、`kf_encrypt/decrypt`、`kf_signdata`、`kf_rand`、
  `kf_log`、`kf_hook*`、`kf_json_encode/decode/attr`、`kf_http_get/post/multi_get`、
  `kf_substr`、`kf_pagination`、`kf_dir_get/set`、`kf_send_mail`、`kf_zip`、`kf_unzip`
- **按需模块自动引入**：邮件与 zip 不再需要应用手工 `require` 核心文件
- **交互运行时内置路由直出**：`/kf/htmx.js`（常量 `KF_RUNTIME_ROUTE`），带 ETag（版本 + sha256 前缀）、
  `Cache-Control: public, max-age=31536000, immutable`，`If-None-Match` 命中时零 I/O 返回 304，
  吐字节前校验 sha256 —— 覆盖安装新框架即自动升级运行时，不再有应用侧副本漂移
- **`kf_is_fragment()`**：把「该出整页还是出片段」这条判据本身显式成函数
- **元素一等方法**：`->get()/post()/put()/patch()/delete()/hx_target()/swap()/oob()/trigger()/`
  `boost()/confirm()/prompt()/vals()/headers()/params()/select()/indicator()/preserve()/sync()/push_url()/on()`
- **`kf_empty()`**：204 空响应，只送事件不换入内容

### 变更

- **`kf_view()` 内建判定**：`kf_view($name, $data, $fragment, $fragment_data)` 在片段请求下只渲染视图本体并丢弃布局；
  旧的 `kf_htmx_view()` 取消
- **`kf_redirect()` 内建判定**：片段请求下变为 `204` + 软跳转头，boost 与浏览器直访仍是真实 302；
  旧的 `kf_htmx_redirect()` 取消
- **`kf_abort()` 在片段请求下输出无布局错误片段**（延续 1.4 行为），XHR 与整页分支不变
- **响应控制头拒绝含换行的头值**（1.4 是抹掉换行后照发）：整条不发送并写日志，避免留下语义错乱的头
- **运行时不可用/校验失败时错误页不再泄漏服务器绝对路径**，路径只进日志
- 模块重组：`helpers.func.php` 与 `htmx.func.php` 按职责并入 request / response / view / util
- `KF_VERSION` 改为语义化版本 `2.0.0`
- 常量更名：`KF_HTMX_VERSION/SHA256/CDN` → `KF_RUNTIME_VERSION/SHA256/CDN`，新增 `KF_RUNTIME_ROUTE`、`KF_RUNTIME_FILE`

### 删除

- `kf_htmx_*` 全部前缀（对照表见迁移文档）
- `kf_htmx_publish()` / `kf_htmx_asset_path()` / `kf_htmx_asset_url()`：运行时不再落地到应用 `assets/`
- `kf_htmx_view()` / `kf_htmx_redirect()`：能力并入 `kf_view()` / `kf_redirect()`
- `kf_old()`（1.4 已移除，此处说明去向）：校验失败改为直接重渲染表单片段，旧值取自请求参数

### 修复

- `kf_csrf_field()` 作为标签函数子节点时被模板引擎转义成页面上可见的文字 → 返回 `KF_Raw`（保留 `__toString`，旧用法不变）
- 文档中 `kf_validate()` 的规则格式写成了字符串管道风格（`'a' => 'required'`），
  真实格式是 `['a' => ['required' => true, 'max' => 20, 'label' => '名称']]`，照旧写法会 fatal

### 测试

- 两套回归共 **155 项断言**：`tests/unit.php`（CLI 105 项）、`tests/http.php`（`php -S` 真实往返 50 项）
- 覆盖门面转发、片段渲染与缓冲复原、OOB 注入位置与转义、元素方法、响应头校验、
  运行时直出（200/304/ETag/immutable/完整性拒绝）、CSRF 头通道、响应拆分防护
- `tests/app/` 是可跑的 php -S 夹具应用，只使用 `kf_` 前缀 —— 门面完整性由它反证

## [1.4] - 2026-09-21（pre-git）

首次引入 htmx 集成层（`htmx.func.php`）与内置 `js/htmx.min.js`。该层在 2.0.0 被拆解内化，
函数与交付方式全部更名，不作为独立版本维护。

## [1.3] / [1.2] / [1.1] - pre-git

仅 bug 修复与性能优化，接口保持「可直接覆盖安装」。逐项明细见各模块文件头部注释：
`klockframe.php`（总览）、`view.func.php`、`config.func.php`、`router.func.php`。
