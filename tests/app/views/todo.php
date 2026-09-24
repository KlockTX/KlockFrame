<?php
/**
 * 待办整页
 *
 * @var array $items
 */
kf_layout('layout');

echo kf_div(
    kf_h1('待办'),
    kf_p('共 ', kf_span((string)count($items))->id('todo-count'), ' 项'),
    kf_raw(kf_capture('todo_list', ['items' => $items])),
    kf_form(
        kf_input()->name('text')->placeholder('新任务'),
        kf_csrf_field(),
        kf_input()->type('submit')->value('添加')
    )->post('/todo/add')->hx_target('#todo-list')->swap('outerHTML')
)->id('todo-wrap');
