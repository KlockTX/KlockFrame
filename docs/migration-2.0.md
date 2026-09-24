# 从 1.x 迁移到 2.0

2.0 是**破坏性版本**：应用侧前缀统一为 `kf_`，局部刷新内建（`kf_htmx_*` 前缀整体消失），
运行时改由框架路由直出（不再有 publish/落盘步骤）。

覆盖安装前建议先跑一遍这条检查，看看命中的调用点有多少：

```bash
grep -rn "kf_htmx_\|[^_a-z]param(\|xn_csrf\|xn_validate\|xn_encrypt\|xn_decrypt\|xn_rand\|xn_log(\|xn_hook\|xn_json_encode\|xn_json_decode\|xn_substr\|pagination(\|http_get(\|http_post(\|xn_send_mail\|xn_zip\|xn_unzip\|xn_get_dir\|xn_set_dir\|ip()" app/
```

## 1. 模块重组

| 1.x | 2.0 |
|-----|-----|
| `helpers.func.php` | 拆为 `request.func.php`（请求与安全）+ `response.func.php`（输出与响应头）+ `util.func.php`（通用工具） |
| `htmx.func.php` | 取消。按职责并入 `request`（识别）、`view`（片段与运行时）、`response`（控制头） |
| `config.func.php` / `router.func.php` / `view.func.php` | 位置不变 |

装载链由内核自动完成，应用不需要改 `include`；但如果你的代码里有
`require 'KlockFrame/helpers.func.php'` 这类手工引入，改成 `KlockFrame/klockframe.php`。

## 2. 函数改名对照

### 请求与安全

| 1.x（直呼核心） | 2.0 |
|------|------|
| `param($k, $def)` | `kf_param($k, $def)` |
| `param_int/word/json/url/base64/force` | `kf_param_int/word/json/url/base64/force` |
| `ip()` | `kf_ip()` |
| `$_SERVER['REQUEST_METHOD']` | `kf_method()`（HEAD 归一为 GET） |
| `$_SERVER['HTTP_X_…']` | `kf_request_header('X-…')` |
| `$_COOKIE['k']` | `kf_cookie('k')` |
| `http_referer()` | `kf_referer()` |
| `xn_csrf_token()` | `kf_csrf_token()` |
| `xn_csrf_check()` | `xn_csrf_check()` → `kf_csrf_check()` |
| `xn_validate()` | `kf_validate()` |
| `xn_encrypt/decrypt()` | `kf_encrypt/kf_decrypt()` |
| `xn_signdata()` | `kf_signdata()` |
| `xn_rand()` | `kf_rand()` |

### 工具

| 1.x | 2.0 |
|------|------|
| `xn_log()` | `kf_log()` |
| `xn_hook()` / `xn_hook_register()` / `xn_hook_filter()` | `kf_hook()` / `kf_hook_register()` / `kf_hook_filter()` |
| `xn_json_encode()` / `xn_json_decode()` | `kf_json_encode()` / `kf_json_decode()` |
| `http_get()` / `http_post()` / `http_multi_get()` | `kf_http_get()` / `kf_http_post()` / `kf_http_multi_get()` |
| `xn_substr()` | `kf_substr()` |
| `pagination()` | `kf_pagination()` |
| `xn_get_dir()` / `xn_set_dir()` | `kf_dir_get()` / `kf_dir_set()` |
| `require xn_send_mail.func.php` + `xn_send_mail()` | `kf_send_mail()`（自动引入） |
| `require xn_zip.func.php` + `xn_zip()/xn_unzip()` | `kf_zip()` / `kf_unzip()`（自动引入） |

`db_*` 与 `cache_*` 不变。

### 局部刷新（原 htmx 集成层）

| 1.4 | 2.0 |
|------|------|
| `kf_htmx_request()` | `kf_is_htmx()` |
| `kf_htmx_boosted()` | `kf_is_boosted()` |
| `kf_htmx_history()` | `kf_is_history()` |
| `kf_htmx_prompt()` | `kf_prompt()` |
| `kf_htmx_target()` | `kf_target_id()` |
| `kf_htmx_trigger_id()` / `_name()` | `kf_trigger_id()` / `kf_trigger_name()` |
| `kf_htmx_current_url()` | `kf_request_url()` |
| `kf_htmx_csrf_ok()` | `kf_csrf_ok()` |
| `kf_htmx_view($page, $d, $frag)` | `kf_view($page, $d, $frag)`（同一函数内建判定） |
| — | `kf_is_fragment()`：新增，整页/片段判据本身 |
| `kf_htmx_capture()` | `kf_capture()` |
| `kf_htmx_fragment()` | `kf_fragment()` |
| `kf_htmx_oob()` | `kf_oob()` |
| `kf_htmx_no_cache()` | `kf_no_cache()` |
| `kf_htmx_header()` | `kf_response_header()` |
| `kf_htmx_reswap()` | `kf_reswap()` |
| `kf_htmx_retarget()` / `_reselect()` | `kf_retarget()` / `kf_reselect()` |
| `kf_htmx_trigger()` / `_after_swap()` / `_after_settle()` | `kf_trigger()` / `kf_trigger_after_swap()` / `kf_trigger_after_settle()` |
| `kf_htmx_refresh()` | `kf_refresh()` |
| `kf_htmx_push_url()` / `_replace_url()` | `kf_push_url()` / `kf_replace_url()` |
| `kf_htmx_location()` | `kf_location()` |
| `kf_htmx_redirect($url, $exit)` | `kf_redirect($url, 302, $exit)` —— **`kf_redirect` 本身已内建分支** |
| — | `kf_empty()`（原 `kf_htmx_empty()`） |
| `kf_htmx_abort_fragment()` | `kf_error_fragment()`（仍由 `kf_abort()` 自动调用） |
| `kf_htmx_tags()` | `kf_runtime()` |
| `kf_htmx_script()` | `kf_runtime_script()` |
| `kf_htmx_config_meta()` | `kf_runtime_config_meta()` |
| `kf_htmx_csrf_script()` | `kf_runtime_csrf_script()` |
| `kf_htmx_json()` | `kf_json_attr()` |
| `kf_htmx_asset_url()` | `kf_runtime_url()` |

常量：`KF_HTMX_VERSION/SHA256/CDN` → `KF_RUNTIME_VERSION/SHA256/CDN`，新增 `KF_RUNTIME_ROUTE`、`KF_RUNTIME_FILE`。

## 3. 运行时交付方式变了（不再需要 publish）

1.4 的做法是把 `js/htmx.min.js` 发布到应用 `assets/js/`。2.0 取消这条路径：

| 1.4 | 2.0 |
|------|------|
| `kf_htmx_publish()` | **删除**，无需调用 |
| `kf_htmx_asset_path()` | **删除** |
| `assets/js/htmx.min.js` | 不再需要；应用侧残留的旧副本可以直接删 |
| `app.htmx.src = 'local'` | 改成 `'route'`（默认值已是 `route`） |

新行为：框架注册内置路由 `KF_RUNTIME_ROUTE`（`/kf/htmx.js`）直出运行时，
带 `ETag`（版本号 + sha256 前缀）与 `Cache-Control: public, max-age=31536000, immutable`，
`If-None-Match` 命中时零 I/O 返回 304；吐字节前校验 sha256，不过就 500 且不带任何内容。

好处：覆盖安装新框架 = 运行时同步升级（URL 上的 `?v=` 随之变化），不存在副本漂移。
内置路由注册在应用路由**之后**，需要接管这条路径的应用可以直接 `kf_any(KF_RUNTIME_ROUTE, …)` 覆盖。

站点装在子目录时，框架会按 `app.base_url` 的路径部分额外注册一条同名路由；
若你的 `base_url` 不含路径而实际装在子目录，需自行补注册。

## 4. 模板写法升级（可选，不改也能跑）

1.4 的 `->hx('get', '/x')` 仍然有效；2.0 增加了一等方法：

```php
// 1.4
kf_button('删除')->hx('delete', '/todo/5')->hx('target', '#list')->hx('swap', 'outerHTML');
// 2.0
kf_button('删除')->delete('/todo/5')->hx_target('#list')->swap('outerHTML');
```

换入目标叫 `->hx_target()`（`->target()` 仍是 HTML 的 `<a target>`）。

## 5. 行为差异（不是改名）

1. **`kf_redirect()` 会看请求来源**：片段请求下变成 `204` + 软跳转头。
   若你有「片段请求里也想要真实 302」的场景，显式写 `http_location($url, 302)`。
2. **`kf_abort()` 在片段请求下输出无布局错误片段**（1.4 已有），XHR 分支与整页分支不变。
3. **`kf_view()` 多两个可选参数**，老调用 `kf_view('home', $data)` 行为不变。
4. **`kf_old()` 已在 1.4 移除**：校验失败改为直接重渲染表单片段，旧值取自请求参数。
5. **响应控制头更严**：含换行的头值整条拒绝（1.4 是抹掉换行），并写日志。
6. **错误页不再泄漏服务器绝对路径**（运行时缺失/校验失败时路径只进日志）。

## 6. 迁移后自检

框架自带两套测试可以直接拿来改（把 `APP_PATH` 指向你的应用即可复用夹具思路）：

```bash
php tests/unit.php   # 105 项
php tests/http.php   # 50 项
```

最小人工检查清单：

- [ ] 页面里 `hx-*` 属性照常渲染，`/kf/htmx.js?v=2.0.11` 返回 200
- [ ] 片段请求（DevTools 里看 `HX-Request`）返回的 HTML 不含 `<!DOCTYPE`
- [ ] `hx-boost` 导航仍返回整页
- [ ] 提交成功后跳转动作正常（片段请求看 `HX-Redirect`，普通请求看 302）
- [ ] 无表单字段的 `hx-delete`/`hx-put` 能通过 `kf_csrf_ok()`
