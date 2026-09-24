<?php
/*
 * image.func.php — 图片处理函数
 *
 * 支持 gif/jpg/png/bmp, 等比例缩略、裁剪、裁剪+缩略
 */

/**
 * 等比例缩略图
 *
 * @param string $srcfile  源文件路径
 * @param int    $width    目标最大宽度
 * @param int    $height   目标最大高度
 * @param string $destfile 目标文件路径
 * @return bool
 */
function image_thumb(string $srcfile, int $width, int $height, string $destfile): bool {
    [$src_w, $src_h, $type] = getimagesize($srcfile);
    if (!$src_w || !$src_h) return false;

    // 等比例计算
    $ratio_w = $width / $src_w;
    $ratio_h = $height / $src_h;
    $ratio = min($ratio_w, $ratio_h, 1);
    $dst_w = (int)($src_w * $ratio);
    $dst_h = (int)($src_h * $ratio);

    $src_img = image_create_from_file($srcfile, $type);
    if (!$src_img) return false;

    $dst_img = imagecreatetruecolor($dst_w, $dst_h);
    // 保持透明
    imagealphablending($dst_img, false);
    imagesavealpha($dst_img, true);
    $transparent = imagecolorallocatealpha($dst_img, 255, 255, 255, 127);
    imagefilledrectangle($dst_img, 0, 0, $dst_w, $dst_h, $transparent);

    imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

    $r = image_save_to_file($dst_img, $destfile, $type);
    imagedestroy($src_img);
    imagedestroy($dst_img);
    return $r;
}

/**
 * 按坐标裁剪
 *
 * @param string $srcfile  源文件
 * @param int    $dst_w    目标宽度
 * @param int    $dst_h    目标高度
 * @param int    $clip_x   裁剪起点 X
 * @param int    $clip_y   裁剪起点 Y
 * @param int    $clip_w   裁剪宽度
 * @param int    $clip_h   裁剪高度
 * @param string $destfile 目标文件
 * @return bool
 */
function image_clip(string $srcfile, int $dst_w, int $dst_h, int $clip_x, int $clip_y, int $clip_w, int $clip_h, string $destfile): bool {
    [$src_w, $src_h, $type] = getimagesize($srcfile);
    if (!$src_w || !$src_h) return false;

    $src_img = image_create_from_file($srcfile, $type);
    if (!$src_img) return false;

    $dst_img = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($dst_img, false);
    imagesavealpha($dst_img, true);
    $transparent = imagecolorallocatealpha($dst_img, 255, 255, 255, 127);
    imagefilledrectangle($dst_img, 0, 0, $dst_w, $dst_h, $transparent);

    imagecopyresampled($dst_img, $src_img, 0, 0, $clip_x, $clip_y, $dst_w, $dst_h, $clip_w, $clip_h);

    $r = image_save_to_file($dst_img, $destfile, $type);
    imagedestroy($src_img);
    imagedestroy($dst_img);
    return $r;
}

/**
 * 先裁剪后缩略
 */
function image_clip_thumb(string $srcfile, int $dst_w, int $dst_h, int $clip_x, int $clip_y, int $clip_w, int $clip_h, string $destfile): bool {
    [$src_w, $src_h, $type] = getimagesize($srcfile);
    if (!$src_w || !$src_h) return false;

    $src_img = image_create_from_file($srcfile, $type);
    if (!$src_img) return false;

    $dst_img = imagecreatetruecolor($dst_w, $dst_h);
    imagealphablending($dst_img, false);
    imagesavealpha($dst_img, true);
    $transparent = imagecolorallocatealpha($dst_img, 255, 255, 255, 127);
    imagefilledrectangle($dst_img, 0, 0, $dst_w, $dst_h, $transparent);

    imagecopyresampled($dst_img, $src_img, 0, 0, $clip_x, $clip_y, $dst_w, $dst_h, $clip_w, $clip_h);

    $r = image_save_to_file($dst_img, $destfile, $type);
    imagedestroy($src_img);
    imagedestroy($dst_img);
    return $r;
}

/**
 * 安全缩略图: 按 ID 生成三级目录
 *
 * @param string $srcfile  源文件
 * @param int    $id       文件 ID
 * @param int    $width    目标宽度
 * @param int    $height   目标高度
 * @param string $path     基础路径
 * @param string $ext      扩展名
 * @return string|false    返回目标文件路径, 失败返回 false
 */
function image_safe_thumb(string $srcfile, int $id, int $width, int $height, string $path, string $ext = 'jpg'): string|false {
    $dir = xn_set_dir($id, $path);
    if (!$dir) return false;
    $destfile = $dir . $id . '.' . $ext;
    $r = image_thumb($srcfile, $width, $height, $destfile);
    return $r ? $destfile : false;
}

// ---------- 内部辅助 ----------

function image_create_from_file(string $file, int $type) {
    return match ($type) {
        IMAGETYPE_GIF       => @imagecreatefromgif($file),
        IMAGETYPE_JPEG      => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG       => @imagecreatefrompng($file),
        IMAGETYPE_BMP       => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($file) : false,
        IMAGETYPE_WEBP      => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        default             => false,
    };
}

function image_save_to_file($img, string $file, int $type): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return match ($type) {
        IMAGETYPE_GIF       => imagegif($img, $file),
        IMAGETYPE_JPEG      => imagejpeg($img, $file, 85),
        IMAGETYPE_PNG       => imagepng($img, $file, 6),
        IMAGETYPE_BMP       => function_exists('imagebmp') ? imagebmp($img, $file) : imagejpeg($img, $file, 85),
        IMAGETYPE_WEBP      => function_exists('imagewebp') ? imagewebp($img, $file, 85) : imagejpeg($img, $file, 85),
        default             => imagejpeg($img, $file, 85),
    };
}
