import json
# Генератор sql/seed.sql: python3 sql/gen_seed.py (правки стартовых данных - здесь, потом перегенерировать)
def q(v):
    if v is None: return 'NULL'
    if isinstance(v, bool): return '1' if v else '0'
    if isinstance(v, int): return str(v)
    s = str(v).replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n')
    return "'" + s + "'"

out = ["-- Стартовые данные World Role Play (группы, разделы, префиксы, настройки, база знаний)", "SET NAMES utf8mb4;", ""]

def ins(table, rows):
    cols = list(rows[0].keys())
    out.append(f"INSERT INTO `{table}` (`" + "`, `".join(cols) + "`) VALUES")
    vals = []
    for r in rows:
        vals.append("  (" + ", ".join(q(r[c]) for c in cols) + ")")
    out.append(",\n".join(vals) + ";")
    out.append("")

groups = [
 (1,'Гость','#9aa0b4',0,0,0,0,1,0,1),
 (2,'Пользователь','#b8bccb',10,0,0,0,1,1,2),
 (3,'Заблокирован','#6b7280',0,0,0,0,1,1,3),
 (4,'Лидер','#ff4d6d',20,0,0,0,0,1,4),
 (5,'Хелпер','#38bdf8',30,1,0,0,0,1,5),
 (6,'Модератор форума','#22c55e',40,1,1,0,0,1,6),
 (7,'Администратор','#f59e0b',60,1,1,0,0,1,7),
 (8,'Старший администратор','#fb923c',80,1,1,0,0,1,8),
 (9,'Разработчик','#a78bfa',90,1,1,1,0,1,9),
 (10,'Главный администратор','#ef4444',100,1,1,1,1,1,10),
]
ins('user_groups', [dict(id=g[0],name=g[1],color=g[2],level=g[3],is_staff=g[4],can_moderate=g[5],can_admin=g[6],is_system=g[7],show_banner=g[8],display_order=g[9]) for g in groups])

prefixes = [
 (1,'На рассмотрении','yellow'),(2,'Одобрено','green'),(3,'Отказано','red'),(4,'Закрыто','gray'),
 (5,'Рассмотрено','green'),(6,'Решено','green'),(7,'Важно','red'),(8,'Информация','blue'),
 (9,'Обновление','purple'),(10,'Мероприятие','pink'),(11,'На обработке','light'),(12,'RP биография','teal'),
]
ins('prefixes', [dict(id=p[0],title=p[1],color=p[2],display_order=i+1) for i,p in enumerate(prefixes)])

T_PLAYER = """[b]1. Ваш игровой ник:[/b] 
[b]2. Игровой ник нарушителя:[/b] 
[b]3. Суть нарушения:[/b] 
[b]4. Дата и время нарушения (МСК):[/b] 
[b]5. Доказательства (скриншоты, видео):[/b] """
T_ADMIN = """[b]1. Ваш игровой ник:[/b] 
[b]2. Игровой ник администратора:[/b] 
[b]3. Выданное наказание и его причина:[/b] 
[b]4. Суть жалобы:[/b] 
[b]5. Дата и время (МСК):[/b] 
[b]6. Скриншот выдачи наказания:[/b] 
[b]7. Скриншот /time при входе в игру:[/b] """
T_LEADER = """[b]1. Ваш игровой ник:[/b] 
[b]2. Игровой ник лидера или заместителя:[/b] 
[b]3. Организация:[/b] 
[b]4. Суть жалобы:[/b] 
[b]5. Дата и время (МСК):[/b] 
[b]6. Доказательства (скриншоты, видео):[/b] """
T_APPEAL = """[b]1. Ваш игровой ник:[/b] 
[b]2. Ник администратора, выдавшего наказание:[/b] 
[b]3. Наказание (бан, мут, варн, деморган) и срок:[/b] 
[b]4. Причина наказания:[/b] 
[b]5. Почему наказание стоит снять или смягчить:[/b] 
[b]6. Скриншот наказания:[/b] """
T_LEADAPP = """[b]1. Игровой ник:[/b] 
[b]2. Реальный возраст:[/b] 
[b]3. Игровой уровень:[/b] 
[b]4. На какую организацию претендуете:[/b] 
[b]5. Опыт лидерства (где и когда):[/b] 
[b]6. Сколько часов в день готовы играть:[/b] 
[b]7. Наказания за последние 30 дней:[/b] 
[b]8. Почему именно вы:[/b] """
T_ADMAPP = """[b]1. Игровой ник:[/b] 
[b]2. Реальный возраст:[/b] 
[b]3. Игровой уровень:[/b] 
[b]4. Опыт работы в администрации (где и когда):[/b] 
[b]5. Знание правил сервера (оцените от 1 до 10):[/b] 
[b]6. Сколько часов в день готовы уделять серверу:[/b] 
[b]7. Наказания за последние 30 дней:[/b] 
[b]8. Почему именно вы:[/b] """
T_TECH = """[b]1. Игровой ник:[/b] 
[b]2. Опишите проблему:[/b] 
[b]3. Когда она началась:[/b] 
[b]4. Что уже пробовали сделать:[/b] 
[b]5. Скриншоты или видео:[/b] """
T_BUG = """[b]1. Игровой ник:[/b] 
[b]2. Где найдена ошибка:[/b] 
[b]3. Как её повторить (по шагам):[/b] 
[b]4. Что должно было произойти:[/b] 
[b]5. Скриншоты или видео:[/b] """
T_IDEA = """[b]1. Суть предложения:[/b] 
[b]2. Что изменится в игре:[/b] 
[b]3. Почему это сделает игру лучше:[/b] """
T_BIO = """[b]Имя и фамилия:[/b] 
[b]Дата и место рождения:[/b] 
[b]Детство:[/b] 
[b]Юность:[/b] 
[b]Взрослая жизнь:[/b] 
[b]Настоящее время:[/b] """


SHOT_HINT = 'Загрузите скриншот на imgur.com или yapx.ru и вставьте ссылку.'
VIDEO_HINT = 'Ссылка на видео (YouTube) или скриншот.'
AGREE = {"key":"agree","label":"Готов нести ответственность в случае обмана","type":"checkbox","text":"Да","required":True,
  "hint":"Вы подтверждаете, что всё написанное - правда, и готовы понести наказание, если обман будет доказан."}
FORMS = {
 52: {"title":"Жалоба на {offender} | {rule}","fields":[
   {"key":"nick","label":"Ваш игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"offender","label":"Игровой ник нарушителя","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"rule","label":"Нарушение","type":"text","required":True,"placeholder":"Например: DM в зелёной зоне","max":100},
   {"key":"essence","label":"Суть жалобы","type":"textarea","required":True},
   {"key":"date","label":"Дата нарушения","type":"date","required":True},
   {"key":"time","label":"Время нарушения (МСК)","type":"text","placeholder":"21:10","max":20},
   {"key":"proof","label":"Доказательства","type":"url","required":True,"hint":VIDEO_HINT},
   AGREE]},
 53: {"title":"Жалоба на администратора {admin} | Причина: {reason}","fields":[
   {"key":"kind","label":"Тип жалобы","type":"radio","required":True,"options":["Жалоба на администрацию","Жалоба на старшую администрацию"]},
   {"key":"nick","label":"Ваш игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"admin","label":"Игровой ник администратора","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"reason","label":"Причина наказания","type":"text","required":True,"max":100,"hint":"Причину наказания можно посмотреть в игре в истории наказаний."},
   {"key":"essence","label":"Суть жалобы","type":"textarea","required":True},
   {"key":"proof","label":"Скриншот истории наказания","type":"url","hint":SHOT_HINT},
   {"key":"proof2","label":"Скриншот при входе в игру (при бане)","type":"url","hint":SHOT_HINT},
   {"key":"date","label":"Дата выдачи наказания","type":"date","required":True},
   AGREE]},
 54: {"title":"Жалоба на лидера {leader} | {org}","fields":[
   {"key":"nick","label":"Ваш игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"leader","label":"Ник лидера или заместителя","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"org","label":"Организация","type":"text","required":True,"max":60},
   {"key":"essence","label":"Суть жалобы","type":"textarea","required":True},
   {"key":"date","label":"Дата нарушения","type":"date","required":True},
   {"key":"proof","label":"Доказательства","type":"url","required":True,"hint":VIDEO_HINT},
   AGREE]},
 55: {"title":"Обжалование наказания {nick} | {punishment}","fields":[
   {"key":"nick","label":"Ваш игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"admin","label":"Ник администратора, выдавшего наказание","type":"text","required":True,"max":32},
   {"key":"punishment","label":"Наказание","type":"select","required":True,"options":["Бан","Варн","Мут","Деморган","Тюрьма","Другое"]},
   {"key":"term","label":"Срок наказания","type":"text","required":True,"placeholder":"Например: 3 дня","max":40},
   {"key":"reason","label":"Причина наказания","type":"text","required":True,"max":100},
   {"key":"why","label":"Почему наказание стоит снять или смягчить","type":"textarea","required":True},
   {"key":"proof","label":"Скриншот наказания","type":"url","hint":SHOT_HINT},
   {"key":"date","label":"Дата выдачи наказания","type":"date","required":True},
   AGREE]},
 67: {"title":"Заявление на пост лидера | {org} | {nick}","fields":[
   {"key":"nick","label":"Игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"org","label":"Организация","type":"text","required":True,"max":60},
   {"key":"age","label":"Реальный возраст","type":"number","required":True,"max":3},
   {"key":"level","label":"Игровой уровень","type":"number","required":True,"max":4},
   {"key":"exp","label":"Опыт лидерства (где и когда)","type":"textarea","required":True},
   {"key":"hours","label":"Сколько часов в день готовы играть","type":"text","required":True,"max":40},
   {"key":"punish","label":"Наказания за последние 30 дней","type":"text","required":True,"placeholder":"Нет / перечислите","max":200},
   {"key":"why","label":"Почему именно вы","type":"textarea","required":True},
   {"key":"discord","label":"Discord для связи","type":"text","max":64},
   AGREE]},
 68: {"title":"Заявление в администрацию | {nick}","fields":[
   {"key":"nick","label":"Игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"age","label":"Реальный возраст","type":"number","required":True,"max":3},
   {"key":"level","label":"Игровой уровень","type":"number","required":True,"max":4},
   {"key":"exp","label":"Опыт в администрации (где и когда)","type":"textarea","required":True},
   {"key":"rules","label":"Знание правил сервера","type":"select","required":True,"options":["Отлично","Хорошо","Средне"]},
   {"key":"hours","label":"Сколько часов в день готовы уделять серверу","type":"text","required":True,"max":40},
   {"key":"punish","label":"Наказания за последние 30 дней","type":"text","required":True,"placeholder":"Нет / перечислите","max":200},
   {"key":"why","label":"Почему именно вы","type":"textarea","required":True},
   {"key":"discord","label":"Discord для связи","type":"text","required":True,"max":64},
   AGREE]},
 21: {"title":"{problem}","fields":[
   {"key":"nick","label":"Игровой ник","type":"text","required":True,"placeholder":"Имя_Фамилия","max":32},
   {"key":"problem","label":"Кратко о проблеме","type":"text","required":True,"placeholder":"Например: вылет при входе на сервер","max":100},
   {"key":"essence","label":"Подробное описание","type":"textarea","required":True},
   {"key":"tried","label":"Что уже пробовали сделать","type":"textarea"},
   {"key":"proof","label":"Скриншот или видео","type":"url","hint":SHOT_HINT}]},
}

def node(id, parent, type, title, desc='', icon='chats', order=0, view=0, thread=10, reply=10, hide=0, closed=0, quick=0, req=0, defp=None, hint=None, tpl=None):
    return dict(id=id,parent_id=parent,type=type,title=title,description=desc,icon=icon,display_order=order,view_level=view,thread_level=thread,reply_level=reply,hide_if_no_access=hide,is_closed=closed,quick_nav=quick,require_prefix=req,default_prefix_id=defp,title_hint=hint,thread_template=tpl,form_json=(json.dumps(FORMS[id], ensure_ascii=False) if id in FORMS else None))

nodes = [
 node(1,None,'category','Главный раздел',order=1),
 node(10,1,'forum','Новости и информация','Обновления сервера, анонсы и важные объявления администрации','megaphone',1,thread=80,reply=80),
 node(11,10,'forum','Обновления сервера','Списки изменений после каждого обновления мода','refresh',1,thread=80,reply=10),
 node(12,10,'forum','Мероприятия и конкурсы','Анонсы ивентов, конкурсов и их итоги','star',2,thread=40,reply=10),
 node(20,1,'forum','Технический раздел','Помощь с игрой, клиентом и ошибками мода','wrench',2,thread=80),
 node(21,20,'forum','Техническая поддержка','Не запускается игра, вылеты, проблемы со входом','help',1,quick=9,defp=11,hint='Кратко опишите проблему',tpl=T_TECH),
 node(22,20,'forum','Баги и ошибки мода','Нашли ошибку в игре - опишите, как её повторить','alert',2,defp=11,hint='Где и в чём ошибка',tpl=T_BUG),
 node(30,1,'forum','Правила проекта','Правила сервера, форума и организаций','rules',3,thread=80,reply=80,quick=1),
 node(31,30,'forum','Общие правила сервера','','rules',1,thread=80,reply=80),
 node(32,30,'forum','Правила форума','','rules',2,thread=80,reply=80,quick=2),
 node(33,30,'forum','Правила государственных организаций','','shield',3,thread=80,reply=80),
 node(34,30,'forum','Правила криминальных организаций','','flag',4,thread=80,reply=80),
 node(40,1,'forum','Предложения по улучшению игры (мода)','Идеи для сервера: новые системы, работы, изменения баланса','lightbulb',4,defp=1,hint='Коротко о предложении',tpl=T_IDEA),
 node(41,40,'forum','Рассмотренные предложения','Предложения, по которым принято решение','check-all',1,thread=40),

 node(2,None,'category','Раздел игрового сервера',order=2),
 node(50,2,'forum','Сервер Los Santos','Всё о жизни на сервере: жалобы, организации, заявления, торговля','server',1,thread=80),
 node(51,50,'forum','Жалобы','Жалобы на игроков, администрацию и лидеров, обжалование наказаний','flag',1,thread=80,quick=0),
 node(52,51,'forum','Жалобы на игроков','Нарушение правил сервера игроками','flag',1,quick=3,req=1,defp=1,hint='Жалоба на Nick_Name',tpl=T_PLAYER),
 node(53,51,'forum','Жалобы на администрацию','Неверное наказание или поведение администратора','shield',2,quick=4,req=1,defp=1,hint='Жалоба на администратора Nick_Name | Причина',tpl=T_ADMIN),
 node(54,51,'forum','Жалобы на лидеров и заместителей','Нарушения со стороны руководства Правительства и Департамента полиции','crown',3,quick=5,req=1,defp=1,hint='Жалоба на лидера Nick_Name | Организация',tpl=T_LEADER),
 node(55,51,'forum','Обжалование наказаний','Просьбы о снятии или смягчении наказания','ban',4,quick=6,req=1,defp=1,hint='Обжалование наказания Nick_Name',tpl=T_APPEAL),
 node(56,50,'forum','Государственные организации','Правительство и Департамент полиции','building',2,thread=80),
 node(57,56,'forum','Правительство','Мэрия Лос-Сантоса: новости, собеседования, заявления','building',1),
 node(58,56,'forum','Департамент полиции','Новости департамента, набор в академию, отчёты','shield',2),
 node(67,50,'forum','Заявления на пост лидера','Хотите возглавить организацию - подайте заявление','crown',4,quick=7,req=1,defp=1,hint='Заявление на пост лидера | Организация',tpl=T_LEADAPP),
 node(68,50,'forum','Набор в администрацию','Заявления в команду администрации сервера','shield',5,quick=8,req=1,defp=1,hint='Заявление в администрацию | Nick_Name',tpl=T_ADMAPP),
 node(69,50,'forum','Торговая площадка','Купля и продажа домов, гаражей и личного транспорта','briefcase',6),

 node(3,None,'category','Прочее',order=3),
 node(80,3,'forum','Игровое комьюнити','Общение, творчество и истории персонажей','users',1,thread=80),
 node(81,80,'forum','Общение','Разговоры обо всём','chat',1),
 node(82,80,'forum','Творчество','Скриншоты, видео, арты и мувики с сервера','image',2),
 node(83,80,'forum','RP биографии','Истории ваших персонажей','book',3,defp=1,hint='RP биография | Имя Фамилия',tpl=T_BIO),
 node(85,3,'forum','Сотрудничество','Для блогеров и контент-мейкеров','handshake',2),
 node(90,3,'forum','Раздел администрации','Служебный раздел команды сервера','lock',3,view=30,thread=30,reply=30),
]
ins('nodes', nodes)

np = []
def link(nodes_, prefs):
    for n in nodes_:
        for p in prefs:
            np.append(dict(node_id=n, prefix_id=p))
link([52,53,54,55,67,68], [1,2,3,4])
link([21,22], [11,6,4])
link([40,41], [1,5,3])
link([10,11,12,31,32,33,34], [7,8,9,10])
link([83], [12,1,2,3])
ins('node_prefixes', np)

settings = dict(news_node_id='11', rules_node_id='30', complaints_node_id='51', tech_node_id='20', server_name='World Role Play | Los Santos')
ins('settings', [dict(k=k, v=v) for k, v in settings.items()])

nav = [
 ('Правила проекта','/forum/forum.php?id=30','rules',1,0),
 ('Жалобы','/forum/forum.php?id=51','flag',2,0),
 ('Тех.раздел','/forum/forum.php?id=20','wrench',3,0),
 ('Discord','https://discord.com/','discord',4,1),
 ('Сотрудничество','/partners.php','handshake',5,0),
]
ins('nav_links', [dict(title=a,url=b,icon=c,display_order=d,new_tab=e) for a,b,c,d,e in nav])

wcats = [
 (1,'Начало игры','start','gamepad','Лаунчер, первый вход, ник и основы RP',1),
 (2,'Дома и имущество','property','building','Покупка дома, налог, аукцион, гаражи',2),
 (3,'Транспорт','transport','car','Автосалон WORLD MOTORS, личные машины, прокат',3),
 (4,'Банк','bank','briefcase','Счёт в World Bank, банкоматы, переводы',4),
 (5,'Организации','orgs','users','Правительство и Департамент полиции',5),
 (6,'Команды','commands','code','Полезные команды сервера',6),
]
ins('wiki_categories', [dict(id=a,title=b,slug=c,icon=d,description=e,display_order=f) for a,b,c,d,e,f in wcats])

A_START = """[b]Что нужно:[/b] компьютер с Windows и GTA San Andreas версии 1.0 (US).

[b]Шаг 1. Лаунчер World RP.[/b] Скачайте лаунчер на странице [url=/start.php]Начать игру[/url]. Он сам поставит клиент, интерфейс World RP и все файлы сервера, проверит их и будет обновлять. Руками ничего ставить не нужно.

[b]Шаг 2. Аккаунт.[/b] Зарегистрируйтесь прямо в лаунчере: ник в формате [icode]Имя_Фамилия[/icode] и надёжный пароль.

[b]Шаг 3. Игра.[/b] Нажмите «Играть» - лаунчер сам подключит вас к серверу, вводить пароль в игре не нужно.

[b]Шаг 4. Первый вход.[/b] Новый персонаж прилетает в аэропорт Лос-Сантоса. Выберите внешность, выйдите из терминала прилёта и подойдите к стойке Центра адаптации (ALT) - там подскажут, с чего начать.

[spoiler=Если что-то не работает]
[list]
[*]Лаунчер пишет, что файлы игры не подходят - нужна GTA San Andreas 1.0 US.
[*]Лаунчер убрал сторонние файлы (CLEO, ASI-скрипты) - это нормально: они лежат в карантине, их можно вернуть.
[*]Не получилось - создайте тему в разделе «Техническая поддержка».
[/list]
[/spoiler]"""
A_NICK = """Ник - это имя вашего персонажа. На RP сервере он должен быть похож на настоящее имя человека.

[b]Правильно:[/b] [icode]Michael_Brown[/icode], [icode]Ivan_Petrov[/icode]
[b]Неправильно:[/b] [icode]Killer_2007[/icode], [icode]Pro_Gamer[/icode], [icode]Vasya[/icode]

[list]
[*]Имя и фамилия пишутся латиницей через нижнее подчёркивание.
[*]Нельзя использовать имена известных людей и оскорбительные слова.
[*]Цифры и лишние символы в нике не допускаются.
[/list]"""
A_TERMS = """[b]RP (Role Play)[/b] - игра по ролям: вы ведёте себя как житель города, а не как игрок.

[b]IC (In Character)[/b] - всё, что происходит в игре с вашим персонажем.
[b]OOC (Out Of Character)[/b] - всё, что не относится к игре.

[b]MG (MetaGaming)[/b] - использование в игре информации, которую персонаж знать не может (из Discord, стрима, ника над головой).
[b]DM (DeathMatch)[/b] - убийство или нападение без RP-причины.
[b]DB (DriveBy)[/b] - убийство или нанесение урона с транспорта без причины.
[b]PG (PowerGaming)[/b] - нереалистичные действия: бежать с тяжёлым ранением, драться одному против пятерых без страха.
[b]SK (SpawnKill)[/b] - убийство на месте появления.
[b]RK (RevengeKill)[/b] - месть за свою смерть после возрождения.

Точные правила и наказания - в разделе «Правила проекта» на форуме."""
A_HOUSES = """В Лос-Сантосе больше тысячи домов - от небольших квартир до особняков класса S.

[b]Покупка.[/b] Подойдите к дому, который продаётся, и введите [icode]/buyhouse[/icode]. У одного игрока - один дом.

[b]Внутри.[/b] Войти и выйти - ALT у двери или [icode]/enter[/icode] и [icode]/exit[/icode]. Сейф, склад, подвал, бар, мебель и комнаты в аренду - в меню дома [icode]/hmenu[/icode].

[b]Налог.[/b] За дом каждый день начисляется налог, оплата - [icode]/paytax[/icode]. Если просрочить больше трёх дней, дом изымут и выставят на аукцион.

[b]Аукцион.[/b] Изъятые и выставленные на продажу дома уходят с аукциона, ставка - [icode]/bid[/icode]. Делать ставку можно, только если своего дома нет.

[b]Продажа.[/b] [icode]/sellhouse[/icode] - продать дом государству. Деньги из сейфа и вещи со склада возвращаются владельцу, а то, что не поместилось в инвентарь, можно забрать командой [icode]/vozvrat[/icode]. Перед продажей заберите машины из гаража дома ([icode]/hcar[/icode]).

[b]Гаражи.[/b] Отдельный гараж покупается командой [icode]/buygarage[/icode]. Поставить машину - [icode]/storeveh[/icode], въехать и выехать - [icode]/gin[/icode] и [icode]/gout[/icode], меню - [icode]/garage[/icode]."""
A_CARS = """[b]Автосалон WORLD MOTORS[/b] - в Вайнвуде. Подойдите к машине на витрине и нажмите ALT: её можно купить или взять бесплатный тест-драйв на 3 минуты. Полный каталог - у стойки ресепшена.

[b]Личные машины.[/b] У игрока может быть до 3 машин. Они остаются там, где вы их оставили. Список, поиск на карте, замок, эвакуатор и продажа - [icode]/car[/icode]. Закрыть свою машину рядом - [icode]/lock[/icode].

[b]Управление.[/b] Двигатель - клавиша 2 или [icode]/engine[/icode], фары - [icode]/lights[/icode]. Следите за топливом и состоянием машины.

[b]Продажа.[/b] Продать машину игроку - [icode]/sellcar[/icode]. Сдать машину салону можно в зоне «Приёмка».

[b]Прокат.[/b] Пока своей машины нет, транспорт можно взять в пункте проката."""
A_BANK = """У каждого персонажа есть счёт в [b]World Bank[/b].

[b]Банкоматы[/b] стоят у мэрии, у Департамента полиции и в аэропорту. Подойдите и нажмите ALT или введите [icode]/bank[/icode].

[b]Что можно сделать:[/b]
[list]
[*]положить и снять наличные;
[*]перевести деньги другому игроку по нику (комиссия 1%);
[*]посмотреть историю операций.
[/list]

[b]Зарплата[/b] сотрудников организаций приходит на счёт в PayDay."""
A_ORGS = """Сейчас в городе работают две государственные организации.

[b]Правительство[/b] - мэрия Лос-Сантоса, вход с террасы на площади Першинг. 12 рангов: от стажёра мэрии до мэра Лос-Сантоса.

[b]Департамент полиции[/b] - 14 рангов: от курсанта академии до шефа полиции. Форма выдаётся по рангу.

Зарплата растёт с рангом и приходит на банковский счёт в PayDay. У организаций свои здания и служебный транспорт - водить его можно на службе.

[b]Команды сотрудника:[/b] [icode]/duty[/icode] - начать или закончить службу, [icode]/r[/icode] - рация организации, [icode]/d[/icode] - общая волна госструктур, [icode]/members[/icode] - кто из коллег в игре, [icode]/orgs[/icode] - список организаций, [icode]/leaveorg[/icode] - уйти из организации.
[b]Команды лидера:[/b] [icode]/invite[/icode], [icode]/uninvite[/icode], [icode]/giverank[/icode].

Наборы и собеседования объявляются в разделах организаций на форуме."""
A_CMDS = """[b]Основное[/b]
[list]
[*][icode]/mm[/icode] - главное меню
[*][icode]/stats[/icode] - статистика персонажа
[*][icode]/inv[/icode] - инвентарь
[*][icode]/passport[/icode] - паспорт
[*][icode]/gps[/icode] - навигатор
[*][icode]/settings[/icode] - настройки: интерфейс, спидометр, место появления
[*][icode]/time[/icode] - время
[*][icode]/me[/icode] - действие персонажа
[*][icode]/admins[/icode] - администрация в игре
[/list]

[b]Дом и гараж[/b]
[list]
[*][icode]/buyhouse[/icode], [icode]/sellhouse[/icode], [icode]/houseinfo[/icode] - покупка, продажа, информация
[*][icode]/hmenu[/icode] - меню дома, [icode]/safe[/icode] - сейф, [icode]/sklad[/icode] - склад, [icode]/podval[/icode] - подвал
[*][icode]/lock[/icode] - закрыть или открыть дом (или свою машину рядом)
[*][icode]/paytax[/icode] - налог, [icode]/bid[/icode] - ставка на аукционе
[*][icode]/rentroom[/icode], [icode]/leaveroom[/icode], [icode]/rooms[/icode], [icode]/setrooms[/icode] - аренда комнат
[*][icode]/hcar[/icode], [icode]/hcarstore[/icode] - гараж дома
[*][icode]/buygarage[/icode], [icode]/garage[/icode], [icode]/storeveh[/icode], [icode]/gin[/icode], [icode]/gout[/icode] - отдельный гараж
[*][icode]/vozvrat[/icode] - забрать вернувшиеся вещи
[/list]

[b]Транспорт[/b]
[list]
[*][icode]/car[/icode] - мои машины, [icode]/sellcar[/icode] - продать машину игроку
[*][icode]/engine[/icode] (клавиша 2) - двигатель, [icode]/lights[/icode] - фары
[/list]

[b]Банк и организации[/b]
[list]
[*][icode]/bank[/icode] - банк у банкомата
[*][icode]/duty[/icode], [icode]/r[/icode], [icode]/d[/icode], [icode]/members[/icode], [icode]/orgs[/icode], [icode]/leaveorg[/icode]
[/list]"""
warts = [
 (1,'Как начать играть','kak-nachat-igrat','Лаунчер, аккаунт, первый вход в аэропорту Лос-Сантоса',A_START,1),
 (1,'Правильный RP ник','pravilnyy-rp-nik','Каким должен быть ник персонажа',A_NICK,2),
 (1,'Основные RP термины','osnovnye-rp-terminy','IC, OOC, MG, DM, PG и другие понятия',A_TERMS,3),
 (2,'Дома, налог и аукцион','doma-nalog-aukcion','Покупка, меню дома, налог, аукцион, гаражи',A_HOUSES,1),
 (3,'Автосалон и личные машины','avtosalon-i-mashiny','WORLD MOTORS, до 3 машин, управление, продажа, прокат',A_CARS,1),
 (4,'World Bank','world-bank','Банкоматы, переводы, зарплата на счёт',A_BANK,1),
 (5,'Государственные организации','gosudarstvennye-organizacii','Правительство и Департамент полиции, ранги, команды',A_ORGS,1),
 (6,'Команды для игроков','komandy-dlya-igrokov','Все основные команды сервера',A_CMDS,1),
]
ins('wiki_articles', [dict(category_id=a,title=b,slug=c,summary=d,body=e,author_id=None,display_order=f,is_published=1,views=0,created_at='2026-10-06 00:00:00',updated_at='2026-10-06 00:00:00') for a,b,c,d,e,f in warts])

import os
open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'seed.sql'),'w',encoding='utf-8').write("\n".join(out))
print("ok", len(nodes), "nodes")
