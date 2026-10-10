# proftechpol-mcp — MCP-сервер сайта «Проект ПРОФТЕХПОЛ» (промышленные полы, Москва и МО)

**Endpoint:** `https://proftechpol.ru/mcp` — MCP Streamable HTTP (stateless, без SSE; CORS открыт; GET → 405).

Read-only каталог подрядчика промышленных полов + канал заявок для ИИ-агентов:

| Инструмент | Что делает |
|---|---|
| `list_services` | 20 услуг/технологий с публичными ценовыми ориентирами «от X ₽/м²» |
| `get_service` | детально об услуге + топ-3 FAQ по теме |
| `list_geo` | 18 городов работ со счётчиками выполненных объектов |
| `find_cases` | 262 кейса (город, тип объекта, м², год, ссылка на карточку) |
| `faq` | 200 ответов инженера на вопросы клиентов (36 тем) |
| `create_lead` | заявка на расчёт — только с явного согласия пользователя (телефон обязателен); уходит в тот же конвейер лидов сайта (Telegram + CRM), защита от спама и rate-limit |

Без авторизации (только публичные данные); расчёт цен агентами не выполняется — расчёт делает инженер.

## Состав репозитория

- `mcp.php` — эндпоинт (PHP 8, без зависимостей; читает `mcp-data.json` рядом; ключ канала заявок `MCP_LEAD_KEY` задаётся в конфиге сайта и проверяется LeadGuard при внутренних POST в `/api/main/`).
- `scripts/fetch-mcp-cases.mjs` — выгрузка кейсов из Cockpit CMS (our_objects) в `scripts/mcp-cases.json`.
- `scripts/build-mcp-data.py` — сборка `mcp-data.json` (услуги + гео + кейсы + FAQ из `faq_blocks.json` сайта).

Данные обновляются перегенерацией и деплоем `mcp-data.json`. Полный контекст — на сайте: [llms.txt](https://proftechpol.ru/llms.txt).

MCP Inspector: `npx @modelcontextprotocol/inspector --cli https://proftechpol.ru/mcp --method tools/list`

© Проект ПРОФТЕХПОЛ (ООО «ПРОФТЕХИНЖИНИРИНГ»), proftechpol.ru · info@proftechpol.ru
