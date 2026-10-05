<?php
// BB-коды -> безопасный HTML. Сначала весь текст экранируется, потом разрешённые теги
// превращаются в HTML по белому списку. Ссылки и картинки только http/https.

function bbcode($text)
{
    $text = str_replace(["\r\n", "\r", "\x02", "\x03"], ["\n", "\n", '', ''], (string)$text);
    $blocks = [];

    // [code] и [icode] вынимаем до разбора, внутри них теги не работают
    $text = preg_replace_callback('~\[code(?:=[a-z0-9+#\-]{1,15})?\](.*?)\[/code\]~is', function ($m) use (&$blocks) {
        $blocks[] = '<pre class="bb-code"><code>' . e(trim($m[1], "\n")) . '</code></pre>';
        return "\x02" . (count($blocks) - 1) . "\x03";
    }, $text);
    $text = preg_replace_callback('~\[icode\](.*?)\[/icode\]~is', function ($m) use (&$blocks) {
        $blocks[] = '<code class="bb-icode">' . e($m[1]) . '</code>';
        return "\x02" . (count($blocks) - 1) . "\x03";
    }, $text);

    $t = e($text);

    // Голые ссылки в тексте
    $t = preg_replace_callback('~(^|[\s(])(https?://(?:(?!&quot;|&#039;|&lt;|&gt;)[^\s\[\]])+)~iu', function ($m) {
        $url = $m[2];
        $tail = '';
        while ($url !== '' && preg_match('~[.,!?;:)]$~', $url)) {
            $tail = substr($url, -1) . $tail;
            $url = substr($url, 0, -1);
        }
        return $m[1] . '<a href="' . $url . '" target="_blank" rel="nofollow ugc noopener">' . str_limit_html($url) . '</a>' . $tail;
    }, $t);

    $inner = function ($tag) {
        return '((?:(?!\[' . $tag . '[\]=]).)*?)';
    };

    $rules = [
        '~\[b\]' . $inner('b') . '\[/b\]~is' => '<strong>$1</strong>',
        '~\[i\]' . $inner('i') . '\[/i\]~is' => '<em>$1</em>',
        '~\[u\]' . $inner('u') . '\[/u\]~is' => '<u>$1</u>',
        '~\[s\]' . $inner('s') . '\[/s\]~is' => '<s>$1</s>',
        '~\[(center|left|right|justify)\]((?:(?!\[(?:center|left|right|justify)\]).)*?)\[/\1\]~is' => '<div class="bb-align" style="text-align:$1">$2</div>',
        '~\[color=(#[0-9a-f]{3,8}|[a-z]{3,20})\]' . $inner('color') . '\[/color\]~is' => '<span style="color:$1">$2</span>',
        '~\[size=([1-7])\]' . $inner('size') . '\[/size\]~is' => '<span class="bb-size-$1">$2</span>',
        '~\[font=([a-z0-9 \-]{2,30})\]' . $inner('font') . '\[/font\]~is' => '<span style="font-family:\'$1\', sans-serif">$2</span>',
    ];

    for ($pass = 0; $pass < 8; $pass++) {
        $before = $t;
        foreach ($rules as $re => $rep) {
            $t = preg_replace($re, $rep, $t);
        }
        if ($t === $before) {
            break;
        }
    }

    $t = preg_replace('~\[hr\]~i', '<hr class="bb-hr">', $t);

    // Ссылки
    $t = preg_replace_callback('~\[url\]((?:https?://|www\.)(?:(?!&quot;|&lt;|&gt;)[^\s\[\]])+?)\[/url\]~i', function ($m) {
        $href = stripos($m[1], 'www.') === 0 ? 'http://' . $m[1] : $m[1];
        return '<a href="' . $href . '" target="_blank" rel="nofollow ugc noopener">' . str_limit_html($m[1]) . '</a>';
    }, $t);
    $t = preg_replace_callback('~\[url=(?:&quot;)?((?:https?://|www\.|/)(?:(?!&quot;|&lt;|&gt;)[^\s\[\]])+?)(?:&quot;)?\]((?:(?!\[url).)*?)\[/url\]~is', function ($m) {
        $href = $m[1];
        if (stripos($href, 'www.') === 0) {
            $href = 'http://' . $href;
        }
        if (preg_match('~^/[/\\\\]~', $href)) {
            return $m[2];
        }
        $internal = $href[0] === '/';
        return '<a href="' . $href . '"' . ($internal ? '' : ' target="_blank" rel="nofollow ugc noopener"') . '>' . $m[2] . '</a>';
    }, $t);

    // Картинки
    $t = preg_replace('~\[img(?:=[0-9x]{1,9})?\](https?://(?:(?!&quot;|&lt;|&gt;)[^\s\[\]])+?)\[/img\]~i',
        '<img class="bb-img" src="$1" alt="" loading="lazy" referrerpolicy="no-referrer">', $t);

    // YouTube
    $t = preg_replace_callback('~\[(youtube|media)(?:=youtube)?\](.*?)\[/\1\]~is', function ($m) {
        $v = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        $id = '';
        if (preg_match('~^[A-Za-z0-9_\-]{11}$~', $v)) {
            $id = $v;
        } elseif (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_\-]{11})~', $v, $mm)) {
            $id = $mm[1];
        }
        if ($id === '') {
            return $m[0];
        }
        return '<div class="bb-video"><iframe src="https://www.youtube-nocookie.com/embed/' . $id . '" loading="lazy" allowfullscreen allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"></iframe></div>';
    }, $t);

    // Упоминание [user=5]Ник[/user]
    $t = preg_replace_callback('~\[user=(\d{1,10})\](.*?)\[/user\]~is', function ($m) {
        return '<a class="bb-mention" href="' . e(url('/forum/member.php', ['id' => (int)$m[1]])) . '">@' . $m[2] . '</a>';
    }, $t);

    // Списки
    for ($pass = 0; $pass < 5; $pass++) {
        $before = $t;
        $t = preg_replace_callback('~\[list(=1|=a)?\]((?:(?!\[list[\]=]).)*?)\[/list\]~is', function ($m) {
            $items = preg_split('~\[\*\]~', $m[2]);
            array_shift($items);
            $tag = $m[1] ? 'ol' : 'ul';
            $h = '<' . $tag . ' class="bb-list"' . ($m[1] === '=a' ? ' type="a"' : '') . '>';
            foreach ($items as $it) {
                $h .= '<li>' . trim($it) . '</li>';
            }
            return $h . '</' . $tag . '>';
        }, $t);
        if ($t === $before) {
            break;
        }
    }

    // Таблицы
    $t = preg_replace_callback('~\[table\](.*?)\[/table\]~is', function ($m) {
        $in = preg_replace('~\]\s+\[~', '][', $m[1]);
        $in = preg_replace(['~\[tr\]~i', '~\[/tr\]~i', '~\[td\]~i', '~\[/td\]~i', '~\[th\]~i', '~\[/th\]~i'],
            ['<tr>', '</tr>', '<td>', '</td>', '<th>', '</th>'], $in);
        return '<div class="bb-table-wrap"><table class="bb-table">' . trim($in) . '</table></div>';
    }, $t);

    // Спойлеры
    for ($pass = 0; $pass < 6; $pass++) {
        $before = $t;
        $t = preg_replace_callback('~\[spoiler(?:=([^\]]{1,120}))?\]((?:(?!\[spoiler[\]=]).)*?)\[/spoiler\]~is', function ($m) {
            $title = trim(preg_replace('~^(?:&quot;|&#039;)|(?:&quot;|&#039;)$~', '', trim($m[1])));
            if ($title === '') {
                $title = 'Спойлер';
            } else {
                $title = 'Спойлер: ' . $title;
            }
            return '<details class="bb-spoiler"><summary>' . $title . '</summary><div class="bb-spoiler-body">' . trim($m[2]) . '</div></details>';
        }, $t);
        if ($t === $before) {
            break;
        }
    }

    // Цитаты: [quote], [quote=Ник], [quote="Ник, post: 12, member: 3"]
    for ($pass = 0; $pass < 10; $pass++) {
        $before = $t;
        $t = preg_replace_callback('~\[quote(?:=([^\]]{1,120}))?\]((?:(?!\[quote[\]=]).)*?)\[/quote\]~is', function ($m) {
            $attr = trim(preg_replace('~^(?:&quot;|&#039;)|(?:&quot;|&#039;)$~', '', trim($m[1])));
            $head = '';
            if ($attr !== '') {
                $parts = explode(',', $attr);
                $name = trim($parts[0]);
                $link = '';
                if (preg_match('~post:\s*(\d+)~', $attr, $pm)) {
                    $link = ' <a class="bb-quote-link" href="' . e(url('/forum/post.php', ['id' => (int)$pm[1]])) . '" title="К сообщению">&uarr;</a>';
                }
                $head = '<div class="bb-quote-head">' . $name . ' написал(а):' . $link . '</div>';
            }
            return '<blockquote class="bb-quote">' . $head . '<div class="bb-quote-body">' . trim($m[2]) . '</div></blockquote>';
        }, $t);
        if ($t === $before) {
            break;
        }
    }

    // Переводы строк
    $t = nl2br($t, false);
    $block = '(?:div|blockquote|ul|ol|li|details|summary|pre|table|tr|td|th|hr)';
    $t = preg_replace('~(<' . $block . '\b[^>]*>|</' . $block . '>)\s*<br>~i', '$1', $t);
    $t = preg_replace('~<br>(\s*</' . $block . '>)~i', '$1', $t);
    $t = preg_replace('~<br>(\s*<(?:ul|ol|table|blockquote|details|div)\b)~i', '$1', $t);

    // Возвращаем блоки кода
    $t = preg_replace_callback("~\x02(\d+)\x03~", function ($m) use ($blocks) {
        return $blocks[(int)$m[1]] ?? '';
    }, $t);

    return $t;
}

// Укорачивает длинный текст ссылки (текст уже экранирован)
function str_limit_html($escaped, $limit = 70)
{
    $raw = html_entity_decode($escaped, ENT_QUOTES, 'UTF-8');
    if (mb_strlen($raw) <= $limit) {
        return $escaped;
    }
    return e(mb_substr($raw, 0, $limit - 20) . '…' . mb_substr($raw, -15));
}

// Текст без BB-кодов: для превью, описаний, поиска
function bbcode_plain($text, $limit = 0)
{
    $t = (string)$text;
    $t = preg_replace('~\[quote[^\]]*\].*?\[/quote\]~is', ' ', $t);
    $t = preg_replace('~\[(img|youtube|media)[^\]]*\].*?\[/\1\]~is', ' ', $t);
    $t = preg_replace('~\[/?[a-z*]+(?:=[^\]]*)?\]~i', ' ', $t);
    $t = trim(preg_replace('~\s+~u', ' ', $t));
    return $limit ? str_limit($t, $limit) : $t;
}
