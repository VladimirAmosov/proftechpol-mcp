#!/usr/bin/env python3
# build-llms-full.py — llms-full.txt: полный индекс ключевых страниц proftechpol.ru
# для ИИ-агентов (llms.txt даёт карту, llms-full — тексты целиком; спека llmstxt.org).
# Тянет живые страницы, извлекает контент в markdown (h1-h4 → #, p/li/td → строки),
# без навигации/скриптов/футера (вырезаем по классам-маркерам шапки/подвала грубо).
# Запуск: python3 scripts/build-llms-full.py  (из корня ptp_php) → llms-full.txt.
# Обновлять при значимых контентных изменениях (после freshness-волн, новых услуг).
import re, urllib.request, html
from datetime import date

BASE = "https://proftechpol.ru"
PAGES = [
    ("Организация", "/about"),
    ("Производственные полы", "/proizvodstvenye-poly"),
    ("Топпинговые полы", "/topping-poly"),
    ("Ремонт промышленных полов", "/remont"),
    ("Обеспыливание бетонного пола", "/obespylivanie-betona"),
    ("Бетонная подготовка и стяжка", "/betonnaya-podgotovka-pola"),
    ("Шлифовка и фрезеровка", "/shlifovka-betonnogo-pola"),
    ("Полы для склада", "/poly-dlya-sklada"),
    ("Полы для ангаров", "/poly-dlya-angarov"),
    ("Полы для паркингов", "/poly-dlya-parkingov"),
    ("Полы для автосервисов", "/poly-dlya-avtoservisov"),
    ("Полы для холодильных камер", "/poly-dlya-holodilnyh-kamer"),
    ("Полы для пищевого производства", "/poly-dlya-pishchevogo-proizvodstva"),
    ("Полы для медицинских учреждений", "/encyclopedia/poly-dlya-medicinskih-uchrezhdeniy"),
    ("Полиуретан-цементные полы", "/poliuretan-cementnye-poly"),
    ("Эпоксидные полы", "/epoksidnye-poly"),
    ("ММА полы", "/mma-poly"),
    ("География работ (18 городов)", "/geografiya-rabot"),
    ("Калькулятор промышленного пола", "/calculator-promyshlennogo-pola"),
    ("Контакты", "/contacts"),
    ("Гайд: Монтаж промышленных полов", "/encyclopedia/montazh-promyshlennyh-polov-rukovodstvo"),
    ("Гайд: Устройство промышленных бетонных полов", "/encyclopedia/ustroystvo-promyshlennyh-betonnyh-polov"),
    ("Гайд: Ремонт трещин в бетонном полу", "/encyclopedia/remont-treshchin-v-betonnom-polu"),
    ("Гайд: Топпинговое покрытие", "/encyclopedia/toppingovoe-pokrytie-betonnogo-pola"),
    ("Гайд: Беспыльные полы", "/encyclopedia/bespylevye-poly-polnoe-rukovodstvo"),
]

def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "ptp-llms-full-builder/1.0"})
    with urllib.request.urlopen(req, timeout=20) as r:
        return r.read().decode("utf-8", "replace")

def to_md(page_html):
    # вырезаем шум: скрипты/стилы/head/виджет звонка/формы-квизы грубо по тегам
    h = re.sub(r"<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>", " ", page_html, flags=re.S | re.I)
    h = re.sub(r"<!--.*?-->", " ", h, flags=re.S)
    out = []
    # заголовки
    h = re.sub(r"<h1[^>]*>(.*?)</h1>", lambda m: "\n# " + m.group(1) + "\n", h, flags=re.S | re.I)
    for lvl in (2, 3, 4):
        h = re.sub(rf"<h{lvl}[^>]*>(.*?)</h{lvl}>", lambda m, l=lvl: "\n" + "#" * l + " " + m.group(1) + "\n", h, flags=re.S | re.I)
    # таблицы → строки с |
    h = re.sub(r"<tr[^>]*>(.*?)</tr>", lambda m: "| " + " | ".join(re.sub(r"<[^>]+>", " ", c).strip() for c in re.findall(r"<t[dh][^>]*>(.*?)</t[dh]>", m.group(1), re.S)) + " |", h, flags=re.S | re.I)
    h = re.sub(r"<li[^>]*>", "\n- ", h, flags=re.I)
    h = re.sub(r"<(p|div|section|br|table|ul|ol)[^>]*>", "\n", h, flags=re.I)
    text = re.sub(r"<[^>]+>", " ", h)
    text = html.unescape(text)
    lines = [re.sub(r"\s+", " ", ln).strip() for ln in text.split("\n")]
    # шум навигации: строки-тире/цифры меню/одиночные символы после «- »
    lines = [ln for ln in lines if not re.fullmatch(r"-|[\d\s·|—–-]{1,4}", ln)]
    prev_blank = False
    for ln in lines:
        if not ln:
            if not prev_blank:
                out.append("")
            prev_blank = True
        else:
            out.append(ln)
            prev_blank = False
    return "\n".join(out).strip()

parts = [
    "# Проект ПРОФТЕХПОЛ — полные тексты ключевых страниц (llms-full)\n",
    f"> Сгенерировано {date.today().isoformat()} из живого сайта {BASE}. Карта сайта — {BASE}/llms.txt. "
    "MCP-сервер для агентов: https://proftechpol.ru/mcp (list_services / get_service / list_geo / find_cases / faq / create_lead / get_facts).\n",
]
ok = 0
for title, path in PAGES:
    url = BASE + path
    try:
        md = to_md(fetch(url))
        if len(md) < 400:
            raise RuntimeError(f"контент подозрительно мал ({len(md)} знаков)")
        parts.append(f"\n---\n\n# Страница: {title}\n\nURL: {url}\n\n{md}\n")
        ok += 1
    except Exception as e:
        parts.append(f"\n---\n\n# Страница: {title}\n\nURL: {url}\n\n[не удалось получить: {e}]\n")
open("llms-full.txt", "w").write("\n".join(parts))
print(f"llms-full.txt: {ok}/{len(PAGES)} страниц, {sum(len(p) for p in parts) // 1024} КБ")
