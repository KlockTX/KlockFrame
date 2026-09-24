<?php
/** @var string $title */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title ?? 'KfFixture', ENT_QUOTES) ?></title>
<?= kf_runtime() ?>
</head>
<body hx-boost="true">
<nav><a href="/">首页</a> · <a href="/todo">待办</a></nav>
<main id="main">
<?= kf_content() ?>
</main>
</body>
</html>
