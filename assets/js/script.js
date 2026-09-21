class TempEmailService {
    constructor() {
        this.currentEmail = null;
        this.currentOpenEmailId = null;
        this.loadedEmails = [];
        this.selectedEmailIds = new Set();
        this.refreshInterval = null;
        this.autoFetchInterval = null;
        this.isRefreshing = false;
        this.isAutoFetching = false;
        this.csrfToken = document.getElementById('csrfToken')?.value || '';
        this.init();
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
                return token;
            }
        } catch (error) {
            console.error('Failed to get fresh CSRF token:', error);
        }
        return this.csrfToken; // fallback to existing token
    }

    init() {
        this.bindEvents();
        this.loadDomains();
        this.loadSavedEmail();
        this.startAutoFetch(); // Start automatic email fetching

        // Check URL for email param
        const urlParams = new URLSearchParams(window.location.search);
        const emailParam = urlParams.get('email');
        if (emailParam) {
            this.currentEmail = { email_address: emailParam, expires_at: new Date(Date.now() + 3600000).toISOString() }; // Assume 1 hour expiry
            this.displayEmail(this.currentEmail);
            this.refreshInbox();
        }

        // Ensure proper initial state display
        if (!this.currentEmail) {
            this.showInitialMessage();
        }
    }

    bindEvents() {
        document.getElementById('generateBtn').addEventListener('click', () => this.generateEmail());
        document.getElementById('copyBtn').addEventListener('click', () => this.copyEmail());
        document.getElementById('deleteBtn').addEventListener('click', () => this.deleteEmail());
        document.getElementById('refreshBtn').addEventListener('click', () => this.refreshInbox(true));
        const selectAllCheckbox = document.getElementById('selectAllEmails');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (event) => this.toggleSelectAllEmails(event.target.checked));
        }
        const deleteSelectedButton = document.getElementById('deleteSelectedBtn');
        if (deleteSelectedButton) {
            deleteSelectedButton.addEventListener('click', () => this.deleteSelectedMessages());
        }
        const archiveSelectedButton = document.getElementById('archiveSelectedBtn');
        if (archiveSelectedButton) {
            archiveSelectedButton.addEventListener('click', () => this.archiveSelectedMessages());
        }
        const signOutLink = document.querySelector('.sign-out');
        if (signOutLink) {
            signOutLink.addEventListener('click', () => {
                this.clearSavedEmail();
            });
        }
        const sampleInboxButton = document.getElementById('loadSampleInboxBtn');
        if (sampleInboxButton) {
            sampleInboxButton.addEventListener('click', () => this.loadSampleInbox());
        }

        // Modal events
        document.querySelector('.close-modal').addEventListener('click', () => this.closeModal());
        document.getElementById('emailModal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                this.closeModal();
            }
        });
    }

    async loadDomains() {
        try {
            const csrfToken = this.csrfToken;
            const response = await fetch('includes/handlers/get_domains.php', {
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });
            const data = await response.json();

            if (data.success && data.domains.length > 0) {
                const select = document.getElementById('domainSelect');
                select.innerHTML = '';

                // Add "Random" option as default
                const randomOption = document.createElement('option');
                randomOption.value = '';
                randomOption.textContent = 'Random Domain';
                select.appendChild(randomOption);

                // Add each domain as an option
                data.domains.forEach(domain => {
                    const option = document.createElement('option');
                    option.value = domain.id;
                    option.textContent = domain.domain_name;
                    select.appendChild(option);
                });
            } else {
                document.getElementById('domainSelect').innerHTML = '<option value="">No domains available</option>';
            }
        } catch (error) {
            console.error('Failed to load domains:', error);
            document.getElementById('domainSelect').innerHTML = '<option value="">Error loading domains</option>';
        }
    }

    async generateEmail() {
        try {
            this.showLoading('generateBtn');

            // Use existing CSRF token instead of trying to get fresh one
            const csrfToken = this.csrfToken;

            // Get selected domain
            const selectedDomain = document.getElementById('domainSelect').value;
            const requestBody = {};

            if (selectedDomain) {
                requestBody.domain_id = parseInt(selectedDomain);
            }

            const response = await fetch('includes/handlers/generate_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(requestBody)
            });

            const data = await response.json();

            if (data.success) {
                this.currentEmail = data;
                this.displayEmail(data);
                this.saveEmail(data);

                // Update inbox to show "No emails received yet" with dancing dots
                this.displayEmails([]);

                this.showMessage('Email generated successfully!', 'success');
            } else {
                this.showMessage(data.error || 'Failed to generate email', 'error');
            }
        } catch (error) {
            console.error('Network error:', error);
            this.showMessage('Network error occurred', 'error');
        } finally {
            this.hideLoading('generateBtn');
        }
    }

    async loadSampleInbox() {
        const button = document.getElementById('loadSampleInboxBtn');
        try {
            if (button) button.disabled = true;
            const response = await fetch('includes/handlers/load_sample_inbox.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken }
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to open the sample inbox');
            }
            this.currentEmail = { email_address: data.email_address, expires_at: data.expires_at };
            this.displayEmail(this.currentEmail);
            this.saveEmail(this.currentEmail);
            await this.refreshInbox(false);
            this.showMessage('Sample inbox loaded. These messages are sanitized test data.', 'success');
        } catch (error) {
            this.showMessage(error.message || 'Unable to open the sample inbox', 'error');
        } finally {
            if (button) button.disabled = false;
        }
    }

    displayEmail(emailData) {
        const emailDisplay = document.getElementById('currentEmail');
        const emailAddress = document.getElementById('emailAddress');
        const expiryTime = document.getElementById('expiryTime');

        emailAddress.textContent = emailData.email_address;
        expiryTime.textContent = new Date(emailData.expires_at).toLocaleString();
        emailDisplay.style.display = 'block';

        // Update generate button text
        document.getElementById('generateBtn').textContent = 'Generate New Email';
    }

    async copyEmail() {
        if (!this.currentEmail) return;

        try {
            await navigator.clipboard.writeText(this.currentEmail.email_address);
            this.showCopyConfirmation();
            this.showMessage('Email address copied to clipboard!', 'success');
        } catch (error) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = this.currentEmail.email_address;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showCopyConfirmation();
            this.showMessage('Email address copied to clipboard!', 'success');
        }
    }

    showCopyConfirmation() {
        const copyButton = document.getElementById('copyBtn');
        if (!copyButton) return;

        clearTimeout(this.copyConfirmationTimer);
        copyButton.classList.remove('copied');
        // Restart the animation when the user copies more than once in quick succession.
        void copyButton.offsetWidth;
        copyButton.classList.add('copied');
        copyButton.textContent = 'Copied ✓';
        copyButton.setAttribute('aria-label', 'Email address copied');

        this.copyConfirmationTimer = setTimeout(() => {
            copyButton.classList.remove('copied');
            copyButton.textContent = 'Copy';
            copyButton.setAttribute('aria-label', 'Copy email address');
        }, 1600);
    }

    async deleteEmail() {
        if (!this.currentEmail) return;

        if (!confirm('Are you sure you want to delete this email address?')) {
            return;
        }

        try {
            const csrfToken = await this.getFreshCSRFToken();

            const response = await fetch('includes/handlers/delete_email.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email_address: this.currentEmail.email_address
                })
            });

            const data = await response.json();

            if (data.success) {
                this.currentEmail = null;
                this.currentOpenEmailId = null;
                document.getElementById('currentEmail').style.display = 'none';
                document.getElementById('emailList').innerHTML = `
                    <div class="no-emails">
                        <p>Generate an email address to start receiving messages</p>
                    </div>
                `;
                this.loadedEmails = [];
                this.selectedEmailIds.clear();
                this.updateBulkActions();
                this.resetReaderPanel();
                this.clearSavedEmail();
                this.stopAutoRefresh();
                this.stopAutoFetch(); // Stop auto-fetching when email is deleted
                this.showMessage('Email address deleted successfully!', 'success');
            } else {
                if (this.isSessionOrphanError(data.error || '')) {
                    this.resetOrphanMailboxState(true);
                    return;
                }
                this.showMessage(data.error || 'Failed to delete email', 'error');
            }
        } catch (error) {
            this.showMessage('Network error occurred', 'error');
        }
    }

    isSessionOrphanError(message) {
        const text = String(message || '').toLowerCase();
        return text.includes('not owned by this session')
            || text.includes('not found for this session')
            || text.includes('mailbox access denied')
            || text.includes('email address not found');
    }

    resetOrphanMailboxState(showFeedback = false) {
        this.currentEmail = null;
        this.currentOpenEmailId = null;
        this.loadedEmails = [];
        this.selectedEmailIds.clear();
        this.clearSavedEmail();
        this.stopAutoRefresh();

        const currentEmail = document.getElementById('currentEmail');
        if (currentEmail) {
            currentEmail.style.display = 'none';
        }

        this.showInitialMessage();
        this.updateBulkActions();
        this.resetReaderPanel();
        this.updateEmailCount(0);

        if (showFeedback) {
            this.showMessage('Previous mailbox is no longer linked to this session. Generate a new address.', 'error');
        }
    }

    async refreshInbox(showFeedback = false) {
        if (this.isRefreshing) {
            return;
        }

        this.isRefreshing = true;

        // Check if we have a current email, if not try to find one from the existing temp emails
        if (!this.currentEmail) {
            // Try to get the most recent active email from localStorage or generate message
            const savedEmail = localStorage.getItem('tempEmail');
            if (savedEmail) {
                try {
                    const emailData = JSON.parse(savedEmail);
                    if (new Date(emailData.expires_at) > new Date()) {
                        this.currentEmail = emailData;
                        this.displayEmail(emailData);
                    }
                } catch (error) {
                    // If we can't load saved email, show message
                    if (showFeedback) {
                        this.showMessage('Please generate an email address first', 'error');
                    }
                    this.isRefreshing = false;
                    return;
                }
            } else {
                if (showFeedback) {
                    this.showMessage('Please generate an email address first', 'error');
                }
                this.isRefreshing = false;
                return;
            }
        }

        try {
            this.showLoading('refreshBtn');

            // Use existing CSRF token first, get fresh one if it fails
            let csrfToken = this.csrfToken;

            const response = await fetch('includes/handlers/get_emails.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email_address: this.currentEmail.email_address
                })
            });

            // If CSRF token fails, try getting a fresh one
            if (response.status === 403) {
                console.log('CSRF token expired, getting fresh token...');
                csrfToken = await this.getFreshCSRFToken();

                const retryResponse = await fetch('includes/handlers/get_emails.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        email_address: this.currentEmail.email_address
                    })
                });

                if (!retryResponse.ok) {
                    throw new Error(`HTTP ${retryResponse.status}: ${retryResponse.statusText}`);
                }

                const retryData = await retryResponse.json();

                if (retryData.success) {
                    this.displayEmails(retryData.emails);
                    this.updateEmailCount(retryData.emails.length);
                    if (showFeedback) {
                        this.showMessage('Inbox refreshed successfully!', 'success');
                    }
                } else {
                    if (this.isSessionOrphanError(retryData.error || '')) {
                        this.resetOrphanMailboxState(showFeedback);
                        return;
                    }
                    if (showFeedback) {
                        this.showMessage(retryData.error || 'Failed to refresh inbox', 'error');
                    }
                }

                return;
            }

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.success) {
                this.displayEmails(data.emails);
                this.updateEmailCount(data.emails.length);
                if (showFeedback) {
                    this.showMessage('Inbox refreshed successfully!', 'success');
                }
            } else {
                if (this.isSessionOrphanError(data.error || '')) {
                    this.resetOrphanMailboxState(showFeedback);
                    return;
                }
                if (showFeedback) {
                    this.showMessage(data.error || 'Failed to refresh inbox', 'error');
                }
            }
        } catch (error) {
            console.error('Refresh inbox error:', error);
            if (showFeedback) {
                this.showMessage('Network error: ' + (error.message || 'Unknown error'), 'error');
            }
        } finally {
            this.hideLoading('refreshBtn');
            this.isRefreshing = false;
        }
    }

    displayEmails(emails) {
        const emailList = document.getElementById('emailList');
        this.loadedEmails = Array.isArray(emails) ? emails : [];
        this.selectedEmailIds.clear();

        if (emails.length === 0) {
            emailList.innerHTML = `
                <div class="no-emails">
                    <div class="empty-inbox-message">
                        <div class="dancing-dots-container">
                            <span class="dancing-dots-text">No emails received yet</span>
                            <div class="dancing-dots">
                                <div class="dot"></div>
                                <div class="dot"></div>
                                <div class="dot"></div>
                                <div class="dot"></div>
                                <div class="dot"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            this.updateBulkActions();
            return;
        }

        const emailsHtml = emails.map(email => `
            <div class="email-item" data-email-id="${email.id}">
                <div class="email-item-select">
                    <input type="checkbox" class="email-select-checkbox" data-email-id="${email.id}" aria-label="Select message ${this.escapeHtml(email.subject || 'No Subject')}">
                </div>
                <div class="email-item-content">
                    <div class="email-header">
                        <div class="email-subject">${this.escapeHtml(email.subject || 'No Subject')}</div>
                        <div class="email-date">${new Date(email.received_at).toLocaleString()}</div>
                    </div>
                    <div class="email-from">From: ${this.escapeHtml(email.sender_name || email.sender_email || 'Unknown')}</div>
                    <div class="email-preview">${this.escapeHtml(this.getEmailPreview(email.body_text || email.body_html || ''))}</div>
                </div>
            </div>
        `).join('');

        emailList.innerHTML = emailsHtml;
        this.updateBulkActions();

        emailList.querySelectorAll('.email-select-checkbox').forEach((checkbox) => {
            checkbox.addEventListener('click', (event) => {
                event.stopPropagation();
            });
            checkbox.addEventListener('change', (event) => {
                const emailId = Number(event.target.dataset.emailId || 0);
                this.toggleEmailSelection(emailId, event.target.checked);
            });
        });

        // Add click events to email items
        emailList.querySelectorAll('.email-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.email-select-checkbox')) {
                    return;
                }
                const emailId = item.dataset.emailId;
                const email = emails.find(e => e.id == emailId);
                this.currentOpenEmailId = Number(emailId);
                this.showEmailModal(email);
            });
        });
    }

    toggleEmailSelection(emailId, isSelected) {
        if (!emailId) return;

        if (isSelected) {
            this.selectedEmailIds.add(emailId);
        } else {
            this.selectedEmailIds.delete(emailId);
        }

        const row = document.querySelector(`.email-item[data-email-id="${emailId}"]`);
        if (row) {
            row.classList.toggle('selected', isSelected);
        }

        this.updateBulkActions();
    }

    toggleSelectAllEmails(checked) {
        this.selectedEmailIds.clear();

        document.querySelectorAll('.email-select-checkbox').forEach((checkbox) => {
            checkbox.checked = checked;
            const emailId = Number(checkbox.dataset.emailId || 0);
            if (checked && emailId) {
                this.selectedEmailIds.add(emailId);
            }
            const row = checkbox.closest('.email-item');
            if (row) {
                row.classList.toggle('selected', checked);
            }
        });

        this.updateBulkActions();
    }

    updateBulkActions() {
        const bar = document.getElementById('bulkActionsBar');
        const selectAll = document.getElementById('selectAllEmails');
        const selectedCount = document.getElementById('selectedEmailCount');
        const deleteSelected = document.getElementById('deleteSelectedBtn');
        const archiveSelected = document.getElementById('archiveSelectedBtn');

        if (!bar || !selectAll || !selectedCount || !deleteSelected || !archiveSelected) {
            return;
        }

        const total = this.loadedEmails.length;
        const selected = this.selectedEmailIds.size;

        bar.style.display = total > 0 ? 'flex' : 'none';
        selectedCount.textContent = `${selected} selected`;
        deleteSelected.disabled = selected === 0;
        archiveSelected.disabled = selected === 0;
        selectAll.checked = total > 0 && selected === total;
        selectAll.indeterminate = selected > 0 && selected < total;
    }

    async archiveSelectedMessages() {
        if (!this.currentEmail || this.selectedEmailIds.size === 0) {
            return;
        }

        const selectedIds = Array.from(this.selectedEmailIds);
        if (!confirm(`Archive ${selectedIds.length} selected message${selectedIds.length > 1 ? 's' : ''}? Archived emails are preserved in /archived.`)) {
            return;
        }

        const archiveButton = document.getElementById('archiveSelectedBtn');
        if (archiveButton) {
            archiveButton.disabled = true;
        }

        try {
            let csrfToken = this.csrfToken;
            let response = await fetch('includes/handlers/archive_messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email_address: this.currentEmail.email_address,
                    message_ids: selectedIds
                })
            });

            if (response.status === 403) {
                csrfToken = await this.getFreshCSRFToken();
                response = await fetch('includes/handlers/archive_messages.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        email_address: this.currentEmail.email_address,
                        message_ids: selectedIds
                    })
                });
            }

            const data = await response.json();
            if (!response.ok || !data.success) {
                if (this.isSessionOrphanError(data.error || '')) {
                    this.resetOrphanMailboxState(true);
                    return;
                }
                this.showMessage(data.error || 'Failed to archive selected messages', 'error');
                return;
            }

            const archivedIds = new Set(selectedIds);
            this.loadedEmails = this.loadedEmails.filter((email) => !archivedIds.has(Number(email.id)));
            this.selectedEmailIds.clear();

            if (this.currentOpenEmailId && archivedIds.has(Number(this.currentOpenEmailId))) {
                this.currentOpenEmailId = null;
                this.resetReaderPanel();
            }

            this.displayEmails(this.loadedEmails);
            this.updateEmailCount(this.loadedEmails.length);
            this.showMessage(`Archived ${Number(data.archived || 0)} message${Number(data.archived || 0) === 1 ? '' : 's'}.`, 'success');
        } catch (error) {
            this.showMessage('Network error while archiving selected messages', 'error');
        } finally {
            this.updateBulkActions();
        }
    }

    async deleteSelectedMessages() {
        if (!this.currentEmail || this.selectedEmailIds.size === 0) {
            return;
        }

        const selectedIds = Array.from(this.selectedEmailIds);
        if (!confirm(`Delete ${selectedIds.length} selected message${selectedIds.length > 1 ? 's' : ''} from SQL inbox?`)) {
            return;
        }

        const button = document.getElementById('deleteSelectedBtn');
        if (button) {
            button.disabled = true;
        }

        try {
            let csrfToken = this.csrfToken;
            let response = await fetch('includes/handlers/delete_messages.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email_address: this.currentEmail.email_address,
                    message_ids: selectedIds
                })
            });

            if (response.status === 403) {
                csrfToken = await this.getFreshCSRFToken();
                response = await fetch('includes/handlers/delete_messages.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        email_address: this.currentEmail.email_address,
                        message_ids: selectedIds
                    })
                });
            }

            const data = await response.json();
            if (!response.ok || !data.success) {
                if (this.isSessionOrphanError(data.error || '')) {
                    this.resetOrphanMailboxState(true);
                    return;
                }
                this.showMessage(data.error || 'Failed to delete selected messages', 'error');
                return;
            }

            const deletedIds = new Set(selectedIds);
            this.loadedEmails = this.loadedEmails.filter((email) => !deletedIds.has(Number(email.id)));
            this.selectedEmailIds.clear();

            if (this.currentOpenEmailId && deletedIds.has(Number(this.currentOpenEmailId))) {
                this.currentOpenEmailId = null;
                this.resetReaderPanel();
            }

            this.displayEmails(this.loadedEmails);
            this.updateEmailCount(this.loadedEmails.length);
            this.showMessage(`Deleted ${Number(data.deleted || 0)} message${Number(data.deleted || 0) === 1 ? '' : 's'} from SQL inbox.`, 'success');
        } catch (error) {
            this.showMessage('Network error while deleting selected messages', 'error');
        } finally {
            this.updateBulkActions();
        }
    }

    resetReaderPanel() {
        const readerPanel = document.getElementById('readerPanel');
        if (!readerPanel || !window.matchMedia('(min-width: 861px)').matches) {
            return;
        }

        readerPanel.innerHTML = `
            <div class="reader-empty"><span>✦</span>
                <h2>Your mailbox is ready</h2>
                <p>Generate a temporary address, then select a message from your inbox to read it here.</p>
            </div>
        `;
    }

    showEmailModal(email) {
        const readerPanel = document.getElementById('readerPanel');
        const canUseInlineReader = !!readerPanel && window.matchMedia('(min-width: 861px)').matches;

        if (canUseInlineReader) {
            const bodyHtml = email.body_html || '';
            const bodyText = email.body_text || email.body || '';
            let bodyMarkup;
            let hasHtmlBody = false;

            if (bodyHtml && bodyHtml.trim() !== '') {
                hasHtmlBody = true;
                const htmlContent = this.sanitizeHTML(bodyHtml).replace(/'/g, '&#39;');
                bodyMarkup = `<iframe class="reader-email-iframe" title="Email content" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms" srcdoc='<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>html,body{margin:0;padding:0}body{padding:14px;background:#f8fafc;font-family:Arial,sans-serif;color:#344054}img{max-width:100%!important;height:auto!important}table{max-width:100%!important}</style></head><body>${htmlContent}</body></html>'></iframe>`;
            } else {
                bodyMarkup = `<pre>${this.escapeHtml(bodyText || 'No email content available')}</pre>`;
            }

            readerPanel.innerHTML = `
                <article class="reader-message">
                    <h2>${this.escapeHtml(email.subject || 'No Subject')}</h2>
                    <div class="reader-message-meta">
                        <span><strong>From:</strong> ${this.escapeHtml(email.sender_name || email.sender_email || 'Unknown')}</span>
                        <span>${new Date(email.received_at).toLocaleString()}</span>
                    </div>
                    <div class="reader-message-body">${bodyMarkup}</div>
                </article>
            `;

            if (hasHtmlBody) {
                const frame = readerPanel.querySelector('.reader-email-iframe');
                this.fitEmailIframeToReader(frame);
            }

            return;
        }

        document.getElementById('modalSubject').textContent = email.subject || 'No Subject';
        document.getElementById('modalFrom').textContent = `${email.sender_name || email.sender_email || 'Unknown'} <${email.sender_email || 'unknown@unknown.com'}>`;
        document.getElementById('modalDate').textContent = new Date(email.received_at).toLocaleString();

        // Display email body with proper formatting
        const modalBody = document.getElementById('modalBody');

        // Safely get body content with null checks
        const bodyHtml = email.body_html || '';
        const bodyText = email.body_text || '';
        const bodyFallback = email.body || '';

        if (bodyHtml && bodyHtml.trim() !== '') {
            // Display HTML content safely in an iframe to isolate styles
            const htmlContent = this.sanitizeHTML(bodyHtml);
            modalBody.innerHTML = `
                <div class="email-html-content">
                    <iframe 
                        style="width: 100%; border: none; min-height: 400px;" 
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms"
                        srcdoc='<!DOCTYPE html>
                        <html>
                        <head>
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                            <style>
                                body { margin: 0; padding: 10px; font-family: Arial, sans-serif; }
                                img { max-width: 100% !important; height: auto !important; }
                            </style>
                        </head>
                        <body>${htmlContent.replace(/'/g, "&#39;")}</body>
                        </html>'>
                    </iframe>
                </div>
                <hr>
                <div class="email-text-fallback">
                    <strong>Text version:</strong>
                    <pre>${this.escapeHtml(bodyText || 'No text version available')}</pre>
                </div>
            `;
        } else if (bodyText && bodyText.trim() !== '') {
            // Display plain text content
            modalBody.innerHTML = `<pre class="email-text-content">${this.escapeHtml(bodyText)}</pre>`;
        } else if (bodyFallback && bodyFallback.trim() !== '') {
            // Fallback for old emails
            modalBody.innerHTML = `<pre class="email-text-content">${this.escapeHtml(bodyFallback)}</pre>`;
        } else {
            // No content available
            modalBody.innerHTML = `<div class="no-content"><p>No email content available</p></div>`;
        }

        document.getElementById('emailModal').style.display = 'flex';
        // Add class to body to prevent scroll without layout shift
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

                // Only upscale clearly narrow email templates, capped for readability.
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
                // If fitting fails, keep the default iframe rendering.
            }
        };

        iframe.addEventListener('load', adjust, { once: true });
        setTimeout(adjust, 220);
    }

    sanitizeHTML(html) {
        // Handle null or undefined input
        if (!html || html === null || html === undefined) {
            return '<p>No HTML content available</p>';
        }

        const htmlStr = String(html);

        // Prefer DOMPurify (loaded from the CDN allowlist) for real sanitization
        // instead of the previous hand-rolled blocklist, which did not neutralize
        // things like javascript: URIs, <style> blocks, or mXSS via double
        // innerHTML re-serialization.
        let cleaned;
        if (typeof window !== 'undefined' && window.DOMPurify) {
            cleaned = window.DOMPurify.sanitize(htmlStr, {
                FORBID_TAGS: ['script', 'noscript', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'style'],
                FORBID_ATTR: ['style', 'srcset'],
                ALLOW_DATA_ATTR: false
            });
        } else {
            // Fallback if DOMPurify failed to load: keep the previous best-effort
            // blocklist so the page doesn't break, but this path should be rare.
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = htmlStr
                .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
                .replace(/<noscript[\s\S]*?>[\s\S]*?<\/noscript>/gi, '');

            tempDiv.querySelectorAll('script, noscript, iframe, object, embed, link, meta, style').forEach(el => el.remove());

            tempDiv.querySelectorAll('*').forEach(element => {
                [...element.attributes].forEach(attr => {
                    const name = attr.name.toLowerCase();
                    const value = attr.value.trim().toLowerCase();
                    if (name.startsWith('on') || value.startsWith('javascript:') || name === 'style') {
                        element.removeAttribute(attr.name);
                    }
                });

                if (['form', 'input', 'button', 'textarea'].includes(element.tagName.toLowerCase())) {
                    element.remove();
                }
            });

            cleaned = tempDiv.innerHTML;
        }

        // Limit the content and add some basic styling
        return `
            <div style="max-width: 100%; overflow-x: auto; font-family: Arial, sans-serif; line-height: 1.4;">
                ${cleaned}
            </div>
        `;
    }

    closeModal() {
        document.getElementById('emailModal').style.display = 'none';
        // Remove modal-open class to restore scroll
        document.body.classList.remove('modal-open');
    }

    updateEmailCount(count) {
        document.getElementById('emailCount').textContent = `(${count} email${count !== 1 ? 's' : ''})`;
    }

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshInterval = setInterval(() => {
            this.refreshInbox();
        }, 30000); // Refresh every 30 seconds
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    saveEmail(emailData) {
        localStorage.setItem('tempEmail', JSON.stringify(emailData));
    }

    loadSavedEmail() {
        const savedEmail = localStorage.getItem('tempEmail');
        if (savedEmail) {
            try {
                const emailData = JSON.parse(savedEmail);
                if (new Date(emailData.expires_at) > new Date()) {
                    this.currentEmail = emailData;
                    this.displayEmail(emailData);
                    this.refreshInbox(false);
                } else {
                    this.clearSavedEmail();
                }
            } catch (error) {
                this.clearSavedEmail();
            }
        }
    }

    clearSavedEmail() {
        localStorage.removeItem('tempEmail');
    }

    showLoading(buttonId) {
        const button = document.getElementById(buttonId);
        button.classList.add('loading');
        button.disabled = true;
    }

    hideLoading(buttonId) {
        const button = document.getElementById(buttonId);
        button.classList.remove('loading');
        button.disabled = false;
    }

    showMessage(message, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `${type}-message`;
        messageDiv.textContent = message;

        const container = document.querySelector('.email-generator') || document.querySelector('.mailbox-sidebar');
        if (container) {
            container.insertBefore(messageDiv, container.firstChild);
        }

        setTimeout(() => {
            messageDiv.remove();
        }, 5000);
    }

    showInitialMessage() {
        document.getElementById('emailList').innerHTML = `
            <div class="no-emails">
                <p>Generate an email address to start receiving messages</p>
            </div>
        `;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    getEmailPreview(body) {
        // Handle null, undefined, or empty body content
        if (!body || body === null || body === undefined) {
            return 'No content available';
        }

        // Convert to string if it's not already
        const bodyStr = String(body);

        // Remove HTML tags and clean up
        const text = bodyStr.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();

        // Return preview with length limit
        return text.length > 100 ? text.substring(0, 100) + '...' : text || 'No content available';
    }

    // Auto-fetch emails from server
    startAutoFetch() {
        // Fetch emails every 60 seconds
        this.autoFetchInterval = setInterval(() => {
            this.autoFetchEmails();
        }, 60000); // 60 seconds

        // Also fetch immediately
        setTimeout(() => this.autoFetchEmails(), 2000);
    }

    async autoFetchEmails() {
        try {
            if (this.isAutoFetching) return;

            // Only auto-fetch if we have a current email
            if (!this.currentEmail) return;

            this.isAutoFetching = true;

            // Call the auto-fetch API
            const response = await fetch('includes/handlers/auto_fetch.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                },
                body: JSON.stringify({
                    email_address: this.currentEmail.email_address
                })
            });

            if (response.ok) {
                const data = await response.json();
                // Refresh the inbox only when new messages were stored.
                if (data.success && Number(data.stored || 0) > 0) {
                    this.refreshInbox(false);
                }
            }
        } catch (error) {
            console.error('Auto-fetch error:', error);
        } finally {
            this.isAutoFetching = false;
        }
    }

    stopAutoFetch() {
        if (this.autoFetchInterval) {
            clearInterval(this.autoFetchInterval);
            this.autoFetchInterval = null;
        }
    }
}

function initializeDarkMode() {
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
            // The visual toggle still works when storage is unavailable.
        }
    });
}

// Initialize page features when the DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    initializeDarkMode();

    // The public page used #emailGenerator; the authenticated mailbox has its
    // own layout. Start the service whenever its required controls are present.
    if (document.getElementById('generateBtn') && document.getElementById('csrfToken')) {
        new TempEmailService();
    }
});
