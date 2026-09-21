class ArchivedMailboxPage {
    constructor() {
        this.csrfToken = document.getElementById('csrfToken')?.value || '';
        this.archivedEmails = [];
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadArchivedEmails();
        this.initializeDarkMode();
    }

    bindEvents() {
        const refreshButton = document.getElementById('refreshArchivedBtn');
        if (refreshButton) {
            refreshButton.addEventListener('click', () => this.loadArchivedEmails(true));
        }

        const closeButton = document.querySelector('#archivedEmailModal .close-modal');
        if (closeButton) {
            closeButton.addEventListener('click', () => this.closeModal());
        }

        const modal = document.getElementById('archivedEmailModal');
        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === event.currentTarget) {
                    this.closeModal();
                }
            });
        }
    }

    async getFreshCSRFToken() {
        try {
            const response = await fetch(window.location.origin + window.location.pathname);
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const token = doc.getElementById('csrfToken')?.value;
            if (token) {
                this.csrfToken = token;
            }
        } catch (error) {
            console.error('Failed to refresh CSRF token', error);
        }
        return this.csrfToken;
    }

    async loadArchivedEmails(showFeedback = false) {
        const list = document.getElementById('archivedList');
        const refreshButton = document.getElementById('refreshArchivedBtn');

        if (refreshButton) {
            refreshButton.disabled = true;
        }

        try {
            let response = await fetch('includes/handlers/get_archived_emails.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify({})
            });

            if (response.status === 403) {
                await this.getFreshCSRFToken();
                response = await fetch('includes/handlers/get_archived_emails.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({})
                });
            }

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to load archived emails');
            }

            this.archivedEmails = Array.isArray(data.emails) ? data.emails : [];
            this.renderList();
            this.updateCount();

            if (showFeedback) {
                this.showMessage('Archived mailbox refreshed.', 'success');
            }
        } catch (error) {
            if (list) {
                list.innerHTML = '<div class="no-emails"><p>Unable to load archived emails right now.</p></div>';
            }
            this.showMessage(error.message || 'Unable to load archived emails', 'error');
        } finally {
            if (refreshButton) {
                refreshButton.disabled = false;
            }
        }
    }

    renderList() {
        const list = document.getElementById('archivedList');
        if (!list) return;

        if (this.archivedEmails.length === 0) {
            list.innerHTML = '<div class="no-emails"><p>No archived emails yet.</p></div>';
            this.resetReaderPanel();
            return;
        }

        const html = this.archivedEmails.map((email) => `
            <div class="email-item" data-email-id="${Number(email.id)}">
                <div class="email-item-content">
                    <div class="email-header">
                        <div class="email-subject">${this.escapeHtml(email.subject || 'No Subject')}</div>
                        <div class="email-date">${new Date(email.archived_at).toLocaleString()}</div>
                    </div>
                    <div class="email-from">From: ${this.escapeHtml(email.sender_name || email.sender_email || 'Unknown')}</div>
                    <div class="email-preview">${this.escapeHtml(this.getEmailPreview(email.body_text || email.body_html || ''))}</div>
                </div>
            </div>
        `).join('');

        list.innerHTML = html;

        list.querySelectorAll('.email-item').forEach((item) => {
            item.addEventListener('click', () => {
                const emailId = Number(item.dataset.emailId || 0);
                const email = this.archivedEmails.find((row) => Number(row.id) === emailId);
                if (email) {
                    this.openEmail(email);
                }
            });
        });
    }

    openEmail(email) {
        const readerPanel = document.getElementById('archivedReaderPanel');
        const canUseInlineReader = !!readerPanel && window.matchMedia('(min-width: 861px)').matches;

        const bodyHtml = email.body_html || '';
        const bodyText = email.body_text || '';
        const bodyFallback = email.body || '';

        let content = '';
        let usesHtmlFrame = false;

        if (bodyHtml && bodyHtml.trim() !== '') {
            usesHtmlFrame = true;
            const htmlContent = this.sanitizeHTML(bodyHtml).replace(/'/g, '&#39;');
            content = `<iframe class="reader-email-iframe" title="Archived email content" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms" srcdoc='<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>html,body{margin:0;padding:0}body{padding:14px;background:#f8fafc;font-family:Arial,sans-serif;color:#344054}img{max-width:100%!important;height:auto!important}table{max-width:100%!important}</style></head><body>${htmlContent}</body></html>'></iframe>`;
        } else if (bodyText && bodyText.trim() !== '') {
            content = `<pre class="email-text-content">${this.escapeHtml(bodyText)}</pre>`;
        } else if (bodyFallback && bodyFallback.trim() !== '') {
            content = `<pre class="email-text-content">${this.escapeHtml(bodyFallback)}</pre>`;
        } else {
            content = '<div class="no-content"><p>No email content available</p></div>';
        }

        if (canUseInlineReader) {
            readerPanel.innerHTML = `
                <article class="reader-message">
                    <h2>${this.escapeHtml(email.subject || 'No Subject')}</h2>
                    <div class="reader-message-meta">
                        <span><strong>From:</strong> ${this.escapeHtml(email.sender_name || email.sender_email || 'Unknown')}</span>
                        <span><strong>Received:</strong> ${new Date(email.received_at).toLocaleString()}</span>
                        <span><strong>Archived:</strong> ${new Date(email.archived_at).toLocaleString()}</span>
                        <span><strong>Address:</strong> ${this.escapeHtml(email.email_address || '')}</span>
                    </div>
                    <div class="reader-message-body">${content}</div>
                </article>
            `;

            if (usesHtmlFrame) {
                const frame = readerPanel.querySelector('.reader-email-iframe');
                this.fitEmailIframeToReader(frame);
            }
            return;
        }

        document.getElementById('archivedModalSubject').textContent = email.subject || 'No Subject';
        document.getElementById('archivedModalFrom').textContent = `${email.sender_name || email.sender_email || 'Unknown'} <${email.sender_email || 'unknown@unknown.com'}>`;
        document.getElementById('archivedModalDate').textContent = new Date(email.received_at).toLocaleString();
        document.getElementById('archivedModalArchivedAt').textContent = new Date(email.archived_at).toLocaleString();

        const modalBody = document.getElementById('archivedModalBody');
        if (modalBody) {
            modalBody.innerHTML = content;
            const frame = modalBody.querySelector('.reader-email-iframe');
            if (frame) {
                this.fitEmailIframeToReader(frame);
            }
        }

        const modal = document.getElementById('archivedEmailModal');
        if (modal) {
            modal.style.display = 'flex';
        }
        document.body.classList.add('modal-open');
    }

    fitEmailIframeToReader(iframe) {
        if (!iframe) return;

        const adjust = () => {
            try {
                const doc = iframe.contentDocument;
                if (!doc || !doc.documentElement || !doc.body) return;

                const contentWidth = Math.max(doc.documentElement.scrollWidth || 0, doc.body.scrollWidth || 0);
                const frameWidth = iframe.clientWidth;
                if (!contentWidth || !frameWidth) return;

                if (contentWidth < frameWidth * 0.82) {
                    const scale = Math.min(frameWidth / contentWidth, 1.45);
                    doc.body.style.transformOrigin = 'top left';
                    doc.body.style.transform = `scale(${scale})`;
                    doc.body.style.width = `${100 / scale}%`;
                    const scaledHeight = Math.ceil((doc.documentElement.scrollHeight || doc.body.scrollHeight) * scale + 24);
                    iframe.style.height = `${Math.max(scaledHeight, 560)}px`;
                } else {
                    doc.body.style.transform = '';
                    doc.body.style.width = '';
                    iframe.style.height = `${Math.max(Math.ceil((doc.documentElement.scrollHeight || doc.body.scrollHeight) + 24), 560)}px`;
                }
            } catch (error) {
                // Keep default rendering when fitting fails.
            }
        };

        iframe.addEventListener('load', adjust, { once: true });
        setTimeout(adjust, 220);
    }

    closeModal() {
        const modal = document.getElementById('archivedEmailModal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.classList.remove('modal-open');
    }

    updateCount() {
        const count = document.getElementById('archivedEmailCount');
        if (count) {
            const total = this.archivedEmails.length;
            count.textContent = `(${total} email${total === 1 ? '' : 's'})`;
        }
    }

    resetReaderPanel() {
        const readerPanel = document.getElementById('archivedReaderPanel');
        if (!readerPanel || !window.matchMedia('(min-width: 861px)').matches) {
            return;
        }
        readerPanel.innerHTML = `
            <div class="reader-empty"><span>✦</span>
                <h2>Archived inbox ready</h2>
                <p>Select an archived message to read it here.</p>
            </div>
        `;
    }

    showMessage(message, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `${type}-message`;
        messageDiv.textContent = message;

        const container = document.querySelector('.archived-list-pane') || document.body;
        container.prepend(messageDiv);

        setTimeout(() => {
            messageDiv.remove();
        }, 4000);
    }

    sanitizeHTML(html) {
        if (!html || html === null || html === undefined) {
            return '<p>No HTML content available</p>';
        }

        const htmlStr = String(html)
            .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
            .replace(/<noscript[\s\S]*?>[\s\S]*?<\/noscript>/gi, '');

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = htmlStr;

        const forbidden = tempDiv.querySelectorAll('script, noscript, iframe, object, embed, link, meta');
        forbidden.forEach((el) => el.remove());

        const allElements = tempDiv.querySelectorAll('*');
        allElements.forEach((element) => {
            const attributes = [...element.attributes];
            attributes.forEach((attr) => {
                if (attr.name.startsWith('on') || attr.name === 'javascript:') {
                    element.removeAttribute(attr.name);
                }
            });

            if (['form', 'input', 'button', 'textarea'].includes(element.tagName.toLowerCase())) {
                element.remove();
            }
        });

        return `<div style="max-width: 100%; overflow-x: auto; font-family: Arial, sans-serif; line-height: 1.4;">${tempDiv.innerHTML}</div>`;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    getEmailPreview(body) {
        if (!body || body === null || body === undefined) {
            return 'No content available';
        }
        const bodyStr = String(body);
        const text = bodyStr.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
        return text.length > 100 ? `${text.substring(0, 100)}...` : (text || 'No content available');
    }

    initializeDarkMode() {
        const toggle = document.getElementById('darkModeToggle');
        const icon = document.getElementById('darkModeIcon');
        const themeColor = document.querySelector('meta[name="theme-color"]');

        if (!toggle || !icon) {
            return;
        }

        const applyDarkMode = (enabled) => {
            document.body.classList.toggle('dark-mode', enabled);
            toggle.setAttribute('aria-pressed', String(enabled));
            toggle.setAttribute('aria-label', enabled ? 'Switch to light mode' : 'Switch to dark mode');
            icon.textContent = enabled ? '☀️' : '🌙';

            if (themeColor) {
                themeColor.setAttribute('content', enabled ? '#0F172A' : '#F8FAFC');
            }
        };

        let darkModeEnabled = false;
        try {
            const savedPreference = localStorage.getItem('darkMode');
            darkModeEnabled = savedPreference === null
                ? window.matchMedia('(prefers-color-scheme: dark)').matches
                : savedPreference === 'true';
        } catch (error) {
            darkModeEnabled = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        applyDarkMode(darkModeEnabled);

        toggle.addEventListener('click', () => {
            darkModeEnabled = !document.body.classList.contains('dark-mode');
            applyDarkMode(darkModeEnabled);
            try {
                localStorage.setItem('darkMode', String(darkModeEnabled));
            } catch (error) {
                // ignore storage errors
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('archivedList') && document.getElementById('csrfToken')) {
        new ArchivedMailboxPage();
    }
});
