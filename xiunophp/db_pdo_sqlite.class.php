<?php
/*
 * db_pdo_sqlite.class.php — PDO SQLite 驱动
 *
 * 特性:
 * - 单文件数据库, 适合嵌入式场景
 * - PDO 预处理语句防注入
 * - 事务支持
 */

class db_pdo_sqlite {

    public array $conf;
    public string $tablepre;
    public ?PDO $wlink = null;
    public ?PDO $rlink = null;
    public ?PDO $link = null;
    public array $sqls = [];

    public function __construct(array $conf) {
        $this->conf = $conf;
        $this->tablepre = $conf['tablepre'] ?? '';
    }

    // ---------- 连接 ----------
    public function connect(): bool {
        if ($this->wlink) return true;
        return $this->connect_master();
    }

    public function connect_master(): bool {
        if ($this->wlink) return true;
        $path = $this->conf['path'] ?? ':memory:';
        try {
            $this->wlink = new PDO("sqlite:$path", null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $this->error(-1, $e->getMessage());
            return false;
        }
        $this->link = $this->wlink;
        $this->rlink = $this->wlink;
        return true;
    }

    public function connect_slave(): bool {
        return $this->connect_master();
    }

    // ---------- 查询 ----------
    public function sql_find_one(string $sql, array $params = []): array|false {
        $stmt = $this->query($sql, $params);
        if (!$stmt) return false;
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $arr = $stmt->fetch();
        return $arr !== false ? $arr : [];
    }

    public function sql_find(string $sql, string $key = '', array $params = []): array|false {
        $stmt = $this->query($sql, $params);
        if (!$stmt) return false;
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $arrlist = $stmt->fetchAll();
        if ($key) $arrlist = arrlist_change_key($arrlist, $key);
        return $arrlist;
    }

    public function find(string $table, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = []): array|false {
        $page = max(1, $page);
        [$condsql, $params] = db_cond_to_sqladd($cond);
        $orderbysql = db_orderby_to_sqladd($orderby);
        $offset = ($page - 1) * $pagesize;
        $cols = $col ? '`' . implode('`,`', array_map(fn($c) => str_replace('`', '``', $c), $col)) . '`' : '*';
        $sql = "SELECT $cols FROM {$this->tablepre}$table $condsql$orderbysql LIMIT $offset,$pagesize";
        return $this->sql_find($sql, $key, $params);
    }

    public function find_one(string $table, array $cond = [], array $orderby = [], array $col = []): array|false {
        [$condsql, $params] = db_cond_to_sqladd($cond);
        $orderbysql = db_orderby_to_sqladd($orderby);
        $cols = $col ? '`' . implode('`,`', array_map(fn($c) => str_replace('`', '``', $c), $col)) . '`' : '*';
        $sql = "SELECT $cols FROM {$this->tablepre}$table $condsql$orderbysql LIMIT 1";
        return $this->sql_find_one($sql, $params);
    }

    public function count(string $table, array $cond = []): int|false {
        [$condsql, $params] = db_cond_to_sqladd($cond);
        $sql = "SELECT COUNT(*) AS num FROM {$this->tablepre}$table $condsql";
        $arr = $this->sql_find_one($sql, $params);
        return $arr ? (int)$arr['num'] : 0;
    }

    public function maxid(string $table, string $field, array $cond = []): int|false {
        [$condsql, $params] = db_cond_to_sqladd($cond);
        $field = '"' . str_replace('"', '""', $field) . '"';
        $sql = "SELECT MAX($field) AS maxid FROM {$this->tablepre}$table $condsql";
        $arr = $this->sql_find_one($sql, $params);
        return $arr ? (int)$arr['maxid'] : 0;
    }

    // ---------- 执行 ----------
    public function query(string $sql, array $params = []): PDOStatement|false {
        if (!$this->rlink && !$this->connect_slave()) return false;
        $link = $this->link = $this->rlink;
        try {
            $t1 = microtime(true);
            if ($params) {
                $stmt = $link->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $link->query($sql);
            }
            $t2 = microtime(true);
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }
        if (count($this->sqls) < 1000) {
            $this->sqls[] = substr((string)($t2 - $t1), 0, 6) . ' ' . $sql;
        }
        return $stmt;
    }

    public function exec(string $sql, array $params = []): int|false {
        if (!$this->wlink && !$this->connect_master()) return false;
        $link = $this->link = $this->wlink;
        try {
            $t1 = microtime(true);
            if ($params) {
                $stmt = $link->prepare($sql);
                $stmt->execute($params);
                $n = $stmt->rowCount();
            } else {
                $n = $link->exec($sql);
            }
            $t2 = microtime(true);
        } catch (PDOException $e) {
            $this->error($e->getCode(), $e->getMessage());
            return false;
        }

        if (count($this->sqls) < 1000) {
            $this->sqls[] = substr((string)($t2 - $t1), 0, 6) . ' ' . $sql;
        }

        if ($n !== false) {
            $pre = strtoupper(substr(trim($sql), 0, 7));
            if ($pre === 'INSERT ' || $pre === 'REPLACE') {
                return (int)$this->last_insert_id();
            }
        } else {
            $this->error();
        }
        return $n;
    }

    public function truncate(string $table): int|false {
        $sql = "DELETE FROM {$this->tablepre}$table";
        return $this->exec($sql);
    }

    // ---------- 事务 ----------
    public function begin_transaction(): bool {
        if (!$this->wlink && !$this->connect_master()) return false;
        return $this->wlink->beginTransaction();
    }

    public function commit(): bool {
        return $this->wlink ? $this->wlink->commit() : false;
    }

    public function rollback(): bool {
        return $this->wlink ? $this->wlink->rollBack() : false;
    }

    // ---------- 辅助 ----------
    public function last_insert_id(): int {
        return (int)$this->wlink->lastInsertId();
    }

    public function version(): string {
        if (!$this->wlink && !$this->connect_master()) return '';
        return $this->wlink->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function errno(): int {
        return $this->link ? (int)$this->link->errorCode() : 0;
    }

    public function errstr(): string {
        if (!$this->link) return '';
        $info = $this->link->errorInfo();
        return $info[2] ?? ($info[0] ?? '');
    }

    protected function error(int $errno = 0, string $errstr = ''): void {
        if ($errno === 0 && $this->link) {
            $info = $this->link->errorInfo();
            $errno = (int)($info[1] ?? 0);
            $errstr = $info[2] ?? '';
        }
        $_SERVER['errno'] = $errno;
        $_SERVER['errstr'] = $errstr;
        DEBUG AND xn_log("errno: $errno, errstr: $errstr", 'db_error');
    }

    public function close(): bool {
        $this->wlink = null;
        $this->rlink = null;
        $this->link = null;
        return true;
    }

    public function __destruct() {
        $this->close();
    }
}
