<?php
/*
 * xn_zip.func.php — 压缩/解压函数
 *
 * 优先使用 ZipArchive, 解压后自动去一层包目录
 */

/**
 * 压缩文件
 *
 * @param array  $files    文件列表 ['path/to/file' => 'name_in_zip', ...] 或 ['file1', 'file2']
 * @param string $destfile 目标 zip 文件
 * @return bool
 */
function xn_zip(array $files, string $destfile): bool {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($destfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        foreach ($files as $k => $v) {
            if (is_int($k)) {
                // ['file1', 'file2'] 格式
                $path = $v;
                $name = basename($v);
            } else {
                // ['path' => 'name'] 格式
                $path = $k;
                $name = $v;
            }
            if (is_file($path)) {
                $zip->addFile($path, $name);
            } elseif (is_dir($path)) {
                $zip->addEmptyDir($name);
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    $local = $name . '/' . $iterator->getSubPathname();
                    if ($item->isDir()) {
                        $zip->addEmptyDir($local);
                    } else {
                        $zip->addFile($item->getRealPath(), $local);
                    }
                }
            }
        }
        return $zip->close();
    }
    // 无 ZipArchive, 尝试系统命令
    if (function_exists('exec')) {
        $list = '';
        foreach ($files as $k => $v) {
            $path = is_int($k) ? $v : $k;
            $list .= ' ' . escapeshellarg($path);
        }
        $cmd = 'zip -r ' . escapeshellarg($destfile) . $list;
        exec($cmd, $output, $ret);
        return $ret === 0;
    }
    return false;
}

/**
 * 解压文件
 * 自动去一层包目录 (如果 zip 内只有一个目录)
 *
 * @param string $srcfile  源 zip 文件
 * @param string $destdir  目标目录
 * @return bool
 */
function xn_unzip(string $srcfile, string $destdir): bool {
    if (!is_dir($destdir)) {
        @mkdir($destdir, 0755, true);
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($srcfile) !== true) {
            return false;
        }
        // 检查是否只有一层目录
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string)$zip->getNameIndex($i);
        }
        // 解压前逐条校验：zip 条目名完全由打包方控制，../../x 会让 extractTo 写出目标目录之外
        foreach ($entries as $entry) {
            if (!xn_zip_entry_is_safe($entry, $destdir)) {
                $zip->close();
                error_log('[xn_unzip] rejected unsafe zip entry: ' . $entry . ' in ' . $srcfile);
                return false;
            }
        }
        $topdir = xn_zip_top_dir($entries);
        $zip->extractTo($destdir);
        $zip->close();
        // 如果有顶层目录, 把内容提上来
        if ($topdir) {
            $srcdir = rtrim($destdir, '/\\') . DIRECTORY_SEPARATOR . $topdir;
            if (is_dir($srcdir)) {
                $items = scandir($srcdir);
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $from = $srcdir . DIRECTORY_SEPARATOR . $item;
                    $to = rtrim($destdir, '/\\') . DIRECTORY_SEPARATOR . $item;
                    @rename($from, $to);
                }
                @rmdir($srcdir);
            }
        }
        return true;
    }
    // 系统命令回退
    if (function_exists('exec')) {
        $cmd = 'unzip -o ' . escapeshellarg($srcfile) . ' -d ' . escapeshellarg($destdir);
        exec($cmd, $output, $ret);
        return $ret === 0;
    }
    return false;
}

/**
 * zip 条目名安全校验：拒绝绝对路径、NUL、反斜杠分隔、Windows 盘符与任何 ".." 段，
 * 并确认解析后的落点仍位于 $destdir 内
 */
function xn_zip_entry_is_safe(string $entry, string $destdir): bool {
    if ($entry === '' || str_contains($entry, "\0")) return false;
    // 反斜杠在 Windows 下等同路径分隔符，条目规范只允许 '/'
    if (str_contains($entry, '\\')) return false;
    if ($entry[0] === '/') return false;
    if (preg_match('#^[A-Za-z]:#', $entry)) return false;
    foreach (explode('/', $entry) as $segment) {
        if ($segment === '..') return false;
    }

    $base = realpath($destdir);
    if ($base === false) return false;

    // 条目通常尚未落盘，逐级上溯到最近存在的祖先再做包含判断
    $target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
    $probe = $target;
    while (!file_exists($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) return false;
        $probe = $parent;
    }
    $real = realpath($probe);
    if ($real === false) return false;

    return $real === $base || str_starts_with($real, $base . DIRECTORY_SEPARATOR);
}

/**
 * 分析 zip 条目, 返回顶层目录名 (如果只有一个)
 */
function xn_zip_top_dir(array $entries): string {
    $dirs = [];
    foreach ($entries as $entry) {
        $parts = explode('/', $entry);
        if (count($parts) > 1) {
            $dirs[$parts[0]] = 1;
        }
    }
    // 如果所有文件都在同一个顶层目录下
    if (count($dirs) === 1) {
        return array_key_first($dirs);
    }
    return '';
}
