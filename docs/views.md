# 模板引擎

KlockFrame 采用 PurePHP 风格的组件化模板引擎，用 PHP 函数构建 HTML，支持链式属性、组件化和布局继承。

## 核心概念

### KF_Element 类

每个 HTML 标签函数返回一个 `KF_Element` 对象，支持链式调用：

```php
$el = kf_div('Hello')
    ->class('box')
    ->id('main')
    ->style('color: red');

echo $el->render();  // <div class="box" id="main" style="color: red">Hello</div>
```

### 安全转义

所有文本内容默认经过 `htmlspecialchars` 转义：

```php
echo kf_div('<script>alert(1)</script>')->render();
// <div>&lt;script&gt;alert(1)&lt;/script&gt;</div>
```

使用 `kf_raw()` 输出原始 HTML：

```php
echo kf_div(kf_raw('<strong>Bold</strong>'))->render();
// <div><strong>Bold</strong></div>
```

## HTML 标签函数

### 容器标签

```php
kf_div(...$c)       // <div>
kf_span(...$c)      // <span>
kf_section(...$c)   // <section>
kf_article(...$c)   // <article>
kf_header(...$c)    // <header>
kf_footer(...$c)    // <footer>
kf_nav(...$c)       // <nav>
kf_main(...$c)      // <main>
kf_aside(...$c)     // <aside>
```

### 标题标签

```php
kf_h1(...$c)  // <h1>
kf_h2(...$c)  // <h2>
kf_h3(...$c)  // <h3>
kf_h4(...$c)  // <h4>
kf_h5(...$c)  // <h5>
kf_h6(...$c)  // <h6>
```

### 文本标签

```php
kf_p(...$c)          // <p>
kf_a(...$c)          // <a>
kf_strong(...$c)     // <strong>
kf_em(...$c)         // <em>
kf_small(...$c)      // <small>
kf_code(...$c)       // <code>
kf_pre(...$c)        // <pre>
kf_blockquote(...$c) // <blockquote>
kf_br()              // <br>
kf_hr()              // <hr>
```

### 列表标签

```php
kf_ul(...$c)  // <ul>
kf_ol(...$c)  // <ol>
kf_li(...$c)  // <li>
```

### 表单标签

```php
kf_form(...$c)      // <form>
kf_input()          // <input> (自闭合)
kf_textarea(...$c)  // <textarea>
kf_button(...$c)    // <button>
kf_select(...$c)    // <select>
kf_option(...$c)    // <option>
kf_label(...$c)     // <label>
```

### 表格标签

```php
kf_table(...$c)  // <table>
kf_thead(...$c)  // <thead>
kf_tbody(...$c)  // <tbody>
kf_tr(...$c)     // <tr>
kf_th(...$c)     // <th>
kf_td(...$c)     // <td>
```

### 媒体标签

```php
kf_img()        // <img> (自闭合)
kf_video(...$c) // <video>
kf_audio(...$c) // <audio>
kf_source()     // <source> (自闭合)
```

### 文档结构

```php
kf_html(...$c)       // <html>
kf_head(...$c)       // <head>
kf_body(...$c)       // <body>
kf_title_tag($t)     // <title>
kf_meta()            // <meta> (自闭合)
kf_link()            // <link> (自闭合)
kf_script(...$c)     // <script>
kf_style_tag(...$c)  // <style>
```

## 链式属性

### 常用属性方法

```php
kf_div('content')
    ->class('box')
    ->id('main')
    ->style('color: red')
    ->href('/link')      // <a> 标签
    ->src('image.jpg')   // <img> 标签
    ->alt('description')
    ->title('tooltip')
    ->name('field')
    ->value('default')
    ->type('text')
    ->placeholder('Enter...')
    ->action('/submit')
    ->method('POST')
    ->target('_blank')
    ->width('100')
    ->height('50')
```

### data-* 属性

```php
kf_div('content')
    ->data('id', '123')
    ->data('url', '/api')
    ->render();
// <div data-id="123" data-url="/api">content</div>
```

### 通用属性

```php
kf_div('content')
    ->attr('role', 'alert')
    ->attr('tabindex', '0')
    ->render();
// <div role="alert" tabindex="0">content</div>
```

### 批量属性

```php
kf_div('content')
    ->attrs(['class' => 'box', 'id' => 'main', 'data-x' => '1'])
    ->render();
// <div class="box" id="main" data-x="1">content</div>
```

### 动态属性

任何未知方法调用都会被当作属性设置：

```php
kf_div('content')
    ->set_aria_label('Close')
    ->set_role('dialog')
    ->render();
// <div aria-label="Close" role="dialog">content</div>
```

### 局部刷新一等方法

框架内建的局部刷新能力直接做成元素方法，不需要字符串属性名：

```php
kf_button('删除')
    ->delete('/todo/5')        // hx-delete
    ->hx_target('#todo-list')  // hx-target
    ->swap('outerHTML')        // hx-swap，可带选项 ->swap('innerHTML', ['swap' => '0.5s'])
    ->confirm('确定删除？');    // hx-confirm

kf_body()->boost();            // 整站升级
kf_input()->get('/search')->trigger('keyup changed delay:300ms');
kf_div()->vals(['id' => 5]);   // 数组自动 JSON 化
kf_div()->on('::after-request', 'this.blur()');   // hx-on::after-request
```

- 值为 `true` 渲染为裸属性（`hx-boost`），`false`/`null` 不输出该属性，可直接写条件表达式。
- `->target()` 仍是 HTML 的 `<a target>`，换入目标用 `->hx_target()`。
- 任意其他 `hx-*` 用通用入口 `->hx('prompt', '输入新名字')` 或 `->hx([...])` 批量。

完整方法与属性对照见 [局部刷新](fragments.md)。

## 嵌套

子元素通过参数传递，支持无限嵌套：

```php
echo kf_div(
    kf_h1('Title'),
    kf_p('Paragraph 1'),
    kf_p('Paragraph 2'),
    kf_ul(
        kf_li('Item 1'),
        kf_li('Item 2'),
        kf_li('Item 3')
    )
)->class('container')->render();
```

数组会被自动展开：

```php
$items = [kf_li('A'), kf_li('B'), kf_li('C')];
echo kf_ul($items)->render();
```

## 视图文件

### 加载视图

```php
kf_view('home', ['title' => 'Hello', 'users' => $users]);
```

约定：加载 `views/home.php`，`$data` 数组被 `extract` 为变量。

`kf_view()` 内建局部刷新判定：片段请求（`kf_is_fragment()`）只渲染视图本体并丢弃布局，
其余情况渲染整页。片段指向别的视图时传第三个参数：

```php
kf_view('todo', ['items' => $items], 'todo_list');   // 片段请求 → views/todo_list.php
```

详见 [局部刷新](fragments.md)。

### 布局继承

在视图中设置布局：

```php
// views/home.php
<?php
kf_layout('layout');
echo kf_div(kf_h1($title))->render();
```

在布局中输出内容：

```php
// views/layout.php
<!DOCTYPE html>
<html>
<head><title><?= $title ?></title></head>
<body>
    <?= kf_content() ?>
</body>
</html>
```

### 局部模板

```php
// 在视图中加载局部模板
kf_partial('sidebar', ['menus' => $menus]);
```

### 共享变量

所有视图共享的变量：

```php
// 在路由或控制器中
kf_share('site_name', 'My App');
kf_share('user', $currentUser);

// 在任何视图中直接使用 $site_name 和 $user
```

## 组件系统

### 注册组件

```php
kf_component('card', function($props) {
    ['title' => $title, 'body' => $body] = $props;
    return kf_div(
        kf_h3($title)->class('card-title'),
        kf_p($body)->class('card-body')
    )->class('card');
});
```

### 使用组件

```php
echo kf_render_component('card', [
    'title' => 'Hello',
    'body'  => 'This is a card component.',
])->render();
```

### 组件嵌套

```php
kf_component('page', function($props) {
    return kf_div(
        kf_render_component('header', ['title' => $props['title']]),
        kf_render_component('content', ['body' => $props['body']]),
        kf_render_component('footer', [])
    )->class('page');
});
```

## 完整示例

```php
<?php
// views/blog/post.php
kf_layout('layout');

// 注册评论组件
kf_component('comment', function($props) {
    return kf_div(
        kf_strong($props['author']),
        kf_p($props['text'])->class('comment-text'),
        kf_small(date('Y-m-d', $props['time']))
    )->class('comment');
});

// 渲染文章
echo kf_article(
    kf_h1($post['title']),
    kf_p($post['content'])->class('post-content'),
    kf_hr(),
    kf_h3('评论'),
    ...array_map(
        fn($c) => kf_render_component('comment', $c),
        $comments
    )
)->class('post')->render();
```
