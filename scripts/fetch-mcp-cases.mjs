#!/usr/bin/env node
// fetch-mcp-cases.mjs — выгрузка кейсов (our_objects) из Cockpit CMS в scripts/mcp-cases.json
// для MCP-эндпоинта /mcp (план AI-поиск 09.10). Только чтение CMS.
// Креды: COCKPIT_ENV (по умолчанию .secrets/ptp_php.env орг-репо ptp) — COCKPIT_BASE_URL/COCKPIT_API_KEY_ADMIN.
// Запуск: node scripts/fetch-mcp-cases.mjs  (из корня ptp_php)
import { readFileSync, writeFileSync } from 'node:fs';

const ENV_PATH = process.env.COCKPIT_ENV || '/Users/wolland/Developer/ptp/.secrets/ptp_php.env';
const env = Object.fromEntries(
  readFileSync(ENV_PATH, 'utf8').split('\n')
    .map(l => /^([A-Z_]+)=(.*)$/.exec(l?.trim() || ''))
    .filter(Boolean).map(m => [m[1], m[2].replace(/^["']|["']$/g, '')])
);
const BASE = env.COCKPIT_BASE_URL?.replace(/\/+$/, '');
const KEY = env.COCKPIT_API_KEY_ADMIN;
if (!BASE || !KEY) { console.error('Нет COCKPIT_BASE_URL/COCKPIT_API_KEY_ADMIN в ' + ENV_PATH); process.exit(1); }

// Город: стемы адресов 18 хабов + Москва (см. mcp-geo.json)
const CITY_STEMS = [
  ['Раменское', /раменск/i], ['Подольск', /подольск/i], ['Балашиха', /балаших/i],
  ['Мытищи', /мытищ/i], ['Красногорск', /красногорск/i], ['Коломна', /коломн/i],
  ['Ногинск', /ногинск/i], ['Щёлково', /щелков/i], ['Дмитров', /дмитров/i],
  ['Одинцово', /одинцов/i], ['Тула', /тул[аьск]/i], ['Химки', /химк/i],
  ['Сергиев Посад', /сергиев/i], ['Ступино', /ступин/i], ['Серпухов', /серпухов/i],
  ['Можайск', /можайск/i], ['Королёв', /корол[её]в/i], ['Видное', /видное|видном/i],
  ['Москва', /москв/i],
];
// Тип объекта: по ключевым словам заголовка (грубая, но устойчивая классификация)
const TYPE_RULES = [
  ['склад', /склад|логист|распределительн|центр выдачи/i],
  ['производство', /производств|цех|завод|фабрик/i],
  ['ангар', /ангар/i],
  ['паркинг', /паркинг|стоянк|гараж/i],
  ['торговля', /торгов|тц|магазин|супермаркет/i],
  ['холодильное', /холодил|морозил/i],
  ['пищевое', /пищ|кухн|ресторан|пекарн|столов/i],
  ['автосервис', /автосервис|мойк|сто\b|автомастер/i],
  [' медицина', /медиц|больниц|клиник|аптек/i],
  ['спорт', /спорт|корт|фитнес|зал/i],
];

const gql = async (query) => {
  const r = await fetch(`${BASE}/api/gql`, {
    method: 'POST',
    headers: { 'api-key': KEY, 'Content-Type': 'application/json' },
    body: JSON.stringify({ query }),
  });
  const j = await r.json();
  if (j.errors) throw new Error(j.errors.map(e => e.message).join('; '));
  return j.data;
};

// Урок API (09.10): GraphQL our_objectsModel с полем square падает «Internal server error» на
// объёме (у части записей square — строка). REST /api/content/items с JSON-проекцией — работает.
const qs = new URLSearchParams({ fields: JSON.stringify({ title: 1, url: 1, square: 1, date: 1, address: 1 }), limit: '1000' });
const r = await fetch(`${BASE}/api/content/items/our_objects?${qs}`, { headers: { 'api-key': KEY } });
if (!r.ok) { console.error('REST our_objects: HTTP ' + r.status); process.exit(1); }
const rows = await r.json();
const cases = [];
for (const o of rows) {
  if (!o?.title || !o?.url) continue;
  const city = (CITY_STEMS.find(([, re]) => re.test(o.address || '')) || [null])[0];
  const types = TYPE_RULES.filter(([, re]) => re.test(o.title)).map(([t]) => t.trim());
  cases.push({
    title: o.title,
    city: city,
    type: types[0] || null,
    square_m2: Number.isFinite(Number(o.square)) ? Number(o.square) : null,
    year: (o.date || '').slice(0, 4) || null,
    url: 'https://proftechpol.ru/portfolio/' + o.url,
  });
}
writeFileSync('scripts/mcp-cases.json', JSON.stringify(cases, null, 1));
const withCity = cases.filter(c => c.city).length;
console.log(`mcp-cases.json: ${cases.length} кейсов, с городом ${withCity}, тип определён у ${cases.filter(c => c.type).length}`);
