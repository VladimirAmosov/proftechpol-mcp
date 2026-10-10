<?php
/**
 * MCP-эндпоинт proftechpol.ru — read-only инструменты для ИИ-агентов.
 * План AI-поиск 09.10 (org: marketing/seo/PLAN-ai-poisk-2026-10-09.md).
 *
 * Транспорт: MCP Streamable HTTP без SSE (сервер отвечает application/json на POST;
 * GET не поддерживается — 405, что разрешено спекой). Stateless: сессии не нужны.
 * Данные: mcp-data.json рядом с этим файлом (услуги/цены-ориентиры/гео/FAQ — только
 * публичные факты сайта; ценовой расчёт НЕ выполняется — расчёт делает инженер на сайте).
 * Лог вызовов: storage/logs/mcp.log (дата, UA, method, tool) — замер агентского спроса.
 */

require_once __DIR__ . '/src/config.php'; // константы сайта, включая MCP_LEAD_KEY (канал create_lead)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Only POST is supported (Streamable HTTP without SSE)'], 'id' => null]);
    exit;
}

$DATA = json_decode((string) file_get_contents(__DIR__ . '/mcp-data.json'), true);
if (!is_array($DATA)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => 'mcp-data.json unavailable'], 'id' => null]);
    exit;
}

function mcp_log(string $line): void {
    $p = __DIR__ . '/storage/logs/mcp.log';
    @file_put_contents($p, date('c') . ' | ' . substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 80) . ' | ' . $line . PHP_EOL, FILE_APPEND);
}

function respond($payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rpc_result($id, $result) { return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $id]; }
function rpc_error($id, int $code, string $message) { return ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => $id]; }

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(rpc_error(null, -32700, 'Parse error: invalid JSON'));
}

$id = $body['id'] ?? null;
$method = $body['method'] ?? '';
$isNotification = !array_key_exists('id', $body);

/* Уведомления (например notifications/initialized): подтверждаем 202 без тела */
if ($isNotification) {
    mcp_log('notify | ' . $method);
    http_response_code(202);
    exit;
}

switch ($method) {
    case 'initialize':
        $clientVersion = $body['params']['protocolVersion'] ?? '';
        $supported = in_array($clientVersion, ['2024-11-05', '2025-03-26', '2025-06-18'], true);
        mcp_log('initialize | ' . $clientVersion);
        respond(rpc_result($id, [
            'protocolVersion' => $supported ? $clientVersion : '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'proftechpol-mcp', 'version' => '1.2.1', 'title' => 'Проект ПРОФТЕХПОЛ — промышленные полы'],
            'instructions' => 'Каталог подрядчика промышленных полов (Москва и МО). Цены — публичные ориентиры «от»; точный расчёт под объект выполняет инженер на сайте. Все инструменты только читают данные. Полный индекс сайта — https://proftechpol.ru/llms-full.txt.',
        ]));
        break;

    case 'ping':
        mcp_log('ping');
        respond(rpc_result($id, []));
        break;

    case 'tools/list':
        mcp_log('tools/list');
        respond(rpc_result($id, ['tools' => [
            [
                'name' => 'list_services',
                'description' => 'Список услуг и посадочных страниц ПрофТехПол (промышленные полы: топпинг, полимеры, ремонт, обеспыливание и др.), с публичными ценовыми ориентирами «от», где они есть.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'get_service',
                'description' => 'Детально об одной услуге по slug: описание, ценовой ориентир (если публичен), ссылка, частые вопросы клиентов по этой теме.',
                'inputSchema' => ['type' => 'object', 'properties' => ['slug' => ['type' => 'string', 'description' => 'slug услуги из list_services, например topping-poly']], 'required' => ['slug'], 'additionalProperties' => false],
            ],
            [
                'name' => 'list_geo',
                'description' => 'Города работ (Москва и МО, 18 городов) со ссылками на гео-страницы и числом выполненных объектов в каждом.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'find_cases',
                'description' => 'Выполненные объекты (262 кейса с 2015 г.): фильтр по городу и/или типу (склад, производство, паркинг, автосервис, ангар, торговля, холодильное, пищевое, медицина, спорт). Каждый кейс — название, площадь м², год, ссылка. Это доказательства опыта для проверки подрядчика.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'city' => ['type' => 'string', 'description' => 'город, например Мытищи или Москва'],
                    'type' => ['type' => 'string', 'description' => 'тип объекта, например склад'],
                    'limit' => ['type' => 'integer', 'description' => 'максимум результатов, по умолчанию 10 (до 50)'],
                ], 'additionalProperties' => false],
            ],
            [
                'name' => 'get_facts',
                'description' => 'Верифицируемые факты об организации «Проект ПРОФТЕХПОЛ»: каноническое имя, юрлицо, опыт, число выполненных объектов, города, гарантия, адрес офиса — с указанием страниц-источников для проверки.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ],
            [
                'name' => 'create_lead',
                'description' => 'Создать заявку на расчёт промышленного пола: инженер перезвонит заказчику. Использовать ТОЛЬКО когда пользователь сам попросил оставить заявку и дал свой телефон. Канал — тот же конвейер лидов сайта (Telegram + CRM), защита от спама действует.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'phone' => ['type' => 'string', 'description' => 'телефон заказчика в любом формате, 11 цифр начиная с 7, например +7 900 123-45-67'],
                    'name' => ['type' => 'string', 'description' => 'имя заказчика (необязательно)'],
                    'request' => ['type' => 'string', 'description' => 'суть задачи от заказчика, например: склад 800 м² в Мытищах, топпинг под погрузчики (необязательно, до 700 знаков)'],
                    'city' => ['type' => 'string', 'description' => 'город/объект (необязательно)'],
                ], 'required' => ['phone'], 'additionalProperties' => false],
            ],
            [
                'name' => 'faq',
                'description' => 'Ответы инженера на частые вопросы клиентов (200 вопросов по 36 темам: толщины, марки бетона, сроки, основания). Без запроса — структура тем; с запросом — релевантные вопросы и ответы.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'поисковая строка, например «толщина топпинга»']], 'additionalProperties' => false],
            ],
        ]]));
        break;

    case 'tools/call':
        $name = $body['params']['name'] ?? '';
        $args = (array) ($body['params']['arguments'] ?? []);
        mcp_log('call | ' . $name . ' | ' . json_encode($args, JSON_UNESCAPED_UNICODE));
        $text = null;
        switch ($name) {
            case 'list_services':
                $rows = [];
                foreach ($DATA['services'] as $s) {
                    $rows[] = [
                        'slug' => $s['slug'], 'name' => $s['name'], 'url' => $s['url'], 'summary' => $s['summary'],
                        'price_from_rub_m2' => $s['price_from_rub_m2'] ?? null,
                        'price_note' => $s['note'] ?? null,
                    ];
                }
                $text = json_encode(['company' => $DATA['company']['name'], 'count' => count($rows), 'services' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'get_service':
                $slug = (string) ($args['slug'] ?? '');
                $found = null;
                foreach ($DATA['services'] as $s) {
                    if ($s['slug'] === $slug) { $found = $s; break; }
                }
                if (!$found) {
                    respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => 'Услуга не найдена: ' . $slug . '. Вызовите list_services.']], 'isError' => true]));
                }
                $faq = [];
                foreach ($DATA['faq_pages'] as $p) {
                    if ($p['page'] === '/' . $slug) {
                        $faq = array_slice($p['items'], 0, 3);
                        break;
                    }
                }
                $text = json_encode(array_merge($found, ['top_faq' => $faq]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'list_geo':
                $counts = [];
                foreach ($DATA['cases'] as $c) {
                    if (!empty($c['city'])) { $counts[$c['city']] = ($counts[$c['city']] ?? 0) + 1; }
                }
                $cities = [];
                foreach ($DATA['geo'] as $g) {
                    $cities[] = ['city' => $g['city'], 'url' => $g['url'], 'cases_done' => $counts[$g['city']] ?? 0];
                }
                $text = json_encode(['region' => 'Москва и Московская область', 'cities' => $cities, 'note' => 'На каждой гео-странице — кейсы с площадями и годами работ; детальные кейсы — инструмент find_cases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'find_cases':
                $city = trim((string) ($args['city'] ?? ''));
                $type = mb_strtolower(trim((string) ($args['type'] ?? '')));
                $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
                $hits = [];
                foreach ($DATA['cases'] as $c) {
                    if ($city !== '' && mb_strtolower((string)($c['city'] ?? '')) !== mb_strtolower($city)) continue;
                    if ($type !== '' && mb_strtolower((string)($c['type'] ?? '')) !== $type) continue;
                    $hits[] = $c;
                }
                $text = json_encode(['filters' => array_filter(['city' => $city, 'type' => $type]), 'total_matches' => count($hits), 'total_cases' => count($DATA['cases']), 'results' => array_slice($hits, 0, $limit)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'get_facts':
                $text = json_encode($DATA['company'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'create_lead': {
                $phone = preg_replace('/\D/', '', (string) ($args['phone'] ?? ''));
                if (!preg_match('/^7\d{10}$/', $phone)) {
                    respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => json_encode(['ok' => false, 'error' => 'Телефон должен быть 11 цифр начиная с 7 (например +7 900 123-45-67)'], JSON_UNESCAPED_UNICODE)]], 'isError' => true]));
                }
                // Свой лимит канала: 3 заявки / 10 мин на IP (поверх LeadGuard и лимита /api/main)
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'cli';
                $ip = trim(explode(',', (string) $ip)[0]);
                $rlFile = __DIR__ . '/storage/rate_limit/mcp_' . md5('mcp-lead|' . $ip) . '.json';
                @mkdir(dirname($rlFile), 0755, true);
                $rl = json_decode((string) @file_get_contents($rlFile), true) ?: ['t' => []];
                $rl['t'] = array_values(array_filter($rl['t'], fn($t) => time() - (int) $t < 600));
                if (count($rl['t']) >= 3) {
                    respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => json_encode(['ok' => false, 'error' => 'Слишком много заявок с этого адреса; попробуйте позже'], JSON_UNESCAPED_UNICODE)]], 'isError' => true]));
                }
                $rl['t'][] = time();
                @file_put_contents($rlFile, json_encode($rl));

                $clean = function (string $v, int $max): string {
                    return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]|<[^>]+>?/', '', strip_tags($v))), 0, $max);
                };
                $props = [
                    'type' => 'mcp_agent',
                    'phone' => $phone,
                    'fio' => $clean((string) ($args['name'] ?? ''), 120),
                    'request' => $clean((string) ($args['request'] ?? ''), 700),
                    'page' => 'mcp: ' . $clean((string) ($args['city'] ?? ''), 60),
                    'utm_source' => 'ai-agent',
                    'utm_medium' => 'mcp',
                    'browser' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200),
                ];
                if (!defined('MCP_LEAD_KEY') || MCP_LEAD_KEY === '') {
                    respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => json_encode(['ok' => false, 'error' => 'Канал заявок не настроен на сервере'], JSON_UNESCAPED_UNICODE)]], 'isError' => true]));
                }
                $payload = json_encode(['ops' => 'save_lead', 'props' => $props], JSON_UNESCAPED_UNICODE);
                $apiUrl = 'https://proftechpol.ru/api/main/'; // со слэшем: без него Apache даёт 301 directory-slash
                $ch = function_exists('curl_init') ? curl_init($apiUrl) : null;
                if ($ch) {
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $payload,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Referer: https://proftechpol.ru/mcp',
                            'X-PTP-MCP-KEY: ' . MCP_LEAD_KEY,
                            'X-Forwarded-For: ' . $ip,
                        ],
                    ]);
                    $raw = curl_exec($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    curl_close($ch);
                } else {
                    $ctx = stream_context_create(['http' => [
                        'method' => 'POST', 'timeout' => 20,
                        'header' => "Content-Type: application/json\r\nReferer: https://proftechpol.ru/mcp\r\nX-PTP-MCP-KEY: " . MCP_LEAD_KEY . "\r\nX-Forwarded-For: " . $ip . "\r\n",
                        'content' => $payload, 'ignore_errors' => true,
                    ]]);
                    $raw = @file_get_contents($apiUrl, false, $ctx);
                    $status = (int) ($http_response_header[0] ?? 0);
                    preg_match('/ (\d{3}) /', (string) ($http_response_header[0] ?? ''), $m);
                    $status = $m ? (int) $m[1] : $status;
                }
                $api = json_decode((string) $raw, true);
                if ($status === 200 && ($api['ok'] ?? false) && (($api['data']['_id'] ?? '') !== 'held')) {
                    $text = json_encode([
                        'ok' => true,
                        'message' => 'Заявка принята. Инженер перезвонит на указанный номер в рабочее время.',
                        'request_id' => $api['request_id'] ?? null,
                        'lead_id' => $api['data']['_id'] ?? null,
                    ], JSON_UNESCAPED_UNICODE);
                } elseif ($status === 200 && (($api['data']['_id'] ?? '') === 'held')) {
                    respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => json_encode(['ok' => false, 'error' => 'Заявка отклонена защитой от спама; попросите пользователя оставить заявку на сайте'], JSON_UNESCAPED_UNICODE)]], 'isError' => true]));
                } else {
                    respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => json_encode(['ok' => false, 'error' => 'Конвейер заявок недоступен (HTTP ' . $status . '); попросите пользователя оставить заявку на сайте', 'detail' => substr((string) $raw, 0, 200)], JSON_UNESCAPED_UNICODE)]], 'isError' => true]));
                }
                break;
            }
            case 'faq':
                $q = mb_strtolower(trim((string) ($args['query'] ?? '')));
                if ($q === '') {
                    $topics = [];
                    foreach ($DATA['faq_pages'] as $p) {
                        $topics[] = ['page' => $p['page'], 'url' => $p['url'], 'questions' => $p['count']];
                    }
                    $text = json_encode(['total_questions' => array_sum(array_column($topics, 'questions')), 'topics' => $topics, 'hint' => 'Передайте query для поиска по вопросам и ответам'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $hits = [];
                    foreach ($DATA['faq_pages'] as $p) {
                        foreach ($p['items'] as $it) {
                            if (mb_strpos(mb_strtolower($it['q'] . ' ' . $it['a']), $q) !== false) {
                                $hits[] = ['page' => $p['page'], 'url' => $p['url'], 'q' => $it['q'], 'a' => $it['a']];
                            }
                        }
                    }
                    $text = json_encode(['query' => $args['query'] ?? '', 'matches' => count($hits), 'results' => array_slice($hits, 0, 10)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                break;
            default:
                respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => 'Неизвестный инструмент: ' . $name]], 'isError' => true]));
        }
        respond(rpc_result($id, ['content' => [['type' => 'text', 'text' => $text]]]));
        break;

    default:
        mcp_log('unknown | ' . $method);
        respond(rpc_error($id, -32601, 'Method not found: ' . $method));
}
