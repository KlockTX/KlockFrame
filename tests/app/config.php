<?php
/**
 * KlockFrame 2.0 测试夹具配置
 */
return [
    // 日志指向临时目录：kf_log 默认按 cwd 落 .php，别污染夹具目录
    'log_path' => sys_get_temp_dir() . '/kf_fixture_log/',
    'app' => [
        'name'     => 'KfFixture',
        'base_url' => '',
        'env'      => 'development',
        'htmx'     => [
            'src'    => 'route',
            'config' => ['defaultSwapStyle' => 'outerHTML', 'logErrors' => true],
        ],
    ],
];
