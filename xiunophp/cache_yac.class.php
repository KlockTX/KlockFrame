<?php
/*
 * cache_yac.class.php — Yac 缓存驱动
 * Yac 不支持过期时间检查, 需自行管理
 */

class cache_yac {

    public string $cachepre;
    public object $yac;

    public function __construct(string $cachepre) {
        $this->cachepre = $cachepre;
        $this->yac = new Yac();
    }

    public function get(string $k) {
        $k = cache_key($k, $this->cachepre);
        $s = $this->yac->get($k);
        return $s === false ? false : $s;
    }

    public function set(string $k, $v, int $life = 0): bool {
        $k = cache_key($k, $this->cachepre);
        return $this->yac->set($k, $v, $life);
    }

    public function delete(string $k): bool {
        $k = cache_key($k, $this->cachepre);
        return $this->yac->delete($k);
    }

    public function truncate(): bool {
        $this->yac->flush();
        return true;
    }
}
