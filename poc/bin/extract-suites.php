<?php declare(strict_types = 1);

/**
 * Turns the php-src ext/pcre tests and the PCRE2 testinput files into
 * pattern lists with subjects: [{pattern, subjects: base64[]}]. The files
 * are read as data, nothing in them runs.
 *
 * Usage: php poc/bin/extract-suites.php <dir> <out-php-src.json> <out-pcre2.json>
 * <dir> holds php-src/ext/pcre/tests (php/php-src, branch PHP-8.6) and
 * pcre2/testdata (PCRE2Project/pcre2).
 */
require __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

[$self, $dir, $outPhp, $outPcre] = $argv;

/**
 * Patterns left out: a \K inside a lookbehind sets the start of the match
 * past its end, and preg_replace_callback() of PHP fails on the negative
 * length ("Possible integer overflow in memory allocation").
 */
const LEFT_OUT = ['#a|(?(DEFINE)(?<sneaky>\Ka))(?<=(?&sneaky))b#'];

/** @param array<string, list<string>> $rows */
function write(string $file, array $rows): void {
    $out = [];
    foreach ($rows as $pattern => $subjects) {
        $pattern = (string) $pattern;
        // a pattern JSON cannot hold, raw bytes past UTF-8, is left out
        if (in_array($pattern, LEFT_OUT, true) || @preg_match($pattern, '') === false || !mb_check_encoding($pattern, 'UTF-8')) {
            continue;
        }
        $subjects = array_values(array_unique($subjects));
        $out[] = ['pattern' => $pattern, 'subjects' => array_map('base64_encode', array_slice($subjects, 0, 200))];
    }
    file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, basename($file) . ': ' . count($out) . " patterns\n");
}

// --- php-src: every preg_* call whose pattern is a literal, with the literal subjects of the file
$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();
$php = [];
foreach (glob("$dir/php-src/ext/pcre/tests/*.phpt") as $file) {
    $text = file_get_contents($file);
    if (!preg_match('/--FILE--\R(.*?)(?=\R--[A-Z_]+--)/s', $text, $m)) {
        continue;
    }
    try {
        $ast = $parser->parse($m[1]) ?? [];
    } catch (Throwable) {
        continue;
    }
    $literals = array_map(static fn (String_ $s): string => $s->value, $finder->findInstanceOf($ast, String_::class));
    foreach ($finder->findInstanceOf($ast, FuncCall::class) as $call) {
        if (!$call->name instanceof Name || !str_starts_with($call->name->toLowerString(), 'preg_')) {
            continue;
        }
        $arg = $call->getArgs()[0]->value ?? null;
        $patterns = [];
        if ($arg instanceof String_) {
            $patterns[] = $arg->value;
        } elseif ($arg instanceof PhpParser\Node\Expr\Array_) {
            foreach ($arg->items as $item) {
                if ($item !== null && $item->value instanceof String_) {
                    $patterns[] = $item->value->value;
                }
            }
        }
        foreach ($patterns as $pattern) {
            $php[$pattern] = [...($php[$pattern] ?? []), ...$literals];
        }
    }
}
write($outPhp, $php);

// --- PCRE2: each pattern with the subject lines that follow it
function unescape(string $s, bool $utf): string {
    $map = ['a' => "\x07", 'b' => "\x08", 'e' => "\x1b", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v"];
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c !== '\\' || $i + 1 >= $n) {
            $out .= $c;
            continue;
        }
        $d = $s[++$i];
        if (isset($map[$d])) {
            $out .= $map[$d];
        } elseif ($d === 'x' && ($s[$i + 1] ?? '') === '{' && preg_match('/\G\{([0-9a-fA-F]+)\}/', $s, $mm, 0, $i + 1)) {
            $cp = hexdec($mm[1]);
            $out .= $utf ? (mb_chr((int) $cp, 'UTF-8') ?: '') : chr((int) $cp & 0xFF);
            $i += strlen($mm[0]);
        } elseif ($d === 'x' && preg_match('/\G[0-9a-fA-F]{1,2}/', $s, $mm, 0, $i + 1)) {
            $out .= chr((int) hexdec($mm[0]));
            $i += strlen($mm[0]);
        } elseif ($d === 'o' && preg_match('/\G\{([0-7]+)\}/', $s, $mm, 0, $i + 1)) {
            $cp = octdec($mm[1]);
            $out .= $utf ? (mb_chr((int) $cp, 'UTF-8') ?: '') : chr((int) $cp & 0xFF);
            $i += strlen($mm[0]);
        } elseif (ctype_digit($d) && preg_match('/\G[0-7]{1,3}/', $s, $mm, 0, $i)) {
            $out .= chr((int) octdec($mm[0]) & 0xFF);
            $i += strlen($mm[0]) - 1;
        } else {
            $out .= $d;
        }
    }

    return $out;
}

const FLAGS = ['i' => 'i', 'caseless' => 'i', 'm' => 'm', 'multiline' => 'm', 's' => 's', 'dotall' => 's', 'x' => 'x', 'extended' => 'x',
    'n' => 'n', 'no_auto_capture' => 'n', 'utf' => 'u', 'dupnames' => 'J', 'ungreedy' => 'U', 'dollar_endonly' => 'D', 'anchored' => 'A'];
// display-only or match-time modifiers of pcre2test that do not change what one match writes
const IGNORED = ['B', 'I', 'aftertext', 'allaftertext', 'g', 'altglobal', 'mark', 'info', 'jit', 'jitverify', 'fullbincode', 'bincode',
    'debug', 'memory', 'stackguard', 'allusedtext', 'startchar', 'hex', 'expand', 'locale', 'framesize', 'find_limits', 'push', 'pushcopy',
    'callout_info', 'tables', 'use_length', 'posix', 'perlcompat', 'no_start_optimize', 'no_dotstar_anchor', 'no_auto_possess', 'auto_callout', 'S'];

$pcre = [];
foreach (glob("$dir/pcre2/testdata/testinput*") as $file) {
    $lines = preg_split('/\R/', file_get_contents($file)) ?: [];
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = $lines[$i];
        if ($line === '' || $line[0] === '#' || ctype_space($line[0]) || ctype_alnum($line[0]) || $line[0] === '\\') {
            continue;
        }
        $delim = $line[0];
        $text = $line;
        // a pattern may run over several lines until its closing delimiter
        while (!preg_match('/^' . preg_quote($delim, '/') . '((?:\\\\.|[^\\\\])*?)' . preg_quote($delim, '/') . '([^' . preg_quote($delim, '/') . ']*)$/s', $text, $pm) && $i + 1 < $n) {
            $text .= "\n" . $lines[++$i];
        }
        if (!isset($pm[1])) {
            continue;
        }
        [$body, $mods] = [$pm[1], trim($pm[2])];
        $flags = '';
        $usable = true;
        foreach ($mods === '' ? [] : preg_split('/\s*,\s*/', $mods) as $mod) {
            $name = explode('=', $mod)[0];
            if (isset(FLAGS[$name])) {
                $flags .= FLAGS[$name];
            } elseif (in_array($name, IGNORED, true)) {
                continue;
            } elseif (preg_match('/^[imsxn]+$/', $name)) {
                $flags .= $name;
            } else {
                $usable = false;
            }
        }
        $subjects = [];
        while ($i + 1 < $n && $lines[$i + 1] !== '' && ctype_space($lines[$i + 1][0])) {
            $subject = ltrim($lines[++$i]);
            if (str_starts_with($subject, '\\=')) {
                continue;
            }
            if (str_contains($subject, '\\=')) {
                continue; // per-subject modifiers change the match
            }
            $subjects[] = unescape($subject, str_contains($flags, 'u'));
        }
        if (!$usable || str_contains($body, '/')) {
            // PHP delimits with "/" below: a pattern holding one keeps its own delimiter only when it is not "/"
            if (!$usable) {
                continue;
            }
        }
        $phpDelim = str_contains($body, '#') ? '~' : '#';
        if (str_contains($body, $phpDelim)) {
            continue;
        }
        $pattern = $phpDelim . $body . $phpDelim . count_chars($flags, 3);
        $pcre[$pattern] = [...($pcre[$pattern] ?? []), ...$subjects];
    }
}
write($outPcre, $pcre);
