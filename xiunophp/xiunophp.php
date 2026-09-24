<?php
/*
 * XiunoPHP 4.1 — 轻量高性能 PHP 8+ 函数式框架
 *
 * 设计原则:
 * - 函数式为主, 类仅用于需要状态的组件 (DB/Cache)
 * - 无 autoload, 无 eval, 无 $$var, 无 __call/__set/__get
 * - PDO 预处理语句防注入
 * - 对 OPCache 友好, 静态可分析
 *
 * 性能优化 (1.1):
 * - 缓存驱动类按需加载（只有配置启用的缓存类型才 include 对应驱动）
 * - 启动阶段减少冗余的 $_SERVER 赋值
 * - ip() 在 CLI 下短路，避免 filter_var 调用
 */

// ---------- 常量 ----------
!defined('DEBUG')          AND define('DEBUG', 1);
!defined('APP_PATH')       AND define('APP_PATH', './');
!defined('XIUNOPHP_PATH')  AND define('XIUNOPHP_PATH', dirname(__FILE__) . '/');
!defined('XIUNOPHP_VERSION') AND define('XIUNOPHP_VERSION', '4.1');

// ---------- 错误报告 ----------
error_reporting(DEBUG ? E_ALL : 0);
ini_set('display_errors', DEBUG ? '1' : '0');

// ---------- 时间 ----------
$starttime = microtime(true);
$time = time();

// ---------- CLI 检测 ----------
define('IN_CMD', !isset($_SERVER['REMOTE_ADDR']) || empty($_SERVER['REMOTE_ADDR']) || (php_sapi_name() === 'cli'));

if (IN_CMD) {
    $_SERVER['REMOTE_ADDR']     ??= '';
    $_SERVER['REQUEST_URI']     ??= '';
    $_SERVER['REQUEST_METHOD']  ??= 'GET';
    $_SERVER['HTTP_USER_AGENT'] ??= '';
} else {
    header('Content-Type: text/html; charset=utf-8');
}

// ---------- Hook 系统 ----------
$GLOBALS['_hooks'] = [];

// ---------- 加载 DB 类（始终需要） ----------
include XIUNOPHP_PATH . 'db_pdo_mysql.class.php';
include XIUNOPHP_PATH . 'db_pdo_sqlite.class.php';

// ---------- 加载缓存类（按需加载） ----------
// cache_mysql 始终加载（兜底驱动）
include XIUNOPHP_PATH . 'cache_mysql.class.php';
// 其他驱动在 cache_new() 中按需 include，减少启动开销

// ---------- 加载函数 ----------
include XIUNOPHP_PATH . 'db.func.php';
include XIUNOPHP_PATH . 'cache.func.php';
include XIUNOPHP_PATH . 'array.func.php';
include XIUNOPHP_PATH . 'image.func.php';
include XIUNOPHP_PATH . 'xn_encrypt.func.php';
include XIUNOPHP_PATH . 'misc.func.php';

xn_hook('xiunophp_include_after');

// ---------- 配置 ----------
$conf ??= [];
$conf['db']       ??= [];
$conf['cache']    ??= [];
$conf['tmp_path'] ??= (ini_get('upload_tmp_dir') ?: './');
$conf['log_path'] ??= './';
$conf['timezone'] ??= 'Asia/Shanghai';

date_default_timezone_set($conf['timezone']);

// ---------- 全局变量 ----------
$ip = IN_CMD ? '' : ip();
$longip = $ip === '' ? 0 : ip2long($ip);
$longip < 0 AND $longip = sprintf('%u', $longip);
$useragent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$lang  ??= [];
$errno = 0;
$errstr = '';

// ---------- 错误处理 ----------
DEBUG AND set_error_handler('error_handle', E_ALL);

// ---------- URL 解析 ----------
if (!empty($_SERVER['HTTP_X_REWRITE_URL'])) {
    $_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
}
$_SERVER['REQUEST_URI'] ??= '';
$_SERVER['REQUEST_URI'] = str_replace('/index.php?', '/', $_SERVER['REQUEST_URI']);

$_REQUEST = array_merge($_COOKIE, $_POST, $_GET, xn_url_parse($_SERVER['REQUEST_URI']));

$_SERVER['REMOTE_ADDR'] ??= '';
$_SERVER['SERVER_ADDR'] ??= '';

$ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
         && strtolower(trim($_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest')
        || param('ajax');
$method = $_SERVER['REQUEST_METHOD'];

// ---------- 存入 $_SERVER 供全局访问 ----------
$_SERVER['starttime']  = $starttime;
$_SERVER['time']       = $time;
$_SERVER['ip']         = $ip;
$_SERVER['longip']     = $longip;
$_SERVER['useragent']  = $useragent;
$_SERVER['conf']       = $conf;
$_SERVER['lang']       = $lang;
$_SERVER['errno']      = $errno;
$_SERVER['errstr']     = $errstr;
$_SERVER['method']     = $method;
$_SERVER['ajax']       = $ajax;

// ---------- 初始化 DB / Cache (延迟连接) ----------
$db = !empty($conf['db']) ? db_new($conf['db']) : null;

$conf['cache']['mysql']['db'] = $db;
$cache = !empty($conf['cache']) ? cache_new($conf['cache']) : null;
unset($conf['cache']['mysql']['db']);

// 从扩展获取密钥
if (function_exists('xiuno_key')) {
    $conf['auth_key'] = xiuno_key();
}

$_SERVER['db']    = $db;
$_SERVER['cache'] = $cache;

xn_hook('xiunophp_init_after');
