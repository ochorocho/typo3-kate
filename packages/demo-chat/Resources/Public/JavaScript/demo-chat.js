/**
 * <typo3-kate-demo-chat>: a chat for backend users, docked to the right of
 * the backend. Messages arrive live from the Mercure hub (EventSource) and
 * are sent through the backend AJAX route kate_demo_chat_send, which
 * publishes them as the logged-in user.
 */
import { LitElement, css, html, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const OPEN_KEY = 'typo3-kate-demo-chat:open';
const MAX_MESSAGES = 200;
const MAX_LENGTH = 1000;
const RETRY_MS = 10000;

const timeFormat = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });

/** The "error" text of a failed AjaxRequest, or a generic one */
async function errorText(error) {
  try {
    const data = await error.resolve();
    if (data?.error) {
      return data.error;
    }
  } catch {
    // no JSON body
  }
  return 'The chat is not reachable.';
}

export class DemoChat extends LitElement {
  static properties = {
    open: { type: Boolean, reflect: true },
    _messages: { state: true },
    _status: { state: true },
    _unread: { state: true },
    _user: { state: true },
    _error: { state: true },
    _sending: { state: true },
  };

  static styles = css`
    :host {
      --chat-width: min(22rem, 100vw);
      --chat-top: var(--typo3-scaffold-header-height, 3.75rem);
      position: fixed;
      top: var(--chat-top);
      right: 0;
      bottom: 0;
      z-index: 980; /* above the modules, below the header's dropdowns and modals */
      display: flex;
      align-items: center;
      pointer-events: none;
      font: inherit;
      color: var(--typo3-text-color-base, #1a1a1a);
    }
    .toggle,
    .panel {
      pointer-events: auto;
    }
    .toggle {
      position: relative;
      writing-mode: vertical-rl;
      padding: 0.75rem 0.4rem;
      border: 1px solid var(--typo3-component-border-color, #ccc);
      border-right: 0;
      border-radius: 0.5rem 0 0 0.5rem;
      background: var(--typo3-component-bg, #fff);
      color: inherit;
      font: inherit;
      cursor: pointer;
      box-shadow: var(--typo3-component-box-shadow, 0 1px 3px rgb(0 0 0 / 0.2));
    }
    .toggle:hover {
      background: var(--typo3-component-hover-bg, #f2f2f2);
    }
    .toggle:focus-visible,
    button:focus-visible,
    textarea:focus-visible {
      outline: 2px solid var(--typo3-state-primary-bg, #0078e6);
      outline-offset: 2px;
    }
    .badge {
      position: absolute;
      top: -0.5rem;
      left: -0.5rem;
      writing-mode: horizontal-tb;
      min-width: 1.25rem;
      padding: 0 0.3rem;
      border-radius: 1rem;
      background: var(--typo3-state-primary-bg, #0078e6);
      color: var(--typo3-state-primary-color, #fff);
      font-size: 0.75rem;
      line-height: 1.25rem;
      text-align: center;
    }
    .panel {
      display: flex;
      flex-direction: column;
      width: var(--chat-width);
      height: 100%;
      box-sizing: border-box;
      border-left: 1px solid var(--typo3-component-border-color, #ccc);
      background: var(--typo3-component-bg, #fff);
      box-shadow: var(--typo3-component-box-shadow, -2px 0 6px rgb(0 0 0 / 0.15));
    }
    header {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem 1rem;
      border-bottom: 1px solid var(--typo3-component-border-color, #ccc);
    }
    h2 {
      flex: 1;
      margin: 0;
      font-size: 1rem;
    }
    .status {
      font-size: 0.8125rem;
      color: var(--typo3-text-color-variant, #666);
    }
    .status::before {
      content: '';
      display: inline-block;
      width: 0.5rem;
      height: 0.5rem;
      margin-right: 0.35rem;
      border-radius: 50%;
      background: #b0b0b0;
    }
    .status[data-status='live']::before {
      background: #3aa154;
    }
    .status[data-status='offline']::before {
      background: #d63a3a;
    }
    .close {
      border: 0;
      background: none;
      color: inherit;
      font-size: 1.25rem;
      line-height: 1;
      cursor: pointer;
      padding: 0.25rem 0.5rem;
    }
    ol {
      flex: 1;
      overflow-y: auto;
      margin: 0;
      padding: 0.75rem 1rem;
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    li {
      max-width: 85%;
      align-self: flex-start;
      padding: 0.4rem 0.65rem;
      border-radius: 0.5rem;
      background: var(--typo3-surface-container-low, #f2f2f2);
      overflow-wrap: anywhere;
    }
    .text {
      white-space: pre-wrap;
    }
    li.own {
      align-self: flex-end;
      background: var(--typo3-state-primary-bg, #0078e6);
      color: var(--typo3-state-primary-color, #fff);
    }
    .meta {
      display: block;
      font-size: 0.75rem;
      opacity: 0.75;
    }
    li.empty {
      max-width: none;
      align-self: center;
      background: none;
      margin: auto;
      color: var(--typo3-text-color-variant, #666);
      font-size: 0.875rem;
      text-align: center;
    }
    .error {
      margin: 0 1rem 0.5rem;
      color: #d63a3a;
      font-size: 0.8125rem;
    }
    form {
      display: flex;
      gap: 0.5rem;
      padding: 0.75rem 1rem;
      border-top: 1px solid var(--typo3-component-border-color, #ccc);
    }
    textarea {
      flex: 1;
      resize: none;
      box-sizing: border-box;
      padding: 0.4rem 0.6rem;
      border: 1px solid var(--typo3-input-border-color, #bbb);
      border-radius: var(--typo3-input-border-radius, 0.25rem);
      background: var(--typo3-input-bg, #fff);
      color: var(--typo3-input-color, inherit);
      font: inherit;
    }
    .send {
      padding: 0.4rem 0.9rem;
      border: 0;
      border-radius: 0.25rem;
      background: var(--typo3-state-primary-bg, #0078e6);
      color: var(--typo3-state-primary-color, #fff);
      font: inherit;
      cursor: pointer;
    }
    .send:disabled {
      opacity: 0.6;
      cursor: default;
    }
    .visually-hidden {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    }
  `;

  constructor() {
    super();
    this.open = localStorage.getItem(OPEN_KEY) === 'true';
    this._messages = [];
    this._status = 'connecting';
    this._unread = 0;
    this._user = null;
    this._error = '';
    this._sending = false;
    this._source = null;
    this._retry = null;
  }

  connectedCallback() {
    super.connectedCallback();
    this._connect();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this._source?.close();
    clearTimeout(this._retry);
  }

  /** Subscribe: the hub URL and a fresh subscriber cookie, then the EventSource */
  async _connect() {
    clearTimeout(this._retry);
    this._source?.close();
    this._status = 'connecting';
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.kate_demo_chat_subscribe).get();
      const data = await response.resolve();
      this._user = data.user;
      this._error = '';
      const source = new EventSource(data.hub, { withCredentials: true });
      source.onopen = () => {
        this._status = 'live';
      };
      source.onmessage = (event) => this._receive(JSON.parse(event.data));
      source.onerror = () => {
        // EventSource reconnects by itself; a closed one (e.g. the cookie
        // expired) needs a new subscription
        if (source.readyState === EventSource.CLOSED) {
          this._retryLater();
        } else {
          this._status = 'connecting';
        }
      };
      this._source = source;
    } catch (error) {
      this._error = await errorText(error);
      this._retryLater();
    }
  }

  _retryLater() {
    this._status = 'offline';
    this._source?.close();
    clearTimeout(this._retry);
    this._retry = setTimeout(() => this._connect(), RETRY_MS);
  }

  _receive(message) {
    if (this._messages.some((known) => known.id === message.id)) {
      return;
    }
    this._messages = [...this._messages, message].slice(-MAX_MESSAGES);
    if (!this.open) {
      this._unread++;
    }
  }

  _toggle() {
    this.open = !this.open;
    localStorage.setItem(OPEN_KEY, String(this.open));
    if (this.open) {
      this._unread = 0;
      this.updateComplete.then(() => this.renderRoot.querySelector('textarea')?.focus());
    } else {
      this.updateComplete.then(() => this.renderRoot.querySelector('.toggle')?.focus());
    }
  }

  async _send(event) {
    event.preventDefault();
    const textarea = this.renderRoot.querySelector('textarea');
    const text = textarea.value.trim();
    if (text === '' || this._sending) {
      return;
    }
    this._sending = true;
    try {
      await new AjaxRequest(TYPO3.settings.ajaxUrls.kate_demo_chat_send).post(
        { text },
        { headers: { 'Content-Type': 'application/json' } },
      );
      textarea.value = '';
      this._error = '';
    } catch (error) {
      this._error = await errorText(error);
    } finally {
      this._sending = false;
      textarea.focus();
    }
  }

  _keydown(event) {
    // Enter sends, Shift+Enter starts a new line
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      this._send(event);
    }
  }

  updated(changed) {
    if (changed.has('_messages') || changed.has('open')) {
      const list = this.renderRoot.querySelector('ol');
      if (list) {
        list.scrollTop = list.scrollHeight;
      }
    }
  }

  _statusText() {
    return { live: 'live', connecting: 'connecting…', offline: 'offline' }[this._status];
  }

  render() {
    if (!this.open) {
      return html`
        <button class="toggle" type="button" aria-expanded="false" aria-controls="chat-panel" @click=${this._toggle}>
          Chat
          ${this._unread > 0
            ? html`<span class="badge">${this._unread}<span class="visually-hidden"> unread</span></span>`
            : nothing}
        </button>
      `;
    }
    return html`
      <section class="panel" id="chat-panel" aria-labelledby="chat-title">
        <header>
          <h2 id="chat-title">Chat</h2>
          <span class="status" data-status=${this._status}>${this._statusText()}</span>
          <button class="close" type="button" aria-expanded="true" aria-controls="chat-panel" title="Collapse the chat" @click=${this._toggle}>
            <span aria-hidden="true">›</span><span class="visually-hidden">Collapse the chat</span>
          </button>
        </header>
        <ol aria-live="polite" aria-label="Messages">
          ${this._messages.length === 0
            ? html`<li class="empty">No messages yet. Messages are live only: they show for everyone logged in to the backend right now.</li>`
            : repeat(this._messages, (message) => message.id, (message) => this._renderMessage(message))}
        </ol>
        ${this._error ? html`<p class="error" role="alert">${this._error}</p>` : nothing}
        <form @submit=${this._send}>
          <label class="visually-hidden" for="chat-text">Message</label>
          <textarea id="chat-text" rows="2" maxlength=${MAX_LENGTH} placeholder="Message (Enter sends)" @keydown=${this._keydown}></textarea>
          <button class="send" type="submit" ?disabled=${this._sending}>Send</button>
        </form>
      </section>
    `;
  }

  _renderMessage(message) {
    const own = this._user !== null && message.user.id === this._user.id;
    const time = new Date(message.time);
    return html`
      <li class=${own ? 'own' : ''}>
        <span class="meta">${own ? 'You' : message.user.name} · <time datetime=${message.time}>${timeFormat.format(time)}</time></span>
        <span class="text">${message.text}</span>
      </li>
    `;
  }
}

if (!customElements.get('typo3-kate-demo-chat')) {
  customElements.define('typo3-kate-demo-chat', DemoChat);
}
if (!document.querySelector('typo3-kate-demo-chat')) {
  document.body.append(document.createElement('typo3-kate-demo-chat'));
}
