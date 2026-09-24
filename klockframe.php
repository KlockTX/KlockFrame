<?php
/*
 * KlockFrame 2.0 — 极轻量 PHP 网站框架
 *
 * 基于 XiunoPHP 4.1, 延续函数式设计语言
 * 模块: 路由分发 + PurePHP 风格组件化模板 + 配置加载 + 请求/响应门面 + 内建局部刷新
 *
 * 设计原则:
 * - 延续 xiunophp 的极致轻量与极致性能
 * - 函数式为主, 无 autoload, 无 eval
 * - PDO 预处理防注入
 * - 对 OPCache 友好
 * - 简化开发者流程: include → 定义路由 → 写视图
 * - 应用侧只有一个前缀：kf_（xiunophp 的 xn_/param 降级为底层实现）
 *
 * 历史版本 1.1–1.4 的改进与修复见各模块文件头部的分节记录。
 *
 * 2.0 破坏性变更（覆盖安装前请先读 docs/migration-2.0.md）:
 *
 * 1) 前缀统一为 kf_，helpers.func.php 与 htmx.func.php 按职责重组为
 *    request / response / util 三个模块：
 *    - 请求侧：param()→kf_param()、param_int()→kf_param_int()、ip()→kf_ip()、
 *      xn_csrf_token()→kf_csrf_token()、xn_csrf_check()→kf_csrf_check()、
 *      xn_validate()→kf_validate()、xn_encrypt/decrypt()→kf_encrypt/kf_decrypt()、
 *      xn_rand()→kf_rand()、xn_signdata()→kf_signdata()
 *    - 工具侧：xn_log()→kf_log()、xn_hook*()→kf_hook*()、xn_json_encode/decode()→
 *      kf_json_encode/decode()、http_get/post/multi_get()→kf_http_*()、
 *      pagination()→kf_pagination()、xn_substr()→kf_substr()、
 *      xn_send_mail()/xn_zip()/xn_unzip()→kf_*()（按需模块自动引入，不再手动 require）
 *    - db_ / cache_ 保持原样：它们本身就是一个模块边界
 * 2) 局部刷新内建，kf_htmx_* 前缀整体消失：
 *    - kf_htmx_view() 不再有 —— kf_view() 自己判定整页/片段（多两个可选参数）
 *    - kf_htmx_request()→kf_is_htmx()、_boosted()→kf_is_boosted()、_history()→kf_is_history()
 *      新增 kf_is_fragment()：整页/片段判据本身
 *    - kf_htmx_capture()→kf_capture()、_fragment()→kf_fragment()、_oob()→kf_oob()
 *    - kf_htmx_redirect() 不再有 —— kf_redirect() 自动按请求来源在 302 与软跳区间择一
 *    - kf_htmx_reswap/retarget/reselect/trigger/refresh/push_url/replace_url/location/empty/no_cache
 *      → 去掉 htmx_ 段；kf_htmx_header()→kf_response_header()
 *    - kf_htmx_csrf_ok()→kf_csrf_ok()、kf_htmx_tags()→kf_runtime()、
 *      kf_htmx_script()→kf_runtime_script()、kf_htmx_abort_fragment()→kf_error_fragment()
 * 3) 运行时不再落地到应用 assets/：由框架内置路由 KF_RUNTIME_ROUTE(/kf/htmx.js) 直出，
 *    带 ETag + immutable 长缓存，命中 If-None-Match 零 I/O 返回 304。
 *    因此 kf_htmx_publish()/kf_htmx_asset_path()/kf_htmx_asset_url() 取消，
 *    改为 kf_runtime_url()/kf_runtime_response()；升级框架即自动升级运行时，不存在副本漂移。
 *    配置 app.htmx.src 的 'local' 改为 'route'。
 * 4) KF_Element 的交互能力升级为显式方法：->get() ->post() ->put() ->patch() ->delete()
 *    ->swap() ->oob() ->trigger() ->boost() ->confirm() ->prompt() ->vals() ->headers()
 *    ->select() ->indicator() ->preserve() ->sync() ->push_url() ->on()；
 *    通用入口 ->hx() 保留。换入目标因与 HTML target 冲突，名为 ->hx_target()。
 * 5) 1.4 已移除 kf_old()：校验失败直接重渲染表单片段，旧值取自请求参数。
 */

// ---------- 常量 ----------
!defined('KF_PATH')    AND define('KF_PATH', dirname(__FILE__) . '/');
!defined('KF_VERSION') AND define('KF_VERSION', '2.0.0');
!defined('APP_PATH')   AND define('APP_PATH', getcwd() . '/');

// ---------- 探测 XiunoPHP 路径 ----------
if (!defined('XIUNOPHP_PATH')) {
    $candidates = [
        KF_PATH . 'xiunophp/',        // KlockFrame/xiunophp/（默认内置）
        dirname(KF_PATH) . '/xiunophp/', // ../xiunophp/（外部独立）
        KF_PATH . 'core/',            // KlockFrame/core/
    ];
    foreach ($candidates as $path) {
        if (is_dir($path) && is_file($path . 'xiunophp.php')) {
            define('XIUNOPHP_PATH', $path);
            break;
        }
    }
    if (!defined('XIUNOPHP_PATH')) {
        die('KlockFrame: XiunoPHP core not found. Expected location: ' . dirname(KF_PATH) . '/xiunophp/');
    }
}

// ---------- 加载 XiunoPHP 核心 ----------
include XIUNOPHP_PATH . 'xiunophp.php';

// ---------- 加载 KlockFrame 模块 ----------
include KF_PATH . 'config.func.php';
include KF_PATH . 'request.func.php';
include KF_PATH . 'router.func.php';
include KF_PATH . 'view.func.php';
include KF_PATH . 'response.func.php';
include KF_PATH . 'util.func.php';

// ---------- 加载应用配置 ----------
if (is_file(APP_PATH . 'config.php')) {
    kf_config_load(APP_PATH . 'config.php');
}

// ---------- 加载应用路由 ----------
if (is_file(APP_PATH . 'routes.php')) {
    include APP_PATH . 'routes.php';
}

// ---------- 注册内置路由（排在应用路由之后，允许应用自行覆盖）----------
// 交互运行时直出；站点装在子目录时额外注册带 base 路径的同名路由
kf_any(KF_RUNTIME_ROUTE, 'kf_runtime_response');
$__kf_base_path = (string)parse_url(kf_base_url(), PHP_URL_PATH);
if ($__kf_base_path !== '' && $__kf_base_path !== '/') {
    kf_any(rtrim($__kf_base_path, '/') . KF_RUNTIME_ROUTE, 'kf_runtime_response');
}

// ---------- 触发 Hook ----------
kf_hook('klockframe_boot');

// ---------- 自动分发（非 CLI 模式）----------
if (!IN_CMD && !defined('KF_NO_DISPATCH')) {
    kf_dispatch();
}
