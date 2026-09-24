<?php
/*
 * router.func.php — 路由系统
 *
 * 特性:
 * - 支持 GET/POST/PUT/DELETE/ANY 方法
 * - 路径参数捕获: /user/{id} 或 /user/{id:\d+}
 * - 路由分组: kf_group('/api', function() { ... })
 * - 处理器: 闭包 / 函数名 / 控制器@方法
 * - 自动分发 + 404 处理
 *
 * 控制器约定:
 *   'user@show' → 加载 app/controllers/user.php → 调用 user_show($params)
 *
 * 性能优化 (1.1):
 * - 路由注册时一次性编译，分发时零额外开销
 * - 控制器文件加载结果缓存，避免重复 file_exists
 * - 分发时使用 count() 局部变量减少 GLOBALS 哈希查找
 * - 已加载控制器文件通过 static 记忆，避免重复 include
 *
 * 1.2 改进:
 * - 405 Method Not Allowed 支持（路径匹配但方法不匹配时）
 * - kf_not_found 复用 kf_abort，消除重复 HTML 输出
 * - 路由表引用遍历，避免大路由表复制
 *
 * 2.0 变更:
 * - Hook 与请求方法读取统一走 kf_ 门面（kf_hook / kf_method）
 * - 内置路由（交互运行时）由 klockframe.php 注册在应用路由之后，应用可自行覆盖
 */

// 全局路由表
$GLOBALS['_kf_routes'] = [];
$GLOBALS['_kf_group_prefix'] = '';
$GLOBALS['_kf_current_route'] = null;

// ---------- 路由注册 ----------

function kf_get(string $path, $handler): void {
    kf_route('GET', $path, $handler);
}

function kf_post(string $path, $handler): void {
    kf_route('POST', $path, $handler);
}

function kf_put(string $path, $handler): void {
    kf_route('PUT', $path, $handler);
}

function kf_delete(string $path, $handler): void {
    kf_route('DELETE', $path, $handler);
}

function kf_any(string $path, $handler): void {
    kf_route('ANY', $path, $handler);
}

function kf_route(string $method, string $path, $handler): void {
    $path = $GLOBALS['_kf_group_prefix'] . $path;
    // 注册时一次性编译，避免分发时重复编译
    [$regex, $params] = kf_compile_path($path);
    $GLOBALS['_kf_routes'][] = [
        'method'  => strtoupper($method),
        'path'    => $path,
        'regex'   => $regex,
        'params'  => $params,
        'handler' => $handler,
    ];
}

/**
 * 路由分组
 *
 * @param string   $prefix   前缀
 * @param callable $callback 回调函数
 */
function kf_group(string $prefix, callable $callback): void {
    $old = $GLOBALS['_kf_group_prefix'];
    $GLOBALS['_kf_group_prefix'] = $old . $prefix;
    try {
        $callback();
    } finally {
        // 回调抛异常也必须还原前缀，否则后续路由会被静默挂到错误前缀下
        $GLOBALS['_kf_group_prefix'] = $old;
    }
}

// ---------- 路径编译 ----------

/**
 * 将路径模式编译为正则表达式
 *
 * /user/{id}       → #^/user/([^/]+)$#  params: ['id']
 * /user/{id:\d+}   → #^/user/(\d+)$#    params: ['id']
 *
 * @return array [regex_string, param_names]
 */
function kf_compile_path(string $path): array {
    // 静态缓存: 相同 path 不重复编译
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $params = [];
    // 匹配 {param} 或 {param:regex}
    $regex = preg_replace_callback('/\{([a-zA-Z_]\w*)(?::([^}]+))?\}/', function($m) use (&$params) {
        $params[] = $m[1];
        $pattern = $m[2] ?? '[^/]+';
        return '(' . $pattern . ')';
    }, $path);
    $result = ['#^' . $regex . '$#', $params];
    // 注册期即校验正则可编译：坏正则在分发时只会让 preg_match 返回 false，
    // 表现为该路由静默 404 且没有任何报错线索
    if (@preg_match($result[0], '') === false) {
        throw new RuntimeException("Invalid route pattern: {$path} → {$result[0]}");
    }
    $cache[$path] = $result;
    return $result;
}

// ---------- 分发 ----------

/**
 * 执行路由分发
 *
 * 1.2 起：当路径匹配但方法不匹配时返回 405 Method Not Allowed，
 * 并自动设置 Allow 响应头列出该路径允许的方法。
 */
function kf_dispatch(): void {
    $method = kf_method(); // HEAD 归一为 GET，复用 GET 路由
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // 提取路径部分（去掉 query string）
    // 使用 strpos 比 parse_url 更快
    $qpos = strpos($uri, '?');
    $path = $qpos !== false ? substr($uri, 0, $qpos) : $uri;
    // 去掉 .htm 后缀
    if (substr($path, -4) === '.htm') {
        $path = substr($path, 0, -4);
    }
    // 确保以 / 开头
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    // 触发分发前 Hook
    kf_hook('klockframe_dispatch_before', $path, $method);

    // 引用遍历，避免大路由表复制
    $routes = &$GLOBALS['_kf_routes'];
    $count = count($routes);

    // 405 检测：路径匹配但方法不匹配时收集允许的方法
    $pathMatched = false;
    $allowedMethods = [];

    for ($i = 0; $i < $count; $i++) {
        $route = &$routes[$i];
        // 先匹配路径（路径匹配率低于方法，但能更早剔除不相关路由）
        if (!preg_match($route['regex'], $path, $matches)) {
            continue;
        }
        // 路径命中
        $pathMatched = true;
        // 检查请求方法
        if ($route['method'] !== $method && $route['method'] !== 'ANY') {
            $allowedMethods[] = $route['method'];
            continue;
        }
        // 完全命中 → 提取参数并执行
        $params = [];
        if ($matches) {
            array_shift($matches); // 去掉完整匹配
            $names = $route['params'];
            $n = count($names);
            for ($j = 0; $j < $n; $j++) {
                $params[$names[$j]] = $matches[$j] ?? '';
            }
        }

        $GLOBALS['_kf_current_route'] = $route;

        // 触发匹配后 Hook
        kf_hook('klockframe_dispatch_matched', $route, $params);

        // 执行处理器
        kf_execute_handler($route['handler'], $params);
        return;
    }

    // 路径命中但方法不匹配 → 405
    if ($pathMatched) {
        if (!headers_sent()) {
            http_response_code(405);
            // Allow 头去重 + 排序（HTTP 规范要求）
            $allowedMethods = array_unique($allowedMethods);
            sort($allowedMethods);
            header('Allow: ' . implode(', ', $allowedMethods));
        }
        kf_abort(405, 'Method Not Allowed');
        return;
    }

    // 无匹配路由 → 404
    kf_not_found();
}

/**
 * 执行路由处理器
 *
 * @param mixed $handler 闭包 / 函数名 / 控制器@方法
 * @param array $params  路由参数
 */
function kf_execute_handler($handler, array $params = []): void {
    // 闭包优先（最常见场景）
    if ($handler instanceof Closure) {
        $handler(...array_values($params));
        return;
    }
    // 控制器@方法: user@show → app/controllers/user.php → user_show()
    if (is_string($handler) && str_contains($handler, '@')) {
        [$controller, $action] = explode('@', $handler, 2);
        // 白名单收口：控制器名参与文件路径拼接、方法名参与函数名构造，
        // 不能依赖路由表一定是字面量这一约定
        if (!preg_match('#^[A-Za-z0-9_\-]+$#', $controller) || !preg_match('#^[A-Za-z0-9_\-]+$#', $action)) {
            kf_abort(500, "Invalid route handler: $handler");
            return;
        }
        $func = $controller . '_' . $action;
        // 函数已存在则直接调用（控制器在 routes.php 中预加载的情况）
        if (function_exists($func)) {
            $func(...array_values($params));
            return;
        }
        // 否则尝试加载控制器文件（带 static 记忆）
        static $loaded = [];
        $file = APP_PATH . 'controllers/' . $controller . '.php';
        if (!isset($loaded[$controller])) {
            $loaded[$controller] = is_file($file);
        }
        if ($loaded[$controller]) {
            include $file;
            if (function_exists($func)) {
                $func(...array_values($params));
                return;
            }
        }
        kf_abort(500, "Controller method not found: $handler");
        return;
    }
    // 函数名
    if (is_string($handler) && function_exists($handler)) {
        $handler(...array_values($params));
        return;
    }
    // 通用 callable（如 ['Class', 'method'] 或类方法）
    if (is_callable($handler)) {
        $handler(...array_values($params));
        return;
    }
    // 处理器无效
    kf_abort(500, 'Invalid route handler');
}

/**
 * 404 处理（复用 kf_abort，统一错误视图路径约定）
 *
 * 视图查找顺序：views/error/404.php → views/404.php → 框架默认 404 HTML
 */
function kf_not_found(): void {
    kf_abort(404, 'Not Found');
}

/**
 * 获取当前匹配的路由信息（调试用）
 *
 * @return array|null
 */
function kf_current_route(): ?array {
    return $GLOBALS['_kf_current_route'] ?? null;
}
