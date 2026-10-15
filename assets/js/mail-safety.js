/**
 * Shared handling for untrusted email HTML, used by the mailbox and archive pages.
 *   - sanitizeHTML(html): DOMPurify (blocklist fallback if the library failed to load)
 *   - guardLinks(iframe): asks for confirmation, with the real destination, before a
 *     link in a received message is opened
 */
(function (global) {
    'use strict';

    function sanitizeHTML(html) {
        if (html === null || html === undefined || html === '') {
            return '<p>No HTML content available</p>';
        }

        var htmlStr = String(html);
        var cleaned;

        if (global.DOMPurify) {
            // Links open in a new tab, never inside the sandboxed frame, and do not
            // expose the opener or referrer. Registered once; runs after sanitizing.
            if (!global.__tipLinkHook) {
                global.DOMPurify.addHook('afterSanitizeAttributes', function (node) {
                    if (node.tagName === 'A' && node.hasAttribute('href')) {
                        node.setAttribute('target', '_blank');
                        node.setAttribute('rel', 'noopener noreferrer nofollow');
                    }
                });
                global.__tipLinkHook = true;
            }

            cleaned = global.DOMPurify.sanitize(htmlStr, {
                FORBID_TAGS: ['script', 'noscript', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'style'],
                FORBID_ATTR: ['style', 'srcset'],
                ALLOW_DATA_ATTR: false
            });
        } else {
            // DOMPurify did not load: weaker blocklist so the page still works.
            var tempDiv = document.createElement('div');
            tempDiv.innerHTML = htmlStr
                .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
                .replace(/<noscript[\s\S]*?>[\s\S]*?<\/noscript>/gi, '');

            tempDiv.querySelectorAll('script, noscript, iframe, object, embed, link, meta, style').forEach(function (el) {
                el.remove();
            });

            tempDiv.querySelectorAll('*').forEach(function (element) {
                Array.prototype.slice.call(element.attributes).forEach(function (attr) {
                    var name = attr.name.toLowerCase();
                    var value = attr.value.trim().toLowerCase();
                    if (name.indexOf('on') === 0 || value.indexOf('javascript:') === 0 || name === 'style') {
                        element.removeAttribute(attr.name);
                    }
                });

                if (element.tagName === 'A' && element.hasAttribute('href')) {
                    element.setAttribute('target', '_blank');
                    element.setAttribute('rel', 'noopener noreferrer nofollow');
                }

                if (['form', 'input', 'button', 'textarea'].indexOf(element.tagName.toLowerCase()) !== -1) {
                    element.remove();
                }
            });

            cleaned = tempDiv.innerHTML;
        }

        return '<div style="max-width: 100%; overflow-x: auto; font-family: Arial, sans-serif; line-height: 1.4;">' +
            cleaned + '</div>';
    }

    function hostOf(value) {
        var m = String(value).trim().match(/^(?:https?:\/\/)?([a-z0-9][a-z0-9.-]*\.[a-z]{2,})(?:[\/:?#]|$)/i);
        return m ? m[1].toLowerCase().replace(/^www\./, '') : null;
    }

    function findCautions(url, linkText) {
        var cautions = [];
        var destHost = url.hostname.toLowerCase().replace(/^www\./, '');
        var shown = hostOf(linkText);

        if (shown && shown !== destHost && !destHost.endsWith('.' + shown)) {
            cautions.push('The link text shows “' + linkText + '”, but the link goes to ' + url.hostname + '.');
        }
        if (url.hostname.indexOf('xn--') !== -1) {
            cautions.push('The domain uses international characters (xn--), which attackers use to imitate real sites.');
        }
        if (url.username || url.password) {
            cautions.push('The address contains a user name before the host, a trick used to disguise the real destination.');
        }
        if (/^\d{1,3}(\.\d{1,3}){3}$/.test(url.hostname)) {
            cautions.push('The destination is a raw IP address rather than a named site.');
        }
        if (url.protocol === 'http:') {
            cautions.push('This link is not encrypted (http).');
        }

        return cautions;
    }

    function closeLinkWarning() {
        var existing = document.getElementById('tipLinkWarning');
        if (existing) {
            existing.remove();
        }
        document.removeEventListener('keydown', onKeydown, true);
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closeLinkWarning();
        }
    }

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function showLinkWarning(rawHref, linkText) {
        var url;
        try {
            url = new URL(rawHref.indexOf('//') === 0 ? 'https:' + rawHref : rawHref);
        } catch (error) {
            return;
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return;
        }

        closeLinkWarning();

        var overlay = make('div', 'tip-link-warning-overlay');
        overlay.id = 'tipLinkWarning';
        var box = make('div', 'tip-link-warning-box');
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', 'tipLinkWarningTitle');

        var title = make('h2', 'tip-link-warning-title', 'Leaving TIP Tool');
        title.id = 'tipLinkWarningTitle';
        box.appendChild(title);
        box.appendChild(make('p', '', 'This message links to an outside website. Check the address before you continue.'));

        var dest = make('p', 'tip-link-warning-dest');
        dest.appendChild(make('span', 'tip-link-warning-label', 'Destination'));
        dest.appendChild(make('strong', 'tip-link-warning-host', url.hostname));
        dest.appendChild(make('span', 'tip-link-warning-path', url.pathname + url.search));
        box.appendChild(dest);

        var cautions = findCautions(url, linkText || '');
        if (cautions.length) {
            var list = make('ul', 'tip-link-warning-cautions');
            cautions.forEach(function (text) {
                list.appendChild(make('li', '', text));
            });
            box.appendChild(list);
        }

        box.appendChild(make('p', 'tip-link-warning-note',
            'Do not enter passwords, codes, or payment details on a page you reached from a message. Open the site yourself instead.'));

        var actions = make('div', 'tip-link-warning-actions');
        var cancel = make('button', 'tip-link-warning-cancel', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', closeLinkWarning);
        var open = make('button', 'tip-link-warning-open', 'Open link in new tab');
        open.type = 'button';
        open.addEventListener('click', function () {
            global.open(url.href, '_blank', 'noopener,noreferrer');
            closeLinkWarning();
        });
        actions.appendChild(cancel);
        actions.appendChild(open);
        box.appendChild(actions);

        overlay.appendChild(box);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeLinkWarning();
            }
        });
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKeydown, true);
        cancel.focus();
    }

    function guardLinks(iframe) {
        if (!iframe) {
            return;
        }

        var attach = function () {
            var doc;
            try {
                doc = iframe.contentDocument;
            } catch (error) {
                return;
            }
            if (!doc || !doc.body || doc.__tipLinkGuard) {
                return;
            }
            doc.__tipLinkGuard = true;
            doc.addEventListener('click', function (event) {
                var target = event.target;
                var anchor = target && target.closest ? target.closest('a[href]') : null;
                if (!anchor) {
                    return;
                }
                var href = (anchor.getAttribute('href') || '').trim();
                if (/^(mailto|tel):/i.test(href)) {
                    return;
                }
                event.preventDefault();
                if (/^(https?:)?\/\//i.test(href)) {
                    showLinkWarning(href, (anchor.textContent || '').trim());
                }
            }, true);
        };

        iframe.addEventListener('load', attach);
        attach();
    }

    global.TipMailSafety = {
        sanitizeHTML: sanitizeHTML,
        guardLinks: guardLinks
    };
})(window);
