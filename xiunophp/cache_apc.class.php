<?php
/*
 * cache_apc.class.php — APCu 缓存驱动
 */

class cache_apc {

    public string $cachepre;

    public function __construct(string $cachepre) {
        $this->cachepre = $cachepre;
    }

    public function get(string $k) {
        $k = cache_key($k, $this->cachepre);
        $s = apcu_fetch($k);
        return $s === false ? false : $s;
    }

    public function set(string $k, $v, int $life = 0): bool {
        $k = cache_key($k, $this->cachepre);
        return apcu_store($k, $v, $life);
    }

    public function delete(string $k): bool {
        $k = cache_key($k, $this->cachepre);
        return apcu_delete($k);
    }

    public function truncate(): bool {
        return apcu_clear_cache();
    }
}
