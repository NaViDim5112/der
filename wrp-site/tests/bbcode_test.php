<?php
// Только из командной строки: через браузер скрипт не запускается
if (PHP_SAPI !== 'cli') {
    exit;
}
// Проверка BB-кодов: php tests/bbcode_test.php
define('WRP_INSTALLER', true);
require __DIR__ . '/../app/bootstrap.php';

$fail = 0;
function check($name, $cond, $out = '')
{
    global $fail;
    if (!$cond) {
        $fail++;
        echo "FAIL: $name\n  $out\n";
    } else {
        echo "ok: $name\n";
    }
}

// XSS
$evil = [
    '<script>alert(1)</script>',
    '[url=javascript:alert(1)]x[/url]',
    '[url]javascript:alert(1)[/url]',
    '[img]javascript:alert(1)[/img]',
    '[img]https://x.com/a.png" onerror="alert(1)[/img]',
    '[url=https://x.com/" onmouseover="alert(1)]x[/url]',
    '[color=red;background:url(javascript:alert(1))]x[/color]',
    '[color=expression(alert(1))]x[/color]',
    '[quote="<img src=x onerror=alert(1)>"]x[/quote]',
    '[spoiler="</summary><script>alert(1)</script>"]x[/spoiler]',
    '[url=//evil.com]x[/url]',
    '[url=/\\evil.com]x[/url]',
    '[size=7 onclick=alert(1)]x[/size]',
    '[font=Arial;color:red]x[/font]',
    '[youtube]"><script>alert(1)</script>[/youtube]',
    "[code]<b>x</b>[/code]",
    "\x02" . "0" . "\x03",
    'https://example.com/"><script>alert(1)</script>',
    '[user=1]<script>[/user]',
];
foreach ($evil as $in) {
    $out = bbcode($in);
    // опасно только то, что стало настоящим тегом или атрибутом; экранированный текст безопасен
    $bad = preg_match('~<script|<[a-z][^>]*\son[a-z]+\s*=|<[a-z][^>]*\s(?:href|src)="(?!https?://|/(?![/\\\\]))|style="[^"]*(?:expression|url\(|;)~i', $out);
    check('xss ' . substr($in, 0, 40), !$bad, $out);
}

$t = bbcode("[b]жирный[/b] [i]к[/i] [u]п[/u] [s]з[/s]");
check('basic', $t === '<strong>жирный</strong> <em>к</em> <u>п</u> <s>з</s>', $t);
$t = bbcode("[b]a [b]b[/b] c[/b]");
check('nested same', $t === '<strong>a <strong>b</strong> c</strong>', $t);
$t = bbcode("[quote=\"Roxas, post: 12, member: 3\"]текст[/quote]");
check('quote attr', str_contains($t, 'Roxas написал(а):') && str_contains($t, 'post.php?id=12'), $t);
$t = bbcode("[quote][quote=A]in[/quote]out[/quote]");
check('nested quote', substr_count($t, '<blockquote') === 2, $t);
$t = bbcode("[spoiler=Нарушение]Тайм-код[/spoiler]");
check('spoiler', str_contains($t, '<summary>Спойлер: Нарушение</summary>'), $t);
$t = bbcode("[list]\n[*]один\n[*]два\n[/list]");
check('list', str_contains($t, '<ul class="bb-list"><li>один</li><li>два</li></ul>'), $t);
$t = bbcode("[url=https://a.com/?x=1&y=2]ссылка[/url]");
check('url amp', str_contains($t, 'href="https://a.com/?x=1&amp;y=2"'), $t);
$t = bbcode("смотри https://a.com/page, ок");
check('autolink', str_contains($t, '<a href="https://a.com/page"') && str_contains($t, '</a>, ок'), $t);
$t = bbcode("[media]https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10[/media]");
check('youtube', str_contains($t, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), $t);
$t = bbcode("строка1\nстрока2\n[center]ц[/center]\nпосле");
check('newlines', str_contains($t, 'строка1<br>') && !str_contains($t, '</div><br>'), $t);
$t = bbcode("[code]a\n  [b]b[/b][/code]");
check('code keeps tags', str_contains($t, '[b]b[/b]') && str_contains($t, '<pre class="bb-code">'), $t);
$t = bbcode("[table][tr][th]A[/th][/tr][tr][td]1[/td][/tr][/table]");
check('table', str_contains($t, '<table class="bb-table"><tr><th>A</th></tr><tr><td>1</td></tr></table>'), $t);
$t = bbcode("[url=/forum/thread.php?id=1]тема[/url]");
check('internal url', str_contains($t, '<a href="/forum/thread.php?id=1">тема</a>'), $t);
$t = bbcode("[color=#ff0000]к[/color] [size=5]б[/size]");
check('color size', str_contains($t, 'style="color:#ff0000"') && str_contains($t, 'bb-size-5'), $t);
$p = bbcode_plain("[quote=A]цитата[/quote][b]Текст[/b] [img]https://a/b.png[/img] ещё");
check('plain', $p === 'Текст ещё', $p);

// Блоки [code]/[icode] и другие теги внутри адреса ссылки или картинки не попадают в атрибут
$nested = [
    '[url=https://a.com/[icode]x[/icode]]t[/url]',
    '[url]https://a.com/[code]x[/code][/url]',
    '[img]https://a.com/[icode]x[/icode][/img]',
    'https://a.com/[icode]x[/icode]',
    '[url=/[icode]x[/icode]]t[/url]',
    '[url=https://a.com/[b]x[/b]]t[/url]',
];
foreach ($nested as $in) {
    $out = bbcode($in);
    $bad = preg_match('~(?:href|src)="[^"]*<~i', $out);
    check('attr clean ' . substr($in, 0, 40), !$bad, $out);
}

// Безопасный адрес возврата
check('safe_return tab', safe_return("/\t/example.com", '/x') === '/x');
check('safe_return ok', safe_return('/forum/thread.php?id=1', '/x') === '/forum/thread.php?id=1');

// Производительность на большом тексте
$big = str_repeat("[b]жирный[/b] текст [i]курсив[/i] https://example.com/x [quote=A]q[/quote]\n", 600);
$t0 = microtime(true);
bbcode($big);
$dt = microtime(true) - $t0;
check('perf ' . round($dt, 3) . 's', $dt < 1.5);
$big2 = str_repeat('[b]', 5000) . 'x';
$t0 = microtime(true);
$r = bbcode($big2);
check('unclosed tags perf', microtime(true) - $t0 < 1.5 && $r !== null);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
