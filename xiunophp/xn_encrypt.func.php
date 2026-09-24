<?php
/*
 * xn_encrypt.func.php — 加密/解密函数
 *
 * 优先使用 xiuno.so 扩展, 否则回退到纯 PHP XXTEA 实现
 * 结果经过 base64 + xn_urlencode 编码
 */

/**
 * 获取加密密钥
 * 优先从扩展获取, 否则从配置获取
 */
function xn_key(): string {
    if (function_exists('xiuno_key')) {
        return xiuno_key();
    }
    global $conf;
    return $conf['auth_key'] ?? '';
}

/**
 * 生成临时安全密钥 (基于时间窗口 + IP + UA)
 *
 * @param int $expire 过期秒数
 * @return string
 */
function xn_safe_key(int $expire = 3600): string {
    $key = xn_key();
    $ip = $_SERVER['ip'] ?? '';
    $ua = $_SERVER['useragent'] ?? '';
    $time_window = (int)(time() / $expire);
    return md5($key . $ip . $ua . $time_window);
}

/**
 * 加密
 *
 * 优先级：xiuno 扩展 > openssl AES-256-GCM > libsodium > XXTEA。
 * 前两者都自带认证标签且使用随机 nonce，同明文两次结果不同；
 * XXTEA 仅在完全没有密码学扩展时兜底，避免老环境直接不可用。
 */
function xn_encrypt(string $txt, ?string $key = null): string {
    $key = $key ?? xn_key();
    if ($key === '') return '';
    if (function_exists('xiuno_encrypt')) {
        $s = xiuno_encrypt($txt, $key);
    } elseif (xn_encrypt_has_aes_gcm()) {
        $iv  = random_bytes(XN_AES_GCM_IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt(
            $txt, 'aes-256-gcm', xn_encrypt_derive_key($key),
            OPENSSL_RAW_DATA, $iv, $tag, '', XN_AES_GCM_TAG_LEN
        );
        $s = $ct === false ? '' : xn_encrypt_magic() . 'G' . $iv . $tag . $ct;
    } elseif (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $s = xn_encrypt_magic() . 'S' . $nonce
            . sodium_crypto_secretbox($txt, $nonce, xn_encrypt_derive_key($key));
    } else {
        $s = xxtea_encrypt($txt, $key);
    }
    if ($s === '') return '';
    return xn_urlencode(base64_encode($s));
}

/**
 * 解密
 *
 * 按前缀里的算法标识分流；无 XE2 前缀的历史密文继续走 XXTEA，
 * 因此升级算法不会让既有数据失效。认证失败一律返回 false。
 */
function xn_decrypt(string $txt, ?string $key = null): string|false {
    $key = $key ?? xn_key();
    if ($key === '') return false;
    $s = base64_decode(xn_urldecode($txt), true);
    if ($s === false || $s === '') return false;
    if (function_exists('xiuno_decrypt')) {
        return xiuno_decrypt($s, $key);
    }

    $magic = xn_encrypt_magic();
    if (!str_starts_with($s, $magic)) return xxtea_decrypt($s, $key);
    $body = substr($s, strlen($magic));
    if ($body === '') return false;
    $algo = $body[0];
    $body = substr($body, 1);

    if ($algo === 'G') {
        if (!xn_encrypt_has_aes_gcm()) return false;
        if (strlen($body) <= XN_AES_GCM_IV_LEN + XN_AES_GCM_TAG_LEN) return false;
        $iv   = substr($body, 0, XN_AES_GCM_IV_LEN);
        $tag  = substr($body, XN_AES_GCM_IV_LEN, XN_AES_GCM_TAG_LEN);
        $ct   = substr($body, XN_AES_GCM_IV_LEN + XN_AES_GCM_TAG_LEN);
        return openssl_decrypt(
            $ct, 'aes-256-gcm', xn_encrypt_derive_key($key),
            OPENSSL_RAW_DATA, $iv, $tag
        );
    }
    if ($algo === 'S') {
        if (!function_exists('sodium_crypto_secretbox_open')) return false;
        $nb = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($body) <= $nb) return false;
        return sodium_crypto_secretbox_open(
            substr($body, $nb), substr($body, 0, $nb), xn_encrypt_derive_key($key)
        );
    }
    return false;
}

/** 认证加密密文的版本标识（其后一字节为算法标识 G/S） */
function xn_encrypt_magic(): string {
    return 'XE2$';
}

/** 任意长度密钥统一派生为 32 字节，避免长密钥被静默截断 */
function xn_encrypt_derive_key(string $key): string {
    return hash('sha256', $key, true);
}

/** openssl 是否支持 AES-256-GCM（静态记忆，避免每请求重复枚举算法表） */
function xn_encrypt_has_aes_gcm(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('openssl_encrypt')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }
    return $ok;
}

// GCM 推荐 96-bit nonce，认证标签取 16 字节
define('XN_AES_GCM_IV_LEN', 12);
define('XN_AES_GCM_TAG_LEN', 16);

// ---------- XXTEA 纯 PHP 实现 ----------

function xxtea_encrypt(string $plain, string $key): string {
    if ($plain === '') return '';
    $v = xxtea_str2long($plain, true);
    $k = xxtea_str2long(str_pad($key, 16, "\0"), false);
    if (count($k) < 4) {
        $k = array_pad($k, 4, 0);
    }
    $n = count($v) - 1;
    $z = $v[$n];
    $y = $v[0];
    $q = floor(6 + 52 / ($n + 1));
    $delta = 0x9E3779B9;
    $sum = 0;

    while ($q-- > 0) {
        $sum = xxtea_int32($sum + $delta);
        $e = ($sum >> 2) & 3;
        for ($p = 0; $p < $n; $p++) {
            $y = $v[$p + 1];
            $mx = xxtea_int32((($z >> 5 & 0x07FFFFFF) ^ ($y << 2)) + (($y >> 3 & 0x1FFFFFFF) ^ ($z << 4))) ^ xxtea_int32(($sum ^ $y) + ($k[($p & 3) ^ $e] ^ $z));
            $z = $v[$p] = xxtea_int32($v[$p] + $mx);
        }
        $y = $v[0];
        $mx = xxtea_int32((($z >> 5 & 0x07FFFFFF) ^ ($y << 2)) + (($y >> 3 & 0x1FFFFFFF) ^ ($z << 4))) ^ xxtea_int32(($sum ^ $y) + ($k[($n & 3) ^ $e] ^ $z));
        $z = $v[$n] = xxtea_int32($v[$n] + $mx);
    }
    return xxtea_long2str($v, false);
}

function xxtea_decrypt(string $cipher, string $key): string|false {
    if ($cipher === '') return '';
    $v = xxtea_str2long($cipher, false);
    $k = xxtea_str2long(str_pad($key, 16, "\0"), false);
    if (count($k) < 4) {
        $k = array_pad($k, 4, 0);
    }
    $n = count($v) - 1;
    $z = $v[$n];
    $y = $v[0];
    $q = floor(6 + 52 / ($n + 1));
    $delta = 0x9E3779B9;
    $sum = xxtea_int32($q * $delta);

    while ($sum != 0) {
        $e = ($sum >> 2) & 3;
        for ($p = $n; $p > 0; $p--) {
            $z = $v[$p - 1];
            $mx = xxtea_int32((($z >> 5 & 0x07FFFFFF) ^ ($y << 2)) + (($y >> 3 & 0x1FFFFFFF) ^ ($z << 4))) ^ xxtea_int32(($sum ^ $y) + ($k[($p & 3) ^ $e] ^ $z));
            $y = $v[$p] = xxtea_int32($v[$p] - $mx);
        }
        $z = $v[$n];
        $mx = xxtea_int32((($z >> 5 & 0x07FFFFFF) ^ ($y << 2)) + (($y >> 3 & 0x1FFFFFFF) ^ ($z << 4))) ^ xxtea_int32(($sum ^ $y) + ($k[(0 & 3) ^ $e] ^ $z));
        $y = $v[0] = xxtea_int32($v[0] - $mx);
        $sum = xxtea_int32($sum - $delta);
    }
    return xxtea_long2str($v, true);
}

function xxtea_int32(int $n): int {
    return $n & 0xFFFFFFFF;
}

function xxtea_str2long(string $s, bool $w): array {
    $v = array_values(unpack('N*', $s . str_repeat("\0", (4 - strlen($s) % 4) % 4)));
    if ($w) {
        $v[] = strlen($s);
    }
    return $v;
}

function xxtea_long2str(array $v, bool $w): string {
    $s = '';
    foreach ($v as $n) {
        $s .= pack('N', $n);
    }
    if ($w) {
        $len = end($v);
        $s = substr($s, 0, $len);
    }
    return $s;
}
