-------------------------- English --------------------------------

XiunoPHP 4.1 is not a framework in the sense that it does not require you to
organize your code, how to inherit the Control Base, Model Base, so it won't
be like any other frame "box" to live in you. It's just adding some of the
initial variables, and the global function. If you want to say that it is the
framework, it can be said that it is a functional framework.

Design principles:
1. No include variables
2. No eval(), no regex 'e' modifier
3. No autoload
4. No $$var
5. No magic methods __call __set __get
6. Use function packaging, distinguish modules by prefix
7. PDO prepared statements for SQL injection prevention
8. PHP 8.0+ support, typed properties, union types, match expressions

-------------------------- 中文 --------------------------------

XiunoPHP 4.1 严格意义上它不是一个框架，它并没有要求你如何组织代码，如何继承 Base Control,
Base Model，所以，它不会像其他框架一样"框"住你。
它只是在对 PHP 进行了一些增强，增加了一些初始变量，和全局函数而已。

设计原则：
	1. 不要 include 变量
	2. 不要采用 eval(), 正则表达式 e 修饰符
	3. 不要采用 autoload
	4. 不要采用 $$var 多重变量
	5. 不要使用 PHP 高级特性 __call __set __get 等魔术方法
	6. 尽量采用函数封装功能，通过前缀区分模块
	7. 使用 PDO 预处理语句防止 SQL 注入
	8. 支持 PHP 8.0+，使用类型声明、联合类型、match 表达式

4.1 相比 4.0 的主要变更：
	- 移除已废弃的 mysql_* 扩展驱动
	- 移除 magic_quotes 相关代码
	- 数据库层全面改用 PDO 预处理语句
	- 所有 SQL 辅助函数返回 [sql, params] 元组
	- 新增事务支持 db_transaction()
	- 新增 Hook 系统 xn_hook_register() / xn_hook() / xn_hook_filter()
	- 新增 JSON 响应 xn_json_response()
	- 新增 CSRF 防护 xn_csrf_token() / xn_csrf_check()
	- 新增数据验证器 xn_validate()
	- 修复 PHP 8.0 移除的 each()、var $property 等不兼容问题
	- 缓存驱动统一使用延迟连接
	- APC 驱动改用 APCu
