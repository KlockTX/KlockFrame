# 数据库

KlockFrame 基于 XiunoPHP 的 PDO 预处理语句数据库层，提供安全、高效的 CRUD 操作。

## 配置

### MySQL

```php
// config.php
'db' => [
    'type'   => 'mysql',
    'master' => [
        'host'     => '127.0.0.1',
        'user'     => 'root',
        'password' => 'secret',
        'name'     => 'myapp',
        'tablepre' => 'pre_',
        'charset'  => 'utf8',
        'port'     => 3306,
    ],
],
```

### 读写分离

```php
'db' => [
    'type'   => 'mysql',
    'master' => [
        'host' => '127.0.0.1', 'user' => 'writer', 'password' => '', 'name' => 'myapp',
    ],
    'slaves' => [
        ['host' => '192.168.1.10', 'user' => 'reader', 'password' => '', 'name' => 'myapp'],
        ['host' => '192.168.1.11', 'user' => 'reader', 'password' => '', 'name' => 'myapp'],
    ],
],
```

写入操作走主库，读取操作随机选一个从库。从库连接失败自动回退到主库。

### SQLite

```php
'db' => [
    'type'      => 'pdo_sqlite',
    'path'      => __DIR__ . '/data/app.db',
    'tablepre'  => '',
],
```

## 查询数据

### 查询单条

```php
$user = db_find_one('users', ['id' => 123]);
// SELECT * FROM pre_users WHERE id=123 LIMIT 1
// 返回关联数组或 false
```

指定字段：

```php
$user = db_find_one('users', ['id' => 123], [], ['id', 'name', 'email']);
// SELECT id, name, email FROM pre_users WHERE id=123 LIMIT 1
```

### 查询列表

```php
$users = db_find('users', ['status' => 1], ['id' => -1], 1, 10);
// SELECT * FROM pre_users WHERE status=1 ORDER BY id DESC LIMIT 0,10
// 返回二维数组
```

参数说明：

```php
db_find(
    string $table,      // 表名（不含前缀）
    array  $cond,       // 条件
    array  $orderby,    // 排序：['id'=>-1] 降序, ['id'=>1] 升序
    int    $page,       // 页码，从 1 开始
    int    $pagesize,   // 每页条数
    string $key,        // 返回数组用此字段做 key
    array  $col         // 指定字段
);
```

以某字段为 key：

```php
$users = db_find('users', [], ['id' => 1], 1, 100, 'id');
// 返回 [123 => ['id'=>123, ...], 456 => [...], ...]
```

### 原始 SQL

```php
// 查询单条
$user = db_sql_find_one("SELECT * FROM pre_users WHERE id=?", [123]);

// 查询列表
$users = db_sql_find("SELECT * FROM pre_users WHERE status=? ORDER BY id DESC LIMIT 10", [1]);

// 带 key
$users = db_sql_find("SELECT id, name FROM pre_users", [], 'id');
```

## 条件写法

### 简单条件

```php
['id' => 123]                    // id = 123
['name' => 'Alice']             // name = 'Alice' (参数化)
['status' => 1, 'type' => 'vip'] // status=1 AND type='vip'
```

### IN 查询

```php
['id' => [1, 2, 3]]             // id=1 OR id=2 OR id=3
['id' => ['IN' => [1, 2, 3]]]   // id IN (1,2,3)
```

### 比较运算

```php
['id' => ['>' => 100]]          // id > 100
['id' => ['<' => 200]]          // id < 200
['id' => ['>=' => 100]]         // id >= 100
['id' => ['<=' => 200]]         // id <= 200
['id' => ['!=' => 0]]           // id != 0
```

### 组合条件

```php
[
    'status' => 1,
    'id' => ['>' => 100, '<' => 200],
    'name' => ['LIKE' => 'alice'],
]
// status=1 AND id>100 AND id<200 AND name LIKE '%alice%'
```

### NULL 条件

```php
['deleted_at' => null]          // deleted_at IS NULL
```

## 插入数据

```php
$id = db_insert('users', [
    'name'  => 'Alice',
    'email' => 'alice@example.com',
    'time'  => time(),
]);
// INSERT INTO pre_users (name, email, time) VALUES (?, ?, ?)
// 返回 last_insert_id
```

`db_replace` 使用 `REPLACE INTO`（存在则替换）：

```php
$id = db_replace('users', ['id' => 1, 'name' => 'Updated']);
```

## 更新数据

```php
$n = db_update('users', ['id' => 123], [
    'name'  => 'Bob',
    'email' => 'bob@example.com',
]);
// UPDATE pre_users SET name=?, email=? WHERE id=123
// 返回影响行数
```

### 自增/自减

字段名加 `+` 或 `-` 后缀：

```php
db_update('posts', ['id' => 1], [
    'views+' => 1,    // views = views + 1
    'likes-' => 1,    // likes = likes - 1
]);
```

## 删除数据

```php
$n = db_delete('users', ['id' => 123]);
// DELETE FROM pre_users WHERE id=123
```

## 统计

### COUNT

```php
$count = db_count('users', ['status' => 1]);
// SELECT COUNT(*) AS num FROM pre_users WHERE status=1
```

> 注意：InnoDB 引擎下，空条件 count 会自动使用 `information_schema.TABLE_ROWS` 估算，提高性能。

### MAX

```php
$maxId = db_maxid('users', 'id');
// SELECT MAX(id) AS maxid FROM pre_users
```

## 事务

```php
db_transaction(function() {
    db_insert('orders', ['user_id' => 1, 'amount' => 100]);
    db_update('users', ['id' => 1], ['balance-' => 100]);
    // 如果任何一步出错，自动回滚
});
```

手动事务：

```php
$db = $_SERVER['db'];
$db->begin_transaction();
try {
    db_insert(...);
    db_update(...);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

## 执行原始 SQL

```php
// 执行 DDL / DML
db_exec("CREATE TABLE pre_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    msg TEXT,
    time INT UNSIGNED
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

db_exec("UPDATE pre_users SET login_time=? WHERE id=?", [time(), 1]);
```

## 表名前缀

表名使用 `tablepre` 配置值作为前缀，所有 `db_*` 函数自动拼接：

```php
// 配置 tablepre = 'pre_'
db_find_one('users', ['id' => 1]);
// 实际表名: pre_users
```

## 错误处理

```php
$n = db_insert('users', $data);
if ($n === false) {
    $errno = $_SERVER['errno'];
    $errstr = $_SERVER['errstr'];
    // 处理错误
}
```

DEBUG 模式下，SQL 错误会自动记录到日志。
