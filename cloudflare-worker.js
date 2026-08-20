/* ============================================================================
   DIVA MODELS — приём заявки и отправка в Telegram  (Cloudflare Worker)
   ============================================================================
   Нужен, когда сайт лежит на статическом хостинге (GitHub Pages и т.п.), где
   нельзя выполнить ни PHP, ни Node. Сайт остаётся статикой, а форма стучится
   сюда — и уже воркер идёт в Telegram. Токен живёт в секретах Cloudflare и в
   браузер не попадает.

   КАК ПОДКЛЮЧИТЬ (5 минут, бесплатно):
     1. dash.cloudflare.com → Workers & Pages → Create → Worker → Deploy
     2. Edit code → вставить весь этот файл → Deploy
     3. Settings → Variables and Secrets → Add:
          TELEGRAM_BOT_TOKEN   (тип Secret)  — токен от BotFather
          TELEGRAM_CHAT_ID     (тип Secret)  — -1002276800723
          ALLOWED_ORIGINS      (тип Text)    — https://ваш-домен.com,https://www.ваш-домен.com
     4. Скопировать адрес воркера (…workers.dev) и вписать его в apply.html
        и apply-form.html:
            var LEAD_ENDPOINT = 'https://ваш-воркер.workers.dev';

   ВАЖНО про ALLOWED_ORIGINS: это правило браузера, а не защита от прямых
   запросов — curl его игнорирует. Реальный заслон от потока — ограничение
   частоты ниже.
   ============================================================================ */

const FIELDS = {
  name:       'Имя',
  phone:      'Телефон',
  telegram:   'Telegram',
  age:        'Возраст',
  experience: 'Опыт',
  taboo18:    'Контент 18+',
};

const LABELS = {
  experience: { none: 'Нет опыта', model: 'Модель', operator: 'Оператор' },
  taboo18:    { yes: 'Да', no: 'Нет' },
};

const esc = (v) => String(v == null ? '' : v)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const clean = (v, max = 200) => String(v == null ? '' : v).trim().slice(0, max);

/* Ограничение частоты. Живёт в памяти изолята, поэтому это заслон от потока,
   а не строгая гарантия — но залить группу сотней заявок уже не даст. */
const HITS = new Map();
function rateLimited(ip, max = 5, windowMs = 10 * 60 * 1000) {
  const now = Date.now();
  const recent = (HITS.get(ip) || []).filter((t) => now - t < windowMs);
  if (recent.length >= max) { HITS.set(ip, recent); return true; }
  recent.push(now);
  HITS.set(ip, recent);
  if (HITS.size > 5000) HITS.clear();
  return false;
}

function corsHeaders(request, env) {
  const origin = request.headers.get('Origin') || '';
  const allowed = (env.ALLOWED_ORIGINS || '').split(',').map((s) => s.trim()).filter(Boolean);
  const allow = allowed.length === 0 ? '*' : (allowed.includes(origin) ? origin : allowed[0]);
  return {
    'Access-Control-Allow-Origin': allow,
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Max-Age': '86400',
    'Vary': 'Origin',
  };
}

const json = (body, status, extra) => new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json; charset=utf-8',
             'X-Content-Type-Options': 'nosniff', ...extra },
});

export default {
  async fetch(request, env) {
    const cors = corsHeaders(request, env);

    // предварительный запрос браузера перед кросс-доменным POST
    if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: cors });

    // Проверка настроек: откройте адрес воркера в браузере и увидите, какие
    // переменные заданы. Значения не показываются — только «есть / нет».
    if (request.method === 'GET') {
      return json({
        ok: true,
        worker: 'diva-leads',
        config: {
          TELEGRAM_BOT_TOKEN: env.TELEGRAM_BOT_TOKEN ? 'задан' : 'НЕ ЗАДАН',
          TELEGRAM_CHAT_ID:   env.TELEGRAM_CHAT_ID   ? String(env.TELEGRAM_CHAT_ID) : 'НЕ ЗАДАН',
          ALLOWED_ORIGINS:    env.ALLOWED_ORIGINS    || 'не задан (разрешены любые сайты)',
        },
        hint: 'Если что-то «НЕ ЗАДАН» — добавьте в Settings → Variables and Secrets и нажмите Deploy ещё раз.',
      }, 200, cors);
    }

    if (request.method !== 'POST') return json({ ok: false, error: 'method_not_allowed' }, 405, cors);

    const missingEnv = [];
    if (!env.TELEGRAM_BOT_TOKEN) missingEnv.push('TELEGRAM_BOT_TOKEN');
    if (!env.TELEGRAM_CHAT_ID)   missingEnv.push('TELEGRAM_CHAT_ID');
    if (missingEnv.length) {
      console.error('не заданы переменные:', missingEnv.join(', '));
      return json({ ok: false, error: 'not_configured', missing: missingEnv }, 500, cors);
    }

    const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
    if (rateLimited(ip)) return json({ ok: false, error: 'too_many_requests' }, 429, cors);

    let body;
    try { body = await request.json(); } catch { body = {}; }
    if (!body || typeof body !== 'object') body = {};

    // ловушка для спам-ботов: поле спрятано от людей
    if (clean(body.website)) return json({ ok: true }, 200, cors);

    const required = ['name', 'phone', 'telegram', 'age', 'experience', 'taboo18'];
    const missing = required.filter((k) => !clean(body[k]));
    if (missing.length) return json({ ok: false, error: 'missing_fields', missing }, 400, cors);

    const rows = required.map((key) => {
      const raw = clean(body[key]);
      const val = (LABELS[key] && LABELS[key][raw]) || raw;
      return `<b>${FIELDS[key]}:</b> ${esc(val)}`;
    });

    const source = clean(body.source, 60) || 'не указан';
    const page   = clean(body.page, 300);
    const when   = clean(body.submittedAt, 60);

    const text = [
      '🔥 <b>Новая заявка</b>',
      '',
      ...rows,
      '',
      `<i>Откуда:</i> ${esc(source)}`,
      when ? `<i>Когда:</i> ${esc(when)}` : null,
      page ? `<i>Страница:</i> ${esc(page)}` : null,
    ].filter((line) => line !== null).join('\n');   // '' — намеренные пустые строки

    try {
      const tg = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: env.TELEGRAM_CHAT_ID,
          text,
          parse_mode: 'HTML',
          disable_web_page_preview: true,
        }),
      });
      const result = await tg.json();
      if (!result.ok) {
        console.error('Telegram отказал:', result.description);
        return json({ ok: false, error: 'telegram_rejected' }, 502, cors);
      }
      return json({ ok: true }, 200, cors);
    } catch (err) {
      console.error('Не удалось достучаться до Telegram:', err);
      return json({ ok: false, error: 'telegram_unreachable' }, 502, cors);
    }
  },
};
