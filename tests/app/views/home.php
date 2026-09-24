<?php
/**
 * 首页：演示元素一等方法
 */
kf_layout('layout');

echo kf_div(
    kf_h1('KlockFrame 2.0'),
    // 局部刷新：点击后把 /todo 的片段换进 #todo-list
    kf_button('加载待办片段')->get('/todo')->hx_target('#todo-list'),
    kf_div()->id('todo-list')->class('slot'),
    // 表单提交后跳转（服务端 kf_redirect 决定软跳转还是 302）
    kf_form(kf_input()->type('submit')->value('跳转'))->post('/go'),
    // 进入视口时懒加载
    kf_div('（滚动到这里会自动加载）')->get('/echo')->trigger('revealed'),
    // 附加参数与请求头
    kf_button('带参请求')->get('/facade')->vals(['q' => '甲&乙'])->params('q')
)->class('home')->id('home');
