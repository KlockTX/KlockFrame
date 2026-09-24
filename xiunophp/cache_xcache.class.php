<?php
/*
 * cache_xcache.class.php — XCache 缓存驱动
 */

class cache_xcache {

    public string $cachepre;

    public function __construct(string $cachepre) {
        $this->cachepre = $cachepre;
    }

    public function get(string $k) {
        $k = cache_key($k, $this->cachepre);
        $s = xcache_get($k);
        return $s === false ? false : $s;
    }

    public function set(string $k, $v, int $life = 0): bool {
        $k = cache_key($k, $this->cachepre);
        return xcache_set($k, $v, $life);
    }

    public function delete(string $k): bool {
        $k = cache_key($k, $this->cachepre);
        return xcache_unset($k);
    }

    public function truncate(): bool {
        xcache_clear_cache(XC_TYPE_VAR, 0);
        return true;
    }
}
