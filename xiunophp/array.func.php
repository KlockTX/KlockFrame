<?php
/*
 * array.func.php — 数组工具函数
 *
 * 提供数组筛选、排序、变换等实用操作
 *
 * 性能优化 (1.1):
 * - arrlist_change_key/arrlist_key_values 使用 isset 优先匹配，避免数组访问开销
 * - arrlist_max/min 处理空数组时直接返回 null，避免 max([]) 警告
 */

// ---------- 基础 ----------

function array_value(array $arr, string $k, $defval = null) {
    return $arr[$k] ?? $defval;
}

function array_filter_empty(array $arr): array {
    return array_filter($arr, fn($v) => $v !== '' && $v !== null && $v !== false);
}

// ---------- 批量处理 ----------

function array_addslashes(array $arr): array {
    return array_map(fn($v) => is_array($v) ? array_addslashes($v) : (is_string($v) ? addslashes($v) : $v), $arr);
}

function array_stripslashes(array $arr): array {
    return array_map(fn($v) => is_array($v) ? array_stripslashes($v) : (is_string($v) ? stripslashes($v) : $v), $arr);
}

function array_htmlspecialchars(array $arr): array {
    return array_map(fn($v) => is_array($v) ? array_htmlspecialchars($v) : (is_string($v) ? htmlspecialchars($v, ENT_QUOTES) : $v), $arr);
}

function array_trim(array $arr): array {
    return array_map(fn($v) => is_array($v) ? array_trim($v) : (is_string($v) ? trim($v) : $v), $arr);
}

// ---------- arrlist 系列 (二维数组操作) ----------

/**
 * 多维数组排序
 *
 * $arrlist = [
 *     ['id'=>1, 'score'=>90],
 *     ['id'=>2, 'score'=>85],
 * ];
 * arrlist_multisort($arrlist, ['score'=>-1]);  // 按 score 降序
 */
function arrlist_multisort(array $arrlist, array $orderby = []): array {
    if (empty($orderby)) return $arrlist;
    // 构建排序参数
    $args = [];
    foreach ($orderby as $col => $order) {
        $col_values = array_column($arrlist, $col);
        $args[] = $col_values;
        $args[] = $order == 1 ? SORT_ASC : SORT_DESC;
    }
    $args[] = &$arrlist;
    call_user_func_array('array_multisort', $args);
    return $arrlist;
}

/**
 * 按条件筛选、排序、分页
 */
function arrlist_cond_orderby(array $arrlist, array $cond = [], array $orderby = [], int $page = 1, int $pagesize = 10): array {
    // 条件筛选
    if ($cond) {
        $arrlist = array_filter($arrlist, function($row) use ($cond) {
            foreach ($cond as $k => $v) {
                if (!is_array($v)) {
                    if (($row[$k] ?? null) != $v) return false;
                } elseif (isset($v[0])) {
                    if (!in_array($row[$k] ?? null, $v)) return false;
                } else {
                    foreach ($v as $op => $val) {
                        $rowval = $row[$k] ?? null;
                        switch (strtoupper($op)) {
                            case '>':  if (!($rowval > $val)) return false; break;
                            case '<':  if (!($rowval < $val)) return false; break;
                            case '>=': if (!($rowval >= $val)) return false; break;
                            case '<=': if (!($rowval <= $val)) return false; break;
                            case '!=': if (!($rowval != $val)) return false; break;
                            case 'LIKE': if (stripos((string)$rowval, (string)$val) === false) return false; break;
                        }
                    }
                }
            }
            return true;
        });
        $arrlist = array_values($arrlist);
    }
    // 排序
    if ($orderby) {
        $arrlist = arrlist_multisort($arrlist, $orderby);
    }
    // 分页
    if ($pagesize > 0) {
        $offset = ($page - 1) * $pagesize;
        $arrlist = array_slice($arrlist, $offset, $pagesize);
    }
    return $arrlist;
}

/**
 * 提取某一列作为 key
 */
function arrlist_change_key(array $arrlist, string $key): array {
    $r = [];
    foreach ($arrlist as $row) {
        if (isset($row[$key])) {
            $r[$row[$key]] = $row;
        }
    }
    return $r;
}

/**
 * 提取某一列的值
 */
function arrlist_values(array $arrlist, string $key): array {
    return array_column($arrlist, $key);
}

/**
 * 提取多列, 返回 key-value 映射
 */
function arrlist_key_values(array $arrlist, string $key, string $value): array {
    $r = [];
    foreach ($arrlist as $row) {
        if (isset($row[$key])) {
            $r[$row[$key]] = $row[$value] ?? null;
        }
    }
    return $r;
}

/**
 * 对某一列求和
 */
function arrlist_sum(array $arrlist, string $key): int|float {
    return array_sum(array_column($arrlist, $key));
}

/**
 * 对某一列求最大值
 */
function arrlist_max(array $arrlist, string $key) {
    if (!$arrlist) return null;
    $vals = array_column($arrlist, $key);
    return $vals ? max($vals) : null;
}

/**
 * 对某一列求最小值
 */
function arrlist_min(array $arrlist, string $key) {
    if (!$arrlist) return null;
    $vals = array_column($arrlist, $key);
    return $vals ? min($vals) : null;
}

/**
 * 仅保留指定的列
 */
function arrlist_keep_keys(array $arrlist, array $keys): array {
    return array_map(fn($row) => array_intersect_key($row, array_flip($keys)), $arrlist);
}

/**
 * 分块
 */
function arrlist_chunk(array $arrlist, int $size): array {
    return array_chunk($arrlist, $size);
}
