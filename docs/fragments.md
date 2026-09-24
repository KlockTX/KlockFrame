# 局部刷新（内建）

KlockFrame 2.0 把局部刷新做成框架的一等能力：同一条路由、同一个视图文件，既能返回整页，也能只返回一块 HTML。
运行时（内置 htmx 2.0.11）由框架自己的路由直出，不需要发布静态文件、不需要前端构建、绝大多数页面不需要写一行 JS。

```
浏览器                                 KlockFrame
│  <button hx-get="/todo">             kf_get('/todo', fn() =>
│─────HX-Request: true─────────────►      kf_view('todo', $items, 'todo_list'));
│                                       │
│  <ul id="todo-list">…</ul>  ◄──────────┴─ 片段请求 → 只回 todo_list
│  （就地换入，页面不刷新）                  其余 → 回整页 + 布局
```

## 1. 三步接入

### 第一步：布局 head 一行

```php
<!-- views/layout.php -->
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>
    <?= kf_runtime() ?>
</head>
```

`kf_runtime()` 输出三样东西，先后顺序无关：

| 输出 | 作用 |
|------|------|
| `<meta name="htmx-config" content="{…}">` | 运行时启动时读取的内部配置（defer 场景下唯一安全的注入点） |
| `<script src="/kf/htmx.js?v=2.0.11" defer>` | 运行时本体，由框架内置路由 `KF_RUNTIME_ROUTE` 直出 |
| `<script>…configRequest…</script>` | 给所有局部刷新请求自动附带 CSRF 请求头 |

### 第二步：决定运行时从哪来

`app.htmx.src` 三选一：

| 值 | 行为 |
|----|------|
| `'route'`（默认） | 框架内置路由直出：`Content-Type: text/javascript` + ETag（版本号 + sha256 前缀）+ `max-age=31536000, immutable`；`If-None-Match` 命中时零 I/O 返回 304 |
| `'cdn'` | jsDelivr 上的同版本文件 |
| 其他字符串 | 视为 URL 原样使用（自建 CDN、`/static/htmx.js` 等） |

直出前会校验文件的 sha256（`KF_RUNTIME_SHA256`）：上传残缺或被改动的文件不会静默送进浏览器，
校验失败返回 500 且**不吐任何一个字节**（路径细节写进日志而非错误页）。

覆盖安装新框架即自动升级运行时——URL 上的 `?v=` 会随版本变化，不存在应用侧副本漂移。

### 第三步：配置（可选）

```php
// config.php
return [
    'app' => [
        'htmx' => [
            'src'    => 'route',              // route | cdn | 自定义 URL
            'defer'  => true,
            'csrf'   => true,                 // 自动附带 CSRF 请求头
            'csrf_header' => 'X-CSRF-Token',  // 头名
            'config' => [                     // 透传给运行时 config 对象
                'defaultSwapStyle'  => 'outerHTML',
                'defaultSwapSettle' => '300ms',
                'historyCacheSize'  => 20,
                'scrollBehavior'    => 'auto',
                'logErrors'         => true,
            ],
        ],
    ],
];
```

`kf_runtime($overrides)` 的入参按项覆盖 `app.htmx.config`。

## 2. 心智模型：kf_view() 自己判定

```php
kf_get('/todo', function () {
    // 第 3 个参数 = 片段请求时渲染的视图；省略则复用同一个视图
    kf_view('todo', ['items' => $items], 'todo_list');
});
```

| 请求 | 返回 | 判据 |
|------|------|------|
| 浏览器直接访问 | 整页 + 布局 | `!kf_is_fragment()` |
| `hx-boost` 提升的导航 | 整页 + 布局（运行时自行抽取） | `kf_is_boosted()` |
| 片段请求（`HX-Request: true` 且非 boost） | 只有 `views/todo_list.php` | `kf_is_fragment()` |

整页视图里照常写 `kf_layout('layout')`，片段模式下该设置被丢弃，所以同一个视图文件天然两用。
需要手工分支时判据函数是 `kf_is_fragment()`。

## 3. 模板里写交互：元素一等方法

```php
kf_button('删除')
    ->delete('/todo/' . $id)      // hx-delete
    ->hx_target('#todo-list')     // hx-target（见下方命名说明）
    ->swap('outerHTML')           // hx-swap
    ->confirm('确定删除？');       // hx-confirm

kf_input()->get('/search')->trigger('keyup changed delay:300ms')->vals(['scope' => 'title']);
kf_body()->boost();               // 整站升级
kf_div('加载更多')->get('/order?page=2')->swap('beforeend');
kf_div('草稿')->on('::after-request', 'this.blur()');   // hx-on::after-request
```

| 方法 | 属性 |
|------|------|
| `->get()/post()/put()/patch()/delete()` | `hx-get` 等请求动词 |
| `->hx_target()` | `hx-target` |
| `->swap($style, $options)` | `hx-swap`，可带 `['swap' => '0.5s', 'transition' => true]` |
| `->oob($selector, $strategy)` | `hx-swap-oob` |
| `->trigger()` | `hx-trigger` |
| `->boost()` | `hx-boost`（裸属性） |
| `->confirm()` / `->prompt()` | `hx-confirm` / `hx-prompt` |
| `->vals()` / `->headers()` | `hx-vals` / `hx-headers`（数组自动 JSON 化） |
| `->params()` / `->select()` / `->indicator()` | 同名属性 |
| `->preserve()` / `->sync()` / `->push_url()` | 同名属性 |
| `->on($event, $code)` | `hx-on::<event>` |
| `->hx($prop, $value)` / `->hx([...])` | 通用入口，任意 `hx-*` |

命名说明：`->target()` 已被 HTML 的 `<a target>` 占用，所以换入目标叫 `->hx_target()`。
值为 `true` 渲染成裸属性，`false`/`null` 直接不输出该属性——可以写 `->get($can_load ?: false)`。

## 4. 请求侧：识别这次请求从哪来

| 函数 | 对应头 | 返回 |
|------|--------|------|
| `kf_is_htmx()` | `HX-Request` | bool |
| `kf_is_boosted()` | `HX-Boosted` | bool |
| `kf_is_fragment()` | 二者合成 | bool，是否只回片段 |
| `kf_is_history()` | `HX-History-Restore-Request` | bool |
| `kf_target_id()` | `HX-Target` | 目标元素 id |
| `kf_trigger_id()` | `HX-Trigger` | 触发元素 id |
| `kf_trigger_name()` | `HX-Trigger-Name` | 触发元素 name |
| `kf_request_url()` | `HX-Current-URL` | 发起请求时的地址栏 URL |
| `kf_prompt()` | `HX-Prompt` | 用户在 `hx-prompt` 里输入的文本 |
| `kf_csrf_ok()` | `X-CSRF-Token` / `csrf_token` | bool，头优先、回落表单字段 |

## 5. 响应侧：控制头

| 函数 | 头 | 用途 |
|------|----|------|
| `kf_response_header($name, $value, $replace)` | 任意 `HX-*` | 通用出口 |
| `kf_reswap($style, $options)` | `HX-Reswap` | 本次响应改换入策略 |
| `kf_retarget($selector)` | `HX-Retarget` | 换到别的元素 |
| `kf_reselect($selector)` | `HX-Reselect` | 只取响应里的一块 |
| `kf_trigger($events, $payload)` | `HX-Trigger` | 触发客户端事件 |
| `kf_trigger_after_swap()` / `kf_trigger_after_settle()` | 同名 | 换入后 / 稳定后触发 |
| `kf_location($spec)` | `HX-Location` | 交给客户端接管导航（可带 target/swap） |
| `kf_refresh()` | `HX-Refresh` | 刷新整页 |
| `kf_push_url($url\|false)` | `HX-Push-Url` / `HX-Push` | 同步地址栏 |
| `kf_replace_url($url)` | `HX-Replace-Url` | 只替换不新增历史 |
| `kf_empty($exit)` | — | 204：只走事件不换入 |
| `kf_no_cache()` | `Cache-Control` | 片段响应禁缓存 |

### 重定向已经内建判定

```php
kf_post('/order/pay', function () {
    // …扣款…
    kf_redirect('/order/done');   // 一条语句，三种来源各自正确
});
```

- 片段请求 → `204` + 软跳转头，由客户端执行整页跳转
- boost 导航 / 浏览器直访 → 真实 `302`

需要先送事件再跳转时传 `$exit=false`（运行时先处理事件头，再处理跳转头）：

```php
kf_redirect('/login', 302, false);
kf_trigger('session:expired');
```

### 防护

`kf_response_header()` 拒绝非法头名与**含换行的头值**（整条不发送，而不是抹掉换行留下语义错乱的头），
并写入日志。响应拆分注入在这层不可行。

## 6. 片段与 Out-of-Band

```php
kf_capture(string $view, array $data = []): string  // 返回片段 HTML（已 trim）
kf_fragment(string $view, array $data = []): void   // 直接输出
kf_oob(string $view, string $selector = '', array $data = [], string $strategy = ''): string
```

一次请求换多个位置：

```php
kf_post('/todo/add', function () {
    $items = todo_add($text);
    echo kf_capture('todo_list', ['items' => $items]);                       // 主目标
    echo kf_oob('todo_count', '#todo-count', ['n' => count($items)], 'outerHTML');
});
```

`hx-swap-oob` 由 `kf_oob()` 注入到片段根元素上，因此片段必须只有一个根元素、
且不要以注释或裸文本开头；`$selector` 传空则按片段自身 `id` 匹配。

## 7. 常见模式

### 搜索建议（防抖输入）

```php
kf_input()->name('q')->placeholder('搜索')
    ->get('/search')->trigger('keyup changed delay:300ms')->hx_target('#results')->params('q');
```

### 无限滚动

```php
kf_get('/order', function () {
    $page  = max(1, kf_param_int('page', 1));
    $orders = db_find('order', ['uid' => $uid], ['id' => -1], $page, 20);
    if (kf_is_fragment()) {
        echo kf_capture('order_rows', ['orders' => $orders]);
        if (count($orders) === 20) echo kf_capture('order_more', ['page' => $page]);
        return;
    }
    kf_view('order', ['orders' => $orders, 'page' => $page]);
});
```

### 点击编辑

```php
kf_span($user['bio'])->get('/user/bio/edit')->hx_target('this')->swap('outerHTML');
```

### 校验失败回填

```php
kf_post('/profile', function () {
    $errors = kf_validate($_POST, ['nickname' => ['required' => true, 'max' => 20, 'label' => '昵称']]);
    if ($errors) {
        // 错误提示和旧值在同一份响应里回来
        echo kf_capture('profile_form', ['errors' => $errors, 'v' => $_POST]);
        return;
    }
    // …保存…
    kf_trigger('toast:show', ['msg' => '已保存']);
    kf_empty();
});
```

视图里直接读 `$v`（`<input value="<?= htmlspecialchars($v['nickname'] ?? '', ENT_QUOTES) ?>">`）。

> 1.3 及以前这条链路是「校验失败 → `kf_redirect()` 回表单页 → flash 存旧值 → 回填」，
> 要一次重定向加一趟 session；一次响应即可闭环，因此 `kf_old()` 已在 1.4 移除。

### 整站升级（改动最小）

```php
kf_body(...$children)->boost();
```

所有 `<a>`/`<form>` 立刻变成无刷新导航，服务端一行不用改。

## 8. 坑清单

| 现象 | 原因与解法 |
|------|-----------|
| 点删除后整块列表被套进了按钮里 | 请求动词没写 `hx_target`，默认目标就是元素自身。显式 `->hx_target('#list')` |
| 控制台报 `htmx:oobErrorNoTarget, #xxx` | OOB 片段的目标在当前页面上不存在（片段被复用到别的页面）。要么该页面也放该元素，要么按目标页面区分响应；不抛异常但会脏日志 |
| 片段被塞进整页、出现两个 `<html>` | 用了模板包含而不是 `kf_capture()`；或 boost 请求被判成片段 |
| 换入后计数没变 | 忘记 `kf_oob()`，或 `$selector` 与页面元素 id 不一致 |
| 204 之后 echo 的内容不见了 | `204 No Content` 按 HTTP 规范不带响应体，正常现象 |
| 前进/后退看到旧数据 | 片段响应需要 `kf_no_cache()`；或该请求是 `kf_is_history()` |
| 删除按钮点了没反应 | `hx-confirm` 被浏览器拦截，或服务端返回 4xx（运行时默认忽略 4xx 的换入，可在 `htmx:responseError` 里处理） |
| 表单提交报 403 | `kf_csrf_field()` 要作为 `kf_form()` 的子节点（返回 `KF_Raw`，不会被转义成文字） |
| 站点装在子目录时运行时 404 | 框架会按 `app.base_url` 的路径部分额外注册一条路由；若 base 配置不含路径，需自行 `kf_any('/子目录' . KF_RUNTIME_ROUTE, 'kf_runtime_response')` |

## 9. 安全

- 片段与整页共用同一套转义规则：`KF_Element` 对文本和属性值一律 `htmlspecialchars`，
  需要原样输出必须显式 `kf_raw()`。
- 不要把用户输入拼进 `hx-*` 之外的地方再用 `kf_raw()` 输出——运行时把片段当 HTML 解析。
- 运行时的 `allowEval` 默认放行 `hx-vals="js:…"`、`hx-on` 里的 JS。
  若要禁止，配置 `'allowEval' => false`，并且不要把这些属性接受用户输入。
- CSRF 请求头由 `kf_runtime()` 自动附带，服务端用 `kf_csrf_ok()` 校验；`app.htmx.csrf => false` 可关掉注入。

## 10. 验证

```bash
php tests/unit.php    # 105 项：门面转发 / 片段 / OOB / 元素方法 / 头校验 / 运行时
php tests/http.php    # 50 项：php -S 真实往返，断言状态码、控制头、304、CSRF 头通道
```

断言全部基于运行时 2.0.11 实际发出的请求头，改坏任何一条响应头语义都会红。
