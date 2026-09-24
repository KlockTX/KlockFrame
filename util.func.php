<?php
/*
 * util.func.php — 工具门面
 *
 * 把 xiunophp 的日志、Hook、JSON、HTTP 客户端、分页、邮件、压缩等
 * 统一成 kf_ 前缀暴露给应用；按需模块（邮件、zip）在首次调用时自动引入，
 * 应用不再需要手动 require 核心文件。
 */

// ========== 日志 ==========

/**
 * 写日志
 *
 * @param string $msg  内容
 * @param string $tag  分类（影响文件名）
 * @param string $file 显式日志文件路径，留空按 tag 推导
 */
function kf_log(string $msg, string $tag = '', string $file = ''): void {
    xn_log($msg, $tag, $file);
}

// ========== Hook ==========

/**
 * 注册动作 Hook
 */
function kf_hook_register(string $name, callable $fn): void {
    xn_hook_register($name, $fn);
}

/**
 * 触发动作 Hook
 */
function kf_hook(string $name, ...$args): void {
    xn_hook($name, ...$args);
}

/**
 * 触发过滤型 Hook（串联改值）
 */
function kf_hook_filter(string $name, $value, ...$args) {
    return xn_hook_filter($name, $value, ...$args);
}

// ========== JSON ==========

/**
 * JSON 编码（保留 Unicode）
 *
 * @return string|false
 */
function kf_json_encode($value): string|false {
    return xn_json_encode($value);
}

/**
 * JSON 解码
 *
 * @param string $json
 * @param bool   $assoc true 返回数组
 */
function kf_json_decode(string $json, bool $assoc = true) {
    return xn_json_decode($json, $assoc);
}

/**
 * 序列化属性值型 JSON
 *
 * 用于 hx-vals / hx-headers 这类要落在 HTML 属性里的值：
 * JSON_HEX_* 把 < > & 与引号全部转义，结果可安全嵌进属性与 <script> 上下文。
 */
function kf_json_attr(array $data): string {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return $json === false ? '{}' : $json;
}

// ========== HTTP 客户端 ==========

/**
 * 外部 HTTP GET
 *
 * @return string|false
 */
function kf_http_get(string $url, int $timeout = 10, array $headers = []): string|false {
    return http_get($url, $timeout, $headers);
}

/**
 * 外部 HTTP POST
 *
 * @return string|false
 */
function kf_http_post(string $url, $postdata, int $timeout = 10, array $headers = []): string|false {
    return http_post($url, $postdata, $timeout, $headers);
}

/**
 * 并行 GET 多个 URL
 */
function kf_http_multi_get(array $urls, int $timeout = 10): array {
    return http_multi_get($urls, $timeout);
}

// ========== 字符串与分页 ==========

/**
 * 按字符集截取字符串
 */
function kf_substr(string $str, int $start, ?int $length = null, string $charset = 'utf-8'): string {
    return xn_substr($str, $start, $length, $charset);
}

/**
 * 生成分页 HTML
 *
 * 局部刷新下更省流量的做法是只回「下一页」片段，
 * 见 kf_oob() 与文档的无限滚动一节。
 */
function kf_pagination(int $total, int $page, int $pagesize, string $url = '', int $show_max = 10): string {
    return pagination($total, $page, $pagesize, $url, $show_max);
}

// ========== 目录 ==========

/**
 * 按 ID 取（并创建）应用目录
 */
function kf_dir_get(int $id, string $path = ''): string {
    return xn_get_dir($id, $path);
}

/**
 * 按 ID 设置应用目录
 */
function kf_dir_set(int $id, string $path = ''): string {
    return xn_set_dir($id, $path);
}

// ========== 按需模块（首次调用自动引入核心文件） ==========

/**
 * 引入按需模块（内部辅助）
 *
 * @param string $func 该模块提供的任一函数名
 * @param string $file 模块文件名
 */
function _kf_require_module(string $func, string $file): void {
    if (function_exists($func)) return;
    $path = XIUNOPHP_PATH . $file;
    if (is_file($path)) {
        include_once $path;
    }
}

/**
 * 发送邮件（自动引入 xn_send_mail 模块）
 *
 * @return bool
 */
function kf_send_mail($smtp, $username, $email, $subject, $message, string $charset = 'UTF-8') {
    _kf_require_module('xn_send_mail', 'xn_send_mail.func.php');
    if (!function_exists('xn_send_mail')) {
        kf_log('[kf_send_mail] 邮件模块不可用: xn_send_mail.func.php', 'mail');
        return false;
    }
    return xn_send_mail($smtp, $username, $email, $subject, $message, $charset);
}

/**
 * 打包（自动引入 zip 模块）
 */
function kf_zip(array $files, string $destfile): bool {
    _kf_require_module('xn_zip', 'xn_zip.func.php');
    return function_exists('xn_zip') ? xn_zip($files, $destfile) : false;
}

/**
 * 解压（自动引入 zip 模块，含穿越防护）
 */
function kf_unzip(string $srcfile, string $destdir): bool {
    _kf_require_module('xn_unzip', 'xn_zip.func.php');
    return function_exists('xn_unzip') ? xn_unzip($srcfile, $destdir) : false;
}
