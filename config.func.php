<?php
/*
 * config.func.php — 配置加载器
 *
 * 支持 dot 访问、运行时修改、多环境配置
 * 加载后自动同步到 xiunophp 的 $conf 全局变量
 *
 * 性能优化 (1.1):
 * - kf_config() 单层 key 直接命中，跳过 explode 开销
 * - dot-access 结果静态缓存，写操作自动失效
 * - kf_config_set() 同步失效相关缓存项
 *
 * 1.2 改进:
 * - kf_config_load() 用 is_file 替代 file_exists（拒绝目录误命中）
 * - kf_config_set() 多层 key 也同步到 $conf 全局变量（修复隐性 bug）
 *
 * 1.3 修复:
 * - kf_config_set() 末尾同步失效 $_kf_base_url 缓存，确保运行时修改
 *   app.base_url 后 kf_url()/kf_asset() 立即生效（之前仅 kf_config_reset_cache 能清）
 */

// 全局配置存储 + 读取缓存
$GLOBALS['_kf_config'] = [];
$GLOBALS['_kf_config_cache'] = [];

/**
 * 加载配置文件
 *
 * @param string $file PHP 配置文件路径（返回数组）
 */
function kf_config_load(string $file): void {
    if (!is_file($file)) return;
    $config = include $file;
    if (!is_array($config)) return;
    $GLOBALS['_kf_config'] = array_merge($GLOBALS['_kf_config'], $config);

    // 同步到 xiunophp 的 $conf 全局变量
    global $conf;
    if (!isset($conf) || !is_array($conf)) {
        $conf = [];
    }
    // 已知键直接赋值（避免重复 isset 判断）
    foreach (['db', 'cache', 'timezone', 'tmp_path', 'log_path', 'auth_key'] as $k) {
        if (isset($config[$k])) {
            $conf[$k] = $config[$k];
        }
    }

    // 更新 $_SERVER
    $_SERVER['conf'] = $conf;

    // 配置变化时清空读取缓存
    $GLOBALS['_kf_config_cache'] = [];
}

/**
 * 获取配置值（dot 访问）
 *
 * @param string $key     配置键，如 'app.name' 或 'db.master.host'
 * @param mixed  $default 默认值
 * @return mixed
 */
function kf_config(string $key, $default = null) {
    // 进程内缓存：相同 key 不重复解析
    $cache = &$GLOBALS['_kf_config_cache'];
    if (isset($cache[$key])) {
        // 区分"未命中缓存"与"值为 null"
        return $cache[$key][0] ? $cache[$key][1] : $default;
    }

    $config = $GLOBALS['_kf_config'];

    // 单层 key 快速路径（无点号，最常见场景）
    if (strpos($key, '.') === false) {
        if (isset($config[$key]) || array_key_exists($key, $config)) {
            $cache[$key] = [true, $config[$key]];
            return $config[$key];
        }
        $cache[$key] = [false, null];
        return $default;
    }

    // 多层 dot 访问
    $keys = explode('.', $key);
    foreach ($keys as $k) {
        if (!is_array($config) || !array_key_exists($k, $config)) {
            $cache[$key] = [false, null];
            return $default;
        }
        $config = $config[$k];
    }
    $cache[$key] = [true, $config];
    return $config;
}

/**
 * 重置配置读取缓存（在配置变化时调用）
 *
 * 1.2 起同时清除 base_url 进程缓存，确保 kf_config_set('app.base_url', ...)
 * 能被 kf_url()/kf_asset() 立即看到。
 */
function kf_config_reset_cache(): void {
    $GLOBALS['_kf_config_cache'] = [];
    unset($GLOBALS['_kf_base_url']);
}

/**
 * 设置配置值（运行时）
 *
 * @param string $key   配置键
 * @param mixed  $value 配置值
 */
function kf_config_set(string $key, $value): void {
    $keys = explode('.', $key);
    $ptr = &$GLOBALS['_kf_config'];
    $n = count($keys);
    for ($i = 0; $i < $n; $i++) {
        $k = $keys[$i];
        if ($i === $n - 1) {
            $ptr[$k] = $value;
        } else {
            if (!isset($ptr[$k]) || !is_array($ptr[$k])) {
                $ptr[$k] = [];
            }
            $ptr = &$ptr[$k];
        }
    }

    // 同步到 $conf（多层 key 也同步，修复 1.1 隐性 bug）
    global $conf;
    if (is_array($conf)) {
        $cptr = &$conf;
        for ($i = 0; $i < $n; $i++) {
            $k = $keys[$i];
            if ($i === $n - 1) {
                $cptr[$k] = $value;
            } else {
                if (!isset($cptr[$k]) || !is_array($cptr[$k])) {
                    $cptr[$k] = [];
                }
                $cptr = &$cptr[$k];
            }
        }
        $_SERVER['conf'] = $conf;
    }

    // 写操作后清空读取缓存并失效 base_url 缓存（保证一致性）
    $GLOBALS['_kf_config_cache'] = [];
    unset($GLOBALS['_kf_base_url']);
}

/**
 * 获取所有配置
 *
 * @return array
 */
function kf_config_all(): array {
    return $GLOBALS['_kf_config'];
}

/**
 * 获取当前环境
 *
 * @return string development|production
 */
function kf_env(): string {
    return kf_config('app.env', 'production');
}
