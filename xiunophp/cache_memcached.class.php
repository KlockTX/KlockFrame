<?php
/*
 * cache_memcached.class.php — Memcached/Memcache 缓存驱动
 * 自适应 Memcached / Memcache 扩展
 */

class cache_memcached {

    public string $cachepre;
    public object $link;
    public array $conf;
    public bool $connected = false;

    public function __construct(array $conf, string $cachepre) {
        $this->conf = $conf;
        $this->cachepre = $cachepre;
    }

    protected function connect(): bool {
        if ($this->connected) return true;
        $servers = $this->conf['servers'] ?? [['host' => '127.0.0.1', 'port' => 11211]];
        if (class_exists('Memcached')) {
            $this->link = new Memcached();
            foreach ($servers as $s) {
                $this->link->addServer($s['host'] ?? '127.0.0.1', $s['port'] ?? 11211);
            }
        } elseif (class_exists('Memcache')) {
            $this->link = new Memcache();
            foreach ($servers as $s) {
                $this->link->addServer($s['host'] ?? '127.0.0.1', $s['port'] ?? 11211);
            }
        } else {
            return false;
        }
        $this->connected = true;
        return true;
    }

    public function get(string $k) {
        if (!$this->connect()) return false;
        $k = cache_key($k, $this->cachepre);
        return $this->link->get($k);
    }

    public function set(string $k, $v, int $life = 0): bool {
        if (!$this->connect()) return false;
        $k = cache_key($k, $this->cachepre);
        if ($this->link instanceof Memcached) {
            return $this->link->set($k, $v, $life);
        }
        return $this->link->set($k, $v, 0, $life);
    }

    public function delete(string $k): bool {
        if (!$this->connect()) return false;
        $k = cache_key($k, $this->cachepre);
        return $this->link->delete($k);
    }

    public function truncate(): bool {
        if (!$this->connect()) return false;
        return $this->link->flush();
    }
}
