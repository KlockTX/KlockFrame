<?php
/*
 * misc.func.php — 核心工具函数
 *
 * 包含: 错误处理, URL解析, 参数获取, HTTP, 文件, 日志, 分页
 * 扩展: Hook系统, JSON响应, CSRF, 验证器
 */

// ========== 错误处理 ==========

function error_handle(int $errno, string $errstr, string $errfile, int $errline): bool {
    // 忽略 @ 抑制的错误
    if (!(error_reporting() & $errno)) return true;

    $levels = [
        E_WARNING    => 'Warning',
        E_NOTICE     => 'Notice',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE  => 'User Notice',
        E_STRICT     => 'Strict',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    $level = $levels[$errno] ?? 'Unknown';

    $s = "PHP $level:  $errstr in $errfile on line $errline\n";
    if (DEBUG > 1) {
        $s .= xn_debug_backtrace();
    }
    echo nl2br(htmlspecialchars($s));
    xn_log($s, 'php_error');
    return true;
}

function xn_debug_backtrace(): string {
    $s = '';
    $traces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    foreach ($traces as $i => $t) {
        $file = $t['file'] ?? '';
        $line = $t['line'] ?? '';
        $func = $t['function'] ?? '';
        $class = $t['class'] ?? '';
        $type = $t['type'] ?? '';
        $s .= "#$i $file($line): $class$type$func()\n";
    }
    return $s;
}

function xn_error(int $errno, string $errstr): void {
    $_SERVER['errno'] = $errno;
    $_SERVER['errstr'] = $errstr;
    DEBUG AND trigger_error($errstr, E_USER_ERROR);
}

function xn_message(int $errno, string $errstr): void {
    global $conf;
    $_SERVER['errno'] = $errno;
    $_SERVER['errstr'] = $errstr;
    if ($errno > 0) {
        DEBUG AND xn_log("errno: $errno, errstr: $errstr", 'xn_message');
    }
    // AJAX 请求返回 JSON
    if (!empty($_SERVER['ajax'])) {
        xn_json_response($errno, $errstr);
    }
    // 非 AJAX 显示提示页
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($errstr) . '</title></head><body><h1>' . htmlspecialchars($errstr) . '</h1></body></html>';
    exit;
}

// ========== URL 解析 ==========

/**
 * 解析 URL 路由参数
 *
 * /user-login.htm?a=b  →  [0=>'user', 1=>'login', 'a'=>'b']
 * /user/login?a=b      →  [0=>'user', 1=>'login', 'a'=>'b']
 *
 * 性能优化 (1.1):
 * - 用 strpos/substr 替代 parse_url（避免正则开销）
 * - .htm 后缀检测用 substr 比较，避免 preg_replace
 */
function xn_url_parse(string $request_url): array {
    // 快速分离 path 与 query（避免 parse_url 的开销）
    $qpos = strpos($request_url, '?');
    if ($qpos !== false) {
        $path = substr($request_url, 0, $qpos);
        $query = substr($request_url, $qpos + 1);
    } else {
        $path = $request_url;
        $query = '';
    }

    // 去掉 .htm 后缀（不区分大小写）
    $len = strlen($path);
    if ($len >= 4) {
        $suffix = strtolower(substr($path, -4));
        if ($suffix === '.htm') {
            $path = substr($path, 0, -4);
        }
    }
    // 去掉开头和结尾的 /
    $path = trim($path, '/');

    $arr = [];
    if ($path !== '') {
        // 支持 user-login 和 user/login 两种格式
        $parts = preg_split('#[-/]#', $path);
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $arr[$i] = $part;
            }
        }
    }

    // 解析 query string
    if ($query) {
        parse_str($query, $qs);
        if ($qs) {
            $arr = array_merge($arr, $qs);
        }
    }

    return $arr;
}

// ========== 参数获取 ==========

function param(string $k, $defval = null, bool $htmlspecialchars = true, bool $addslashes = false) {
    if (!isset($_REQUEST[$k])) return $defval;
    $v = $_REQUEST[$k];
    if (is_array($v)) return $v;
    if (is_string($v)) {
        $htmlspecialchars AND $v = htmlspecialchars($v, ENT_QUOTES);
        $addslashes AND $v = addslashes($v);
    }
    return $v;
}

function param_word(string $k, $defval = null): string {
    if (!isset($_REQUEST[$k])) return (string)$defval;
    $v = $_REQUEST[$k];
    $v = preg_replace('/[^\w]/', '', $v);
    return $v !== '' ? $v : (string)$defval;
}

function param_int(string $k, int $defval = 0): int {
    if (!isset($_REQUEST[$k])) return $defval;
    return (int)$_REQUEST[$k];
}

function param_base64(string $k, $defval = null) {
    $v = param($k, $defval, false);
    if ($v === $defval) return $defval;
    return base64_decode($v);
}

function param_json(string $k, $defval = null) {
    $v = param($k, $defval, false);
    if ($v === $defval) return $defval;
    return xn_json_decode($v);
}

function param_url(string $k, $defval = null): string {
    $v = param($k, $defval, false);
    if ($v === $defval) return $defval;
    // 仅允许 http/https
    if (preg_match('#^https?://#i', $v)) return $v;
    return '';
}

function param_force(string $k, $defval) {
    if (!isset($_REQUEST[$k])) return $defval;
    $v = $_REQUEST[$k];
    if (is_int($defval)) return (int)$v;
    if (is_float($defval)) return (float)$v;
    if (is_bool($defval)) return (bool)$v;
    return (string)$v;
}

// 超级全局安全访问
function _GET(string $k, $defval = null) {
    return $_GET[$k] ?? $defval;
}
function _POST(string $k, $defval = null) {
    return $_POST[$k] ?? $defval;
}
function _COOKIE(string $k, $defval = null) {
    return $_COOKIE[$k] ?? $defval;
}
function _SERVER(string $k, $defval = null) {
    return $_SERVER[$k] ?? $defval;
}
function G(string $k, $defval = null) {
    return $_REQUEST[$k] ?? $defval;
}

// ========== IP ==========

/**
 * 获取客户端 IP
 *
 * 配置 $conf['trusted_proxies']（数组或逗号分隔字符串）后进入严格模式：
 * 仅当直连地址属于可信代理时才采信 X-Forwarded-For / X-Real-IP，否则一律用 REMOTE_ADDR。
 * 未配置时保持历史行为（反向代理站点可直接升级），但转发头由客户端可控，
 * 直连场景下该值可被伪造，建议部署在代理后面时显式配置。
 */
function ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted = $GLOBALS['conf']['trusted_proxies'] ?? null;

    if ($trusted !== null && $trusted !== '') {
        $list = is_array($trusted) ? $trusted : explode(',', (string)$trusted);
        $list = array_filter(array_map('trim', $list), static fn($v) => $v !== '');
        $direct_is_proxy = in_array($remote, $list, true);
        if (!$direct_is_proxy) {
            // 直连非可信代理：转发头不可信，直接返回连接层地址
            return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    // 兼容模式：历史行为
    static $warned = false;
    if (!$warned && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && IN_CMD === false) {
        $warned = true;
        error_log('[ip] X-Forwarded-For 被采信但未配置 trusted_proxies，该头可被客户端伪造；'
            . '如站点位于反向代理之后，请设置 $conf[\'trusted_proxies\']');
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// ========== HTTP ==========

function http_get(string $url, int $timeout = 10, array $headers = []): string|false {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => $timeout,
            'header'  => implode("\r\n", $headers),
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    return @file_get_contents($url, false, $ctx);
}

function http_post(string $url, $postdata, int $timeout = 10, array $headers = []): string|false {
    if (is_array($postdata)) {
        $postdata = http_build_query($postdata);
        $headers[] = 'Content-type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'timeout' => $timeout,
            'header'  => implode("\r\n", $headers),
            'content' => $postdata,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    return @file_get_contents($url, false, $ctx);
}

function https_get(string $url, int $timeout = 10): string|false {
    return http_get($url, $timeout);
}

function https_post(string $url, $postdata, int $timeout = 10): string|false {
    return http_post($url, $postdata, $timeout);
}

function http_multi_get(array $urls, int $timeout = 10): array {
    if (!function_exists('curl_multi_init')) {
        $results = [];
        foreach ($urls as $url) {
            $results[] = http_get($url, $timeout);
        }
        return $results;
    }
    $handles = [];
    $mh = curl_multi_init();
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $results = [];
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status === CURLM_OK);
    foreach ($handles as $i => $ch) {
        $results[$i] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function http_url_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $parts = parse_url($uri);
    return $parts['path'] ?? '/';
}

function http_404(): void {
    header('HTTP/1.1 404 Not Found');
    exit;
}

function http_403(): void {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

function http_location(string $url, int $code = 302): void {
    header("Location: $url", true, $code);
    exit;
}

function http_referer(): string {
    return $_SERVER['HTTP_REFERER'] ?? '';
}

// ========== 文件操作 ==========

function file_get_contents_try(string $file, int $times = 3): string|false {
    for ($i = 0; $i < $times; $i++) {
        $s = @file_get_contents($file);
        if ($s !== false) return $s;
        usleep(100000); // 100ms
    }
    return false;
}

function file_put_contents_try(string $file, $data, int $times = 3, int $flags = 0): int|false {
    // 追加模式不能 tmp+rename（会丢失追加语义），回退直接写入
    if ($flags & FILE_APPEND) {
        for ($i = 0; $i < $times; $i++) {
            $n = @file_put_contents($file, $data, $flags | LOCK_EX);
            if ($n !== false) return $n;
            usleep(100000);
        }
        return false;
    }
    // 非追加：tmp + rename 原子写入，避免写一半崩溃损坏目标文件
    for ($i = 0; $i < $times; $i++) {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $n = @file_put_contents($tmp, $data, $flags | LOCK_EX);
        if ($n !== false) {
            if (@rename($tmp, $file)) return $n;
            @unlink($tmp);
        }
        usleep(100000);
    }
    return false;
}

/**
 * 安全修改 PHP/JSON 配置文件中的变量
 * 先备份, 再写入, 失败可恢复
 */
function file_replace_var(string $file, array $replace, bool $auto_add = false): string|false {
    $s = file_get_contents($file);
    if ($s === false) return false;
    foreach ($replace as $k => $v) {
        $pattern = '/\$' . preg_quote($k, '/') . '\s*=\s*[^;\r\n]+/i';
        $replacement = '$' . $k . ' = ' . var_export($v, true);
        if (preg_match($pattern, $s)) {
            $s = preg_replace($pattern, $replacement, $s);
        } elseif ($auto_add) {
            $s .= "\n" . $replacement . ";\n";
        }
    }
    // 备份
    $bakfile = $file . '.bak';
    copy($file, $bakfile);
    $n = file_put_contents_try($file, $s);
    return $n !== false ? $s : false;
}

function file_backup(string $file): string|false {
    $bakfile = $file . '.bak';
    if (copy($file, $bakfile)) return $bakfile;
    return false;
}

function file_backup_restore(string $file): bool {
    $bakfile = $file . '.bak';
    if (file_exists($bakfile)) {
        return copy($bakfile, $file);
    }
    return false;
}

function file_backup_unlink(string $file): bool {
    $bakfile = $file . '.bak';
    if (file_exists($bakfile)) {
        return unlink($bakfile);
    }
    return true;
}

// ========== 目录操作 ==========

/**
 * 按 ID 生成三级目录: 123456789 → 123/456/789
 */
function xn_set_dir(int $id, string $path = ''): string {
    $id = sprintf('%09d', $id);
    $dir1 = substr($id, 0, 3);
    $dir2 = substr($id, 3, 3);
    $dir3 = substr($id, 6, 3);
    $dir = $path . $dir1 . '/' . $dir2 . '/' . $dir3 . '/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function xn_get_dir(int $id, string $path = ''): string {
    $id = sprintf('%09d', $id);
    $dir1 = substr($id, 0, 3);
    $dir2 = substr($id, 3, 3);
    $dir3 = substr($id, 6, 3);
    return $path . $dir1 . '/' . $dir2 . '/' . $dir3 . '/';
}

function rmdir_recusive(string $dir, bool $keep_root = false): bool {
    if (!is_dir($dir)) return false;
    // 安全检查: 不允许删除根目录
    if (in_array(realpath($dir), ['/', '\\', realpath($_SERVER['DOCUMENT_ROOT'] ?? '.')])) {
        return false;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rmdir_recusive($path, false);
        } else {
            @unlink($path);
        }
    }
    if (!$keep_root) {
        @rmdir($dir);
    }
    return true;
}

// ========== 日志 ==========

function xn_log(string $s, string $tag = '', string $file = ''): void {
    global $conf;
    $log_path = $conf['log_path'] ?? './';
    $s = xn_log_post_data($s);
    if (!$file) {
        $ym = date('Ym');
        $d = date('d');
        $dir = $log_path . $ym . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . $d . ($tag ? '_' . $tag : '') . '.php';
    }
    $line = date('Y-m-d H:i:s') . ' ' . $s . "\n";
    // 防止直接访问：日志落为 .php，守卫必须在文件首行才有效。
    // 仅「文件不存在时写守卫」不够——文件若被其他方式创建，追加的记录即可被 Web 直接读取；
    // 而守卫也无法靠追加补上（那样既有内容仍排在最前），只能在确认缺失后原子重写。
    if (!file_exists($file)) {
        file_put_contents_try($file, "<?php exit;?>\n" . $line, 1, FILE_APPEND);
        return;
    }
    $existing = (string)@file_get_contents($file);
    if ($existing !== '' && strncmp($existing, '<?php', 5) !== 0) {
        // 已有内容但缺守卫：整体重写为「守卫 + 原内容 + 本次记录」
        if (file_put_contents_try($file, "<?php exit;?>\n" . $existing . $line, 1) !== false) {
            return;
        }
        // 重写失败时仍退回追加，宁可暂缺守卫也不丢日志
    }
    file_put_contents_try($file, $line, 1, FILE_APPEND);
}

/**
 * 日志中脱敏密码等敏感字段
 */
function xn_log_post_data(string $s): string {
    $sensitive = ['password', 'passwd', 'pwd', 'secret', 'token', 'auth_key'];
    foreach ($sensitive as $key) {
        $s = preg_replace('/(' . $key . ')=([^&\s]+)/i', '$1=***', $s);
    }
    return $s;
}

// ========== JSON ==========

function xn_json_encode($v): string|false {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function xn_json_decode(string $s, bool $assoc = true) {
    if ($s === '') return null;
    $r = json_decode($s, $assoc);
    return $r === null && trim($s) !== 'null' ? false : $r;
}

// ========== 分页 ==========

function pagination(int $total, int $page, int $pagesize, string $url = '', int $show_max = 10): string {
    if ($total === 0) return '';
    $pages = (int)ceil($total / $pagesize);
    if ($pages <= 1) return '';
    $page = max(1, min($page, $pages));

    $s = '<div class="pagination">';
    // 上一页
    if ($page > 1) {
        $s .= '<a href="' . xn_url_page($url, $page - 1) . '">&laquo;</a>';
    }
    // 页码
    $start = max(1, $page - (int)($show_max / 2));
    $end = min($pages, $start + $show_max - 1);
    $start = max(1, $end - $show_max + 1);

    if ($start > 1) {
        $s .= '<a href="' . xn_url_page($url, 1) . '">1</a>';
        if ($start > 2) $s .= '<span>...</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $s .= '<span class="current">' . $i . '</span>';
        } else {
            $s .= '<a href="' . xn_url_page($url, $i) . '">' . $i . '</a>';
        }
    }
    if ($end < $pages) {
        if ($end < $pages - 1) $s .= '<span>...</span>';
        $s .= '<a href="' . xn_url_page($url, $pages) . '">' . $pages . '</a>';
    }
    // 下一页
    if ($page < $pages) {
        $s .= '<a href="' . xn_url_page($url, $page + 1) . '">&raquo;</a>';
    }
    $s .= '</div>';
    return $s;
}

function xn_url_page(string $url, int $page): string {
    if ($url === '') {
        $url = http_url_path();
    }
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . 'page=' . $page;
}

// ========== 字符串工具 ==========

function xn_urlencode(string $s): string {
    return urlencode($s);
}

function xn_urldecode(string $s): string {
    return urldecode($s);
}

function xn_substr(string $s, int $start, ?int $length = null, string $charset = 'utf-8'): string {
    return mb_substr($s, $start, $length, $charset);
}

function xn_textarea(string $s): string {
    return str_replace([' ', "\t", "\n", "\r"], ['&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;', '<br>', ''], htmlspecialchars($s, ENT_QUOTES));
}

function xn_rand(int $len = 8, string $type = 'alnum'): string {
    $chars = match ($type) {
        'num'   => '0123456789',
        'lower' => 'abcdefghijklmnopqrstuvwxyz',
        'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'alnum' => '0123456789abcdefghijklmnopqrstuvwxyz',
        default => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
    };
    $s = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $s .= $chars[random_int(0, $max)];
    }
    return $s;
}

function xn_md5(string $s, string $salt = ''): string {
    return md5($s . $salt);
}

/**
 * 签名数据 (用于 API 鉴权)
 */
function xn_signdata(array $data, string $key): string {
    ksort($data);
    $str = '';
    foreach ($data as $k => $v) {
        if ($v !== '' && $v !== null) {
            $str .= "$k=$v&";
        }
    }
    return md5(rtrim($str, '&') . $key);
}

function xn_url(string $url, array $params = []): string {
    if ($params) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }
    return $url;
}

// ========== 扩展: Hook 系统 ==========

/**
 * 注册 Hook
 */
function xn_hook_register(string $name, callable $fn): void {
    $GLOBALS['_hooks'][$name][] = $fn;
}

/**
 * 触发 Hook (动作型, 无返回值)
 */
function xn_hook(string $name, ...$args): void {
    foreach ($GLOBALS['_hooks'][$name] ?? [] as $fn) {
        $fn(...$args);
    }
}

/**
 * 触发 Hook (过滤型, 串联修改值)
 */
function xn_hook_filter(string $name, $value, ...$args) {
    foreach ($GLOBALS['_hooks'][$name] ?? [] as $fn) {
        $value = $fn($value, ...$args);
    }
    return $value;
}

// ========== 扩展: JSON 响应 ==========

function xn_json_response(int $code = 0, string $message = '', $data = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo xn_json_encode([
        'code'    => $code,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

// ========== 扩展: CSRF ==========

function xn_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = xn_rand(32);
    }
    return $_SESSION['csrf_token'];
}

function xn_csrf_check(?string $token = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $token ?? param('csrf_token', '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// ========== 扩展: 验证器 ==========

/**
 * 简单数据验证
 *
 * $rules = [
 *     'username' => ['required' => true, 'max' => 20],
 *     'email'    => ['email' => true],
 *     'age'      => ['int' => true, 'min' => 0, 'max' => 150],
 * ];
 *
 * @return array 错误消息数组, 空数组表示通过
 */
function xn_validate(array $data, array $rules): array {
    $errors = [];
    foreach ($rules as $field => $rule) {
        $val = $data[$field] ?? null;
        $label = $rule['label'] ?? $field;
        foreach ($rule as $key => $param) {
            if ($key === 'label') continue;
            $err = xn_validate_rule($val, $key, $param, $label);
            if ($err) {
                $errors[$field] = $err;
                break;
            }
        }
    }
    return $errors;
}

function xn_validate_rule($val, string $rule, $param, string $label): string {
    switch ($rule) {
        case 'required':
            if ($param && ($val === null || $val === '')) return "$label 不能为空";
            break;
        case 'max':
            if (is_string($val) && mb_strlen($val) > $param) return "$label 长度不能超过 $param";
            if (is_numeric($val) && $val > $param) return "$label 不能大于 $param";
            break;
        case 'min':
            if (is_string($val) && mb_strlen($val) < $param) return "$label 长度不能少于 $param";
            if (is_numeric($val) && $val < $param) return "$label 不能小于 $param";
            break;
        case 'email':
            if ($param && $val && !filter_var($val, FILTER_VALIDATE_EMAIL)) return "$label 格式不正确";
            break;
        case 'url':
            if ($param && $val && !filter_var($val, FILTER_VALIDATE_URL)) return "$label 格式不正确";
            break;
        case 'int':
            if ($param && $val !== null && $val !== '' && !filter_var($val, FILTER_VALIDATE_INT)) return "$label 必须是整数";
            break;
        case 'float':
            if ($param && $val !== null && $val !== '' && !filter_var($val, FILTER_VALIDATE_FLOAT)) return "$label 必须是数字";
            break;
        case 'in':
            if ($val !== null && $val !== '' && !in_array($val, (array)$param)) return "$label 值不在允许范围";
            break;
        case 'regex':
            if ($val && !preg_match($param, $val)) return "$label 格式不正确";
            break;
    }
    return '';
}
