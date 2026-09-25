# API 参考

应用侧只有一层前缀：`kf_`。xiunophp 的 `param()` / `xn_*()` 是底层实现，
仅在编写扩展时直呼（见文末「底层」一节）。

- [门面函数详解](facade.md)
- [局部刷新一等方法与坑清单](fragments.md)
- [从 1.x 迁移](migration-2.0.md)

## 路由（router.func.php）

| 函数 | 说明 |
|------|------|
| `kf_get(string $path, $handler): void` | 注册 GET 路由 |
| `kf_post(string $path, $handler): void` | 注册 POST 路由 |
| `kf_put(string $path, $handler): void` | 注册 PUT 路由 |
| `kf_delete(string $path, $handler): void` | 注册 DELETE 路由 |
| `kf_any(string $path, $handler): void` | 注册任意方法路由 |
| `kf_route(string $method, string $path, $handler): void` | 注册路由 |
| `kf_group(string $prefix, callable $callback): void` | 路由分组 |
| `kf_dispatch(): void` | 执行路由分发 |
| `kf_not_found(): void` | 触发 404 |
| `kf_compile_path(string $path): array` | 编译路径为正则 |
| `kf_execute_handler($handler, array $params): void` | 执行路由处理器 |
| `kf_current_route(): ?array` | 当前匹配的路由信息 |

## 配置（config.func.php）

| 函数 | 说明 |
|------|------|
| `kf_config_load(string $file): void` | 加载配置文件 |
| `kf_config(string $key, $default = null): mixed` | 获取配置（dot 访问） |
| `kf_config_set(string $key, $value): void` | 设置配置 |
| `kf_config_all(): array` | 获取所有配置 |
| `kf_config_reset_cache(): void` | 清读取缓存（含 base_url） |
| `kf_env(): string` | development\|production |

## 请求与安全（request.func.php）

### 参数

| 函数 | 说明 |
|------|------|
| `kf_param(string $key, $default = null, bool $escape = true, bool $slashes = false): mixed` | 取参数（默认转义） |
| `kf_param_int(string $key, int $default = 0): int` | 整型 |
| `kf_param_word(string $key, $default = null): string` | 仅字母数字下划线 |
| `kf_param_json(string $key, $default = null): mixed` | JSON 解码 |
| `kf_param_url(string $key, $default = null): string` | URL |
| `kf_param_base64(string $key, $default = null): mixed` | Base64 |
| `kf_param_force(string $key, $default): mixed` | 按默认值类型强转 |

### 请求信息

| 函数 | 说明 |
|------|------|
| `kf_ip(): string` | 客户端 IP（可信代理链） |
| `kf_method(): string` | 方法（大写，HEAD 归一为 GET） |
| `kf_request_header(string $name, string $default = ''): string` | 读请求头 |
| `kf_cookie(string $name, string $default = ''): string` | 读 Cookie |
| `kf_referer(): string` | 来源页 |
| `kf_form_data(string $source = 'post'): array` | 整份表单原始值（post\|get\|request），不转义 |
| `kf_is_xhr(): bool` | 是否传统 XHR（`X-Requested-With` / `?ajax=1`） |

### 局部刷新识别

| 函数 | 说明 |
|------|------|
| `kf_is_htmx(): bool` | 是否框架局部刷新发起（`HX-Request`） |
| `kf_is_boosted(): bool` | 是否 hx-boost 提升的导航 |
| `kf_is_fragment(): bool` | 是否只应回片段（HX 且非 boost） |
| `kf_is_history(): bool` | 是否历史恢复请求 |
| `kf_target_id(): string` | 目标元素 id |
| `kf_trigger_id(): string` | 触发元素 id |
| `kf_trigger_name(): string` | 触发元素 name |
| `kf_request_url(): string` | 发起请求时的地址栏 URL |
| `kf_prompt(): string` | `hx-prompt` 用户输入 |

### 安全

| 函数 | 说明 |
|------|------|
| `kf_csrf_token(): string` | 会话内令牌 |
| `kf_csrf_check(?string $token = null): bool` | 校验令牌（默认读 `csrf_token` 字段） |
| `kf_csrf_ok(): bool` | 请求头优先、回落表单字段 |
| `kf_validate(array $data, array $rules): array` | 规则支持字符串式 `'required\|max:20\|label:昵称'` 与数组式 `['required' => true, 'max' => 20]` |
| `kf_encrypt(string $text, ?string $key = null): string` | 带认证标签的加密 |
| `kf_decrypt(string $text, ?string $key = null): string\|false` | 解密 |
| `kf_signdata(array $data, string $key): string` | 签名 |
| `kf_rand(int $len = 8, string $type = 'alnum'): string` | 随机串（num\|lower\|upper\|alnum\|默认混合） |

## 响应（response.func.php）

| 函数 | 说明 |
|------|------|
| `kf_base_url(): string` | base_url（进程内缓存） |
| `kf_url(string $path = ''): string` | 站点 URL |
| `kf_asset(string $path): string` | 资源 URL |
| `kf_csrf_field(): KF_Raw` | CSRF 隐藏字段（可作标签函数子节点） |
| `kf_json($data, int $status = 0): void` | JSON 响应并结束 |
| `kf_abort(int $code, string $message = ''): void` | 错误响应（片段/XHR/整页自动分支） |
| `kf_error_fragment(int $code, string $message = ''): void` | 无布局错误片段 |
| `kf_redirect(string $url, int $code = 302, bool $exit = true): void` | 重定向（按请求来源自动分支） |
| `kf_back(): void` | 回来源页 |
| `kf_flash(?string $key = null, $value = null): mixed` | 跨重定向一次性消息 |
| `kf_dump($var, bool $exit = true): void` | 仅 DEBUG 输出 |
| `kf_elapsed(): float` | 本次请求耗时（毫秒） |

### 响应控制头（局部刷新）

| 函数 | 头 |
|------|----|
| `kf_response_header(string $name, string $value = 'true', bool $replace = true): bool` | 任意 `HX-*` |
| `kf_reswap(string $style, array $options = []): bool` | `HX-Reswap` |
| `kf_retarget(string $selector): bool` | `HX-Retarget` |
| `kf_reselect(string $selector): bool` | `HX-Reselect` |
| `kf_trigger($events, $payload = null, string $suffix = ''): bool` | `HX-Trigger` |
| `kf_trigger_after_swap($events, $payload = null): bool` | `HX-Trigger-After-Swap` |
| `kf_trigger_after_settle($events, $payload = null): bool` | `HX-Trigger-After-Settle` |
| `kf_location($spec): bool` | `HX-Location` |
| `kf_refresh(): bool` | `HX-Refresh` |
| `kf_push_url(string\|bool $url): bool` | `HX-Push-Url` / `HX-Push` |
| `kf_replace_url(string $url): bool` | `HX-Replace-Url` |
| `kf_empty(bool $exit = true): void` | — 204 空响应 |
| `kf_no_cache(): void` | `Cache-Control: no-store` |

## 视图与模板（view.func.php）

| 函数 | 说明 |
|------|------|
| `kf_view(string $name, array $data = [], ?string $fragment = null, array $fragment_data = []): void` | 渲染视图（整页/片段自动判定） |
| `kf_layout(string $name): void` | 声明布局 |
| `kf_content(): string` | 取视图内容（布局中用） |
| `kf_partial(string $name, array $data = []): void` | 服务端模板包含 |
| `kf_share(string $key, $value): void` | 全局共享变量 |
| `kf_capture(string $name, array $data = []): string` | 渲染片段并返回字符串 |
| `kf_fragment(string $name, array $data = []): void` | 输出片段 |
| `kf_oob(string $name, string $selector = '', array $data = [], string $strategy = ''): string` | Out-of-Band 片段 |
| `kf_component(string $name, callable $handler): void` | 注册组件 |
| `kf_render_component(string $name, array $props = []): KF_Element` | 渲染组件 |
| `kf_raw(string $html): KF_Raw` | 原始 HTML（不转义） |
| `kf_view_file(string $name): ?string` | 视图文件路径解析（带缓存） |

### 交互运行时

| 函数 | 说明 |
|------|------|
| `kf_runtime(array $config = []): KF_Element` | 一行装载（meta + 脚本 + CSRF 注入） |
| `kf_runtime_url(): string` | 运行时 URL（route\|cdn\|自定义） |
| `kf_runtime_script(): KF_Element` | 脚本标签 |
| `kf_runtime_config_meta(array $overrides = []): KF_Raw` | `<meta name="htmx-config">` |
| `kf_runtime_csrf_script(): KF_Raw` | CSRF 请求头注入脚本 |
| `kf_runtime_response(): void` | 内置路由处理：直出运行时（ETag/304/sha256 校验） |

### HTML 标签函数

**容器**: `kf_div`, `kf_span`, `kf_section`, `kf_article`, `kf_header`, `kf_footer`, `kf_nav`, `kf_main`, `kf_aside`, `kf_figure`, `kf_figcaption`, `kf_details`, `kf_summary`

**标题**: `kf_h1`, `kf_h2`, `kf_h3`, `kf_h4`, `kf_h5`, `kf_h6`

**文本**: `kf_p`, `kf_a`, `kf_strong`, `kf_em`, `kf_b`, `kf_i`, `kf_u`, `kf_s`, `kf_small`, `kf_mark`, `kf_code`, `kf_pre`, `kf_blockquote`, `kf_q`, `kf_abbr`, `kf_cite`, `kf_br`, `kf_hr`, `kf_wbr`

**列表**: `kf_ul`, `kf_ol`, `kf_li`, `kf_dl`, `kf_dt`, `kf_dd`

**表单**: `kf_form`, `kf_input`, `kf_textarea`, `kf_button`, `kf_select`, `kf_option`, `kf_optgroup`, `kf_label`, `kf_fieldset`, `kf_legend`

**表格**: `kf_table`, `kf_thead`, `kf_tbody`, `kf_tfoot`, `kf_tr`, `kf_th`, `kf_td`, `kf_caption`

**媒体**: `kf_img`, `kf_video`, `kf_audio`, `kf_source`, `kf_picture`, `kf_iframe`

**文档**: `kf_html`, `kf_head`, `kf_body`, `kf_title_tag`, `kf_meta`, `kf_link`, `kf_script`, `kf_style_tag`, `kf_base`

**语义**: `kf_time`, `kf_address`

### KF_Element 方法

| 方法 | 说明 |
|------|------|
| `class($v)` / `id($v)` / `style($v)` | 常用属性 |
| `href($v)` / `src($v)` / `alt($v)` / `title($v)` | 常用属性 |
| `name($v)` / `value($v)` / `type($v)` / `placeholder($v)` | 表单属性 |
| `action($v)` / `method($v)` | 表单属性 |
| `target($v)` / `rel($v)` | 链接属性（HTML target） |
| `width($v)` / `height($v)` / `colspan($v)` / `rowspan($v)` | 尺寸/表格 |
| `attr(string $name, string $v)` | 任意属性 |
| `data(string $name, string $v)` | `data-*` |
| `attrs(array $attrs)` | 批量属性 |
| `hx(string\|array $prop, $value = null)` | 任意 `hx-*`（通用入口） |
| `get($url)` / `post($url)` / `put($url)` / `patch($url)` / `delete($url)` | 请求动词 |
| `hx_target(string $selector)` | `hx-target`（`target()` 已被 HTML 占用） |
| `swap(string $style, array $options = [])` | `hx-swap` 带选项 |
| `oob(string $selector = '', string $strategy = '')` | `hx-swap-oob` |
| `trigger(string $v)` | `hx-trigger` |
| `boost(bool $v = true)` | `hx-boost`（裸属性） |
| `confirm(string $v)` / `prompt(string $v)` | 提交前确认/输入 |
| `vals(array $v)` / `headers(array $v)` | 数组自动 JSON 化 |
| `params($v)` / `select($v)` / `indicator($v)` | 参数与抽取控制 |
| `preserve(bool $v = true)` / `sync(string $v)` / `push_url($v)` | 状态与历史 |
| `on(string $event, string $code)` | `hx-on::<event>` |
| `render(): void` / `toString(): string` | 输出 |
| `__call(string $name, array $args)` | 动态属性（`set_aria_label()` → `aria-label`） |

## 工具（util.func.php）

| 函数 | 说明 |
|------|------|
| `kf_log(string $msg, string $tag = '', string $file = ''): void` | 写日志 |
| `kf_hook_register(string $name, callable $fn): void` | 注册 Hook |
| `kf_hook(string $name, ...$args): void` | 触发动作 Hook |
| `kf_hook_filter(string $name, $value, ...$args): mixed` | 过滤型 Hook |
| `kf_json_encode($value): string\|false` | JSON 编码 |
| `kf_json_decode(string $json, bool $assoc = true): mixed` | JSON 解码 |
| `kf_json_attr(array $data): string` | 落在 HTML 属性里的 JSON（全转义） |
| `kf_http_get(string $url, int $timeout = 10, array $headers = []): string\|false` | 出站 GET |
| `kf_http_post(string $url, $postdata, int $timeout = 10, array $headers = []): string\|false` | 出站 POST |
| `kf_http_multi_get(array $urls, int $timeout = 10): array` | 并行 GET |
| `kf_substr(string $s, int $start, ?int $length = null, string $charset = 'utf-8'): string` | 按字符截取 |
| `kf_pagination(int $total, int $page, int $pagesize, string $url = '', int $show_max = 10): string` | 分页 HTML |
| `kf_dir_get(int $id, string $path = ''): string` | 取（并创建）应用目录 |
| `kf_dir_set(int $id, string $path = ''): string` | 设置应用目录 |
| `kf_send_mail($smtp, $username, $email, $subject, $message, string $charset = 'UTF-8')` | 邮件（自动引入按需模块） |
| `kf_zip(array $files, string $destfile): bool` | 压缩（自动引入按需模块） |
| `kf_unzip(string $srcfile, string $destdir): bool` | 解压（含 zip slip 防护） |

## 数据库与缓存（底层模块 API）

`db_*` / `cache_*` 带连接语义、本身就是模块边界，保持原前缀。
详见 [数据库](database.md)。

| 函数 | 说明 |
|------|------|
| `db_new(array $conf): object` | 创建 DB 实例 |
| `db_connect(object $d = null): bool` | 连接 |
| `db_close(object $d = null): bool` | 关闭 |
| `db_exec(string $sql, array $params = [], object $d = null): int\|false` | 执行 SQL |
| `db_sql_find_one(string $sql, array $params = [], object $d = null): array\|false` | 原始查询单条 |
| `db_sql_find(string $sql, array $params = [], string $key = '', object $d = null): array\|false` | 原始查询列表 |
| `db_find(string $table, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = [], object $d = null): array\|false` | 查询列表 |
| `db_find_one(string $table, array $cond = [], array $orderby = [], array $col = [], object $d = null): array\|false` | 查询单条 |
| `db_count(string $table, array $cond = [], object $d = null): int\|false` | 统计 |
| `db_maxid(string $table, string $field, array $cond = [], object $d = null): int\|false` | 最大 ID |
| `db_insert(string $table, array $arr, object $d = null): int\|false` | 插入 |
| `db_replace(string $table, array $arr, object $d = null): int\|false` | 替换插入 |
| `db_update(string $table, array $cond, array $update, object $d = null): int\|false` | 更新 |
| `db_delete(string $table, array $cond, object $d = null): int\|false` | 删除 |
| `db_truncate(string $table, object $d = null): int\|false` | 清空表 |
| `db_transaction(callable $fn, object $d = null): mixed` | 事务封装 |
| `cache_get(string $k, object $c = null): mixed` | 取缓存 |
| `cache_set(string $k, $v, int $life = 0, object $c = null): bool` | 写缓存 |
| `cache_delete(string $k, object $c = null): bool` | 删缓存 |
| `cache_truncate(object $c = null): bool` | 清缓存 |

## 底层（xiunophp）

下面这些是 `kf_` 门面背后的实现。应用代码通常不需要调用；
只有在编写扩展、或需要门面未覆盖的行为（如 `http_location()` 直接设 Location 头）时才用。

| 底层函数 | 应用侧应使用 |
|----------|--------------|
| `param` 系列 | `kf_param*` |
| `ip()` | `kf_ip()` |
| `http_referer()` | `kf_referer()` |
| `xn_csrf_token/check()` | `kf_csrf_token/check()` |
| `xn_validate()` | `kf_validate()` |
| `xn_encrypt/decrypt/signdata/rand/substr/md5()` | `kf_*` 对应 |
| `xn_log()` / `xn_hook*()` | `kf_log()` / `kf_hook*()` |
| `xn_json_encode/decode/response()` | `kf_json_encode/decode()`、`kf_json()` |
| `http_get/post/multi_get/location/403/404()` | `kf_http_*()`、`kf_redirect()` |
| `pagination()` | `kf_pagination()` |
| `xn_get_dir/set_dir()` | `kf_dir_get/set()` |
| `xn_send_mail()`、`xn_zip/unzip()`（按需模块） | `kf_send_mail()`、`kf_zip/unzip()` |

## 常量

| 常量 | 说明 |
|------|------|
| `KF_PATH` | KlockFrame 目录路径 |
| `KF_VERSION` | 框架版本号 |
| `APP_PATH` | 应用目录路径 |
| `DEBUG` | 调试模式 (1/0) |
| `IN_CMD` | CLI 模式 (true/false) |
| `XIUNOPHP_PATH` | XiunoPHP 核心路径 |
| `KF_NO_DISPATCH` | 定义后禁用自动分发 |
| `KF_RUNTIME_VERSION` | 内置交互运行时版本（2.0.11） |
| `KF_RUNTIME_SHA256` | 运行时文件的 sha256，直出前校验 |
| `KF_RUNTIME_CDN` | 同版本 CDN 地址 |
| `KF_RUNTIME_ROUTE` | 内置路由路径（`/kf/htmx.js`） |
| `KF_RUNTIME_FILE` | 框架内置运行时文件绝对路径 |

## 全局变量

| 变量 | 说明 |
|------|------|
| `$_SERVER['db']` | 数据库实例 |
| `$_SERVER['cache']` | 缓存实例 |
| `$_SERVER['conf']` | 配置数组 |
| `$_SERVER['time']` | 请求时间戳 |
| `$_SERVER['ip']` | 客户端 IP |
| `$_SERVER['method']` | 请求方法 |
| `$_SERVER['ajax']` | 是否传统 XHR（`X-Requested-With`） |
| `$_SERVER['starttime']` | 开始时间 |
| `$_SERVER['errno']` / `$_SERVER['errstr']` | 错误号 / 错误信息 |
