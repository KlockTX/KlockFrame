<?php
/**
 * 内置服务器路由脚本：真实文件直出，其余交给单入口
 */
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
