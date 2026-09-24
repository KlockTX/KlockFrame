<?php
/*
 * db_pdo_mysql.class.php — PDO MySQL 驱动
 *
 * 特性:
 * - 读写分离 (主库写, 从库随机读)
 * - PDO 预处理语句防注入
 * - 事务支持
 * - InnoDB 检测与优化
 * - count() 空条件时用 information_schema 估算
 */

class db_pdo_mysql {

    public array $conf;
    public string $tablepre;
    public ?PDO $wlink = null;  // 写连接
    public ?PDO $rlink = null;  // 读连接
    public ?PDO $link = null;   // 当前活跃连接
    public array $sqls = [];
    public int $innodb = 0;

    public function __construct(array $conf) {
        $this->conf = $conf;
        $this->tablepre = $conf['master']['tablepre'] ?? ($conf['tablepre'] ?? '');
    }

    // ---------- 连接 ----------
    public function connect(): bool {
        if ($this->wlink) return true;
        return $this->connect_master();
    }

    public function connect_master(): bool {
        if ($this->wlink) return true;
        $conf = $this->conf;
        $master = $conf['master'] ?? $conf;
        try {
            $this->wlink = $this->real_connect(
                $master['host'] ?? '127.0.0.1',
                $master['user'] ?? 'root',
                $master['password'] ?? '',
                $master['name'] ?? '',
                $master['charset'] ?? 'utf8',
                $master['port'] ?? 3306
            );
        } catch (PDOException $e) {
            $this->error(-1, $e->getMessage());
            return false;
        }
        $this->link = $this->wlink;
        // 如果没有配置从库, 读连接复用主连接
        if (empty($conf['slaves'])) {
            $this->rlink = $this->wlink;
        }
        return true;
    }

    public function connect_slave(): bool {
        if ($this->rlink) return true;
        $conf = $this->conf;
        if (!empty($conf['slaves'])) {
            $n = array_rand($conf['slaves']);
            $slave = $conf['slaves'][$n];
            try {
                $this->rlink = $this->real_connect(
                    $slave['host'] ?? '127.0.0.1',
                    $slave['user'] ?? 'root',
                    $slave['password'] ?? '',
                    $slave['name'] ?? '',
                    $slave['charset'] ?? 'utf8',
                    $slave['port'] ?? 3306
                );
            } catch (PDOException $e) {
                // 从库连接失败, 回退到主库
                $this->connect_master();
                $this->rlink = $this->wlink;
            }
        } else {
            $this->connect_master();
            $this->rlink = $this->wlink;
        }
        return true;
    }

    protected function real_connect(string $host, string $user, string $password, string $name, string $charset, int $port): PDO {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset",
        ];
        $link = new PDO($dsn, $user, $password, $options);
        $this->innodb = $this->is_support_innodb($link);
        return $link;
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
        // 空条件时用 information_schema 估算 (InnoDB 性能优化)
        if ($condsql === '' && $this->innodb) {
            $sql = "SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$this->tablepre}$table'";
            $arr = $this->sql_find_one($sql);
            return $arr ? (int)$arr['TABLE_ROWS'] : 0;
        }
        $sql = "SELECT COUNT(*) AS num FROM {$this->tablepre}$table $condsql";
        $arr = $this->sql_find_one($sql, $params);
        return $arr ? (int)$arr['num'] : 0;
    }

    public function maxid(string $table, string $field, array $cond = []): int|false {
        [$condsql, $params] = db_cond_to_sqladd($cond);
        $field = '`' . str_replace('`', '``', $field) . '`';
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

        // InnoDB 优化: CREATE TABLE 时优先使用 InnoDB
        // 用首字符快速过滤，避免对每个 exec 都做两次 stripos
        if ($this->innodb) {
            $first = $sql[0] ?? '';
            if ($first === 'C' || $first === 'c') {
                if (stripos($sql, 'CREATE TABLE') !== false && stripos($sql, 'ENGINE') === false) {
                    $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8';
                }
            }
        }

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
            // INSERT/REPLACE 返回 last_insert_id，其他返回影响行数
            $first7 = strtoupper(substr(trim($sql), 0, 7));
            if ($first7 === 'INSERT ' || $first7 === 'REPLACE') {
                return (int)$this->last_insert_id();
            }
        } else {
            $this->error();
        }
        return $n;
    }

    public function truncate(string $table): int|false {
        $sql = "TRUNCATE TABLE {$this->tablepre}$table";
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

    protected function is_support_innodb(PDO $link): int {
        $stmt = $link->query("SHOW ENGINES");
        $engines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($engines as $engine) {
            if (strcasecmp($engine['Engine'], 'InnoDB') === 0) {
                return isset($engine['Support']) && ($engine['Support'] === 'DEFAULT' || $engine['Support'] === 'YES') ? 1 : 0;
            }
        }
        return 0;
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
