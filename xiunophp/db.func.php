<?php
/*
 * db.func.php — 数据库函数层
 *
 * 核心变更 (4.1):
 * - 所有条件/插入/更新辅助函数返回 [sql, params] 元组
 * - 底层使用 PDO 预处理语句, 杜绝 SQL 注入
 * - 新增 db_transaction() 事务封装
 *
 * 性能优化 (1.1):
 * - db_cond_to_sqladd() / db_array_to_insert_sqladd() / db_array_to_update_sqladd()
 *   使用进程内静态缓存，相同输入直接命中（CRUD 高频路径）
 * - 缓存键用 md5(serialize) 简化，避免重复字符串拼接
 * - 内部辅助函数局部化变量，减少属性哈希查找
 */

// ---------- 工厂 ----------
function db_new(array $conf): object {
    $type = $conf['type'] ?? 'mysql';
    switch ($type) {
        case 'mysql':
        case 'pdo_mysql':
            return new db_pdo_mysql($conf);
        case 'pdo_sqlite':
            return new db_pdo_sqlite($conf);
        default:
            return new db_pdo_mysql($conf);
    }
}

function db_connect(?object $d = null): bool {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    return $d ? $d->connect() : false;
}

function db_close(?object $d = null): bool {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    return $d ? $d->close() : false;
}

// ---------- 执行 ----------
function db_exec(string $sql, array $params = [], ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    DEBUG AND xn_log($sql, 'db_exec');
    $n = $d->exec($sql, $params);
    db_errno_errstr($n, $d, $sql);
    return $n;
}

function db_sql_find_one(string $sql, array $params = [], ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    DEBUG AND xn_log($sql, 'db_sql_find_one');
    $arr = $d->sql_find_one($sql, $params);
    db_errno_errstr($arr, $d, $sql);
    return $arr;
}

function db_sql_find(string $sql, array $params = [], string $key = '', ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    DEBUG AND xn_log($sql, 'db_sql_find');
    $arrlist = $d->sql_find($sql, $key, $params);
    db_errno_errstr($arrlist, $d, $sql);
    return $arrlist;
}

// ---------- CRUD ----------
function db_find(string $table, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10, string $key = '', array $col = [], ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $arrlist = $d->find($table, $cond, $orderby, $page, $pagesize, $key, $col);
    db_errno_errstr($arrlist, $d);
    return $arrlist;
}

function db_find_one(string $table, array $cond = [], array $orderby = [], array $col = [], ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $arr = $d->find_one($table, $cond, $orderby, $col);
    db_errno_errstr($arr, $d);
    return $arr;
}

function db_count(string $table, array $cond = [], ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $n = $d->count($table, $cond);
    db_errno_errstr($n, $d);
    return $n;
}

function db_maxid(string $table, string $field, array $cond = [], ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $n = $d->maxid($table, $field, $cond);
    db_errno_errstr($n, $d);
    return $n;
}

function db_insert(string $table, array $arr, ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $insertsql = db_array_to_insert_sqladd($arr);
    $sql = "INSERT INTO {$d->tablepre}$table $insertsql[0]";
    $n = $d->exec($sql, $insertsql[1]);
    db_errno_errstr($n, $d, $sql);
    return $n;
}

function db_replace(string $table, array $arr, ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $insertsql = db_array_to_insert_sqladd($arr);
    $sql = "REPLACE INTO {$d->tablepre}$table $insertsql[0]";
    $n = $d->exec($sql, $insertsql[1]);
    db_errno_errstr($n, $d, $sql);
    return $n;
}

function db_update(string $table, array $cond, array $update, ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $updatesql = db_array_to_update_sqladd($update);
    [$condsql, $condparams] = db_cond_to_sqladd($cond);
    $sql = "UPDATE {$d->tablepre}$table SET $updatesql[0] $condsql";
    $params = array_merge($updatesql[1], $condparams);
    $n = $d->exec($sql, $params);
    db_errno_errstr($n, $d, $sql);
    return $n;
}

function db_delete(string $table, array $cond, ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    [$condsql, $params] = db_cond_to_sqladd($cond);
    $sql = "DELETE FROM {$d->tablepre}$table $condsql";
    $n = $d->exec($sql, $params);
    db_errno_errstr($n, $d, $sql);
    return $n;
}

function db_read(string $table, array $cond = [], array $orderby = [], array $col = [], ?object $d = null) {
    return db_find_one($table, $cond, $orderby, $col, $d);
}

function db_create(string $table, array $data, ?object $d = null) {
    return db_insert($table, $data, $d);
}

function db_truncate(string $table, ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $sql = "TRUNCATE TABLE {$d->tablepre}$table";
    $n = $d->exec($sql);
    db_errno_errstr($n, $d, $sql);
    return $n;
}

// ---------- 事务 ----------
function db_transaction(callable $fn, ?object $d = null) {
    $db = $_SERVER['db'] ?? null;
    $d = $d ?: $db;
    if (!$d) return false;
    $d->begin_transaction();
    try {
        $r = $fn();
        $d->commit();
        return $r;
    } catch (Throwable $e) {
        $d->rollback();
        throw $e;
    }
}

// ---------- 错误处理 ----------
function db_errno_errstr($r, object $d, string $sql = ''): void {
    if ($r === false || $r === -1) {
        $errno = $d->errno();
        $errstr = $d->errstr();
        $_SERVER['errno'] = $errno;
        $_SERVER['errstr'] = $errstr;
        DEBUG AND xn_log("errno: $errno, errstr: $errstr, sql: $sql", 'db_error');
    }
}

function db_errstr_safe(): string {
    $errstr = $_SERVER['errstr'] ?? '';
    // 生产环境隐藏详细错误
    return DEBUG ? $errstr : '';
}

// ---------- SQL 辅助函数 ----------

/**
 * 条件数组转 SQL WHERE 子句
 *
 * 支持的写法:
 *   ['id' => 123]
 *   ['id' => [1, 2, 3]]              // IN / OR
 *   ['id' => ['>' => 100, '<' => 200]]
 *   ['username' => ['LIKE' => 'jack']]
 *
 * @return array [string $sql, array $params]
 */
function db_cond_to_sqladd(array $cond): array {
    if (empty($cond)) return ['', []];

    // 静态缓存：CRUD 高频路径，相同条件直接命中
    static $cache = [];
    $cache_key = md5(serialize($cond));
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $s = ' WHERE ';
    $params = [];
    foreach ($cond as $k => $v) {
        $col = '`' . str_replace('`', '``', $k) . '`';
        if (!is_array($v)) {
            if (is_int($v) || is_float($v)) {
                $s .= "$col=$v AND ";
            } elseif ($v === null) {
                $s .= "$col IS NULL AND ";
            } else {
                $s .= "$col=? AND ";
                $params[] = $v;
            }
        } elseif (isset($v[0])) {
            // 数组 → OR
            $s .= '(';
            foreach ($v as $v1) {
                if (is_int($v1) || is_float($v1)) {
                    $s .= "$col=$v1 OR ";
                } else {
                    $s .= "$col=? OR ";
                    $params[] = $v1;
                }
            }
            $s = substr($s, 0, -4) . ') AND ';
        } else {
            // 操作符 => 值
            foreach ($v as $op => $v1) {
                $op = strtoupper($op);
                if ($op === 'LIKE') {
                    $s .= "$col LIKE ? AND ";
                    $params[] = "%$v1%";
                } elseif ($op === 'IN') {
                    $placeholders = [];
                    foreach ((array)$v1 as $item) {
                        if (is_int($item) || is_float($item)) {
                            $placeholders[] = $item;
                        } else {
                            $placeholders[] = '?';
                            $params[] = $item;
                        }
                    }
                    $s .= "$col IN (" . implode(',', $placeholders) . ") AND ";
                } else {
                    if (is_int($v1) || is_float($v1)) {
                        $s .= "$col $op $v1 AND ";
                    } else {
                        $s .= "$col $op ? AND ";
                        $params[] = $v1;
                    }
                }
            }
        }
    }
    $s = substr($s, 0, -4);
    $result = [$s, $params];
    $cache[$cache_key] = $result;
    return $result;
}

/**
 * 排序数组转 ORDER BY 子句
 *
 * @return string
 */
function db_orderby_to_sqladd(array $orderby): string {
    if (empty($orderby)) return '';
    $s = ' ORDER BY ';
    $comma = '';
    foreach ($orderby as $k => $v) {
        $col = '`' . str_replace('`', '``', $k) . '`';
        $s .= $comma . $col . ($v == 1 ? ' ASC' : ' DESC');
        $comma = ',';
    }
    return $s;
}

/**
 * 数组转 INSERT VALUES 子句
 *
 * @return array [string $sql, array $params]
 */
function db_array_to_insert_sqladd(array $arr): array {
    // 静态缓存
    static $cache = [];
    $cache_key = md5(serialize($arr));
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $keys = [];
    $placeholders = [];
    $params = [];
    foreach ($arr as $k => $v) {
        $keys[] = '`' . str_replace('`', '``', $k) . '`';
        if (is_int($v) || is_float($v)) {
            $placeholders[] = (string)$v;
        } elseif ($v === null) {
            $placeholders[] = 'NULL';
        } else {
            $placeholders[] = '?';
            $params[] = $v;
        }
    }
    $sql = '(' . implode(',', $keys) . ') VALUES (' . implode(',', $placeholders) . ')';
    $result = [$sql, $params];
    $cache[$cache_key] = $result;
    return $result;
}

/**
 * 数组转 UPDATE SET 子句
 *
 * 支持 +/- 后缀做自增自减: ['stocks+' => 1] → stocks=stocks+1
 *
 * @return array [string $sql, array $params]
 */
function db_array_to_update_sqladd(array $arr): array {
    // 静态缓存
    static $cache = [];
    $cache_key = md5(serialize($arr));
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $s = '';
    $params = [];
    foreach ($arr as $k => $v) {
        $op = substr($k, -1);
        if ($op === '+' || $op === '-') {
            $col = '`' . str_replace('`', '``', substr($k, 0, -1)) . '`';
            if (is_int($v) || is_float($v)) {
                $s .= "$col=$col$op$v,";
            } else {
                $s .= "$col=$col$op?,";
                $params[] = $v;
            }
        } else {
            $col = '`' . str_replace('`', '``', $k) . '`';
            if (is_int($v) || is_float($v)) {
                $s .= "$col=$v,";
            } elseif ($v === null) {
                $s .= "$col=NULL,";
            } else {
                $s .= "$col=?,";
                $params[] = $v;
            }
        }
    }
    $result = [substr($s, 0, -1), $params];
    $cache[$cache_key] = $result;
    return $result;
}
