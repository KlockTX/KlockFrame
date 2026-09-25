<?php
/*
 * request.func.php — 请求门面
 *
 * 应用侧读请求只有一种写法：kf_param() / kf_request_header() / kf_is_htmx()。
 * xiunophp 的 param() / xn_*() 降级为底层实现，业务代码不再直接调用。
 *
 * 局部刷新所需的请求头识别也归在这里：htmx 是框架的内置交互模型，
 * 不是外挂进来的第三方库，所以不再有独立模块和独立前缀。
 */

// ========== 请求参数 ==========

/**
 * 获取请求参数（自动转义）
 *
 * @param string $key     参数名
 * @param mixed  $default 缺省值
 * @param bool   $escape  是否 htmlspecialchars
 * @param bool   $slashes 是否 addslashes（默认关闭）
 */
function kf_param(string $key, $default = null, bool $escape = true, bool $slashes = false) {
    return param($key, $default, $escape, $slashes);
}

/** 整型参数 */
function kf_param_int(string $key, int $default = 0): int {
    return param_int($key, $default);
}

/** 单词型参数（仅字母数字下划线） */
function kf_param_word(string $key, $default = null): string {
    return param_word($key, $default);
}

/** JSON 参数（解码为数组/对象） */
function kf_param_json(string $key, $default = null) {
    return param_json($key, $default);
}

/** URL 型参数 */
function kf_param_url(string $key, $default = null): string {
    return param_url($key, $default);
}

/** Base64 参数 */
function kf_param_base64(string $key, $default = null) {
    return param_base64($key, $default);
}

/** 强制类型参数 */
function kf_param_force(string $key, $default) {
    return param_force($key, $default);
}

// ========== 请求信息 ==========

/**
 * 客户端 IP（可信代理链解析）
 */
function kf_ip(): string {
    return ip();
}

/**
 * 请求方法（大写；HEAD 归一为 GET）
 */
function kf_method(): string {
    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return $m === 'HEAD' ? 'GET' : $m;
}

/**
 * 读取请求头
 *
 * 不叫 kf_header()：那个名字属于 <header> 标签函数，
 * 且与写头的 kf_response_header() 对称起见统一用 request/response 成对。
 *
 * @param string $name    头名，如 'X-CSRF-Token'、'HX-Target'
 * @param string $default 缺省值
 */
function kf_request_header(string $name, string $default = ''): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $val = $_SERVER[$key] ?? null;
    return is_string($val) ? $val : $default;
}

/**
 * 读取 Cookie
 */
function kf_cookie(string $name, string $default = ''): string {
    $val = $_COOKIE[$name] ?? null;
    return is_scalar($val) ? (string)$val : $default;
}

/**
 * 来源页
 */
function kf_referer(): string {
    return http_referer();
}

// ========== 局部刷新：请求侧识别 ==========
//
// htmx 在每次请求上带 HX-* 头，框架据此决定「出整页还是出片段」。

/**
 * 是否由框架的局部刷新机制发起（`HX-Request`）
 */
function kf_is_htmx(): bool {
    return kf_request_header('HX-Request') === 'true';
}

/**
 * 是否 hx-boost 提升的导航
 *
 * boost 需要整页响应，htmx 自行从文档里抽取目标内容，
 * 因此「片段还是整页」必须排除 boost。
 */
function kf_is_boosted(): bool {
    return kf_request_header('HX-Boosted') === 'true';
}

/**
 * 是否浏览器前进/后退触发的历史恢复请求
 */
function kf_is_history(): bool {
    return kf_request_header('HX-History-Restore-Request') === 'true';
}

/**
 * 当前请求应当只渲染片段（HX 发起且非 boost 提升）
 *
 * 这就是 kf_view() 内部用的判据，需要手工分支时调它。
 */
function kf_is_fragment(): bool {
    return kf_is_htmx() && !kf_is_boosted();
}

/**
 * hx-prompt 弹窗中用户输入的文本
 */
function kf_prompt(): string {
    return kf_request_header('HX-Prompt');
}

/**
 * 换入目标元素 id（`HX-Target`）
 */
function kf_target_id(): string {
    return kf_request_header('HX-Target');
}

/**
 * 触发请求的元素 id（`HX-Trigger`）
 */
function kf_trigger_id(): string {
    return kf_request_header('HX-Trigger');
}

/**
 * 触发元素的名字（`HX-Trigger-Name`）
 */
function kf_trigger_name(): string {
    return kf_request_header('HX-Trigger-Name');
}

/**
 * 发起请求时浏览器地址栏的 URL（`HX-Current-URL`）
 */
function kf_request_url(): string {
    return kf_request_header('HX-Current-URL');
}

// ========== 安全 ==========

/**
 * 会话内 CSRF 令牌
 */
function kf_csrf_token(): string {
    return xn_csrf_token();
}

/**
 * 校验 CSRF 令牌（默认取请求字段 csrf_token）
 */
function kf_csrf_check(?string $token = null): bool {
    return xn_csrf_check($token);
}

/**
 * CSRF 校验：优先读局部刷新自动附带的请求头，回落到表单字段
 *
 * 无表单的 hx-delete / hx-put 拿不到 csrf_token 字段，
 * 依赖 kf_runtime() 注入的请求头（头名见 app.htmx.csrf_header）。
 */
function kf_csrf_ok(): bool {
    $token = kf_request_header((string)kf_config('app.htmx.csrf_header', 'X-CSRF-Token'));
    return $token !== '' ? xn_csrf_check($token) : xn_csrf_check();
}

/**
 * 数据验证
 *
 * 两种写法都接受，按喜好选：
 *   kf_validate($data, ['email' => 'required|email|max:200'])            // 字符串管道
 *   kf_validate($data, ['email' => ['required' => true, 'max' => 200]])  // 数组式
 *
 * 支持规则：required、max:N、min:N、email、url、int、float、in:a,b,c、regex:...，
 * 以及 `label:显示名`（错误消息里字段的名字）。
 * 注意 `in` 在底层要求数组参数，字符串式已按逗号切好；
 * 含 `|` 的正则（如 `/^a|b$/`）请用数组式，否则会被当成两条规则切开。
 *
 * @param array $data  待验数据
 * @param array $rules 规则集
 * @return array [字段 => 错误消息]，空数组表示通过
 */
function kf_validate(array $data, array $rules): array {
    return xn_validate($data, _kf_normalize_rules($rules));
}

/**
 * 把字符串管道式规则翻成底层 xn_validate 的数组式（内部辅助）
 *
 * 数组式的值原样透传，因此旧写法行为完全不变。
 */
function _kf_normalize_rules(array $rules): array {
    $out = [];
    foreach ($rules as $field => $rule) {
        if (!is_string($rule)) {
            $out[$field] = $rule;
            continue;
        }
        $spec = [];
        foreach (explode('|', $rule) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            $pos = strpos($item, ':');
            if ($pos === false) {
                $spec[$item] = true;
                continue;
            }
            $key = substr($item, 0, $pos);
            $val = substr($item, $pos + 1);
            // in 需要数组；数值参数转成数字以匹配 max/min 的比较；regex 保留原样（含 : 与 | 除外）
            $spec[$key] = match ($key) {
                'in'     => explode(',', $val),
                'max',
                'min'    => is_numeric($val) ? $val + 0 : $val,
                default  => $val,
            };
        }
        $out[$field] = $spec;
    }
    return $out;
}

/**
 * 是否传统 XHR 请求（`X-Requested-With: XMLHttpRequest` 或 `?ajax=1`）
 *
 * 与 kf_is_htmx() 是两条独立通道：htmx 不发 X-Requested-With。
 */
function kf_is_xhr(): bool {
    return !empty($_SERVER['ajax']);
}

/**
 * 取整份表单数据（原始值，未转义）
 *
 * 校验前必须拿原始值；输出到 HTML 时由模板引擎或 kf_* 属性函数负责转义，
 * 所以这里不做 htmlspecialchars，避免双重转义。
 *
 * @param string $source post|get|request
 */
function kf_form_data(string $source = 'post'): array {
    return match (strtolower($source)) {
        'get'     => $_GET,
        'request' => $_REQUEST,
        default   => $_POST,
    };
}

/**
 * 对称加密（带认证标签）
 */
function kf_encrypt(string $text, ?string $key = null): string {
    return xn_encrypt($text, $key);
}

/**
 * 解密，失败返回 false
 */
function kf_decrypt(string $text, ?string $key = null): string|false {
    return xn_decrypt($text, $key);
}

/**
 * 数据签名
 */
function kf_signdata(array $data, string $key): string {
    return xn_signdata($data, $key);
}

/**
 * 随机字符串
 *
 * @param int    $len  长度
 * @param string $type alnum|alpha|numeric
 */
function kf_rand(int $len = 8, string $type = 'alnum'): string {
    return xn_rand($len, $type);
}
