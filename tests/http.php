<?php
/**
 * KlockFrame 2.0 真实 HTTP 往返测试
 *
 * 起一台 php -S 跑 tests/app 夹具，用运行时真实发出的请求头验证：
 *   1 装载：kf_runtime() 的 meta/defer 脚本/CSRF 注入出现在页面里
 *   2 内置路由：运行时直出 200、Content-Type、ETag、immutable 长缓存、If-None-Match → 304
 *   3 双输出：kf_view() 对片段/整页/boost 的自动判定
 *   4 Out-of-Band、错误片段（403/422/404/未注册路径）
 *   5 kf_redirect() 三条分支（302 / 204+软跳转 / boost 302）
 *   6 各类响应控制头与事件时机
 *   7 响应头拆分注入防护
 *   8 CSRF 头通道（无表单字段的 hx-delete 只能靠它）
 *   9 kf_ 门面在真实请求下的表现
 *  10 全程无 PHP 诊断泄漏
 *
 * 用法: php tests/http.php
 * 退出码: 0=全部通过, 1=存在失败
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限 CLI 运行\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dir = __DIR__ . '/app';

// 装载框架以取用 KF_RUNTIME_* 常量（CLI 下不分发）
define('KF_NO_DISPATCH', true);
define('APP_PATH', $dir . '/');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cli';
require dirname(__DIR__) . '/klockframe.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
    else { $fail++; echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function free_port(): int {
    for ($p = 8321; $p < 8421; $p++) {
        $s = @fsockopen('127.0.0.1', $p, $en, $es, 0.2);
        if ($s === false) return $p;
        fclose($s);
    }
    return 0;
}
$port = free_port();
if ($port === 0) exit("没有可用端口\n");

$err_log = sys_get_temp_dir() . '/kf_http_server.log';
$cookie  = sys_get_temp_dir() . '/kf_http_cookie.txt';
@unlink($err_log); @unlink($cookie);
@unlink(sys_get_temp_dir() . '/kf_fixture_todos.json');

$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir, $dir . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $err_log, 'a'], 2 => ['file', $err_log, 'a']],
    $pipes,
    $dir
);
if (!is_resource($proc)) exit("无法启动内置服务器\n");

register_shutdown_function(static function () use ($proc, $pipes, $err_log, $cookie) {
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    @unlink($err_log);
    @unlink($cookie);
    @unlink(sys_get_temp_dir() . '/kf_fixture_todos.json');
    // 清掉夹具写下的日志目录
    $log = sys_get_temp_dir() . '/kf_fixture_log';
    if (is_dir($log)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($log, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($log);
    }
});

$ready = false;
for ($i = 0; $i < 60; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.3);
    if ($s !== false) { fclose($s); $ready = true; break; }
    usleep(100000);
}
if (!$ready) {
    echo "服务器未就绪:\n" . (string)file_get_contents($err_log) . "\n";
    exit(1);
}
check('T0 内置服务器就绪', true, '127.0.0.1:' . $port);

/** 发一次请求，返回 status/headers/body */
function req(string $path, array $opts = []): array {
    global $port, $cookie;
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = $opts['headers'] ?? [];
    if (isset($opts['post'])) {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($opts['post']));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if (isset($opts['method'])) {
        curl_setopt($h, CURLOPT_CUSTOMREQUEST, $opts['method']);
        curl_setopt($h, CURLOPT_POSTFIELDS, $opts['body'] ?? '');
    }
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($h);
    $status = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    $hsize  = (int)curl_getinfo($h, CURLINFO_HEADER_SIZE);
    curl_close($h);
    $raw = $raw === false ? '' : (string)$raw;
    $head = substr($raw, 0, $hsize);
    $resp = [];
    foreach (preg_split('/\R+/', $head) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, 'HTTP/')) continue;
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $resp[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return ['status' => $status, 'headers' => $resp, 'body' => substr($raw, $hsize), 'raw' => $raw, 'head' => $head];
}
function hx(array $extra = []): array {
    $out = ['HX-Request: true'];
    foreach ($extra as $k => $v) $out[] = $k . ': ' . $v;
    return $out;
}
function h(array $r, string $name): string {
    return $r['headers'][strtolower($name)] ?? '';
}
$leaked = static function (array $r): bool {
    return (bool)preg_match('/(Warning:|Deprecated:|Notice:|Fatal error|Parse error)/', $r['body']);
};

echo "\n=== 1. 页面装载 ===\n";
$r = req('/');
check('1.1 整页含运行时装载标签', $r['status'] === 200
    && str_contains($r['body'], '<meta name="htmx-config"')
    && str_contains($r['body'], 'src="/kf/htmx.js?v=' . KF_RUNTIME_VERSION . '"')
    && str_contains($r['body'], 'defer')
    && str_contains($r['body'], 'htmx:configRequest'), substr($r['body'], 0, 100));
check('1.2 元素一等方法渲染正确', str_contains($r['body'], 'hx-get="/todo"')
    && str_contains($r['body'], 'hx-target="#todo-list"')
    && str_contains($r['body'], 'hx-trigger="revealed"')
    && str_contains($r['body'], 'hx-post="/go"'));
check('1.3 vals 里的中文与 & 安全落在属性', str_contains($r['body'], 'hx-vals="{&quot;q&quot;:&quot;'),
    (string)preg_match('/hx-vals="[^"]*"/', $r['body'], $m) ? substr($m[0], 0, 70) : '');
check('1.4 布局里 hx-boost 生效位', str_contains($r['body'], '<body hx-boost="true">'));
check('10.1 首页无 PHP 诊断泄漏', !$leaked($r));

echo "\n=== 2. 内置运行时路由 ===\n";
$r = req('/kf/htmx.js');
check('2.1 直出 200 且类型正确', $r['status'] === 200
    && str_contains(h($r, 'Content-Type'), 'text/javascript'), h($r, 'Content-Type'));
check('2.2 内容即框架内置文件', strlen($r['body']) === filesize(KF_RUNTIME_FILE), 'len=' . strlen($r['body']));
check('2.3 ETag 由版本+sha 组成', h($r, 'ETag') === '"' . KF_RUNTIME_VERSION . '-' . substr(KF_RUNTIME_SHA256, 0, 16) . '"', h($r, 'ETag'));
check('2.4 长缓存 immutable', h($r, 'Cache-Control') === 'public, max-age=31536000, immutable', h($r, 'Cache-Control'));
$r2 = req('/kf/htmx.js', ['headers' => ['If-None-Match: ' . h($r, 'ETag')]]);
check('2.5 If-None-Match 命中返回 304 且无响应体', $r2['status'] === 304 && trim($r2['body']) === '', 'status=' . $r2['status']);
check('2.6 304 仍带 ETag 与类型', h($r2, 'ETag') === h($r, 'ETag') && str_contains(h($r2, 'Content-Type'), 'text/javascript'));
$rh = req('/kf/htmx.js', ['method' => 'HEAD']);
check('2.7 HEAD 复用 GET 路由', $rh['status'] === 200 && trim($rh['body']) === '', 'status=' . $rh['status']);
check('2.8 带版本查询串同样命中', req('/kf/htmx.js?v=' . KF_RUNTIME_VERSION)['status'] === 200);

echo "\n=== 3. 双输出 ===\n";
$r = req('/todo');
check('3.1 浏览器直访回整页', str_contains($r['body'], '<!DOCTYPE') && str_contains($r['body'], 'id="todo-list"'));
$r = req('/todo', ['headers' => hx()]);
check('3.2 片段请求只回片段', str_starts_with($r['body'], '<ul id="todo-list"') && !str_contains($r['body'], '<!DOCTYPE'), substr($r['body'], 0, 60));
$r = req('/todo', ['headers' => hx(['HX-Boosted' => 'true'])]);
check('3.3 boost 导航回整页', str_contains($r['body'], '<!DOCTYPE'), substr($r['body'], 0, 40));
$r = req('/echo', ['headers' => hx(['HX-Target' => 'main', 'HX-Trigger' => 'btn1', 'HX-Trigger-Name' => 'probe',
    'HX-Prompt' => '输入的文字', 'HX-Current-URL' => 'http://x.test/todo', 'HX-History-Restore-Request' => 'true'])]);
$d = json_decode($r['body'], true);
check('3.4 请求侧识别端到端', is_array($d) && $d['hx'] === true && $d['fragment'] === true
    && $d['history'] === true && $d['prompt'] === '输入的文字' && $d['target'] === 'main'
    && $d['trigger'] === 'btn1' && $d['trigger_name'] === 'probe', substr($r['body'], 0, 140));
$d2 = json_decode(req('/echo')['body'], true);
check('3.5 无头时判为整页', $d2['hx'] === false && $d2['fragment'] === false);

echo "\n=== 4. OOB 与错误片段 ===\n";
$token = trim(req('/token')['body']);
$r = req('/todo/add', ['headers' => hx(), 'post' => ['text' => '换入新行', 'csrf_token' => $token]]);
check('4.1 一次响应同时换入列表与带外计数',
    str_contains($r['body'], '<ul id="todo-list"')
    && str_contains($r['body'], 'hx-swap-oob="outerHTML:#todo-count"')
    && str_contains($r['body'], '换入新行'), substr($r['body'], 0, 120));
check('4.2 响应控制头 Reswap 与 Trigger', h($r, 'HX-Reswap') === 'outerHTML'
    && str_contains(h($r, 'HX-Trigger'), 'todo:added'), h($r, 'HX-Reswap') . '|' . h($r, 'HX-Trigger'));
$r = req('/todo/add', ['headers' => hx(), 'post' => ['text' => '  ', 'csrf_token' => $token]]);
check('4.3 校验失败：422 + 错误片段而非整页', $r['status'] === 422
    && !str_contains($r['body'], '<!DOCTYPE') && str_contains($r['body'], '任务名不能为空'), substr($r['body'], 0, 90));
$r = req('/todo/add', ['headers' => hx(), 'post' => ['text' => 'x', 'csrf_token' => 'wrong']]);
check('4.4 CSRF 失败：403 + 默认错误片段', $r['status'] === 403
    && str_contains($r['body'], 'class="kf-error"') && !str_contains($r['body'], '<!DOCTYPE'), substr($r['body'], 0, 90));
$r = req('/boom', ['headers' => hx()]);
check('4.5 404 片段用项目自己的错误视图', $r['status'] === 404
    && str_contains($r['body'], 'id="err"') && !str_contains($r['body'], '<!DOCTYPE'), $r['body']);
check('4.6 404 整页（非片段请求）带布局', str_contains(req('/boom')['body'], '<!DOCTYPE'));
$r = req('/no-such-page', ['headers' => hx()]);
check('4.7 未注册路径在片段请求下也回片段', $r['status'] === 404 && !str_contains($r['body'], '<!DOCTYPE'));
$r = req('/frag-home', ['headers' => hx(['HX-Request' => 'true', 'X-Probe' => 'v'])]);
check('4.8 kf_no_cache 落到响应头', str_contains(h($r, 'Cache-Control'), 'no-store'), h($r, 'Cache-Control'));

echo "\n=== 5. kf_redirect 三条分支 ===\n";
$r = req('/go', ['headers' => hx(), 'post' => ['x' => '1']]);
check('5.1 片段请求走 204 + 软跳转头', $r['status'] === 204 && h($r, 'HX-Redirect') === '/todo'
    && trim($r['body']) === '', 'status=' . $r['status'] . ' loc=' . h($r, 'HX-Redirect'));
$r = req('/go', ['post' => ['x' => '1']]);
check('5.2 普通表单提交走真实 302', $r['status'] === 302 && h($r, 'Location') === '/todo');
$r = req('/go', ['headers' => hx(['HX-Boosted' => 'true']), 'post' => ['x' => '1']]);
check('5.3 boost 提交走真实 302', $r['status'] === 302 && h($r, 'Location') === '/todo');
$r = req('/soft-redirect', ['headers' => hx()]);
check('5.4 exit=false 只设头不结束响应', $r['status'] === 204 && h($r, 'HX-Redirect') === '/todo'
    && str_contains(h($r, 'HX-Trigger'), 'form:saved') && trim($r['body']) === '', h($r, 'HX-Trigger'));
$r = req('/clear', ['method' => 'POST', 'headers' => hx()]);
check('5.5 kf_empty：204 无体但事件送达', $r['status'] === 204 && trim($r['body']) === ''
    && h($r, 'HX-Trigger') === '{"toast:show":{"msg":"已保存"}}', h($r, 'HX-Trigger'));

echo "\n=== 6. 响应控制头 ===\n";
$r = req('/headers', ['headers' => hx()]);
check('6.1 Retarget/Reselect/Push/Replace', h($r, 'HX-Retarget') === '#box' && h($r, 'HX-Reselect') === '.inner'
    && h($r, 'HX-Push-Url') === '/pushed' && h($r, 'HX-Replace-Url') === '/replaced', json_encode($r['headers'], JSON_UNESCAPED_SLASHES));
check('6.2 Reswap 带选项', h($r, 'HX-Reswap') === 'innerHTML swap:0.5s transition:true', h($r, 'HX-Reswap'));
check('6.3 事件在 swap/settle 两个时机', h($r, 'HX-Trigger-After-Swap') === '{"swap:done":true}'
    && h($r, 'HX-Trigger-After-Settle') === '{"settle:a":1,"settle:b":true}', h($r, 'HX-Trigger-After-Settle'));
check('6.4 Location 为 JSON 规格', h($r, 'HX-Location') === '{"path":"/todo","target":"#main"}', h($r, 'HX-Location'));
$r = req('/refresh', ['headers' => hx()]);
check('6.5 Refresh 与 Push:false', h($r, 'HX-Refresh') === 'true' && h($r, 'HX-Push') === 'false');

echo "\n=== 7. 注入防护 ===\n";
$r = req('/split', ['headers' => hx()]);
check('7.1 非法头名被拒（不落头）', h($r, 'X-Injected-Name') === '' && !str_contains($r['raw'], 'X-Injected-Name:')
    && str_contains($r['body'], '>0-'), $r['body']);
check('7.2 头值含换行整条拒绝', h($r, 'HX-Reswap') === '' && !str_contains($r['raw'], 'X-Injected-Value:')
    && str_contains($r['body'], '>0-0<'), $r['body']);

echo "\n=== 8. CSRF 头通道 ===\n";
$token = trim(req('/token')['body']);
check('8.1 带正确头的校验通过', req('/csrf', ['headers' => ['X-CSRF-Token: ' . $token]])['body'] === 'OK');
check('8.2 带错误头的校验拒绝', req('/csrf', ['headers' => ['X-CSRF-Token: nope' . $token]])['body'] === 'DENY');
check('8.3 无头无字段拒绝', req('/csrf')['body'] === 'DENY');
$del = req('/todo/1', ['method' => 'DELETE', 'headers' => array_merge(hx(), ['X-CSRF-Token: ' . $token])]);
check('8.4 无表单字段的 DELETE 仅凭头通过并回片段', $del['status'] === 200
    && str_contains($del['body'], '<ul id="todo-list"')
    && str_contains($del['body'], 'hx-swap-oob="outerHTML:#todo-count"')
    && !str_contains($del['body'], '写周报'), substr($del['body'], 0, 100));
$del2 = req('/todo/2', ['method' => 'DELETE', 'headers' => hx()]);
check('8.5 无头的 DELETE 被拒并回错误片段', $del2['status'] === 403 && !str_contains($del2['body'], '<!DOCTYPE'));
check('8.6 页面内联的 token 与会话一致',
    str_contains(req('/')['body'], 'headers["X-CSRF-Token"]="' . $token . '"'),
    (string)preg_match('/configRequest.{0,70}/s', req('/')['body'], $m) ? $m[0] : '');

echo "\n=== 9. 门面在真实请求下 ===\n";
$r = req('/facade?n=42', ['headers' => ['X-Probe: 探针']]);
$d = json_decode($r['body'], true);
check('9.1 门面各函数返回合理值', is_array($d) && $d['param'] === '默认' && $d['param_int'] === 42
    && $d['method'] === 'GET' && $d['header'] === '探针' && $d['ip_ok'] === true
    && $d['rand_len'] === 16 && $d['encrypt_rt'] === '敏感载荷' && $d['encrypt_bad'] === true
    && $d['validate'] === ['a'] && $d['substr'] === '中文' && $d['hook'] === 'hook-ok'
    && $d['pagination'] === true && $d['elapsed_ge'] === true, substr((string)json_encode($d, JSON_UNESCAPED_UNICODE), 0, 200));
check('9.2 运行时 URL 走内置路由', is_array($d) && str_starts_with((string)($d['runtime'] ?? ''), '/kf/htmx.js?v='),
    is_array($d) ? (string)($d['runtime'] ?? '') : '');
check('9.3 json_attr 在真实响应里仍被转义', is_array($d) && $d['json_attr'] === '{"q":"\\u003Ca\\u003E\\u0026\\u0022b"}',
    is_array($d) ? (string)($d['json_attr'] ?? '') : '');
check('10.2 全程响应无 PHP 诊断泄漏', !$leaked($r) && !$leaked(req('/')) && !$leaked(req('/todo')));

echo "\n=== 11. 片段缓存策略与校验语法（2.1）===\n";
$r = req('/todo', ['headers' => hx()]);
check('11.1 片段响应自动 no-store', str_contains(h($r, 'Cache-Control'), 'no-store'), h($r, 'Cache-Control'));
$r = req('/todo');
check('11.2 整页不被 kf_no_cache 改写（仍是 PHP session limiter 的默认值）',
    h($r, 'Cache-Control') !== 'no-store, must-revalidate'
    && str_contains(h($r, 'Cache-Control'), 'no-cache'), h($r, 'Cache-Control') ?: '(无)');
$token = trim(req('/token')['body']);
$r = req('/todo/add', ['headers' => hx(), 'post' => ['text' => 'x', 'csrf_token' => 'bad']]);
check('11.3 CSRF 仍旧优先拦截（新增校验步骤未改变顺序）', $r['status'] === 403, 'status=' . $r['status']);
$r = req('/todo/add', ['headers' => hx(), 'post' => ['text' => str_repeat('长', 50), 'csrf_token' => $token]]);
check('11.4 字符串管道式规则在真实请求下生效（max:40 被拒）',
    $r['status'] === 422 && str_contains($r['body'], '任务名不能为空'), 'status=' . $r['status']);
$r = req('/todo/add', ['headers' => hx(), 'post' => ['text' => '管道式通过', 'csrf_token' => $token]]);
check('11.5 合法输入照旧换入列表与 OOB', $r['status'] === 200
    && str_contains($r['body'], '管道式通过') && str_contains($r['body'], 'hx-swap-oob='), 'status=' . $r['status']);

echo "\n服务器日志（应无告警）:\n";
$logsan = trim((string)@file_get_contents($err_log));
$interesting = array_values(array_filter(explode("\n", $logsan), static function ($l) {
    return preg_match('/Warning|Deprecated|Notice|Fatal|error:/', $l) === 1;
}));
check('10.3 服务器日志无告警/废弃提示', $interesting === [], implode(' | ', array_slice($interesting, 0, 2)));

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
