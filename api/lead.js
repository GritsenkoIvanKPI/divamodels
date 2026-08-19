/* ============================================================================
   DIVA MODELS — приём заявки и отправка в Telegram  (вариант для Node / Vercel)
   ============================================================================
   Используется, если сайт лежит на Vercel, Netlify Functions или своём
   Node-сервере. Для обычного PHP-хостинга есть send.php — он делает то же
   самое, подключается одной строкой в форме.

   ЧТО НУЖНО НАСТРОИТЬ (переменные окружения, НЕ в коде):
     TELEGRAM_BOT_TOKEN   — токен от @BotFather, например 1234567890:AAH...
     TELEGRAM_CHAT_ID     — id группы, отрицательное число: -1001234567890

   На Vercel: Settings → Environment Variables → добавить обе → Redeploy.

   ВАЖНО: токен никогда не попадает в браузер. Страница обращается только
   к этому адресу, а он уже сам ходит в Telegram.
   ============================================================================ */

const FIELDS = {
  name:       'Имя',
  phone:      'Телефон',
  telegram:   'Telegram',
  age:        'Возраст',
  experience: 'Опыт',
  taboo18:    'Контент 18+',
};

// человеческие подписи вместо служебных значений кнопок
const LABELS = {
  experience: { none: 'Нет опыта', model: 'Модель', operator: 'Оператор' },
  taboo18:    { yes: 'Да', no: 'Нет' },
};

const escapeHtml = (v) => String(v == null ? '' : v)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// обрезаем всё, что пришло из браузера: и от случайных простыней, и от мусора
const clean = (v, max = 200) => String(v == null ? '' : v).trim().slice(0, max);

module.exports = async (req, res) => {
  if (req.method !== 'POST') {
    res.status(405).json({ ok: false, error: 'method_not_allowed' });
    return;
  }

  const token  = process.env.TELEGRAM_BOT_TOKEN;
  const chatId = process.env.TELEGRAM_CHAT_ID;
  if (!token || !chatId) {
    console.error('TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID не заданы');
    res.status(500).json({ ok: false, error: 'not_configured' });
    return;
  }

  let body = req.body;
  if (typeof body === 'string') { try { body = JSON.parse(body); } catch { body = {}; } }
  if (!body || typeof body !== 'object') body = {};

  // Ловушка для ботов: поле спрятано от людей, поэтому заполнить его может
  // только автозаполнялка спамера. Отвечаем «ок», чтобы бот не подбирал дальше.
  if (clean(body.website)) { res.status(200).json({ ok: true }); return; }

  const required = ['name', 'phone', 'telegram', 'age', 'experience', 'taboo18'];
  const missing = required.filter((k) => !clean(body[k]));
  if (missing.length) {
    res.status(400).json({ ok: false, error: 'missing_fields', missing });
    return;
  }

  const rows = required.map((key) => {
    const raw = clean(body[key]);
    const val = (LABELS[key] && LABELS[key][raw]) || raw;
    return `<b>${FIELDS[key]}:</b> ${escapeHtml(val)}`;
  });

  const source = clean(body.source, 60) || 'не указан';
  const page   = clean(body.page, 300);
  const when   = clean(body.submittedAt, 60);

  const text = [
    '🔥 <b>Новая заявка</b>',
    '',
    ...rows,
    '',
    `<i>Откуда:</i> ${escapeHtml(source)}`,
    when ? `<i>Когда:</i> ${escapeHtml(when)}` : null,
    page ? `<i>Страница:</i> ${escapeHtml(page)}` : null,
    // filter(Boolean) would eat the intentional '' spacer lines — drop only nulls
  ].filter((line) => line !== null).join('\n');

  try {
    const tg = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: chatId,
        text,
        parse_mode: 'HTML',
        disable_web_page_preview: true,
      }),
    });
    const result = await tg.json();
    if (!result.ok) {
      // описание от Telegram очень помогает: "chat not found", "bot was blocked" и т.п.
      console.error('Telegram отказал:', result.description);
      res.status(502).json({ ok: false, error: 'telegram_rejected' });
      return;
    }
    res.status(200).json({ ok: true });
  } catch (err) {
    console.error('Не удалось достучаться до Telegram:', err);
    res.status(502).json({ ok: false, error: 'telegram_unreachable' });
  }
};
