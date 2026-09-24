# 快速开始

## 环境要求

- PHP 8.0+
- PDO 扩展（MySQL 或 SQLite）
- 可选：Redis / Memcached / APCu

## 安装

### 方式一：直接下载

1. 将 `KlockFrame/` 和 `xiunophp/` 放在项目同级目录
2. 创建应用文件

```
myapp/
├── KlockFrame/      # 框架
├── xiunophp/        # 核心
├── index.php        # 入口
├── config.php       # 配置
└── views/           # 视图
```

### 方式二：内置核心

将 `xiunophp/` 复制到 `KlockFrame/xiunophp/`，框架会自动探测。

## 第一个页面

### 1. 创建入口 `index.php`

```php
<?php
require_once __DIR__ . '/KlockFrame/klockframe.php';

kf_get('/', function() {
    kf_view('home', ['title' => 'Hello KlockFrame']);
});
```

### 2. 创建配置 `config.php`

```php
<?php
return [
    'app' => [
        'name'  => 'My App',
        'debug' => true,
        'env'   => 'development',
    ],
    'db' => [
        'type'   => 'mysql',
        'master' => [
            'host'     => '127.0.0.1',
            'user'     => 'root',
            'password' => '',
            'name'     => 'myapp',
            'tablepre' => 'pre_',
            'charset'  => 'utf8',
            'port'     => 3306,
        ],
    ],
    'cache' => [],
    'timezone' => 'Asia/Shanghai',
];
```

### 3. 创建视图 `views/home.php`

```php
<?php
kf_layout('layout');

echo kf_div(
    kf_h1($title),
    kf_p('Welcome to KlockFrame!')
)->class('container')->render();
```

### 4. 创建布局 `views/layout.php`

```php
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title ?? 'My App') ?></title>
</head>
<body>
    <?= kf_content() ?>
</body>
</html>
```

### 5. 启动开发服务器

```bash
php -S localhost:8000
```

访问 `http://localhost:8000` 即可看到页面。

## 目录约定

| 路径 | 说明 |
|------|------|
| `index.php` | 应用入口，定义路由 |
| `config.php` | 配置文件，返回数组 |
| `routes.php` | 可选，路由定义文件（被自动加载） |
| `views/` | 视图目录 |
| `views/layout.php` | 布局模板 |
| `controllers/` | 控制器目录（使用控制器@方法语法时） |
| `logs/` | 日志目录 |

## 配置数据库

```php
// config.php
return [
    'db' => [
        'type'   => 'mysql',
        'master' => [
            'host'     => '127.0.0.1',
            'user'     => 'root',
            'password' => 'secret',
            'name'     => 'myapp',
            'tablepre' => 'pre_',
        ],
        // 读写分离（可选）
        'slaves' => [
            ['host' => '192.168.1.10', 'user' => 'reader', 'password' => '', 'name' => 'myapp'],
        ],
    ],
];
```

## 配置缓存

```php
'cache' => [
    'type'     => 'redis',
    'cachepre' => 'myapp_',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
    ],
],
```

## 下一步

- [路由系统](routing.md) — 定义 URL 和处理器的映射
- [模板引擎](views.md) — 用 PHP 函数构建 HTML
- [数据库](database.md) — CRUD 操作
