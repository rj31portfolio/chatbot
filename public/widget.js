/* AI Lead Agent: framework-independent, isolated, public widget client. */
(() => {
  'use strict';
  const script = document.currentScript;
  const widgetId = script?.dataset.widgetId;
  if (!widgetId || document.querySelector(`[data-ai-widget="${CSS.escape(widgetId)}"]`)) return;
  const api = new URL('api/widget/', script.src).href;
  const storageKey = `ai-lead-agent:${widgetId}`;
  const host = document.createElement('div');
  host.dataset.aiWidget = widgetId;
  host.style.cssText = 'position:fixed;z-index:2147483000;bottom:24px;right:24px;';
  const root = host.attachShadow({ mode: 'open' });
  let config, session, busy = false, lastId = 0, poller, opened = false;
  const readStorage = () => { try { return JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch { return null; } };
  const writeStorage = data => { try { sessionStorage.setItem(storageKey, JSON.stringify(data)); } catch {} };
  session = readStorage();

  async function request(path, data, method = 'POST') {
    const response = await fetch(api + path, {
      method, mode: 'cors', credentials: 'omit',
      headers: { 'Content-Type': 'application/json', 'X-Widget-Id': widgetId, ...(session?.token ? { Authorization: `Bearer ${session.token}` } : {}) },
      ...(method !== 'GET' ? { body: JSON.stringify({ ...data, widget_id: widgetId, ...(session?.session_id ? { session_id: session.session_id } : {}) }) } : {})
    });
    const body = await response.json();
    if (!response.ok || !body.success) {
      if (response.status === 401) { session = null; writeStorage(null); lastId = 0; }
      throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'Please try again.');
    }
    return body.data;
  }
  async function ensureSession() {
    if (session?.session_id) return;
    const page = new URL(location.href); const utm = {};
    for (const key of ['source', 'medium', 'campaign', 'term', 'content']) if (page.searchParams.has(`utm_${key}`)) utm[`utm_${key}`] = page.searchParams.get(`utm_${key}`).slice(0, 255);
    session = await request('session', { visitor_id: session?.visitor_id || crypto.randomUUID(), page_url: page.origin + page.pathname, referrer: document.referrer ? new URL(document.referrer).origin + new URL(document.referrer).pathname : null, utm });
    writeStorage(session); startPolling();
  }
  const element = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = text; return node; };
  function message(text, sender = 'ai') {
    const node = element('div', `bubble ${sender}`, text);
    root.querySelector('.messages').append(node); scroll(); return node;
  }
  function scroll() { const list = root.querySelector('.messages'); list.scrollTop = list.scrollHeight; }
  function error(text) { const n = message(text, 'system'); n.setAttribute('role', 'alert'); }
  async function refresh() {
    if (!session?.session_id || busy) return;
    try {
      const data = await request('history', { after: lastId });
      for (const item of data.messages) { message(item.message, item.sender_type); lastId = Math.max(lastId, item.id); }
    } catch { clearInterval(poller); }
  }
  function startPolling() { clearInterval(poller); if (opened) { refresh(); poller = setInterval(refresh, 12000); } }
  function toggle(force) {
    opened = typeof force === 'boolean' ? force : !opened;
    root.querySelector('.window').hidden = !opened;
    root.querySelector('.launcher').setAttribute('aria-expanded', String(opened));
    if (opened) { root.querySelector('.composer input').focus(); startPolling(); } else clearInterval(poller);
  }
  async function send(event) {
    event.preventDefault(); if (busy) return;
    const input = root.querySelector('.composer input'); const text = input.value.trim(); if (!text) return;
    busy = true; input.value = ''; root.querySelector('.send').disabled = true;
    const pending = message(text, 'visitor'); const typing = message('Thinking\u2026', 'typing');
    try {
      await ensureSession(); await request('message', { message: text });
      pending.remove(); typing.remove(); busy = false; await refresh();
    } catch (e) { typing.remove(); error(e.message); }
    finally { busy = false; root.querySelector('.send').disabled = false; input.focus(); }
  }
  function leadForm() {
    const pane = root.querySelector('.form-pane'); pane.replaceChildren(); pane.hidden = false;
    const form = element('form', 'lead-form'); form.append(element('h3', '', 'Let\u2019s keep in touch'));
    const fields = [...config.fields]; if (!fields.includes('email') && !fields.includes('phone')) fields.push('email');
    for (const field of fields) {
      const label = element('label', '', field.replaceAll('_', ' ')); const input = element(field === 'requirement' ? 'textarea' : 'input'); input.name = field;
      if (field === 'email') input.type = 'email'; if (field === 'phone') input.type = 'tel'; input.maxLength = field === 'requirement' ? 3000 : 255;
      label.append(input); form.append(label);
    }
    const consent = element('label', 'consent'); const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.name = 'consent'; checkbox.value = '1'; checkbox.required = true;
    consent.append(checkbox, document.createTextNode('I agree to share these details with the business for follow-up.')); form.append(consent);
    const submit = element('button', 'action', 'Send my details'); form.append(submit);
    const cancel = element('button', 'cancel', 'Back to chat'); cancel.type = 'button'; cancel.onclick = () => pane.hidden = true; form.append(cancel);
    form.onsubmit = async e => { e.preventDefault(); submit.disabled = true; try { await ensureSession(); const data = Object.fromEntries(new FormData(form)); await request('lead', data); pane.hidden = true; message('Thanks! Your details have been shared with the team.'); } catch (e) { error(e.message); } finally { submit.disabled = false; } };
    pane.append(form);
  }
  function appointmentForm() {
    const pane = root.querySelector('.form-pane'); pane.replaceChildren(); pane.hidden = false;
    const form = element('form', 'lead-form'); form.append(element('h3', '', 'Request an appointment'), element('p', 'hint', 'Share your contact details first. The team will confirm availability.'));
    for (const [name, title, type] of [['service', 'Service', 'text'], ['starts_at', 'Preferred date & time', 'datetime-local']]) {
      const label = element('label', '', title); const input = element('input'); input.name = name; input.type = type; input.required = true; label.append(input); form.append(label);
    }
    const submit = element('button', 'action', 'Request appointment'); form.append(submit); const back = element('button', 'cancel', 'Back to chat'); back.type = 'button'; back.onclick = () => pane.hidden = true; form.append(back);
    form.onsubmit = async e => { e.preventDefault(); submit.disabled = true; try { await ensureSession(); const data = Object.fromEntries(new FormData(form)); data.starts_at = new Date(data.starts_at).toISOString(); data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone; await request('appointment', data); pane.hidden = true; message('Appointment requested. The team will confirm the time.'); } catch (e) { error(e.message); } finally { submit.disabled = false; } };
    pane.append(form);
  }
  function render() {
    const style = element('style');
    style.textContent = `:host{all:initial;font-family:ui-sans-serif,system-ui,-apple-system,sans-serif;color:#242429;font-size:14px;line-height:1.5;--color:#f97316}*{box-sizing:border-box}button,input,textarea{font:inherit}button{cursor:pointer}button:disabled{opacity:.5}button:focus-visible,a:focus-visible,input:focus-visible{outline:3px solid #fdba74;outline-offset:2px}[hidden]{display:none!important}.launcher{width:58px;height:58px;border-radius:50%;border:0;color:#fff;background:var(--color);box-shadow:0 6px 24px #0002;font-size:24px;display:grid;place-items:center;margin-left:auto}.launcher svg{width:26px;height:26px}.window{position:absolute;bottom:72px;right:0;width:360px;height:min(570px,calc(100dvh - 115px));background:#fff;box-shadow:0 8px 45px #0002;border:1px solid #eee;border-radius:var(--radius,16px);overflow:hidden;display:flex;flex-direction:column}.left .window{right:auto;left:0}.left .launcher{margin-left:0}.header{background:var(--color);color:white;padding:20px;display:flex;align-items:center;justify-content:space-between}.title{font-weight:650;font-size:15px}.sub{font-size:10px;color:#fff9;margin-top:3px}.close{background:none;color:white;font-size:24px;border:0;padding:0 4px}.messages{flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:12px;padding:18px;min-height:0}.bubble{font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere;padding:11px 13px;border-radius:12px;background:#f4f4f6;max-width:90%;align-self:flex-start}.bubble.visitor{background:var(--color);color:white;align-self:flex-end}.bubble.human{background:#eef7f0}.bubble.system{background:#fff4ee;color:#a66037;font-size:11px}.bubble.typing{font-size:11px;color:#aaa}.composer{display:flex;gap:8px;padding:12px;border-top:1px solid #eee}.composer input{flex:1;min-width:0;border:1px solid #e6e6ea;padding:10px;border-radius:7px;font-size:12px;background:white;color:#333}.send{border:0;background:var(--color);color:white;border-radius:7px;width:40px;font-size:20px}.actions{display:flex;gap:10px;flex-wrap:wrap;padding:0 14px 10px}.actions button,.actions a{border:0;background:transparent;color:var(--color);font-size:10px;padding:3px 0;text-decoration:none}.branding{text-align:center;color:#aaa;font-size:9px;padding:5px 0 9px}.form-pane{position:absolute;top:79px;bottom:0;width:100%;background:#fff;overflow:auto;padding:20px}.lead-form{display:flex;flex-direction:column;gap:12px}.lead-form h3{margin:0;font-size:16px}.lead-form label{font-size:11px;color:#777;display:flex;flex-direction:column;gap:5px;text-transform:capitalize}.lead-form input,.lead-form textarea{border:1px solid #ddd;border-radius:6px;padding:9px;width:100%;font-size:12px;background:white;color:#333}.lead-form .consent{flex-direction:row;align-items:flex-start;font-size:10px;text-transform:none}.consent input{width:14px;flex-shrink:0;accent-color:var(--color)}.action{padding:11px;border:0;background:var(--color);color:white;border-radius:6px;font-size:12px}.cancel{border:0;background:white;color:#999;font-size:11px;padding:6px}.hint{font-size:11px;color:#999;margin:0}@media(max-width:480px){.window{width:min(360px,calc(100vw - 32px));height:min(600px,calc(100dvh - 100px))}.launcher{width:52px;height:52px}}`;
    root.append(style);
    const wrapper = element('div', config.position === 'left' ? 'left' : 'right'); wrapper.style.setProperty('--color', /^#[0-9a-f]{6}$/i.test(config.color) ? config.color : '#f97316'); wrapper.style.setProperty('--radius', `${Math.min(32, Math.max(0, Number(config.settings.radius ?? 16)))}px`);
    const window = element('section', 'window'); window.hidden = true; window.setAttribute('aria-label', config.title);
    const header = element('header', 'header'); const title = element('div'); title.append(element('div', 'title', config.title), element('div', 'sub', 'AI assistant \u00b7 Here to help'));
    const close = element('button', 'close', '\u00d7'); close.type = 'button'; close.setAttribute('aria-label', 'Close chat'); close.onclick = () => toggle(false); header.append(title, close);
    const messages = element('div', 'messages'); messages.setAttribute('role', 'log'); messages.setAttribute('aria-live', 'polite');
    const composer = element('form', 'composer'); const input = element('input'); input.placeholder = config.settings.placeholder || 'Type your message\u2026'; input.maxLength = 3000; input.required = true; input.setAttribute('aria-label', 'Message'); const sendButton = element('button', 'send', '\u2191'); sendButton.setAttribute('aria-label', 'Send message'); composer.append(input, sendButton); composer.onsubmit = send;
    const actions = element('div', 'actions');
    if (config.capture) { const contact = element('button', '', 'Share contact details'); contact.onclick = leadForm; actions.append(contact); }
    if (config.appointments) { const book = element('button', '', 'Request appointment'); book.onclick = appointmentForm; actions.append(book); }
    for (const [field, label, prefix] of [['phone', 'Call', 'tel:'], ['email', 'Email', 'mailto:'], ['whatsapp', 'WhatsApp', 'https://wa.me/']]) {
      if (!config[field] || config.settings[`show_${field}`] === false) continue;
      const value = field === 'email' ? config[field].replace(/[\r\n?]/g, '') : config[field].replace(/[^\d+]/g, '');
      const link = element('a', '', label); link.href = prefix + (field === 'whatsapp' ? value.replace(/\+/g, '') : value); link.rel = 'noopener noreferrer'; if (field === 'whatsapp') link.target = '_blank'; actions.append(link);
    }
    const end = element('button', '', 'End chat'); end.onclick = async () => { try { if (session?.session_id) await request('end', {}); session = null; writeStorage(null); clearInterval(poller); lastId = 0; message('Conversation ended. You can start a new one whenever you like.'); } catch (e) { error(e.message); } }; actions.append(end);
    const pane = element('div', 'form-pane'); pane.hidden = true;
    window.append(header, messages, composer, actions, pane); if (config.settings.show_branding !== false) window.append(element('div', 'branding', `Powered by ${config.brand}`));
    const launcher = element('button', 'launcher'); launcher.setAttribute('aria-label', 'Open chat'); launcher.setAttribute('aria-expanded', 'false');
    launcher.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11a8 8 0 0 1-8 8H7l-5 3 1-6a8 8 0 1 1 18-5Z"/><path d="M8 10h8M8 14h5"/></svg>';
    launcher.onclick = () => toggle(); wrapper.append(window, launcher); root.append(wrapper); document.body.append(host);
    if (config.position === 'left') { host.style.right = 'auto'; host.style.left = '24px'; }
    host.style.bottom = `${Math.min(200, Math.max(0, Number(config.settings.bottom ?? 24)))}px`;
    message(config.welcome_message);
    const delay = Number(config.settings.auto_open_seconds || 0); if (delay > 0) setTimeout(() => toggle(true), Math.min(600, delay) * 1000);
  }
  request('config', {}, 'GET').then(data => { config = data; render(); }).catch(() => { /* Fail without breaking the customer website. */ });
})();
