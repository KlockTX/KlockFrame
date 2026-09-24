<?php
/*
 * view.func.php — PurePHP 风格组件化模板引擎
 *
 * 设计理念:
 * - HTML 标签即 PHP 函数: kf_div('content')->class('box')
 * - 链式属性设置: ->class(), ->id(), ->href(), ->data()
 * - 组件化: kf_component() + kf_render_component()
 * - 安全: 文本默认 htmlspecialchars 转义, kf_raw() 显式跳过
 * - 布局继承: kf_layout() + kf_content()
 *
 * 示例:
 *   echo kf_div(
 *       kf_h1('Hello World'),
 *       kf_p('Welcome to KlockFrame')
 *   )->class('container')->render();
 *
 * 性能优化 (1.1):
 * - void 标签使用关联数组哈希查找 (O(1)) 替代 in_array (O(n))
 * - 视图/布局文件路径用 static 缓存，避免每次 file_exists 系统调用
 * - 属性拼接优化为单次循环 + implode
 * - 字符串强转使用 (string) cast 替代 strval()
 *
 * 1.2 改进:
 * - 提取 _kf_extract_view_data() 内部函数，消除 view/partial 重复代码
 * - 修复变量遮蔽：extract 前保护 $file/$name 等内部变量
 * - 视图文件名/路径变量重命名为 $__kf_* 前缀，避免被 extract 覆盖
 *
 * 2.0 变更:
 * - kf_view() 内建局部刷新：片段请求自动只渲染视图本体，无需换函数
 * - 局部刷新成为元素的一等方法：->get() ->post() ->delete() ->swap() ->trigger() …
 * - 片段渲染 kf_capture()/kf_fragment()/kf_oob()（原 htmx.func.php 的对应能力）
 * - 运行时由框架路由直出，kf_runtime() 一行装载（见文件末尾「交互运行时」一节）
 */

// ========== KF_Raw: 原始 HTML ==========

class KF_Raw {
    public string $html;
    public function __construct(string $html) {
        $this->html = $html;
    }
    public function __toString(): string {
        return $this->html;
    }
}

function kf_raw(string $html): KF_Raw {
    return new KF_Raw($html);
}

// ========== KF_Element: HTML 元素 ==========

class KF_Element {

    private string $tag;
    private array $children = [];
    private array $attrs = [];
    private bool $fragment = false;

    // HTML void 标签（自闭合）— 关联数组以 O(1) 查找
    private static array $voidTags = [
        'img'     => 1, 'br'    => 1, 'hr'      => 1, 'input' => 1,
        'meta'    => 1, 'link'  => 1, 'area'    => 1, 'base'  => 1,
        'col'     => 1, 'embed' => 1, 'source'  => 1, 'track' => 1,
        'wbr'     => 1,
    ];

    public function __construct(string $tag, ...$children) {
        $this->tag = $tag;
        $this->children = $children;
    }

    /**
     * 创建无包装元素的 HTML 片段，用于 head 等需要直接插入父元素的组件。
     */
    public static function fragment(...$children): self {
        $element = new self('', ...$children);
        $element->fragment = true;
        return $element;
    }

    // ---------- 链式属性方法 ----------

    public function class(string $v): self {
        $this->attrs['class'] = $v;
        return $this;
    }

    public function id(string $v): self {
        $this->attrs['id'] = $v;
        return $this;
    }

    public function style(string $v): self {
        $this->attrs['style'] = $v;
        return $this;
    }

    public function href(string $v): self {
        $this->attrs['href'] = $v;
        return $this;
    }

    public function src(string $v): self {
        $this->attrs['src'] = $v;
        return $this;
    }

    public function alt(string $v): self {
        $this->attrs['alt'] = $v;
        return $this;
    }

    public function title(string $v): self {
        $this->attrs['title'] = $v;
        return $this;
    }

    public function name(string $v): self {
        $this->attrs['name'] = $v;
        return $this;
    }

    public function value(string $v): self {
        $this->attrs['value'] = $v;
        return $this;
    }

    public function type(string $v): self {
        $this->attrs['type'] = $v;
        return $this;
    }

    public function placeholder(string $v): self {
        $this->attrs['placeholder'] = $v;
        return $this;
    }

    public function action(string $v): self {
        $this->attrs['action'] = $v;
        return $this;
    }

    public function method(string $v): self {
        $this->attrs['method'] = $v;
        return $this;
    }

    public function target(string $v): self {
        $this->attrs['target'] = $v;
        return $this;
    }

    public function rel(string $v): self {
        $this->attrs['rel'] = $v;
        return $this;
    }

    public function width(string $v): self {
        $this->attrs['width'] = $v;
        return $this;
    }

    public function height(string $v): self {
        $this->attrs['height'] = $v;
        return $this;
    }

    public function colspan(string $v): self {
        $this->attrs['colspan'] = $v;
        return $this;
    }

    public function rowspan(string $v): self {
        $this->attrs['rowspan'] = $v;
        return $this;
    }

    /**
     * 通用属性设置
     */
    public function attr(string $name, string $v): self {
        $this->attrs[$name] = $v;
        return $this;
    }

    /**
     * data-* 属性: data('id', '123') → data-id="123"
     */
    public function data(string $name, string $v): self {
        $this->attrs['data-' . $name] = $v;
        return $this;
    }

    /**
     * 批量设置属性
     */
    public function attrs(array $attrs): self {
        $this->attrs = array_merge($this->attrs, $attrs);
        return $this;
    }

    /**
     * 局部刷新属性（通用入口）
     *
     * hx('get', '/list') → hx-get="/list"
     * hx(['get' => '/x', 'swap' => 'outerHTML']) → 批量
     * 值为数组时按 JSON 序列化（hx-vals / hx-headers）；
     * true 渲染为裸属性（hx-boost），false/null 时不输出该属性。
     */
    public function hx(string|array $prop, $value = null): self {
        if (is_array($prop)) {
            foreach ($prop as $__kf_k => $__kf_v) {
                $this->hx_attr((string)$__kf_k, $__kf_v);
            }
            return $this;
        }
        return $this->hx_attr($prop, $value);
    }

    /**
     * 写入单个 hx-* 属性（内部辅助）
     */
    private function hx_attr(string $name, $value): self {
        if ($value === false || $value === null) {
            return $this;
        }
        $key = strncmp($name, 'hx-', 3) === 0 ? $name : 'hx-' . str_replace('_', '-', $name);
        $this->attrs[$key] = $value === true ? '' : (is_array($value) ? kf_json_attr($value) : (string)$value);
        return $this;
    }

    // ---------- 局部刷新一等方法 ----------
    //
    // 请求动词直接写在元素上，不借字符串属性名：
    //   kf_button('加载')->get('/todo')->into('#list')

    public function get($url): self    { return $this->hx_attr('get', $url); }
    public function post($url): self   { return $this->hx_attr('post', $url); }
    public function put($url): self    { return $this->hx_attr('put', $url); }
    public function patch($url): self  { return $this->hx_attr('patch', $url); }
    public function delete($url): self { return $this->hx_attr('delete', $url); }

    /** 换入目标元素（HTML 的 target 属性已被链接占用，故保留 hx- 前缀） */
    public function hx_target(string $selector): self { return $this->hx_attr('target', $selector); }

    /** 换入策略，可带选项：swap('outerHTML', ['swap' => '0.5s', 'transition' => true]) */
    public function swap(string $style, array $options = []): self {
        foreach ($options as $k => $v) {
            $style .= ' ' . $k . ':' . (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        }
        return $this->hx_attr('swap', $style);
    }

    /** Out-of-Band：本次响应顺带换到别处 */
    public function oob(string $selector = '', string $strategy = ''): self {
        return $this->hx_attr('swap-oob', $strategy === '' ? ($selector === '' ? 'true' : $selector)
            : ($selector === '' ? $strategy : $strategy . ':' . $selector));
    }

    /** 触发方式：trigger('click')、trigger('keyup changed delay:300ms')、trigger('revealed') */
    public function trigger(string $v): self      { return $this->hx_attr('trigger', $v); }

    /** 整站升级：把本页链接与表单提升为局部刷新 */
    public function boost(bool $v = true): self   { return $this->hx_attr('boost', $v); }

    /** 提交前确认 */
    public function confirm(string $v): self      { return $this->hx_attr('confirm', $v); }

    /** 提交前输入框 */
    public function prompt(string $v): self       { return $this->hx_attr('prompt', $v); }

    /** 附加工具参数（数组自动 JSON 化） */
    public function vals(array $v): self          { return $this->hx_attr('vals', $v); }

    /** 附加请求头（数组自动 JSON 化） */
    public function headers(array $v): self       { return $this->hx_attr('headers', $v); }

    /** 只发送部分参数 */
    public function params($v): self              { return $this->hx_attr('params', is_array($v) ? $v : (string)$v); }

    /** 从响应里抽取指定元素 */
    public function select(string $v): self       { return $this->hx_attr('select', $v); }

    /** 请求中显示的元素 */
    public function indicator(string $v): self    { return $this->hx_attr('indicator', $v); }

    /** 换入时保留该元素的现状 */
    public function preserve(bool $v = true): self { return $this->hx_attr('preserve', $v); }

    /** 同元素并发请求策略 */
    public function sync(string $v): self         { return $this->hx_attr('sync', $v); }

    /** 同步地址栏 URL（false 表示不写历史） */
    public function push_url($v): self            { return $this->hx_attr('push-url', $v === false ? 'false' : (string)$v); }

    /** 监听运行时事件：on('::after-request', 'this.blur()') */
    public function on(string $event, string $code): self {
        return $this->hx_attr('on' . (str_starts_with($event, ':') ? '' : ':') . $event, $code);
    }


    /**
     * 动态方法调用: set_data_id('x') → data-id="x"
     *               set_aria_label('x') → aria-label="x"
     */
    public function __call(string $name, array $args): self {
        if (str_starts_with($name, 'set_')) {
            $attr = substr($name, 4);
            $attr = str_replace('_', '-', $attr);
            $this->attrs[$attr] = $args[0] ?? '';
            return $this;
        }
        // 未知方法当作属性设置
        $attr = str_replace('_', '-', $name);
        $this->attrs[$attr] = $args[0] ?? '';
        return $this;
    }

    // ---------- 渲染 ----------

    /**
     * 转为 HTML 字符串
     */
    public function toString(): string {
        $attrs = '';
        foreach ($this->attrs as $k => $v) {
            // 属性名此前原样拼进标签：键名里的引号或空格可提前闭合属性列表并注入额外属性，
            // 故只接受符合 HTML 命名规则的键（data-* / aria-* / xlink:href 等均通过）
            if (!preg_match('#^[a-zA-Z_:][-a-zA-Z0-9_:.]*$#', (string)$k)) continue;
            if ($v === true || $v === '') {
                $attrs .= ' ' . $k;
            } else {
                $attrs .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
            }
        }

        $tag = $this->tag;
        // void 标签自闭合（哈希查找 O(1)）
        if (isset(self::$voidTags[$tag])) {
            return '<' . $tag . $attrs . '>';
        }

        $content = '';
        foreach ($this->children as $child) {
            $content .= self::renderChild($child);
        }

        if ($this->fragment) {
            return $content;
        }
        return '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
    }

    /**
     * 渲染子元素
     */
    private static function renderChild($child): string {
        if ($child === null || $child === false) {
            return '';
        }
        if ($child instanceof self) {
            return $child->toString();
        }
        if ($child instanceof KF_Raw) {
            return $child->html;
        }
        if (is_array($child)) {
            $s = '';
            foreach ($child as $c) {
                $s .= self::renderChild($c);
            }
            return $s;
        }
        if (is_string($child)) {
            return htmlspecialchars($child, ENT_QUOTES);
        }
        return htmlspecialchars((string)$child, ENT_QUOTES);
    }

    /**
     * 直接输出
     */
    public function render(): void {
        echo $this->toString();
    }

    /**
     * 魔术方法: echo $element
     */
    public function __toString(): string {
        return $this->toString();
    }
}

// ========== HTML 标签函数 ==========

// --- 容器 ---
function kf_div(...$c): KF_Element { return new KF_Element('div', ...$c); }
function kf_span(...$c): KF_Element { return new KF_Element('span', ...$c); }
function kf_section(...$c): KF_Element { return new KF_Element('section', ...$c); }
function kf_article(...$c): KF_Element { return new KF_Element('article', ...$c); }
function kf_header(...$c): KF_Element { return new KF_Element('header', ...$c); }
function kf_footer(...$c): KF_Element { return new KF_Element('footer', ...$c); }
function kf_nav(...$c): KF_Element { return new KF_Element('nav', ...$c); }
function kf_main(...$c): KF_Element { return new KF_Element('main', ...$c); }
function kf_aside(...$c): KF_Element { return new KF_Element('aside', ...$c); }
function kf_figure(...$c): KF_Element { return new KF_Element('figure', ...$c); }
function kf_figcaption(...$c): KF_Element { return new KF_Element('figcaption', ...$c); }
function kf_details(...$c): KF_Element { return new KF_Element('details', ...$c); }
function kf_summary(...$c): KF_Element { return new KF_Element('summary', ...$c); }

// --- 标题 ---
function kf_h1(...$c): KF_Element { return new KF_Element('h1', ...$c); }
function kf_h2(...$c): KF_Element { return new KF_Element('h2', ...$c); }
function kf_h3(...$c): KF_Element { return new KF_Element('h3', ...$c); }
function kf_h4(...$c): KF_Element { return new KF_Element('h4', ...$c); }
function kf_h5(...$c): KF_Element { return new KF_Element('h5', ...$c); }
function kf_h6(...$c): KF_Element { return new KF_Element('h6', ...$c); }

// --- 文本 ---
function kf_p(...$c): KF_Element { return new KF_Element('p', ...$c); }
function kf_a(...$c): KF_Element { return new KF_Element('a', ...$c); }
function kf_strong(...$c): KF_Element { return new KF_Element('strong', ...$c); }
function kf_em(...$c): KF_Element { return new KF_Element('em', ...$c); }
function kf_b(...$c): KF_Element { return new KF_Element('b', ...$c); }
function kf_i(...$c): KF_Element { return new KF_Element('i', ...$c); }
function kf_u(...$c): KF_Element { return new KF_Element('u', ...$c); }
function kf_s(...$c): KF_Element { return new KF_Element('s', ...$c); }
function kf_small(...$c): KF_Element { return new KF_Element('small', ...$c); }
function kf_mark(...$c): KF_Element { return new KF_Element('mark', ...$c); }
function kf_code(...$c): KF_Element { return new KF_Element('code', ...$c); }
function kf_pre(...$c): KF_Element { return new KF_Element('pre', ...$c); }
function kf_blockquote(...$c): KF_Element { return new KF_Element('blockquote', ...$c); }
function kf_q(...$c): KF_Element { return new KF_Element('q', ...$c); }
function kf_abbr(...$c): KF_Element { return new KF_Element('abbr', ...$c); }
function kf_cite(...$c): KF_Element { return new KF_Element('cite', ...$c); }
function kf_br(): KF_Element { return new KF_Element('br'); }
function kf_hr(): KF_Element { return new KF_Element('hr'); }
function kf_wbr(): KF_Element { return new KF_Element('wbr'); }

// --- 列表 ---
function kf_ul(...$c): KF_Element { return new KF_Element('ul', ...$c); }
function kf_ol(...$c): KF_Element { return new KF_Element('ol', ...$c); }
function kf_li(...$c): KF_Element { return new KF_Element('li', ...$c); }
function kf_dl(...$c): KF_Element { return new KF_Element('dl', ...$c); }
function kf_dt(...$c): KF_Element { return new KF_Element('dt', ...$c); }
function kf_dd(...$c): KF_Element { return new KF_Element('dd', ...$c); }

// --- 表单 ---
function kf_form(...$c): KF_Element { return new KF_Element('form', ...$c); }
function kf_input(): KF_Element { return new KF_Element('input'); }
function kf_textarea(...$c): KF_Element { return new KF_Element('textarea', ...$c); }
function kf_button(...$c): KF_Element { return new KF_Element('button', ...$c); }
function kf_select(...$c): KF_Element { return new KF_Element('select', ...$c); }
function kf_option(...$c): KF_Element { return new KF_Element('option', ...$c); }
function kf_optgroup(...$c): KF_Element { return new KF_Element('optgroup', ...$c); }
function kf_label(...$c): KF_Element { return new KF_Element('label', ...$c); }
function kf_fieldset(...$c): KF_Element { return new KF_Element('fieldset', ...$c); }
function kf_legend(...$c): KF_Element { return new KF_Element('legend', ...$c); }

// --- 表格 ---
function kf_table(...$c): KF_Element { return new KF_Element('table', ...$c); }
function kf_thead(...$c): KF_Element { return new KF_Element('thead', ...$c); }
function kf_tbody(...$c): KF_Element { return new KF_Element('tbody', ...$c); }
function kf_tfoot(...$c): KF_Element { return new KF_Element('tfoot', ...$c); }
function kf_tr(...$c): KF_Element { return new KF_Element('tr', ...$c); }
function kf_th(...$c): KF_Element { return new KF_Element('th', ...$c); }
function kf_td(...$c): KF_Element { return new KF_Element('td', ...$c); }
function kf_caption(...$c): KF_Element { return new KF_Element('caption', ...$c); }

// --- 媒体 ---
function kf_img(): KF_Element { return new KF_Element('img'); }
function kf_video(...$c): KF_Element { return new KF_Element('video', ...$c); }
function kf_audio(...$c): KF_Element { return new KF_Element('audio', ...$c); }
function kf_source(): KF_Element { return new KF_Element('source'); }
function kf_picture(...$c): KF_Element { return new KF_Element('picture', ...$c); }
function kf_iframe(...$c): KF_Element { return new KF_Element('iframe', ...$c); }

// --- 文档结构 ---
function kf_html(...$c): KF_Element { return new KF_Element('html', ...$c); }
function kf_head(...$c): KF_Element { return new KF_Element('head', ...$c); }
function kf_body(...$c): KF_Element { return new KF_Element('body', ...$c); }
function kf_title_tag(string $t): KF_Element { return new KF_Element('title', $t); }
function kf_meta(): KF_Element { return new KF_Element('meta'); }
function kf_link(): KF_Element { return new KF_Element('link'); }
function kf_script(...$c): KF_Element { return new KF_Element('script', ...$c); }
function kf_style_tag(...$c): KF_Element { return new KF_Element('style', ...$c); }
function kf_base(): KF_Element { return new KF_Element('base'); }

// --- 语义 ---
function kf_time(...$c): KF_Element { return new KF_Element('time', ...$c); }
function kf_address(...$c): KF_Element { return new KF_Element('address', ...$c); }

// ========== 视图文件加载 ==========

$GLOBALS['_kf_layout'] = null;
$GLOBALS['_kf_shared'] = [];
$GLOBALS['_kf_content'] = '';

/**
 * 视图文件路径解析（带 static 缓存）
 * 避免每次渲染都触发 file_exists 系统调用
 */
function kf_view_file(string $name): ?string {
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    $file = APP_PATH . 'views/' . $name . '.php';
    $cache[$name] = is_file($file) ? $file : null;
    return $cache[$name];
}

/**
 * 合并共享变量 + extract 到当前作用域（内部辅助）
 *
 * 1.2 新增：消除 kf_view / kf_partial 中的重复逻辑。
 * 使用 EXTR_SKIP 避免覆盖已有局部变量；调用方应使用 $__kf_* 前缀
 * 命名内部变量，防止被 extract 的同名 key 覆盖。
 *
 * @param array $data 调用方传入的数据
 * @return array 合并后的数据（供调用方 extract）
 */
function _kf_merge_view_data(array $data): array {
    $shared = $GLOBALS['_kf_shared'];
    return $shared ? array_merge($shared, $data) : $data;
}

/**
 * 加载视图（整页与片段自动判定）
 *
 * 约定: APP_PATH . 'views/' . $name . '.php'
 * 视图文件中可以使用 kf_ 标签函数和提取出的 $key 变量。
 *
 * 局部刷新请求（kf_is_fragment()）只渲染视图本体、丢弃布局；
 * 若片段应指向另一个视图，传 $fragment。同一个视图因此天然两用。
 *
 * @param string      $name          视图名（如 'home', 'user/profile'）
 * @param array       $data          传递给视图的数据
 * @param string|null $fragment      片段请求时渲染的视图名，默认与 $name 相同
 * @param array       $fragment_data 片段专用数据（覆盖 $data 同名键）
 */
function kf_view(string $name, array $data = [], ?string $fragment = null, array $fragment_data = []): void {
    // 使用 $__kf_* 前缀防止被 extract 覆盖（变量遮蔽修复）
    $__kf_file = kf_view_file($name);
    if ($__kf_file === null) {
        kf_abort(500, "View not found: $name");
    }

    if (kf_is_fragment()) {
        echo kf_capture($fragment ?? $name, $fragment_data ? array_merge($data, $fragment_data) : $data);
        return;
    }

    // 合并共享变量并提取（EXTR_SKIP 不覆盖 $__kf_* 局部变量）
    $__kf_data = _kf_merge_view_data($data);
    if ($__kf_data) {
        extract($__kf_data, EXTR_SKIP);
    }

    // 始终捕获视图内容（视图内可能通过 kf_layout() 设置布局）
    ob_start();
    include $__kf_file;
    $__kf_content = ob_get_clean();

    // 视图加载后检查是否设置了布局
    if ($GLOBALS['_kf_layout'] !== null) {
        $GLOBALS['_kf_content'] = $__kf_content;
        $__kf_layout_name = $GLOBALS['_kf_layout'];
        $GLOBALS['_kf_layout'] = null;
        $__kf_layout_file = kf_view_file($__kf_layout_name);
        if ($__kf_layout_file !== null) {
            include $__kf_layout_file;
        }
    } else {
        echo $__kf_content;
    }
}

/**
 * 设置布局
 *
 * @param string $name 布局名称
 */
function kf_layout(string $name): void {
    $GLOBALS['_kf_layout'] = $name;
}

/**
 * 获取视图内容（在布局中使用）
 *
 * @return string
 */
function kf_content(): string {
    return $GLOBALS['_kf_content'] ?? '';
}

/**
 * 加载局部模板
 *
 * @param string $name 模板名称
 * @param array  $data 数据
 */
function kf_partial(string $name, array $data = []): void {
    $__kf_file = kf_view_file($name);
    if ($__kf_file === null) return;
    $__kf_data = _kf_merge_view_data($data);
    if ($__kf_data) {
        extract($__kf_data, EXTR_SKIP);
    }
    include $__kf_file;
}

// ========== 片段渲染（局部刷新） ==========

/**
 * 渲染视图片段并返回 HTML（跳过布局）
 *
 * 视图内部照常可以调用 kf_layout()，片段模式下该设置被丢弃，
 * 因此同一个视图文件既能整页渲染也能片段渲染。
 *
 * @param string $name 视图名
 * @param array  $data 数据
 */
function kf_capture(string $name, array $data = []): string {
    $__kf_file = kf_view_file($name);
    if ($__kf_file === null) {
        kf_abort(500, "View not found: $name");
        return '';
    }

    // 保护外层待处理的布局设置（片段可嵌套渲染）
    $__kf_pending_layout = $GLOBALS['_kf_layout'];
    $__kf_level = ob_get_level();
    $GLOBALS['_kf_layout'] = null;

    try {
        $__kf_data = _kf_merge_view_data($data);
        ob_start();
        if ($__kf_data) {
            extract($__kf_data, EXTR_SKIP);
        }
        include $__kf_file;
        $__kf_html = (string)ob_get_clean();
    } catch (Throwable $e) {
        // 只回收本次开启的缓冲，避免片段内容泄漏到外层输出
        while (ob_get_level() > $__kf_level) {
            if (!@ob_end_clean()) break;
        }
        $GLOBALS['_kf_layout'] = $__kf_pending_layout;
        throw $e;
    }

    $GLOBALS['_kf_layout'] = $__kf_pending_layout;
    return trim($__kf_html);
}

/**
 * 输出视图片段
 */
function kf_fragment(string $name, array $data = []): void {
    echo kf_capture($name, $data);
}

/**
 * 在片段根元素上标记换出带外目标（内部辅助）
 *
 * 片段由 KF_Element 渲染，属性值里的 > 已被转义为 &gt;，
 * 因此标签名后首个裸 > 必定是开始标签的结束符，可安全插入属性。
 */
function _kf_mark_oob(string $html, string $value): string {
    if (!preg_match('/^\s*<[a-zA-Z][^\s\/>]*/', $html, $__kf_m, PREG_OFFSET_CAPTURE)) {
        kf_log('[kf_oob] 片段根元素无法定位，换入标记未注入: ' . substr($html, 0, 60), 'view');
        return $html;
    }
    $__kf_at = $__kf_m[0][1] + strlen($__kf_m[0][0]);
    return substr($html, 0, $__kf_at)
        . ' hx-swap-oob="' . htmlspecialchars($value, ENT_QUOTES) . '"'
        . substr($html, $__kf_at);
}

/**
 * 生成 Out-of-Band 片段：换入当前请求目标之外的元素
 *
 * 典型用法（一次请求同时更新列表行和计数）：
 *   echo kf_capture('order_row', ['o' => $o]);
 *   echo kf_oob('order_count', '#order-count', ['n' => $n], 'outerHTML');
 *
 * @param string $name     视图名
 * @param string $selector 目标 CSS 选择器（如 '#list'），留空表示按根元素 id 匹配
 * @param array  $data     数据
 * @param string $strategy 换入策略，留空则用运行时默认（outerHTML）
 */
function kf_oob(string $name, string $selector = '', array $data = [], string $strategy = ''): string {
    $value = $selector === '' ? 'true' : ($strategy === '' ? $selector : $strategy . ':' . $selector);
    return _kf_mark_oob(kf_capture($name, $data), $value);
}

/**
 * 共享变量（所有视图可用）
 *
 * @param string $key
 * @param mixed  $value
 */
function kf_share(string $key, $value): void {
    $GLOBALS['_kf_shared'][$key] = $value;
}

// ========== 组件系统 ==========

$GLOBALS['_kf_components'] = [];

/**
 * 注册组件
 *
 * @param string   $name    组件名
 * @param callable $handler 组件处理器，接收 $props 数组，返回 KF_Element
 */
function kf_component(string $name, callable $handler): void {
    $GLOBALS['_kf_components'][$name] = $handler;
}

/**
 * 渲染组件
 *
 * @param string $name  组件名
 * @param array  $props 属性数组
 * @return KF_Element
 */
function kf_render_component(string $name, array $props = []): KF_Element {
    if (!isset($GLOBALS['_kf_components'][$name])) {
        // 页面输出保持原行为（渲染占位错误块），但服务端必须留下痕迹，
        // 否则漏注册的组件只在前台显示成一行文字，线上极难定位
        $caller = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2), 1, 1)[0] ?? [];
        error_log(sprintf(
            '[kf_render_component] unregistered component "%s" called from %s:%s',
            $name, (string)($caller['file'] ?? '?'), (string)($caller['line'] ?? '?')
        ));
        return kf_div("Component not found: $name")->class('kf-error');
    }
    $result = $GLOBALS['_kf_components'][$name]($props);
    if ($result instanceof KF_Element) {
        return $result;
    }
    if (is_string($result)) {
        return kf_div(kf_raw($result));
    }
    return kf_div((string)$result);
}

// ========== 交互运行时（内置 htmx） ==========
//
// 运行时随框架分发，不再是「先发布到 assets/ 才能用」的外部资产：
// 布局里一行 kf_runtime() 即完成装载，脚本由框架内置路由直出。

/**
 * 运行时脚本 URL
 *
 * 配置 app.htmx.src：
 * - 'route'（默认）→ 框架内置路由直出，带版本号查询串以配合长缓存
 * - 'cdn'          → jsDelivr 上的同版本文件
 * - 其他字符串     → 视为 URL 原样使用
 */
function kf_runtime_url(): string {
    $src = (string)kf_config('app.htmx.src', 'route');
    if ($src === 'cdn') return KF_RUNTIME_CDN;
    if ($src !== 'route') return $src;
    return kf_url(KF_RUNTIME_ROUTE) . '?v=' . KF_RUNTIME_VERSION;
}

/**
 * 运行时脚本标签
 */
function kf_runtime_script(): KF_Element {
    $el = kf_script()->src(kf_runtime_url());
    if (kf_config('app.htmx.defer', true)) {
        // 空串值渲染为裸属性 defer
        $el->attr('defer', '');
    }
    return $el;
}

/**
 * 运行时内部配置（<meta name="htmx-config">）
 *
 * 用 meta 而不是内联脚本，是为了让脚本能 defer 加载：
 * 内联脚本无法 defer，会先于运行时执行而写不进配置对象。
 *
 * @param array $overrides 覆盖 app.htmx.config 的键值
 */
function kf_runtime_config_meta(array $overrides = []): KF_Raw {
    $config = array_merge((array)kf_config('app.htmx.config', []), $overrides);
    if (!$config) {
        return kf_raw('');
    }
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        kf_log('[kf_runtime_config_meta] 配置无法序列化为 JSON，已跳过', 'view');
        return kf_raw('');
    }
    return kf_raw('<meta name="htmx-config" content="' . htmlspecialchars($json, ENT_QUOTES) . '">');
}

/**
 * 为所有局部刷新请求自动附加 CSRF 请求头
 *
 * @return KF_Raw 脚本 HTML；app.htmx.csrf=false 时为空串
 */
function kf_runtime_csrf_script(): KF_Raw {
    if (!kf_config('app.htmx.csrf', true)) {
        return kf_raw('');
    }
    $header = (string)kf_config('app.htmx.csrf_header', 'X-CSRF-Token');
    if (!preg_match('#^[A-Za-z0-9-]+$#', $header)) {
        kf_log('[kf_runtime_csrf_script] 非法请求头名，CSRF 注入脚本已跳过: ' . $header, 'view');
        return kf_raw('');
    }
    // JSON_HEX_* 保证结果可安全嵌入 <script> 上下文
    $h = json_encode($header, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $t = json_encode(kf_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return kf_raw(
        '<script>document.addEventListener("htmx:configRequest",function(e){'
        . 'e.detail.headers[' . $h . ']=' . $t . ';});</script>'
    );
}

/**
 * 一行装载局部刷新能力（放进 <head>）
 *
 * = 运行时配置 meta + 运行时脚本 + CSRF 注入，三者先后顺序无关。
 * 不叫 kf_head()：那个名字属于 <head> 标签函数。
 *
 * @param array $config 覆盖 app.htmx.config 的键值
 */
function kf_runtime(array $config = []): KF_Element {
    return KF_Element::fragment(
        kf_runtime_config_meta($config),
        kf_runtime_script(),
        kf_runtime_csrf_script()
    );
}

/**
 * 内置路由处理器：直出交互运行时
 *
 * ETag 由版本号与预期 sha256 组成，命中 If-None-Match 时零 I/O 返回 304；
 * 需要吐字节时才读文件并校验完整性——上传中断或被改动的文件不会静默进浏览器。
 */
function kf_runtime_response(): void {
    $etag = '"' . KF_RUNTIME_VERSION . '-' . substr(KF_RUNTIME_SHA256, 0, 16) . '"';
    if (!headers_sent()) {
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
    }
    if (trim(kf_request_header('If-None-Match')) === $etag) {
        if (!headers_sent()) http_response_code(304);
        return;
    }
    if (!is_file(KF_RUNTIME_FILE)) {
        kf_log('[kf_runtime_response] 运行时文件缺失: ' . KF_RUNTIME_FILE, 'runtime');
        kf_abort(500, 'KlockFrame runtime unavailable');
    }
    if (hash_file('sha256', KF_RUNTIME_FILE) !== KF_RUNTIME_SHA256) {
        kf_log('[kf_runtime_response] 运行时 sha256 校验不通过: ' . KF_RUNTIME_FILE, 'runtime');
        kf_abort(500, 'KlockFrame runtime integrity check failed');
    }
    echo (string)file_get_contents(KF_RUNTIME_FILE);
}
