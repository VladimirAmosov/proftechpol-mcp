#!/usr/bin/env python3
# Генератор mcp-data.json для MCP-эндпоинта /mcp (план AI-поиск 09.10, mcp.php).
# Источники: src/Config/faq_blocks.json (FAQ клиентов) + курируемые списки услуг/гео ниже.
# Ценовые якоря — ТОЛЬКО публичные «от X/м²» из seo_overrides desc (ценовой гейт владельца).
# Запуск: python3 scripts/build-mcp-data.py (из корня ptp_php) → mcp-data.json, затем деплой mcp-data.json.
# Обновлять: при изменении faq_blocks.json, услуг, гео-хабов или публичных цен.
import json
faq = json.load(open("src/Config/faq_blocks.json"))
services = json.load(open("scripts/mcp-services.json"))
geo = json.load(open("scripts/mcp-geo.json"))
cases = json.load(open("scripts/mcp-cases.json"))
faq_pages = []
for route, block in faq.items():
    items = [{"q": it["q"], "a": it["a"][:700]} for it in block.get("items", [])]
    if items:
        faq_pages.append({"page": route, "url": "https://proftechpol.ru" + route, "count": len(items), "items": items})
data = {
  "company": {"name": "Проект ПРОФТЕХПОЛ", "domain": "https://proftechpol.ru",
              "about": "Проектная подрядная организация: устройство и ремонт промышленных полов, опыт более 15 лет. Более 250 объектов, 18 городов Москвы и МО.",
              "phones": ["+7 (495) 248-13-80", "+7 (800) 550-90-72"], "email": "info@proftechpol.ru",
              "facts": {
                "canonical_name": "Проект ПРОФТЕХПОЛ",
                "legal_name": "ООО «ПРОФТЕХИНЖИНИРИНГ»",
                "experience": "более 15 лет (страница /about)",
                "completed_objects": "250+ (портфолио сайта)",
                "portfolio_cases_in_data": 262,
                "cities_served": "18 городов Москвы и МО (/geografiya-rabot)",
                "warranty": "гарантия качества по договору (/about)",
                "office_address": "Москва, ул. Б. Андроньевская, д. 23, корп. 1",
                "sources": "все факты опубликованы на proftechpol.ru; проверки — страницы в скобках"
              },
              "updated": __import__("datetime").date.today().isoformat()},
  "services": services, "geo": geo, "cases": cases, "faq_pages": faq_pages,
}
json.dump(data, open("mcp-data.json", "w"), ensure_ascii=False, indent=1)
print("mcp-data.json:", len(services), "услуг,", len(geo), "городов,", len(cases), "кейсов,", len(faq_pages), "FAQ-страниц,", sum(p["count"] for p in faq_pages), "вопросов")
