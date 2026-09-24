# 门面函数

KlockFrame 2.0 起，应用侧只有 **一个前缀：`kf_`**。
xiunophp 核心的 `param()` / `xn_*()` 降级为底层实现，业务代码不再直接调用它们。

约定：

- 读请求、出响应、渲染视图、通用工具 —— 一律 `kf_`
- `db_` / `cache_` 保持原样：它们本身就是一个模块边界，且带连接语义，不属于「门面转发」
- 底层 `xn_*` 仍然可以直接调用（例如写扩展时），但文档与示例不再出现它

## 请求（request.func.php）

### 参数

| 函数 | 底层 | 说明 |
|------|------|------|
| `kf_param($key, $default = null, $escape = true, $slashes = false)` | `param()` | 取值，默认转义 |
| `kf_param_int($key, $default = 0)` | `param_int()` | 整型 |
| `kf_param_word($key, $default = null)` | `param_word()` | 仅字母数字下划线 |
| `kf_param_json($key, $default = null)` | `param_json()` | JSON 解码 |
| `kf_param_url($key, $default = null)` | `param_url()` | URL |
| `kf_param_base64($key, $default = null)` | `param_base64()` | Base64 |
| `kf_param_force($key, $default)` | `param_force()` | 按默认值类型强转 |

### 请求信息

| 函数 | 说明 |
|------|------|
| `kf_ip()` | 客户端 IP（可信代理链解析） |
| `kf_method()` | 请求方法，大写；HEAD 归一为 GET |
| `kf_request_header($name, $default = '')` | 读请求头 |
| `kf_cookie($name, $default = '')` | 读 Cookie |
| `kf_referer()` | 来源页 |

> 不叫 `kf_header()`：那个名字属于 `<header>` 标签函数；读写成对使用
> `kf_request_header()` / `kf_response_header()`。

### 安全

| 函数 | 底层 | 说明 |
|------|------|------|
| `kf_csrf_token()` | `xn_csrf_token()` | 会话内令牌 |
| `kf_csrf_check($token = null)` | `xn_csrf_check()` | 校验（默认读 `csrf_token` 字段） |
| `kf_csrf_ok()` | — | 请求头优先、回落表单字段（局部刷新用） |
| `kf_validate($data, $rules)` | `xn_validate()` | 规则格式 `['字段' => ['required' => true, 'max' => 20, 'label' => '名称']]`，返回 `[字段 => 错误消息]` |
| `kf_encrypt()` / `kf_decrypt()` | `xn_encrypt/decrypt()` | 带认证标签的对称加密 |
| `kf_signdata()` | `xn_signdata()` | 数据签名 |
| `kf_rand($len = 8, $type = 'alnum')` | `xn_rand()` | 字符集：`num` / `lower` / `upper` / `alnum` / 其他=大小写+数字 |

### 局部刷新识别

见 [局部刷新](fragments.md) 第 4 节：`kf_is_htmx()`、`kf_is_boosted()`、`kf_is_fragment()`、
`kf_is_history()`、`kf_target_id()`、`kf_trigger_id()`、`kf_trigger_name()`、`kf_request_url()`、`kf_prompt()`。

## 响应（response.func.php）

| 函数 | 说明 |
|------|------|
| `kf_base_url()` / `kf_url($path)` / `kf_asset($path)` | URL 生成 |
| `kf_csrf_field()` | CSRF 隐藏字段（返回 `KF_Raw`，可作标签函数子节点） |
| `kf_json($data, $status = 0)` | JSON 响应并结束 |
| `kf_abort($code, $msg)` | 错误响应：片段请求回片段、XHR 回 JSON、其余回错误整页 |
| `kf_error_fragment($code, $msg)` | 只出无布局错误片段 |
| `kf_redirect($url, $code = 302, $exit = true)` | 重定向（内建按请求来源分支） |
| `kf_back()` | 回来源页 |
| `kf_flash($key = null, $value = null)` | 跨重定向一次性消息 |
| `kf_dump($var, $exit = true)` | 仅 DEBUG 下输出 |
| `kf_elapsed()` | 本次请求耗时（毫秒） |

响应控制头见 [局部刷新](fragments.md) 第 5 节。

## 视图（view.func.php）

| 函数 | 说明 |
|------|------|
| `kf_view($name, $data = [], $fragment = null, $fragment_data = [])` | 渲染视图（整页/片段自动判定） |
| `kf_layout($name)` / `kf_content()` | 布局声明与取内容 |
| `kf_partial($name, $data = [])` | 服务端模板包含 |
| `kf_share($key, $value)` | 全局共享变量 |
| `kf_capture($name, $data = [])` | 渲染片段并返回字符串 |
| `kf_fragment($name, $data = [])` | 输出片段 |
| `kf_oob($name, $selector, $data, $strategy)` | Out-of-Band 片段 |
| `kf_component()` / `kf_render_component()` | 组件注册与渲染 |
| `kf_raw($html)` | 原始 HTML |
| `kf_head()` / `kf_header()` / `kf_div()` … | HTML 标签函数 |
| `kf_runtime()` | 一行装载局部刷新能力 |
| `kf_runtime_url()` / `kf_runtime_script()` / `kf_runtime_response()` | 运行时地址、脚本标签、内置路由处理 |
| `kf_runtime_config_meta()` / `kf_runtime_csrf_script()` | 配置 meta、CSRF 注入脚本 |

## 工具（util.func.php）

| 函数 | 底层 | 说明 |
|------|------|------|
| `kf_log($msg, $tag = '', $file = '')` | `xn_log()` | 写日志 |
| `kf_hook_register()` / `kf_hook()` / `kf_hook_filter()` | `xn_hook*()` | 扩展点 |
| `kf_json_encode()` / `kf_json_decode()` | `xn_json_encode/decode()` | JSON |
| `kf_json_attr($data)` | — | 落在 HTML 属性里的 JSON（`< > & "` 全转义） |
| `kf_http_get()` / `kf_http_post()` / `kf_http_multi_get()` | `http_*()` | 出站到外部 HTTP |
| `kf_substr()` | `xn_substr()` | 按字符集截取 |
| `kf_pagination()` | `pagination()` | 分页 HTML |
| `kf_dir_get()` / `kf_dir_set()` | `xn_get_dir/set_dir()` | 应用目录 |
| `kf_send_mail()` | `xn_send_mail()` | 邮件（**自动引入按需模块**） |
| `kf_zip()` / `kf_unzip()` | `xn_zip/unzip()` | 压缩解压（自动引入按需模块，含 zip slip 防护） |

邮件、zip 这类按需模块过去要应用自己 `require` 核心文件；现在调用 `kf_*` 即可，
框架内部用 `_kf_require_module()` 惰性引入，模块不可用时返回 false 并记日志。

## 数据与缓存

`db_find()` / `db_insert()` / `cache_get()` 等保持原前缀，见
[数据库](database.md)。它们不是门面转发，而是真正带连接语义的模块 API。
