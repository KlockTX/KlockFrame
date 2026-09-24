# 路由系统

KlockFrame 提供轻量但功能完备的路由系统，支持路径参数、路由分组和多种处理器类型。

## 基本路由

```php
// GET 请求
kf_get('/', function() {
    echo 'Hello World';
});

// POST 请求
kf_post('/user/create', function() {
    $name = kf_param('name');
    db_insert('users', ['name' => $name]);
    kf_redirect('/users');
});

// PUT / DELETE
kf_put('/user/{id}', function($id) { /* ... */ });
kf_delete('/user/{id}', function($id) { /* ... */ });

// 匹配所有方法
kf_any('/health', function() {
    kf_json(['status' => 'ok']);
});
```

## 路径参数

### 基本参数

```php
kf_get('/user/{id}', function($id) {
    echo "User ID: $id";
});
// /user/123 → $id = '123'
```

### 正则约束

```php
kf_get('/user/{id:\d+}', function($id) {
    echo "User ID: $id";
});
// /user/123 → 匹配
// /user/abc → 不匹配
```

### 多参数

```php
kf_get('/post/{category}/{slug}', function($category, $slug) {
    echo "Category: $category, Slug: $slug";
});
// /post/php/hello-world → $category='php', $slug='hello-world'
```

## 路由分组

```php
kf_group('/api', function() {
    kf_get('/users', function() {
        // 匹配 GET /api/users
    });

    kf_get('/posts', function() {
        // 匹配 GET /api/posts
    });

    kf_post('/upload', function() {
        // 匹配 POST /api/upload
    });
});
```

嵌套分组：

```php
kf_group('/api', function() {
    kf_group('/v1', function() {
        kf_get('/users', function() {
            // 匹配 GET /api/v1/users
        });
    });
});
```

## 处理器类型

### 1. 闭包

```php
kf_get('/', function() {
    kf_view('home');
});
```

### 2. 函数名

```php
function home_page() {
    kf_view('home');
}

kf_get('/', 'home_page');
```

### 3. 控制器@方法

```php
kf_get('/user/{id}', 'user@show');
```

约定：
- 加载 `controllers/user.php` 文件
- 调用 `user_show($id)` 函数

```php
// controllers/user.php
function user_show($id) {
    $user = db_find_one('users', ['id' => $id]);
    kf_view('user', ['user' => $user]);
}
```

## 路由文件

可以在 `routes.php` 中集中定义路由，框架会自动加载：

```php
// routes.php
kf_get('/', function() { /* ... */ });
kf_get('/about', function() { /* ... */ });
kf_get('/contact', function() { /* ... */ });
```

## 手动分发

默认情况下，`klockframe.php` 末尾会自动调用 `kf_dispatch()`。

如需手动控制分发时机，定义 `KF_NO_DISPATCH` 常量：

```php
<?php
define('KF_NO_DISPATCH', true);
require_once __DIR__ . '/KlockFrame/klockframe.php';

// 自定义逻辑...

kf_dispatch(); // 手动分发
```

## URL 重写

### Apache (.htaccess)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.php [QSA,L]
```

### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### PHP 内置服务器

```bash
php -S localhost:8000
```

无需额外配置，内置服务器会自动将请求交给 `index.php`。

## 404 处理

当没有路由匹配时，框架会调用 `kf_not_found()`：

1. 设置 HTTP 404 状态码
2. 尝试加载 `views/404.php` 视图
3. 如果视图不存在，输出默认 404 页面

自定义 404 页面：

```php
// views/404.php
<?php
kf_layout('layout');
echo kf_div(
    kf_h1('404'),
    kf_p('页面不存在'),
    kf_a('← 返回首页')->href('/')
)->class('error-page')->render();
```

## Hook 集成

路由分发过程提供两个 Hook 点：

```php
// 分发前
kf_hook_register('klockframe_dispatch_before', function($path, $method) {
    // 记录访问日志、权限检查等
});

// 匹配后
kf_hook_register('klockframe_dispatch_matched', function($route, $params) {
    // 参数预处理、审计日志等
});
```
