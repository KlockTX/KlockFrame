<?php
/**
 * KlockFrame 2.0 测试夹具路由
 *
 * 只使用 kf_ 前缀：这是「应用侧只需一个前缀」的活证据。
 */

function todo_file(): string {
    return sys_get_temp_dir() . '/kf_fixture_todos.json';
}
function todo_read(): array {
    if (!is_file(todo_file())) {
        return [
            ['id' => 1, 'text' => '写周报', 'done' => true],
            ['id' => 2, 'text' => '买咖啡豆', 'done' => false],
        ];
    }
    $data = kf_json_decode((string)file_get_contents(todo_file()));
    return is_array($data) ? $data : [];
}
function todo_write(array $items): void {
    file_put_contents(todo_file(), kf_json_encode($items));
}

/** 首页：整页与片段共用同一视图，由 kf_view() 自己判定 */
kf_get('/', function () {
    kf_view('home');
});

/** 列表页：片段请求回 todo_list，boost 导航与浏览器直访回整页 */
kf_get('/todo', function () {
    kf_view('todo', ['items' => todo_read()], 'todo_list');
});

/** 新增：一次响应同时换入列表与带外计数；空输入走错误片段 */
kf_post('/todo/add', function () {
    if (!kf_csrf_ok()) {
        kf_abort(403, 'CSRF 校验未通过');
    }
    $text = trim((string)($_POST['text'] ?? ''));
    if ($text === '') {
        kf_abort(422, '任务名不能为空');
    }
    $items = todo_read();
    $next = 1;
    foreach ($items as $it) $next = max($next, (int)$it['id'] + 1);
    $items[] = ['id' => $next, 'text' => $text, 'done' => false];
    todo_write($items);
    kf_reswap('outerHTML');
    kf_trigger('todo:added', ['n' => count($items)]);
    echo kf_capture('todo_list', ['items' => $items]);
    echo kf_oob('todo_count', '#todo-count', ['n' => count($items)], 'outerHTML');
});

/** 删除：只带自动注入的 CSRF 头、没有表单字段，用于验证头通道确实打通 */
kf_delete('/todo/{id:\d+}', function ($id) {
    if (!kf_csrf_ok()) {
        kf_abort(403, 'CSRF 校验未通过');
    }
    $items = [];
    foreach (todo_read() as $it) {
        if ((int)$it['id'] !== (int)$id) $items[] = $it;
    }
    todo_write($items);
    echo kf_capture('todo_list', ['items' => $items]);
    echo kf_oob('todo_count', '#todo-count', ['n' => count($items)], 'outerHTML');
});

/** 提交后跳转：片段请求走软跳转，其余走真实 302 */
kf_post('/go', function () {
    kf_redirect('/todo');
});

/** 只设头不结束响应，便于继续附加事件 */
kf_get('/soft-redirect', function () {
    kf_redirect('/todo', 302, false);
    kf_trigger('form:saved');
    echo '<i id="soft">ok</i>';
});

/** 只触发事件不换入任何东西 */
kf_post('/clear', function () {
    kf_trigger('toast:show', ['msg' => '已保存']);
    kf_empty();
});

/** 各类响应控制头 */
kf_get('/headers', function () {
    kf_retarget('#box');
    kf_reselect('.inner');
    kf_push_url('/pushed');
    kf_replace_url('/replaced');
    kf_reswap('innerHTML', ['swap' => '0.5s', 'transition' => true]);
    kf_trigger_after_swap('swap:done');
    kf_trigger_after_settle(['settle:a' => 1, 'settle:b' => true]);
    kf_location(['path' => '/todo', 'target' => '#main']);
    echo '<i id="hdr">ok</i>';
});

kf_get('/refresh', function () {
    kf_refresh();
    kf_push_url(false);
    echo '<i id="ref">ok</i>';
});

/** 片段响应禁缓存 */
kf_get('/frag-home', function () {
    kf_no_cache();
    kf_fragment('home');
});

/** 请求侧识别结果 */
kf_get('/echo', function () {
    kf_json([
        'hx'           => kf_is_htmx(),
        'boosted'      => kf_is_boosted(),
        'fragment'     => kf_is_fragment(),
        'history'      => kf_is_history(),
        'prompt'       => kf_prompt(),
        'target'       => kf_target_id(),
        'trigger'      => kf_trigger_id(),
        'trigger_name' => kf_trigger_name(),
        'request_url'  => kf_request_url(),
        'method'       => kf_method(),
    ]);
});

/** 响应头拆分注入防护 */
kf_get('/split', function () {
    $bad_name  = kf_response_header("Evil\r\nX-Injected-Name: 1", 'v');
    $bad_value = kf_response_header('Reswap', "outerHTML\r\nX-Injected-Value: 1");
    echo '<i id="split">' . (int)$bad_name . '-' . (int)$bad_value . '</i>';
});

/** 门面函数在真实请求下的表现 */
kf_get('/facade', function () {
    $cipher = kf_encrypt('敏感载荷', 'fixture-key');
    kf_log('facade 探针', 'fixture');
    $fired = null;
    kf_hook_register('fixture_probe', function ($v) use (&$fired) { $fired = $v; });
    kf_hook('fixture_probe', 'hook-ok');
    kf_json([
        'param'      => kf_param('q', '默认'),
        'param_int'  => kf_param_int('n', 7),
        'method'     => kf_method(),
        'header'     => kf_request_header('X-Probe', '无'),
        'ip_ok'      => filter_var(kf_ip(), FILTER_VALIDATE_IP) !== false || kf_ip() === '',
        'rand_len'   => strlen(kf_rand(16)),
        'encrypt_rt' => kf_decrypt($cipher, 'fixture-key'),
        'encrypt_bad'=> kf_decrypt($cipher, 'wrong-key-fixture') === false,
        'validate'   => array_keys(kf_validate(['a' => ''], ['a' => ['required' => true]])),
        'substr'     => kf_substr('中文截取', 0, 2),
        'json_attr'  => kf_json_attr(['q' => '<a>&"b']),
        'pagination' => strlen(kf_pagination(95, 2, 10, '/p/{page}')) > 0,
        'hook'       => $fired,
        'elapsed_ge' => kf_elapsed() >= 0,
        'runtime'    => kf_runtime_url(),
    ]);
});

/** CSRF：token 取值与请求头校验 */
kf_get('/token', function () {
    echo kf_csrf_token();
});
kf_get('/csrf', function () {
    echo kf_csrf_ok() ? 'OK' : 'DENY';
});

/** 故意 404，验证错误片段 */
kf_get('/boom', function () {
    kf_abort(404, '东西不见了');
});
