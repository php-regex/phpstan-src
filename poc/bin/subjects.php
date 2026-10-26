<?php declare(strict_types = 1);

/**
 * Adds subjects to a pattern list: samples the PHPRegex generator writes for
 * each pattern, variations of them, the pattern's own literals, random
 * strings over its characters, and a fixed list of edge strings.
 *
 * Usage: PHPREGEX_ROOT=<php-regex/php-regex checkout> php poc/bin/subjects.php <in.json> <out.json>
 * It runs with the PHPRegex monorepo (https://github.com/php-regex/php-regex),
 * whose generator is not part of the parser package this branch installs.
 */
require getenv('PHPREGEX_ROOT') . '/vendor/autoload.php';
use PHPRegex\Generator\SampleGenerator;
ini_set('pcre.jit', '0');
$in = json_decode(file_get_contents($argv[1]), true);
$fixed = ['', '0', '1', '00', '01', '10', 'a', 'A', 'b', 'c', 'x', 'y', 'z', 'ab', 'aa', 'abc', 'cd', "a\n", "\n", "a\nb", "\na", "\r\n", "a\r\n", "0\n", "1\n", ' ', ' 1', '1 ', '-1', '-', '1.5', '.5', '1.', '٣', '١٢', '0٣', '０', '１２', '߀', '१', "\u{212A}", 'k', 'K', "\u{17F}", 's', 'S', 'ss', 'ß', 'ẞ', 'é', 'É', "e\u{301}", 'ı', 'İ', 'i', 'I', 'σ', 'Σ', 'ς', 'ﬆ', 'st', "\x00", "\xff", "a\xffb", "\xc3", '#', 'a#b', 'm', 'n', 'aab', 'aba', 'bab', 'xy', 'xyz', 'ba', 'bc', 'ac', 'abab', '123', '2026-10', '0.0', 'foo', 'bar', '_', '0a', 'a0', 'ab0', '٠', '9', '٩'];
$out = [];
$t0 = microtime(true);
foreach ($in as $i => $row) {
    $pat = $row['pattern'];
    $subs = [];
    $gen = new SampleGenerator(3);
    for ($s = 1; $s <= 10; $s++) {
        $gen->setSeed(crc32($pat) + $s);
        try { $subs[] = $gen->generate($pat); } catch (\Throwable $e) { break; }
    }
    $samples = $subs;
    // mutations of generated samples
    foreach ($samples as $smp) {
        $subs[] = $smp . "\n";
        $subs[] = "\n" . $smp;
        $subs[] = 'x' . $smp . 'x';
        $subs[] = substr($smp, 0, -1);
        $subs[] = substr($smp, 1);
        $subs[] = strtoupper($smp);
        $subs[] = strtolower($smp);
        $subs[] = strtr($smp, '0123456789', '0000000000');
        $subs[] = str_replace(['0','1','2','3','4','5','6','7','8','9'], ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], $smp);
        $subs[] = str_replace(['k','K','s','S'], ["\u{212A}", "\u{212A}", "\u{17F}", "\u{17F}"], $smp);
        $subs[] = $smp . $smp;
    }
    // literals of the body
    $body = $pat;
    preg_match_all('/[A-Za-z0-9]+/', $body, $lm);
    foreach (array_slice(array_unique($lm[0]), 0, 12) as $lit) { $subs[] = $lit; $subs[] = $lit . "\n"; }
    // random over alphabet
    $alpha = array_values(array_unique(array_merge(mb_str_split(preg_replace('/[\\\\()\[\]{}?*+|^$]/', '', $body) ?? '') ?: [], ['0', '1', 'a', 'b', ' ', "\n", '٣', 'K'])));
    mt_srand(crc32($pat));
    for ($r = 0; $r < 25; $r++) {
        $len = mt_rand(0, 8); $s = '';
        for ($k = 0; $k < $len; $k++) $s .= $alpha[mt_rand(0, count($alpha) - 1)];
        $subs[] = $s;
    }
    $subs = array_merge($subs, $fixed);
    $subs = array_values(array_unique(array_map('strval', $subs)));
    $out[] = ['pattern' => $pat, 'src' => $row['src'], 'samples' => count($samples), 'subjects' => array_map('base64_encode', $subs)];
    if ($i % 500 === 0) fwrite(STDERR, "$i " . round(microtime(true) - $t0) . "s\n");
}
file_put_contents($argv[2], json_encode($out, JSON_UNESCAPED_SLASHES));
