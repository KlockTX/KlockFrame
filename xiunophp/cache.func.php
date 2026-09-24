<?php
/*
 * cache.func.php — 缓存函数层
 *
 * 统一接口: get / set / delete / truncate
 * 键名超过 32 字节自动 md5, 自动加前缀
 *
 * 性能优化 (1.1):
 * - 缓存驱动类按需 include（只有实际使用的驱动才加载）
 * - 驱动加载结果 static 记忆，避免重复 is_file 判断
 */

function cache_new(array $conf): object {
    $type = $conf['type'] ?? 'mysql';
    $cachepre = $conf['cachepre'] ?? '';
    switch ($type) {
        case 'redis':
            cache_load_driver('redis');
            return new cache_redis($conf['redis'] ?? [], $cachepre);
        case 'memcached':
        case 'memcache':
            cache_load_driver('memcached');
            return new cache_memcached($conf['memcached'] ?? [], $cachepre);
        case 'mysql':
            // cache_mysql 已在 xiunophp.php 始终加载（兜底驱动）
            return new cache_mysql($conf['mysql'] ?? [], $cachepre);
        case 'apc':
            cache_load_driver('apc');
            return new cache_apc($cachepre);
        case 'xcache':
            cache_load_driver('xcache');
            return new cache_xcache($cachepre);
        case 'yac':
            cache_load_driver('yac');
            return new cache_yac($cachepre);
        default:
            return new cache_mysql($conf['mysql'] ?? [], $cachepre);
    }
}

/**
 * 按需加载缓存驱动类（带记忆，避免重复 is_file）
 */
function cache_load_driver(string $name): void {
    static $loaded = [];
    if (isset($loaded[$name])) {
        return;
    }
    $loaded[$name] = true;
    $file = XIUNOPHP_PATH . 'cache_' . $name . '.class.php';
    if (is_file($file)) {
        include $file;
    }
}

function cache_get(string $k, ?object $c = null) {
    $cache = $_SERVER['cache'] ?? null;
    $c = $c ?: $cache;
    return $c ? $c->get($k) : false;
}

function cache_set(string $k, $v, int $life = 0, ?object $c = null): bool {
    $cache = $_SERVER['cache'] ?? null;
    $c = $c ?: $cache;
    return $c ? $c->set($k, $v, $life) : false;
}

function cache_delete(string $k, ?object $c = null): bool {
    $cache = $_SERVER['cache'] ?? null;
    $c = $c ?: $cache;
    return $c ? $c->delete($k) : false;
}

function cache_truncate(?object $c = null): bool {
    $cache = $_SERVER['cache'] ?? null;
    $c = $c ?: $cache;
    return $c ? $c->truncate() : false;
}

/**
 * 键名规范化: 加前缀 + 长键名 md5
 */
function cache_key(string $k, string $cachepre): string {
    if (strlen($k) > 32) {
        $k = md5($k);
    }
    return $cachepre . $k;
}
