<?php
/*
 * response.func.php — 响应输出
 *
 * URL 生成、JSON、重定向、错误页、Flash，以及局部刷新的响应侧控制头。
 *
 * 与 1.x 的关键差别：响应侧不再区分「普通响应」和「htmx 响应」——
 * kf_redirect()/kf_abort()/kf_json() 自己会看请求是哪一类，
 * 应用代码只写一种逻辑，浏览器直访、boost 导航、片段请求各自得到正确形态。
 *
 * 防护：kf_response_header() 拒绝非法头名与含换行的头值（整条不发，
 * 而不是抹掉换行留下语义错乱的头），避免响应拆分注入。
 */

// ---------- 内置交互运行时的元信息 ----------
!defined('KF_RUNTIME_VERSION') AND define('KF_RUNTIME_VERSION', '2.0.11');
// 上游 dist/htmx.min.js 的 sha256：直出前校验，残缺或被替换的文件不会被静默送进浏览器
!defined('KF_RUNTIME_SHA256')  AND define('KF_RUNTIME_SHA256', 'd6fdc75f204e6bdefa99b69bf1e6d4ac69b8a364f77929f45c13476b4000f717');
// 运行时由框架路由直出的路径（子目录部署时配合 app.base_url 使用）
!defined('KF_RUNTIME_ROUTE')   AND define('KF_RUNTIME_ROUTE', '/kf/htmx.js');
!defined('KF_RUNTIME_FILE')    AND define('KF_RUNTIME_FILE', KF_PATH . 'js/htmx.min.js');
!defined('KF_RUNTIME_CDN')     AND define('KF_RUNTIME_CDN', 'https://cdn.jsdelivr.net/npm/htmx.org@2.0.11/dist/htmx.min.js');

// ========== URL 与资源 ==========

/**
 * base_url（进程内缓存，kf_config_reset_cache 可失效）
 */
function kf_base_url(): string {
    if (!isset($GLOBALS['_kf_base_url'])) {
        $GLOBALS['_kf_base_url'] = kf_config('app.base_url', '');
    }
    return $GLOBALS['_kf_base_url'];
}

/**
 * 生成资源 URL
 *
 * @param string $path 相对 assets/ 的路径，如 'css/style.css'
 */
function kf_asset(string $path): string {
    return rtrim(kf_base_url(), '/') . '/assets/' . ltrim($path, '/');
}

/**
 * 生成站点 URL
 */
function kf_url(string $path = ''): string {
    return rtrim(kf_base_url(), '/') . '/' . ltrim($path, '/');
}

// ========== 表单与调试 ==========

/**
 * CSRF 隐藏字段
 *
 * 返回 KF_Raw：作为标签函数的子节点时必须原样输出，
 * 否则会被模板引擎按文本转义成页面上可见的文字。
 */
function kf_csrf_field(): KF_Raw {
    return kf_raw('<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(kf_csrf_token(), ENT_QUOTES) . '">');
}

/**
 * 调试输出（仅 DEBUG）
 */
function kf_dump($var, bool $exit = true): void {
    if (!defined('DEBUG') || !DEBUG) return;
    echo '<pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;margin:10px;overflow:auto;">';
    var_dump($var);
    echo '</pre>';
    if ($exit) exit;
}

/**
 * 本次请求耗时（毫秒）
 */
function kf_elapsed(): float {
    // CLI 工具或自定义入口可能没有 starttime，缺失按 0 计而非报未定义索引
    $start = (float)($_SERVER['starttime'] ?? 0);
    if ($start <= 0.0) return 0.0;
    return round((microtime(true) - $start) * 1000, 2);
}

// ========== Flash ==========

/**
 * 一次性消息（跨重定向）
 *
 * 只在真正发生整页跳转时才有意义；同一次响应内的提示请用
 * kf_trigger() 直接送达客户端，省掉一趟 session。
 *
 * @param string|null $key   null 时取全部
 * @param mixed       $value null 时读取并消费
 */
function kf_flash(?string $key = null, $value = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($key === null) {
        return $_SESSION['_kf_flash'] ?? [];
    }
    if ($value === null) {
        $val = $_SESSION['_kf_flash'][$key] ?? null;
        unset($_SESSION['_kf_flash'][$key]);
        return $val;
    }
    $_SESSION['_kf_flash'][$key] = $value;
    return null;
}

// ========== 响应输出 ==========

/**
 * JSON 响应
 *
 * 直接把 $data 作为响应体输出（不套 {code,message,data}），
 * 便于前端按顶层字段取值。
 *
 * @param mixed $data       响应数据
 * @param int   $statusCode HTTP 状态码，0 表示不改动
 */
function kf_json($data, int $statusCode = 0): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        if ($statusCode > 0) {
            http_response_code($statusCode);
        }
    }
    $json = kf_json_encode($data);
    echo $json === false ? '{}' : $json;
    exit;
}

/**
 * 中止并返回错误
 *
 * 片段请求 → 无布局错误片段；XHR → JSON；其余 → 错误整页。
 * 视图查找顺序：views/error/{code}.php → views/{code}.php → 框架默认错误 HTML
 *
 * @param int    $code    HTTP 状态码
 * @param string $message 错误消息
 */
function kf_abort(int $code, string $message = ''): void {
    if (!headers_sent()) {
        http_response_code($code);
    }
    // 局部刷新：只回片段，带布局会让运行时抽不出内容
    if (kf_is_fragment()) {
        kf_error_fragment($code, $message);
    }
    if (!empty($_SERVER['ajax'])) {
        kf_json(['success' => false, 'code' => $code, 'message' => $message], $code);
    }
    $view = APP_PATH . 'views/error/' . $code . '.php';
    if (is_file($view)) {
        kf_view('error/' . $code, ['code' => $code, 'message' => $message]);
        exit;
    }
    // 回退：项目可能把错误视图直接放在 views/ 下（如 views/404.php）
    if (is_file(APP_PATH . 'views/' . $code . '.php')) {
        kf_view((string)$code, ['code' => $code, 'message' => $message]);
        exit;
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $code . '</title></head><body><h1>'
        . $code . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p></body></html>';
    exit;
}

/**
 * 输出无布局的错误片段（由 kf_abort 在片段请求下调用）
 *
 * 视图查找顺序与 kf_abort 一致；错误视图自身再次 abort 时不再递归。
 *
 * @param int    $code
 * @param string $message
 */
function kf_error_fragment(int $code, string $message = ''): void {
    static $rendering = false;
    if (!$rendering) {
        $rendering = true;
        foreach (['error/' . $code, (string)$code] as $view) {
            if (kf_view_file($view) === null) continue;
            echo kf_capture($view, ['code' => $code, 'message' => $message]);
            exit;
        }
    }
    echo (string)kf_div(kf_h1((string)$code), kf_p($message))
        ->class('kf-error')->id('kf-error-' . $code);
    exit;
}

/**
 * 重定向
 *
 * 自动按请求来源选一种运行时能正确消化的方式：
 * - 片段请求 → 204 + 重定向控制头，由客户端执行整页跳转
 * - boost 导航 / 浏览器直访 → 真实 $code（默认 302）
 *
 * 需要「先送事件再跳转」时传 $exit=false（运行时先处理事件头再处理跳转头）。
 *
 * @param string $url
 * @param int    $code 整页跳转用的状态码
 * @param bool   $exit false 时只设头不结束响应
 */
function kf_redirect(string $url, int $code = 302, bool $exit = true): void {
    if (kf_is_fragment()) {
        if (!headers_sent()) {
            http_response_code(204);
        }
        kf_response_header('Redirect', $url);
        if ($exit) exit;
        return;
    }
    http_location($url, $code);
}

/**
 * 返回上一页
 */
function kf_back(): void {
    $referer = kf_referer();
    http_location($referer !== '' ? $referer : '/');
}

// ========== 局部刷新：响应侧控制 ==========

/**
 * 校验并规范化响应控制头（内部辅助，纯函数便于单测）
 *
 * @return string|false 非法返回 false，合法返回补全 HX- 前缀后的头名
 */
function _kf_response_header_normalize(string $name, string $value): string|false {
    if (!preg_match('#^(?:HX-)?[A-Za-z][A-Za-z0-9-]*$#', $name)) {
        kf_log('[kf_response_header] 非法头名被拒绝: ' . substr($name, 0, 40), 'response');
        return false;
    }
    if (strpbrk($value, "\r\n") !== false) {
        // 含换行会被拆成额外响应头，整条拒绝
        kf_log('[kf_response_header] 头值含换行被拒绝: ' . $name, 'response');
        return false;
    }
    return strncmp($name, 'HX-', 3) === 0 ? $name : 'HX-' . $name;
}

/**
 * 设置任意响应控制头
 *
 * @param string $name    'Reswap' 或 'HX-Reswap'
 * @param string $value
 * @param bool   $replace 是否覆盖同名头
 * @return bool 非法或头已发送时 false
 */
function kf_response_header(string $name, string $value = 'true', bool $replace = true): bool {
    $header = _kf_response_header_normalize($name, $value);
    if ($header === false || headers_sent()) return false;
    header($header . ': ' . $value, $replace);
    return true;
}

/**
 * 覆盖本次响应的换入策略
 *
 * @param string $style   innerHTML|outerHTML|beforebegin|afterbegin|beforeend|afterend|delete|none
 * @param array  $options 如 ['swap' => '0.5s', 'transition' => true]
 */
function kf_reswap(string $style, array $options = []): bool {
    $value = $style;
    foreach ($options as $k => $v) {
        $value .= ' ' . $k . ':' . (is_bool($v) ? ($v ? 'true' : 'false') : $v);
    }
    return kf_response_header('Reswap', $value);
}

/**
 * 把换入目标改到别的元素
 */
function kf_retarget(string $selector): bool {
    return kf_response_header('Retarget', $selector);
}

/**
 * 换入后重新抽取目标的选择器
 */
function kf_reselect(string $selector): bool {
    return kf_response_header('Reselect', $selector);
}

/**
 * 触发客户端事件（响应到达即触发）
 *
 * @param string|array $events  事件名，或 [事件名 => 载荷]
 * @param mixed        $payload 单个事件名的载荷
 * @param string       $suffix  内部使用：'-After-Swap' / '-After-Settle'
 */
function kf_trigger($events, $payload = null, string $suffix = ''): bool {
    if (is_string($events)) {
        $events = [$events => $payload === null ? true : $payload];
    }
    $json = kf_json_encode($events);
    return kf_response_header('Trigger' . $suffix, $json === false ? '{}' : $json);
}

/**
 * 换入完成后触发客户端事件
 */
function kf_trigger_after_swap($events, $payload = null): bool {
    return kf_trigger($events, $payload, '-After-Swap');
}

/**
 * 换入稳定后触发客户端事件
 */
function kf_trigger_after_settle($events, $payload = null): bool {
    return kf_trigger($events, $payload, '-After-Settle');
}

/**
 * 让客户端刷新整页
 */
function kf_refresh(): bool {
    return kf_response_header('Refresh');
}

/**
 * 同步地址栏 URL（不重新请求）
 *
 * @param string|bool $url false 表示不写入历史记录
 */
function kf_push_url($url): bool {
    return $url === false
        ? kf_response_header('Push', 'false')
        : kf_response_header('Push-Url', (string)$url);
}

/**
 * 仅替换地址栏 URL，不新增历史记录
 */
function kf_replace_url(string $url): bool {
    return kf_response_header('Replace-Url', $url);
}

/**
 * 交由客户端接管整页导航
 *
 * @param string|array $spec 路径，或 path/target/swap/vals/headers 等字段
 */
function kf_location($spec): bool {
    if (is_string($spec)) {
        $spec = ['path' => $spec];
    }
    $json = kf_json_encode($spec);
    return kf_response_header('Location', $json === false ? '{}' : $json);
}

/**
 * 空响应：只触发事件/走副作用，不换入任何内容
 *
 * 204 按 HTTP 规范不携带响应体。
 */
function kf_empty(bool $exit = true): void {
    if (!headers_sent()) {
        http_response_code(204);
    }
    if ($exit) exit;
}

/**
 * 片段响应禁用缓存
 *
 * 不加的话浏览器缓存会污染历史记录与局部刷新结果。
 */
function kf_no_cache(): void {
    if (headers_sent()) return;
    header('Cache-Control: no-store, must-revalidate');
}
