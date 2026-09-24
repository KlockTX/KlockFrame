<?php
/*
 * cache_mysql.class.php — MySQL 缓存驱动
 * 用 MySQL 表做 KV 存储, 复用 $db 连接
 * 表结构: CREATE TABLE cache (k VARCHAR(255) PRIMARY KEY, v TEXT, expiry INT UNSIGNED)
 */

class cache_mysql {

    public string $cachepre;
    public ?object $db;
    public string $table = 'cache';

    public function __construct(array $conf, string $cachepre) {
        $this->db = $conf['db'] ?? null;
        $this->cachepre = $cachepre;
        $this->table = $conf['table'] ?? 'cache';
    }

    public function get(string $k) {
        if (!$this->db) return false;
        $k = cache_key($k, $this->cachepre);
        $arr = db_find_one($this->table, ['k' => $k], [], ['v', 'expiry'], $this->db);
        if (empty($arr)) return false;
        if ($arr['expiry'] > 0 && $arr['expiry'] < time()) {
            db_delete($this->table, ['k' => $k], $this->db);
            return false;
        }
        return xn_json_decode($arr['v']);
    }

    public function set(string $k, $v, int $life = 0): bool {
        if (!$this->db) return false;
        $k = cache_key($k, $this->cachepre);
        $s = xn_json_encode($v);
        $expiry = $life > 0 ? time() + $life : 0;
        db_replace($this->table, [
            'k' => $k,
            'v' => $s,
            'expiry' => $expiry,
        ], $this->db);
        return true;
    }

    public function delete(string $k): bool {
        if (!$this->db) return false;
        $k = cache_key($k, $this->cachepre);
        db_delete($this->table, ['k' => $k], $this->db);
        return true;
    }

    public function truncate(): bool {
        if (!$this->db) return false;
        db_truncate($this->table, $this->db);
        return true;
    }
}
