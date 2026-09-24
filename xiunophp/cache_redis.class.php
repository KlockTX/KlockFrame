<?php
/*
 * cache_redis.class.php — Redis 缓存驱动
 * 值使用 JSON 序列化, 支持过期时间
 */

class cache_redis {

    public string $cachepre;
    public ?Redis $link = null;
    public array $conf;
    public bool $connected = false;

    public function __construct(array $conf, string $cachepre) {
        $this->conf = $conf;
        $this->cachepre = $cachepre;
    }

    protected function connect(): bool {
        if ($this->connected) return true;
        if (!class_exists('Redis')) return false;
        $this->link = new Redis();
        $host = $this->conf['host'] ?? '127.0.0.1';
        $port = $this->conf['port'] ?? 6379;
        $timeout = $this->conf['timeout'] ?? 3;
        $r = $this->link->connect($host, $port, $timeout);
        if (!$r) return false;
        $password = $this->conf['password'] ?? '';
        if ($password) $this->link->auth($password);
        $db = $this->conf['db'] ?? 0;
        if ($db) $this->link->select($db);
        $this->connected = true;
        return true;
    }

    public function get(string $k) {
        if (!$this->connect()) return false;
        $k = cache_key($k, $this->cachepre);
        $s = $this->link->get($k);
        return $s === false ? false : xn_json_decode($s);
    }

    public function set(string $k, $v, int $life = 0): bool {
        if (!$this->connect()) return false;
        $k = cache_key($k, $this->cachepre);
        $s = xn_json_encode($v);
        if ($life > 0) {
            return $this->link->setex($k, $life, $s);
        }
        return $this->link->set($k, $s);
    }

    public function delete(string $k): bool {
        if (!$this->connect()) return false;
        $k = cache_key($k, $this->cachepre);
        return (bool)$this->link->del($k);
    }

    public function truncate(): bool {
        if (!$this->connect()) return false;
        return $this->link->flushdb();
    }

    public function __destruct() {
        if ($this->connected && $this->link) {
            $this->link->close();
        }
    }
}
