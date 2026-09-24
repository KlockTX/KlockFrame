# 参与贡献

先说结论：**改完必须能跑 `php tests/unit.php` 与 `php tests/http.php`，两套全绿再提 PR。**

## 模块地图

```
klockframe.php        内核装载链 + 版本变更总览
config.func.php       配置（dot 访问、运行时改值、缓存失效）
request.func.php      请求门面：参数 / 头 / 方法 / IP / CSRF / 校验 / 加密 / 局部刷新识别
router.func.php       路由：编译、分组、405/Allow、控制器约定
view.func.php         模板：KF_Element 与标签函数、视图/片段/OOB、组件、交互运行时装载与直出
response.func.php     响应：URL、JSON、错误页、重定向、响应控制头、Flash
util.func.php         工具门面：日志 / Hook / JSON / 出站 HTTP / 分页 / 目录 / 邮件 / zip
js/htmx.min.js        内置交互运行时（框架路由直出，不做静态发布）
xiunophp/             底层核心（第三方，MIT）
docs/                 文档
tests/                unit.php（CLI 回归）、http.php（php -S 往返）、app/（夹具应用）
```

## 硬规则

这些规则是这个框架「轻且可静态分析」的前提，违反基本会被拒：

1. **不使用 autoload** —— 直接 `include`，对 OPCache 最优
2. **不使用 `eval`** —— 安全可审计
3. **不使用 `$$var`** —— 静态可分析
4. **应用侧只出现 `kf_` 前缀** —— 新能力一律先落门面，`xn_*` / `param()` 只作底层实现；
   `db_` / `cache_` 是带连接语义的模块 API，不做二次包装
5. **显式优于隐式** —— 新属性/新动作优先做成一等方法（参考 `->delete()`、`kf_is_fragment()`），
   不要依赖 `__call` 的字符串猜测
6. **模板转义不可绕过** —— 文本与属性值一律 `htmlspecialchars`，原样输出必须显式 `kf_raw()`
7. **函数内局部变量用 `$__kf_` 前缀** —— 视图会 `extract()` 数据，防止变量遮蔽

## 命名约定

| 场景 | 约定 |
|------|------|
| 应用侧函数 | `kf_` + 名词/动词，如 `kf_param_int`、`kf_is_fragment`、`kf_response_header` |
| 内部辅助 | 前缀 `_kf_`，如 `_kf_mark_oob`、`_kf_require_module` |
| 读请求头 / 写响应头 | `kf_request_header()` / `kf_response_header()`（`kf_header`、`kf_head` 已被 `<header>`/`<head>` 标签函数占用） |
| 常量 | `KF_` + 大写，且必须 `!defined(...) AND define(...)` 允许应用覆盖 |

## 跑测试

```bash
php tests/unit.php     # CLI 单元：门面转发、片段、OOB、元素方法、响应头校验、运行时
php tests/http.php     # 起 php -S 跑 tests/app 夹具，断言状态码、控制头、304、CSRF 头通道
```

要求 PHP 8.0+，扩展 `curl`、`zip`（`tests/http.php` 用 curl 客户端，`unit.php` 的 F25/F26 用 ZipArchive）。

改了模板或交互行为，还要在浏览器里过一遍夹具：

```bash
php -S 127.0.0.1:8080 -t tests/app tests/app/router.php
# 打开 http://127.0.0.1:8080/ ，验证：片段换入、hx-boost 软导航、OOB 计数、hx-confirm、无控制台报错
```

## 兼容性纪律

- 兼容性改动：递增小版本，在 `klockframe.php` 头部写中文变更条目，历史标注保持「可直接覆盖安装」
- 破坏性改动：升主版本，必须同时提供 `docs/migration-<major>.md`（改名对照表 + 行为差异 + 自查 grep 命令）
- 任何版本变动都要同步 `CHANGELOG.md` 并打 tag（`vX.Y.Z`）
- 删函数要删干净：定义、文档表、示例、测试一处不留

## PR 检查清单

- [ ] `tests/unit.php` 与 `tests/http.php` 全绿（贴出「结果: N 通过, 0 失败」）
- [ ] 新行为有对应断言；改坏了旧行为要说明原因
- [ ] 所有文件 `php -l` 通过，且 `tests/unit.php` 的 D1/D2 零诊断
- [ ] 文档已同步（`docs/`、`README.md`、`docs/api-reference.md` 的表）
- [ ] 没有把应用侧调用引向 `xn_*` / `param()`
- [ ] 无新依赖、无 autoload、无 eval
