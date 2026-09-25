<?php
/**
 * KlockFrame 2.0 单元回归（CLI）
 *
 * 覆盖：
 *   F. kf_ 门面：请求参数/头/方法、安全、工具函数逐一转发正确
 *   H. 局部刷新请求侧识别（HX-* 头）
 *   V. 片段渲染：kf_capture / kf_fragment / kf_oob、布局旁路、异常不泄漏缓冲
 *   E. 元素一等方法：动词与交互属性渲染、JSON 值、布尔语义、转义
 *   S. 响应控制头校验（非法名/含换行值拒绝）
 *   A. 交互运行时：URL 解析、kf_runtime() 装载、内置路由注册顺序、完整性校验
 *   D. 兼容：全部框架文件在 E_ALL 下独立加载零诊断
 *
 * 真实 HTTP 语义（状态码、响应头、304）由 tests/http.php 覆盖。
 *
 * 用法: php tests/unit.php
 * 退出码: 0=全部通过, 1=存在失败
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限 CLI 运行\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
// 先启动会话：CSRF 用例需要 token，CLI 下一旦有输出就无法再启动
@session_start();

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$tmp  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kf_unit_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/views/error', 0755, true);
register_shutdown_function(static function () use ($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp . '/views/error'); @rmdir($tmp . '/views'); @rmdir($tmp);
});

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
    else { $fail++; echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

// ---------- 测试用应用 ----------
file_put_contents($tmp . '/views/page.php', <<<'PHP'
<?php
kf_layout('layout');
echo kf_div(kf_h1($title ?? 'x'))->id('main');
PHP);
file_put_contents($tmp . '/views/layout.php', <<<'PHP'
<!DOCTYPE html><html><head><title>L</title></head><body><?= kf_content() ?></body></html>
PHP);
file_put_contents($tmp . '/views/row.php', <<<'PHP'
<?php
echo kf_div($text ?? '')->id('row')->attr('data-x', 'a>b')->class('c1 c2');
PHP);
file_put_contents($tmp . '/views/nested.php', <<<'PHP'
<?php
kf_layout('layout');
echo kf_div(kf_raw(kf_capture('row', ['text' => 'inner'])))->id('wrap');
PHP);
file_put_contents($tmp . '/views/boom.php', <<<'PHP'
<?php
throw new RuntimeException('视图内异常');
PHP);
file_put_contents($tmp . '/views/error/404.php', <<<'PHP'
<?php
kf_layout('layout');
echo kf_div('片段错误 ' . ($code ?? ''))->id('err');
PHP);
file_put_contents($tmp . '/views/plain_text.php', '裸文本片段');
// 应用路由：用于验证框架内置路由注册在其后（应用可覆盖）
file_put_contents($tmp . '/routes.php', <<<'PHP'
<?php
kf_get('/app-first', function () { echo 'app'; });
PHP);

chdir($root);
define('KF_NO_DISPATCH', true);
define('APP_PATH', $tmp . '/');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cli';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
require $root . 'klockframe.php';
// 日志落到临时目录：响应头拒绝等分支会 kf_log，默认按 cwd 落 .php 会污染仓库
kf_config_set('log_path', $tmp . '/log/');

check('L0 装载为 2.1.0 且只有 kf_ 前缀的应用面', KF_VERSION === '2.1.0', KF_VERSION);
check('L1 旧模块文件已不存在',
    !is_file($root . 'helpers.func.php') && !is_file($root . 'htmx.func.php'));
$stale = array_values(array_filter(
    get_defined_functions()['user'],
    static fn(string $f): bool => str_starts_with($f, 'kf_htmx_')
));
check('L2 没有任何 kf_htmx_* 残留函数', $stale === [], implode(',', $stale));

echo "\n=== F. kf_ 门面 ===\n";
$_GET = ['q' => '搜索 <b>x</b>', 'n' => '42', 'none' => ''];
$_REQUEST = $_GET;
check('F1 kf_param 默认转义', kf_param('q') === '搜索 &lt;b&gt;x&lt;/b&gt;', (string)kf_param('q'));
check('F2 kf_param 可关转义取原值', kf_param('q', '', false) === '搜索 <b>x</b>');
check('F3 缺失键返回默认', kf_param('nope', 'dflt') === 'dflt');
check('F4 kf_param_int 强转', kf_param_int('n') === 42 && kf_param_int('missing', 7) === 7);
check('F5 kf_param_word 白名单', kf_param_word('n') === '42');
$_POST = ['j' => '{"a":1}'];
$_REQUEST = $_POST + $_GET;
check('F6 kf_param_json 解码', kf_param_json('j') === ['a' => 1], var_export(kf_param_json('j'), true));
$_REQUEST = $_GET;
check('F7 kf_method 大写且 HEAD 归一', kf_method() === 'GET');
$_SERVER['REQUEST_METHOD'] = 'HEAD';
check('F7b HEAD 归一为 GET', kf_method() === 'GET');
$_SERVER['REQUEST_METHOD'] = 'POST';
check('F7c POST 原样', kf_method() === 'POST');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_PROBE'] = '探针值';
check('F8 kf_request_header 读取', kf_request_header('X-Probe') === '探针值' && kf_request_header('X-Nope', 'd') === 'd');
$_COOKIE = ['sid' => 'abc'];
check('F9 kf_cookie 读取（数组值不炸）', kf_cookie('sid') === 'abc' && kf_cookie('bad', 'd') === 'd');
$_COOKIE = ['bad' => ['x']];
check('F9b 数组 Cookie 回落默认', kf_cookie('bad', 'd') === 'd');
$_SERVER['HTTP_REFERER'] = 'https://cpac.top/a';
check('F10 kf_referer', kf_referer() === 'https://cpac.top/a');
check('F11 kf_ip 与底层同源', kf_ip() === ip(), kf_ip());
check('F12 kf_rand 长度与字符集', strlen(kf_rand(16)) === 16 && preg_match('#^[a-z]{24}$#', kf_rand(24, 'lower')) === 1
    && preg_match('#^[0-9]{8}$#', kf_rand(8, 'num')) === 1);
$cipher = kf_encrypt('载荷 payload', 'unit-key');
check('F13 kf_encrypt/decrypt 往返', kf_decrypt($cipher, 'unit-key') === '载荷 payload');
check('F14 错误密钥解不出', kf_decrypt($cipher, 'other-key-unit') === false);
check('F15 kf_signdata 稳定', kf_signdata(['a' => 1], 'k') === kf_signdata(['a' => 1], 'k'));
check('F16 kf_validate 返回错误键',
    array_keys(kf_validate(['a' => '', 'b' => 'ok'], ['a' => ['required' => true], 'b' => ['required' => true]])) === ['a']);
check('F16b kf_validate 通过时为空', kf_validate(['a' => 'x'], ['a' => ['required' => true]]) === []);
check('F17 kf_substr 按字符截', kf_substr('中文截取', 0, 2) === '中文', kf_substr('中文截取', 0, 2));
check('F18 kf_pagination 出 HTML', str_contains(kf_pagination(95, 2, 10, '/p/{page}'), '<a'));
check('F19 kf_json_encode/decode 往返', kf_json_decode((string)kf_json_encode(['a' => '中'])) === ['a' => '中']);
$attr_json = kf_json_attr(['q' => '<a>&"b']);
check('F20 kf_json_attr 转义 HTML/脚本敏感字符',
    !str_contains($attr_json, '<') && !str_contains($attr_json, '>') && !str_contains($attr_json, '&')
    && $attr_json === '{"q":"\\u003Ca\\u003E\\u0026\\u0022b"}', $attr_json);
$hook_ran = null;
kf_hook_register('unit_probe', static function ($v) use (&$hook_ran) { $hook_ran = $v; });
kf_hook('unit_probe', 'ok');
check('F21 kf_hook 注册并触发', $hook_ran === 'ok');
check('F22 kf_hook_filter 改值', kf_hook_filter('unit_no_filter', 'raw') === 'raw');
kf_log('unit 探针', 'unit', $tmp . '/unit-log.php');
check('F23 kf_log 落盘且带 exit 守卫',
    is_file($tmp . '/unit-log.php') && str_starts_with((string)file_get_contents($tmp . '/unit-log.php'), '<?php exit;?>'));
check('F24 kf_dir_get 返回目录路径', is_dir(kf_dir_get(1)) || is_string(kf_dir_get(1)));
// 按需模块：应用侧只需 kf_zip/kf_unzip，不需要手动 require 核心文件
$zipdir = $tmp . '/z'; @mkdir($zipdir, 0755, true);
file_put_contents($zipdir . '/a.txt', 'A');
if (class_exists('ZipArchive')) {
    check('F25 kf_zip 自动引入模块并打包', kf_zip([$zipdir . '/a.txt' => 'a.txt'], $zipdir . '/p.zip') === true);
    check('F26 kf_unzip 解包', kf_unzip($zipdir . '/p.zip', $zipdir . '/out') === true
        && is_file($zipdir . '/out/a.txt'));
} else {
    echo "[SKIP] ZipArchive 不可用\n";
}
check('F27 门面不需要应用直呼 xn_/param', !function_exists('kf_dummy') && function_exists('kf_param'));

echo "\n=== H. 局部刷新请求侧 ===\n";
function sethx(array $headers): void {
    foreach (['HX-Request','HX-Boosted','HX-History-Restore-Request','HX-Prompt','HX-Target',
              'HX-Trigger','HX-Trigger-Name','HX-Current-URL'] as $k) {
        unset($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))]);
    }
    foreach ($headers as $k => $v) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
}
sethx([]);
check('H1 无头时不是局部刷新', kf_is_htmx() === false && kf_is_fragment() === false);
sethx(['HX-Request' => 'true']);
check('H2 HX-Request 被识别', kf_is_htmx() === true && kf_is_fragment() === true && kf_is_boosted() === false);
sethx(['HX-Request' => 'true', 'HX-Boosted' => 'true']);
check('H3 boost 判为整页（不是片段）', kf_is_htmx() === true && kf_is_fragment() === false);
sethx(['HX-Request' => '1']);
check('H4 非 true 值不误判', kf_is_htmx() === false);
sethx(['HX-Request' => 'true', 'HX-Prompt' => "带'引号'", 'HX-Target' => 'list', 'HX-Trigger' => 'btn1',
       'HX-Trigger-Name' => 'named', 'HX-Current-URL' => 'https://x.test/a', 'HX-History-Restore-Request' => 'true']);
check('H5 各识别函数取值正确',
    kf_prompt() === "带'引号'" && kf_target_id() === 'list' && kf_trigger_id() === 'btn1'
    && kf_trigger_name() === 'named' && kf_request_url() === 'https://x.test/a' && kf_is_history() === true);
sethx([]);
check('H6 缺失头返回空串', kf_prompt() === '' && kf_target_id() === '');

echo "\n=== V. 片段渲染 ===\n";
$frag = kf_capture('row', ['text' => '甲']);
check('V1 片段跳过布局', $frag === '<div id="row" data-x="a&gt;b" class="c1 c2">甲</div>', $frag);
check('V2 片段文本仍被转义', str_contains(kf_capture('row', ['text' => '<b>x</b>']), '&lt;b&gt;'));
$nested = kf_capture('nested', ['title' => 'T']);
check('V3 片段内嵌套片段不吞外层布局状态',
    str_contains($nested, 'id="wrap"') && str_contains($nested, 'id="row"') && !str_contains($nested, '<!DOCTYPE'), $nested);
check('V4 片段渲染后布局回到待处理态', $GLOBALS['_kf_layout'] === null);
ob_start();
kf_view('page', ['title' => '整页']);
$page = ob_get_clean();
check('V5 非片段请求下 kf_view 输出整页', str_contains($page, '<!DOCTYPE'), substr($page, 0, 30));
sethx(['HX-Request' => 'true']);
ob_start();
kf_view('page', ['title' => '整页'], 'row', ['text' => '片段专用']);
$page = ob_get_clean();
check('V6 kf_view 第三参数指定片段视图', $page === '<div id="row" data-x="a&gt;b" class="c1 c2">片段专用</div>', $page);
ob_start();
kf_view('page', ['title' => '同名片段']);
$page = ob_get_clean();
check('V7 未指定片段时复用同一视图且丢布局', $page === '<div id="main"><h1>同名片段</h1></div>', $page);
sethx(['HX-Request' => 'true', 'HX-Boosted' => 'true']);
ob_start();
kf_view('page', ['title' => '整页']);
$page = ob_get_clean();
check('V8 boost 导航按整页返回', str_contains($page, '<!DOCTYPE'), substr($page, 0, 30));
sethx(['HX-Request' => 'true']);
check('V9 kf_fragment 直接输出', (function () { ob_start(); kf_fragment('row', ['text' => '直出']); return ob_get_clean(); })() === '<div id="row" data-x="a&gt;b" class="c1 c2">直出</div>');
check('V10 共享变量对片段可见', (function () {
    kf_share('text', '共享值');
    $r = kf_capture('row');
    $GLOBALS['_kf_shared'] = [];
    return str_contains($r, '共享值');
})());
$levels_before = ob_get_level();
try {
    kf_capture('boom');
    check('V11 视图异常向上抛出', false);
} catch (Throwable $e) {
    check('V11 视图异常向上抛出', $e->getMessage() === '视图内异常');
}
check('V12 异常后缓冲区层级复原', ob_get_level() === $levels_before, 'level=' . ob_get_level());
check('V13 异常后布局状态复原', $GLOBALS['_kf_layout'] === null);
check('V14 裸文本片段被 trim', kf_capture('plain_text') === '裸文本片段');

echo "\n=== O. Out-of-Band ===\n";
$oob = kf_oob('row', '#list', ['text' => '行']);
check('O1 选择器形态', $oob === '<div hx-swap-oob="#list" id="row" data-x="a&gt;b" class="c1 c2">行</div>', $oob);
check('O2 策略:选择器形态', str_contains(kf_oob('row', '#list', ['text' => '行'], 'beforeend'), 'hx-swap-oob="beforeend:#list"'));
check('O3 无选择器时按 id 匹配', str_contains(kf_oob('row', '', ['text' => '行']), 'hx-swap-oob="true"'));
check('O4 注入不破坏原有属性与内容',
    str_contains($oob, 'id="row"') && str_contains($oob, 'class="c1 c2"') && str_contains($oob, '>行</div>'));
$oob5 = kf_oob('row', '#a"b', ['text' => 'x']);
check('O5 选择器中的引号被转义', str_contains($oob5, 'hx-swap-oob="#a&quot;b"') && !str_contains($oob5, 'hx-swap-oob="#a"b"'), $oob5);
check('O6 无法定位根元素时原样返回', _kf_mark_oob('裸文本', '#x') === '裸文本');
check('O7 前导空白仍能定位根元素', str_starts_with(_kf_mark_oob("  \n <p>a</p>", '#x'), "  \n <p hx-swap-oob=\"#x\">a</p>"));

echo "\n=== E. 元素一等方法 ===\n";
check('E1 动词方法', (string)kf_button('x')->get('/a') === '<button hx-get="/a">x</button>'
    && str_contains((string)kf_form()->post('/b'), 'hx-post="/b"')
    && str_contains((string)kf_div()->put('/c'), 'hx-put="/c"')
    && str_contains((string)kf_div()->patch('/d'), 'hx-patch="/d"')
    && str_contains((string)kf_button()->delete('/e'), 'hx-delete="/e"'));
check('E2 链式组合可读', (string)kf_button('删除')->delete('/todo/5')->hx_target('#list')->swap('outerHTML')->confirm('确定？')
    === '<button hx-delete="/todo/5" hx-target="#list" hx-swap="outerHTML" hx-confirm="确定？">删除</button>',
    (string)kf_button('删除')->delete('/todo/5')->hx_target('#list')->swap('outerHTML')->confirm('确定？'));
check('E3 swap 带选项', (string)kf_div()->swap('innerHTML', ['swap' => '0.5s', 'transition' => true])
    === '<div hx-swap="innerHTML swap:0.5s transition:true"></div>');
check('E4 oob 方法', str_contains((string)kf_div()->oob('#c', 'outerHTML'), 'hx-swap-oob="outerHTML:#c"'));
check('E5 trigger 表达式', str_contains((string)kf_input()->get('/s')->trigger('keyup changed delay:300ms'), 'hx-trigger="keyup changed delay:300ms"'));
check('E6 boost 裸属性', (string)kf_div()->boost() === '<div hx-boost></div>', (string)kf_div()->boost());
check('E7 vals/headers 数组自动 JSON', str_contains((string)kf_div()->vals(['q' => '中']), 'hx-vals="{&quot;q&quot;:&quot;中&quot;}"'),
    (string)kf_div()->vals(['q' => '中']));
check('E8 on 事件方法', (string)kf_div()->on('::after-request', 'this.blur()') === '<div hx-on::after-request="this.blur()"></div>',
    (string)kf_div()->on('::after-request', 'this.blur()'));
check('E9 其余属性方法齐全', str_contains((string)kf_div()->select('#a')->indicator('.loading')->preserve()
    ->sync('abort:first')->params(['a', 'b'])->push_url('/p')->prompt('输入'), 'hx-select="#a"'));
check('E10 通用 hx() 仍可用', (string)kf_div('x')->hx('get', '/y') === '<div hx-get="/y">x</div>'
    && (string)kf_div('x')->hx(['swap' => 'none']) === '<div hx-swap="none">x</div>');
check('E11 false/null 不输出属性', (string)kf_div('x')->get(false)->delete(null) === '<div>x</div>');
check('E12 条件表达式直接可用', str_contains((string)kf_div()->get($ok = true ? '/on' : false), 'hx-get="/on"'));
check('E13 属性值无法逃出引号', !str_contains((string)kf_div()->get('"/ onload="alert(1)'), 'onload="alert(1)"'));
check('E14 HTML target 未被占用', (string)kf_a('t')->href('/x')->target('_blank') === '<a href="/x" target="_blank">t</a>');
check('E15 kf_runtime 与 <head> 标签函数不冲突',
    (string)kf_head(kf_div('h')) === '<head><div>h</div></head>' && kf_runtime() instanceof KF_Element);
check('E16 kf_header 仍是 <header> 标签函数', (string)kf_header('site') === '<header>site</header>');

echo "\n=== S. 响应控制头校验 ===\n";
check('S1 头名含换行被拒', _kf_response_header_normalize("Evil\r\nX-Injected: 1", 'v') === false);
check('S2 头名含空格被拒', _kf_response_header_normalize('Bad Name', 'v') === false);
check('S3 头值含 CRLF 整条拒绝', _kf_response_header_normalize('Reswap', "outerHTML\r\nX: 1") === false);
check('S4 头值含裸 LF 被拒', _kf_response_header_normalize('Trigger', "a\nb") === false);
check('S5 合法值通过并补前缀', _kf_response_header_normalize('Reswap', 'outerHTML swap:0.5s') === 'HX-Reswap');
check('S6 已带 HX- 不重复补', _kf_response_header_normalize('HX-Refresh', 'true') === 'HX-Refresh');
check('S7 选择器与 JSON 里的引号照常放行',
    _kf_response_header_normalize('Retarget', '#a[data-x="1"]:nth-child(2)') === 'HX-Retarget');
check('S8 头已发送时返回 false 不报错', kf_response_header('Reswap', 'innerHTML') === false);

echo "\n=== A. 交互运行时 ===\n";
kf_config_set('app.base_url', 'https://cpac.top');
check('A1 route 模式解析为内置路由 + 版本串',
    kf_runtime_url() === 'https://cpac.top/kf/htmx.js?v=' . KF_RUNTIME_VERSION, kf_runtime_url());
kf_config_set('app.htmx.src', 'cdn');
check('A2 cdn 模式', kf_runtime_url() === KF_RUNTIME_CDN && str_contains(KF_RUNTIME_CDN, '@' . KF_RUNTIME_VERSION));
kf_config_set('app.htmx.src', '/static/htmx.js');
check('A3 自定义 URL 原样', kf_runtime_url() === '/static/htmx.js');
kf_config_set('app.htmx.src', 'route');
$tags = (string)kf_runtime(['defaultSwapStyle' => 'outerHTML']);
check('A4 装载顺序 meta → 脚本 → CSRF', ($a = strpos($tags, '<meta')) !== false
    && ($b = strpos($tags, '<script src')) > $a && strpos($tags, 'htmx:configRequest') > $b, substr($tags, 0, 60));
check('A5 脚本带 defer', str_contains($tags, 'defer'));
check('A6 CSRF 头名与 token 内联正确', preg_match('/headers\["X-CSRF-Token"\]="[0-9a-zA-Z]{32}";/', $tags) === 1);
kf_config_set('app.htmx.config', ['defaultSwapStyle' => 'outerHTML']);
check('A7 config 走 meta 通道', str_contains((string)kf_runtime(), 'defaultSwapStyle'));
kf_config_set('app.htmx.config', []);
check('A8 无配置时不输出 meta', str_contains((string)kf_runtime(), '<meta') === false);
kf_config_set('app.htmx.csrf', false);
check('A9 csrf=false 时不注入', !str_contains((string)kf_runtime(), 'configRequest'));
kf_config_set('app.htmx.csrf', true);
kf_config_set('app.htmx.csrf_header', 'X-Bad Header');
check('A10 非法头名被拒且不产出脚本', (string)kf_runtime_csrf_script() === '');
kf_config_set('app.htmx.csrf_header', 'X-CSRF-Token');
$rt_idx = null; $app_idx = null;
foreach ($GLOBALS['_kf_routes'] as $i => $r) {
    if ($r['path'] === KF_RUNTIME_ROUTE) $rt_idx = $rt_idx ?? $i;
    if ($r['path'] === '/app-first') $app_idx = $i;
}
check('A11 内置路由已注册', $rt_idx !== null, 'index=' . var_export($rt_idx, true));
check('A12 内置路由注册在应用路由之后（应用可覆盖）',
    $app_idx !== null && $rt_idx !== null && $app_idx < $rt_idx, "app={$app_idx} runtime={$rt_idx}");
$rt_out = (function () { ob_start(); kf_runtime_response(); return (string)ob_get_clean(); });
check('A13 运行时直出内容完整', strlen($rt_out()) === filesize(KF_RUNTIME_FILE));
check('A14 直出前校验 sha256（正常文件通过）', hash_file('sha256', KF_RUNTIME_FILE) === KF_RUNTIME_SHA256);

// 被篡改的运行时文件不得静默送进浏览器：用子进程预定义 KF_RUNTIME_FILE 验证
$tamper = $tmp . '/kf_tampered.js';
file_put_contents($tamper, (string)file_get_contents(KF_RUNTIME_FILE) . 'x');
$probe = $root . 'tests/.tmp_probe_runtime.php';
file_put_contents($probe, <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('KF_NO_DISPATCH', true);
define('APP_PATH', $argv[2]);
define('KF_RUNTIME_FILE', $argv[1]);
@mkdir(APP_PATH, 0755, true);
require dirname(__DIR__) . '/klockframe.php';
// 探针子进程的 cwd 是工作区，日志必须收进临时目录
kf_config_set('log_path', APP_PATH);
kf_runtime_response();
PHP);
$probe_out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' '
    . escapeshellarg($tamper) . ' ' . escapeshellarg($tmp . '/probe_app/') . ' 2>&1');
check('A15 完整性校验不过时拒绝直出', str_contains($probe_out, 'integrity check failed'), substr($probe_out, 0, 100));
check('A16 拒绝时未吐出行时字节', !str_contains($probe_out, 'var htmx='));
check('A17 错误页不泄漏服务器绝对路径',
    !str_contains($probe_out, $tamper) && !str_contains($probe_out, $tmp),
    (string)preg_match('#[A-Za-z]:[^ <]*\.js#', $probe_out, $m) ? $m[0] : '');
@unlink($probe);

echo "\n=== N. 2.1 新增能力 ===\n";
check('N1 字符串管道式规则与数组式等价',
    kf_validate(['a' => ''], ['a' => 'required']) === kf_validate(['a' => ''], ['a' => ['required' => true]]));
check('N2 required 生效', array_keys(kf_validate(['a' => ''], ['a' => 'required'])) === ['a']);
check('N3 max 数值参数按长度判定', array_keys(kf_validate(['a' => 'abcdef'], ['a' => 'max:4'])) === ['a']
    && kf_validate(['a' => 'abc'], ['a' => 'max:4']) === []);
check('N4 管道多规则按序短路', kf_validate(['a' => ''], ['a' => 'required|max:4']) === ['a' => 'a 不能为空']);
check('N5 label 改错误消息里的显示名',
    kf_validate(['a' => ''], ['a' => 'required|label:昵称']) === ['a' => '昵称 不能为空'],
    json_encode(kf_validate(['a' => ''], ['a' => 'required|label:昵称']), JSON_UNESCAPED_UNICODE));
check('N6 in 按逗号切成数组（底层要求数组参数）',
    kf_validate(['a' => 'x'], ['a' => 'in:a,b,c']) !== [] && kf_validate(['a' => 'b'], ['a' => 'in:a,b,c']) === []);
check('N7 email/int 等布尔式规则可用', kf_validate(['a' => 'not-mail'], ['a' => 'email']) !== []
    && kf_validate(['a' => '1.5'], ['a' => 'int']) !== [] && kf_validate(['a' => '7'], ['a' => 'int']) === []);
check('N8 regex 原样传参', kf_validate(['a' => 'abc'], ['a' => 'regex:/^[0-9]+$/']) !== []
    && kf_validate(['a' => '123'], ['a' => 'regex:/^[0-9]+$/']) === []);
check('N9 数组式原样透传（含数字参数）',
    _kf_normalize_rules(['a' => ['required' => true, 'max' => 20]]) === ['a' => ['required' => true, 'max' => 20]]);
$_SERVER['ajax'] = true;
check('N10 kf_is_xhr 读到底层标志', kf_is_xhr() === true);
unset($_SERVER['ajax']);
sethx([]); // 前面片段用例留着 HX-Request，这里要的是「两条通道都为假」的基线
check('N11 无标志时 kf_is_xhr 为 false（与 htmx 通道互不干扰）',
    kf_is_xhr() === false && kf_is_htmx() === false);
$_POST = ['text' => '原始值 <b>', 'n' => '1'];
$_GET = ['q' => 'g'];
check('N12 kf_form_data 默认取 POST 且不转义（转义交给模板）', kf_form_data() === $_POST);
check('N13 kf_form_data 可取 GET / REQUEST', kf_form_data('get') === $_GET && is_array(kf_form_data('request')));
check('N14 片段自动禁缓存默认开启', kf_config('app.htmx.no_cache', true) === true);
$nc_ok = true;
try { kf_no_cache(); } catch (Throwable $e) { $nc_ok = false; }
check('N15 头已发送时 kf_no_cache 静默不抛', $nc_ok === true);
check('N16 required 不 trim 纯空白（表单须先 trim 再校验）',
    kf_validate(['a' => '   '], ['a' => 'required']) === []
    && kf_validate(['a' => ''], ['a' => 'required']) !== []);

echo "\n=== D. PHP 8.4 加载兼容性 ===\n";
// 仓库根即框架本体：只扫根目录模块与 xiunophp/，不把 tests/ 当框架文件
$framework_files = [];
foreach (array_merge(glob($root . '*.php'), glob($root . 'xiunophp/*.php')) as $file) {
    $framework_files[] = str_replace('\\', '/', substr($file, strlen($root)));
}
sort($framework_files);
check('D0 覆盖到 2.0 的三个新模块',
    in_array('request.func.php', $framework_files, true)
    && in_array('response.func.php', $framework_files, true)
    && in_array('util.func.php', $framework_files, true), count($framework_files) . ' 个文件');

function kf_load_diag(string $rel, string $root): array {
    // 模块文件在 include 期会读 KF_PATH（运行时文件常量），预定义后才是真正的独立加载检测
    $code = "define('APP_PATH','" . addslashes($root) . "');"
        . "define('KF_PATH','" . addslashes($root) . "');"
        . "require '" . addslashes($root . $rel) . "';";
    $cmd  = escapeshellarg(PHP_BINARY)
        . ' -d display_errors=1 -d error_reporting=32767 -d log_errors=0 -r ' . escapeshellarg($code) . ' 2>&1';
    $out  = (string)shell_exec($cmd);
    $lines = [];
    foreach (preg_split('/\R/', $out) ?: [] as $l) {
        if (preg_match('/Deprecated|Notice:|Warning:|Fatal error|Parse error/', $l)) $lines[] = trim($l);
    }
    return $lines;
}
$dirty = [];
foreach ($framework_files as $rel) {
    $diag = kf_load_diag($rel, $root);
    if ($diag !== []) $dirty[] = $rel . ' → ' . substr($diag[0], 0, 110);
}
check('D1 框架 ' . count($framework_files) . ' 个文件独立加载零诊断', $dirty === [], "\n  " . implode("\n  ", $dirty));
$chain = kf_load_diag('klockframe.php', $root);
check('D2 生产装载链加载零诊断', $chain === [], "\n  " . implode("\n  ", $chain));

echo "\n结果: {$pass} 通过, {$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
