# API сайта World Role Play

Адрес сайта в примерах - `https://example.com`, подставьте свой домен.

Открытые адреса для лаунчера (C# WPF + WebView2, React) и других программ. Ничего, кроме того, что видит гость сайта, они не отдают.

## Новости для лаунчера

```
GET /api/news.php?limit=10
```

- `limit` - сколько новостей, от 1 до 20. Без параметра - значение из админки (по умолчанию 10).
- Источник: темы раздела из «Настройки → Форум → Раздел новостей» вместе с подразделами, плюс второй раздел из «Админ-панель → Вложения и опросы → Новости для лаунчера». Обе ленты идут вместе, новые сверху.
- Только разделы, открытые гостям (уровень просмотра 0 у раздела и всех родителей). Удалённые темы не попадают. Закрытый раздел в настройке даёт пустой список, а не ошибку.
- Без сессии и cookie, просмотры тем не увеличиваются.

### Заголовки

| Заголовок | Значение |
|---|---|
| `Content-Type` | `application/json; charset=utf-8` |
| `Cache-Control` | `public, max-age=60` - чаще раза в минуту спрашивать нет смысла |
| `Access-Control-Allow-Origin` | `*` (только для GET; `OPTIONS` отвечает 204, другие методы - 405) |

### Ответ

| Поле | Тип | Что это |
|---|---|---|
| `ok` | bool | `true`, если всё хорошо |
| `site` | string | Название сайта из настроек |
| `forum` | string | Полный адрес форума |
| `generated` | string | Время ответа, ISO 8601 |
| `count` | int | Сколько новостей в `items` |
| `items[].id` | int | id темы |
| `items[].title` | string | Заголовок темы |
| `items[].prefix` | string или null | Префикс темы («Обновление», «Важно» ...) |
| `items[].prefix_color` | string или null | Цвет префикса: gray, light, green, red, orange, yellow, blue, purple, pink, teal, dark |
| `items[].url` | string | Полный адрес темы на форуме (открывать в браузере) |
| `items[].date` | string | Дата создания, ISO 8601 с часовым поясом сайта |
| `items[].author` | string или null | Ник автора (null - аккаунт удалён) |
| `items[].excerpt` | string | Начало первого сообщения простым текстом, до 300 символов |
| `items[].html` | string | Первое сообщение целиком в HTML (BB-коды уже разобраны, текст экранирован, ссылки и картинки с полным адресом) |
| `items[].image` | string или null | Первая картинка `[img]` из сообщения, полный адрес - для обложки карточки |
| `items[].replies` | int | Ответов в теме |
| `items[].views` | int | Просмотров темы |

Пример (`/api/news.php?limit=1`):

```json
{
  "ok": true,
  "site": "World Role Play",
  "forum": "https://example.com/forum/",
  "generated": "2026-10-06T14:47:21+03:00",
  "count": 1,
  "items": [
    {
      "id": 1,
      "title": "Обновление: World Bank и организации",
      "prefix": "Обновление",
      "prefix_color": "purple",
      "url": "https://example.com/forum/thread.php?id=1",
      "date": "2026-10-03T22:41:38+03:00",
      "author": "admin",
      "excerpt": "Обновление сервера. Банк World Bank: банкоматы у мэрии, Департамента полиции и в аэропорту. Правительство и Департамент полиции: ранги и зарплата на счёт в PayDay. Новые игроки прилетают в аэропорт Лос-Сантоса",
      "html": "<div class=\"bb-align\" style=\"text-align:center\"><span class=\"bb-size-5\"><strong>Обновление сервера</strong></span></div>\n\n<ul class=\"bb-list\"><li>Банк World Bank: банкоматы у мэрии, Департамента полиции и в аэропорту</li>...</ul>",
      "image": "https://example.com/uploads/attachments/2026/10/9f1c2a7b0d4e5f6a7b8c.png",
      "replies": 1,
      "views": 736
    }
  ]
}
```

Ошибка - `{"ok": false, "error": "..."}` с кодом 405 (не GET), 500 (ошибка сервера) или 503 (сайт не установлен). Лаунчеру лучше показывать последние сохранённые новости, если ответ не пришёл.

### Как показывать `html`

В HTML встречаются классы сайта: `bb-img` (картинка), `bb-quote` / `bb-quote-head` / `bb-quote-body` (цитата), `bb-spoiler` (`<details>`), `bb-list`, `bb-table`, `bb-code`, `bb-video` (`<iframe>` YouTube, VK Видео, Rutube, Twitch), `bb-size-1` ... `bb-size-7`, `bb-mention`. Стили для них лаунчер задаёт сам (можно взять из `public/assets/css/base.css`, раздел «BB-коды»). Ссылки лучше открывать во внешнем браузере.

### Пример запроса

C# (WPF):

```csharp
using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
var json = await http.GetStringAsync("https://example.com/api/news.php?limit=5");
var news = JsonSerializer.Deserialize<NewsResponse>(json, new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
```

React (WebView2):

```js
const r = await fetch('https://example.com/api/news.php?limit=5');
const data = await r.json();
if (data.ok) setNews(data.items);
```

## RSS

```
GET /forum/rss.php            - последние темы всех открытых разделов
GET /forum/rss.php?node=11    - раздел вместе с подразделами
```

RSS 2.0, 20 последних тем, в описании - начало первого сообщения простым текстом. Закрытый для гостей раздел - ответ 404. Кэш - 5 минут. Ссылка на ленту есть в `<head>` и кнопкой «RSS» в каждом открытом разделе.
