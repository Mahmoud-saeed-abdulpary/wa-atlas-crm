/* WA Atlas CRM – Admin JS  v1.0.3
   ==============================================
   FIXES:
   - 403 auto-recovery: fetches fresh nonce then retries once automatically
   - Buttons disabled during requests (no double-submit)
   - fetch_instances passes fresh=1 after create/delete/restart
   - All tables show real error messages instead of spinning forever
   - initFields() wired into page router
   - debounced contact search
   ============================================== */

(function ($) {
    'use strict';

    var API = waCRM.ajax_url;
    var N   = waCRM.nonce; // mutable – gets refreshed on 403

    // ── AJAX helper with automatic nonce refresh on 403 ───────────────────────
    function ajax(action, data, _retry) {
        var dfd = $.Deferred();
        $.ajax({
            url:      API,
            method:   'POST',
            dataType: 'json',
            data:     $.extend({ action: action, _nonce: N }, data || {}),
        })
        .done(function (r) { dfd.resolve(r); })
        .fail(function (xhr) {
            if (xhr.status === 403 && !_retry) {
                // Nonce probably expired – get a fresh one then replay
                $.post(API, { action: 'wacrm_get_nonce' }, null, 'json')
                    .done(function (nr) {
                        if (nr && nr.success && nr.data && nr.data.nonce) {
                            N = nr.data.nonce;
                            ajax(action, data, true)
                                .done(function (r) { dfd.resolve(r); })
                                .fail(function () {
                                    toast('Session expired – please reload the page (F5)', 'error');
                                    dfd.resolve({ success: false, data: { message: 'Session expired.' } });
                                });
                        } else {
                            toast('Session expired – please reload the page (F5)', 'error');
                            dfd.resolve({ success: false, data: { message: 'Session expired.' } });
                        }
                    })
                    .fail(function () {
                        toast('Session expired – please reload the page (F5)', 'error');
                        dfd.resolve({ success: false, data: { message: 'Session expired.' } });
                    });
                return;
            }
            var raw = xhr.responseText || '';
            var msg = 'Request failed';
            if (xhr.status === 0)   msg = 'Network error – check your connection';
            else if (raw === '0')   msg = 'Action not found – try reactivating the plugin';
            else if (raw.indexOf('Fatal') !== -1 || raw.indexOf('Parse error') !== -1)
                                    msg = 'PHP error on server – check debug.log';
            else if (raw && raw[0] !== '{')
                                    msg = 'Invalid server response – disable WP_DEBUG_DISPLAY';
            try { var p = JSON.parse(raw); if (p && p.data && p.data.message) msg = p.data.message; } catch (e) {}
            toast(msg, 'error');
            console.error('[wacrm]', action, xhr.status, raw.substring(0, 400));
            dfd.resolve({ success: false, data: { message: msg } });
        });
        return dfd.promise();
    }

    // ── Utilities ─────────────────────────────────────────────────────────────
    function toast(msg, type) {
        type = type || 'success';
        var w = document.getElementById('wacrm-toast');
        if (!w) { w = document.createElement('div'); w.id = 'wacrm-toast'; document.body.appendChild(w); }
        var el = document.createElement('div');
        el.className = 'wacrm-toast-item ' + type;
        el.innerHTML = (type === 'success' ? '✓ ' : '✕ ') + escHtml(String(msg));
        w.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.remove(); }, 5000);
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = String(str || '');
        return d.innerHTML;
    }

    function debounce(fn, ms) {
        var t;
        return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); };
    }

    function setBtnLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = loading;
        if (loading) { btn.dataset.label = btn.textContent; btn.textContent = 'Loading…'; }
        else if (btn.dataset.label) btn.textContent = btn.dataset.label;
    }

    function renderStatus(s) {
        var map = {
            open: 'green', connecting: 'yellow', close: 'red', pending: 'yellow',
            active: 'green', draft: 'gray', running: 'blue', completed: 'green',
            paused: 'yellow', failed: 'red', sent: 'green',
            connected: 'green', disconnected: 'red', processing: 'blue',
        };
        var col = map[s] || 'gray';
        return '<span class="wacrm-badge badge-' + col + '">' + escHtml(s || '—') + '</span>';
    }

    function pagination(total, current, perPage, onClick) {
        var pages = Math.ceil(total / perPage);
        if (pages <= 1) return document.createDocumentFragment();
        var w = document.createElement('div');
        w.className = 'wacrm-pagination';
        var start = Math.max(1, current - 2), end = Math.min(pages, current + 2);
        if (current > 1) {
            var prev = document.createElement('button');
            prev.className = 'wacrm-btn wacrm-btn-sm wacrm-btn-outline';
            prev.textContent = '‹ Prev';
            prev.onclick = function () { onClick(current - 1); };
            w.appendChild(prev);
        }
        for (var pg = start; pg <= end; pg++) {
            (function (p) {
                var b = document.createElement('button');
                b.className = 'wacrm-btn wacrm-btn-sm ' + (p === current ? 'wacrm-btn-primary' : 'wacrm-btn-outline');
                b.textContent = p;
                b.onclick = function () { onClick(p); };
                w.appendChild(b);
            })(pg);
        }
        if (current < pages) {
            var next = document.createElement('button');
            next.className = 'wacrm-btn wacrm-btn-sm wacrm-btn-outline';
            next.textContent = 'Next ›';
            next.onclick = function () { onClick(current + 1); };
            w.appendChild(next);
        }
        return w;
    }

    function openModal(title, bodyHtml, onConfirm) {
        closeModal();
        var o = document.createElement('div');
        o.className = 'wacrm-modal-overlay'; o.id = 'wacrm-modal-overlay';
        var footer = onConfirm
            ? '<div class="wacrm-modal-footer">' +
              '<button class="wacrm-btn wacrm-btn-outline" id="wm-cancel">Cancel</button>' +
              '<button class="wacrm-btn wacrm-btn-primary" id="wm-confirm">Save</button></div>'
            : '';
        o.innerHTML = '<div class="wacrm-modal"><div class="wacrm-modal-header"><h3>' + title +
            '</h3><button class="wacrm-modal-close" id="wm-x">✕</button></div>' +
            '<div class="wacrm-modal-body">' + bodyHtml + '</div>' + footer + '</div>';
        document.body.appendChild(o);
        document.getElementById('wm-x').onclick = closeModal;
        var cc = document.getElementById('wm-cancel');
        if (cc) cc.onclick = closeModal;
        if (onConfirm) {
            var cb = document.getElementById('wm-confirm');
            cb.onclick = function () {
                var self = this; self.disabled = true; self.textContent = 'Saving…';
                onConfirm(o, function () { self.disabled = false; self.textContent = 'Save'; });
            };
        }
        o.addEventListener('click', function (e) { if (e.target === o) closeModal(); });
    }

    function closeModal() {
        var el = document.getElementById('wacrm-modal-overlay');
        if (el) el.remove();
    }

    // ── Quota badge ────────────────────────────────────────────────────────────
    function initQuotaBadge() {
        var q = waCRM.quota || {}, max = q.max || 5000, used = q.used || 0;
        var pct = max > 0 ? Math.round((used / max) * 100) : 0;
        var wrap = document.querySelector('.wacrm-quota-badge');
        if (!wrap) return;
        if (pct >= 90) wrap.classList.add('danger');
        else if (pct >= 70) wrap.classList.add('warning');
        var fill = wrap.querySelector('.quota-bar-fill');
        if (fill) fill.style.width = pct + '%';
        var txt = wrap.querySelector('.quota-text');
        if (txt) txt.textContent = (q.remaining || 0).toLocaleString() + ' msgs left';
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        initQuotaBadge();
        var appEl = document.getElementById('wacrm-app');
        if (!appEl) return;
        switch (appEl.getAttribute('data-wacrm-page')) {
            case 'dashboard':   initDashboard();   break;
            case 'instances':   initInstances();   break;
            case 'contacts':    initContacts();    break;
            case 'lists':       initLists();       break;
            case 'fields':      initFields();      break;
            case 'campaigns':   initCampaigns();   break;
            case 'automations': initAutomations(); break;
            case 'templates':   initTemplates();   break;
            case 'woocommerce': initOrders();      break;
            case 'logs':        initLogs();        break;
            case 'settings':    initSettings();    break;
        }
    });

    // ── Dashboard ──────────────────────────────────────────────────────────────
    function initDashboard() {
        ajax('wacrm_get_dashboard_data').done(function (r) {
            if (!r.success) return;
            var d = r.data;
            function set(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }
            set('stat-sent-today',  d.sent_today || 0);
            set('stat-sent-month',  d.sent_month || 0);
            set('stat-quota-used',  (d.quota_used || 0) + ' / ' + (d.quota_max || 5000));
            set('stat-failed',      d.failed || 0);
            set('stat-contacts',    d.total_contacts || 0);
            set('stat-campaigns',   d.active_campaigns || 0);
            set('stat-automations', d.active_automations || 0);
            var ie = document.getElementById('stat-instance');
            if (ie) ie.innerHTML = renderStatus(d.instance_status);
            if (d.daily_data && window.Chart) {
                var ctx = document.getElementById('dashboard-chart');
                if (ctx) new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels:   d.daily_data.map(function (x) { return x.date; }),
                        datasets: [{ label: 'Messages Sent', data: d.daily_data.map(function (x) { return x.count; }), backgroundColor: '#4ade80', borderRadius: 4 }],
                    },
                    options: { responsive: true, plugins: { legend: { display: false } } },
                });
            }
        });
    }

    // ── Instances ──────────────────────────────────────────────────────────────
    var qrTimer = null;

    function initInstances() {
        loadInstances();
        var btn = document.getElementById('btn-create-instance');
        if (btn) btn.addEventListener('click', function () {
            openModal('Create New WhatsApp Instance',
                '<div class="wacrm-form-row"><label>Instance Name</label>' +
                '<input class="wacrm-input" id="inst-name" placeholder="e.g. my-store" autocomplete="off"></div>' +
                '<p style="font-size:12px;color:var(--muted);margin-top:8px">Use lowercase letters, numbers, and hyphens only.</p>',
                function (modal, resetBtn) {
                    var name = (modal.querySelector('#inst-name').value || '').trim().toLowerCase().replace(/[^a-z0-9\-]/g, '');
                    if (!name) { toast('Instance name is required', 'error'); if (resetBtn) resetBtn(); return; }
                    ajax('wacrm_create_instance', { instance_name: name }).done(function (r) {
                        if (resetBtn) resetBtn();
                        if (r.success) { toast('Instance created!'); closeModal(); loadInstances(true); }
                        else toast((r.data && r.data.message) || 'Failed to create instance', 'error');
                    });
                }
            );
        });
    }

    function loadInstances(fresh) {
        var grid = document.getElementById('instances-grid');
        if (!grid) return;
        grid.innerHTML = '<div style="padding:32px;text-align:center"><span class="wacrm-spinner"></span> Loading instances…</div>';
        ajax('wacrm_fetch_instances', fresh ? { fresh: 1 } : {}).done(function (r) {
            if (!r.success) {
                grid.innerHTML = '<div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Could not load instances. Check Evolution API settings.') + '</div>';
                return;
            }
            var data = r.data || [];
            if (!data.length) {
                grid.innerHTML = '<div class="wacrm-empty"><div class="empty-icon">📱</div><h3>No instances yet</h3><p>Click <strong>New Instance</strong> to connect a WhatsApp number.</p></div>';
                return;
            }
            grid.innerHTML = data.map(function (inst) {
                var isConnected = inst.status === 'open';
                return '<div class="wacrm-instance-card">' +
                    '<div class="instance-header">' +
                    '<span class="instance-icon">📱</span>' +
                    '<div><h3 class="instance-name">' + escHtml(inst.instance_name) + '</h3>' +
                    (inst.connected_num ? '<div style="font-size:12px;color:var(--muted)">📞 ' + escHtml(inst.connected_num) + '</div>' : '') +
                    '</div>' + renderStatus(inst.status || 'pending') + '</div>' +
                    '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">' +
                    (!isConnected ? '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-qr" data-name="' + escHtml(inst.instance_name) + '">📷 Scan QR</button>' : '') +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-restart" data-name="' + escHtml(inst.instance_name) + '">↺ Restart</button>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-inst" data-name="' + escHtml(inst.instance_name) + '">Delete</button>' +
                    '</div></div>';
            }).join('');

            grid.querySelectorAll('.btn-qr').forEach(function (b) {
                b.addEventListener('click', function () { startQRPolling(this.dataset.name); });
            });
            grid.querySelectorAll('.btn-restart').forEach(function (b) {
                b.addEventListener('click', function () {
                    var self = this, name = this.dataset.name;
                    setBtnLoading(self, true);
                    ajax('wacrm_restart_instance', { instance_name: name }).done(function (r) {
                        setBtnLoading(self, false);
                        toast(r.success ? 'Restarted' : (r.data && r.data.message) || 'Error', r.success ? 'success' : 'error');
                        if (r.success) setTimeout(function () { loadInstances(true); }, 2000);
                    });
                });
            });
            grid.querySelectorAll('.btn-del-inst').forEach(function (b) {
                b.addEventListener('click', function () {
                    var name = this.dataset.name;
                    if (!confirm('Delete instance "' + name + '"? Cannot be undone.')) return;
                    var self = this; setBtnLoading(self, true);
                    ajax('wacrm_delete_instance', { instance_name: name }).done(function (r) {
                        if (r.success) { toast('Instance deleted'); loadInstances(true); }
                        else { setBtnLoading(self, false); toast((r.data && r.data.message) || 'Error', 'error'); }
                    });
                });
            });
        });
    }

    function startQRPolling(name) {
        if (qrTimer) clearInterval(qrTimer);
        var attempts = 0;
        openModal('Scan QR Code – ' + escHtml(name),
            '<div id="qr-wrap" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span> Generating QR…</div>' +
            '<p style="text-align:center;font-size:12px;color:var(--muted);margin:0">WhatsApp → Linked Devices → Link a Device</p>', null);
        function poll() {
            if (++attempts > 40) { clearInterval(qrTimer); toast('QR timed out. Try again.', 'error'); return; }
            ajax('wacrm_get_qr', { instance_name: name }).done(function (r) {
                var wrap = document.getElementById('qr-wrap');
                if (!wrap) { clearInterval(qrTimer); return; }
                if (r.success && r.data) {
                    var qr = r.data.qrcode || r.data.base64 || r.data.code || '';
                    if (qr) { var src = qr.startsWith('data:') ? qr : 'data:image/png;base64,' + qr; wrap.innerHTML = '<img src="' + src + '" style="max-width:260px;border-radius:8px">'; }
                }
                ajax('wacrm_instance_status', { instance_name: name }).done(function (sr) {
                    if (sr.data && sr.data.instance && sr.data.instance.state === 'open') {
                        clearInterval(qrTimer); closeModal(); toast('✅ WhatsApp connected!'); loadInstances(true);
                    }
                });
            });
        }
        poll();
        qrTimer = setInterval(poll, 5000);
    }

    // ── Contacts ───────────────────────────────────────────────────────────────
    var contactPage = 1;

    function initContacts() {
        loadContacts();
        var btnAdd = document.getElementById('btn-add-contact');
        var btnCsv = document.getElementById('btn-import-csv');
        var search = document.getElementById('contact-search');
        if (btnAdd) btnAdd.addEventListener('click', function () { contactModal(null); });
        if (btnCsv) btnCsv.addEventListener('click', importCSVModal);
        if (search)  search.addEventListener('input', debounce(function () { contactPage = 1; loadContacts(); }, 350));
    }

    function loadContacts() {
        var tbody  = document.getElementById('contacts-tbody');
        var search = (document.getElementById('contact-search') || {}).value || '';
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_contacts', { page: contactPage, search: search }).done(function (r) {
            if (!r.success) {
                tbody.innerHTML = '<tr><td colspan="7"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load contacts') + '</div></td></tr>';
                return;
            }
            var data = r.data.contacts || [], total = r.data.total || 0;
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="7"><div class="wacrm-empty"><div class="empty-icon">👥</div><h3>No contacts yet</h3></div></td></tr>';
            } else {
                tbody.innerHTML = data.map(function (c) {
                    return '<tr>' +
                        '<td><input type="checkbox" value="' + c.id + '"></td>' +
                        '<td><strong>' + escHtml((c.first_name || '') + ' ' + (c.last_name || '')) + '</strong></td>' +
                        '<td>' + escHtml(c.phone || '—') + '</td>' +
                        '<td>' + escHtml(c.email || '—') + '</td>' +
                        '<td>' + escHtml(c.whatsapp || '—') + '</td>' +
                        '<td>' + (c.tags ? '<span class="wacrm-badge badge-blue">' + escHtml(c.tags) + '</span>' : '—') + '</td>' +
                        '<td>' +
                        '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-ec" data-id="' + c.id + '">Edit</button> ' +
                        '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-dc" data-id="' + c.id + '">Delete</button>' +
                        '</td></tr>';
                }).join('');
                tbody.querySelectorAll('.btn-ec').forEach(function (b) {
                    b.addEventListener('click', function () { contactModal(data.find(function (c) { return c.id == b.dataset.id; })); });
                });
                tbody.querySelectorAll('.btn-dc').forEach(function (b) {
                    b.addEventListener('click', function () {
                        if (!confirm('Delete this contact?')) return;
                        var self = this; setBtnLoading(self, true);
                        ajax('wacrm_delete_contact', { id: b.dataset.id }).done(function (r) {
                            if (r.success) { toast('Deleted'); loadContacts(); }
                            else { setBtnLoading(self, false); toast('Error', 'error'); }
                        });
                    });
                });
            }
            var pw = document.getElementById('contacts-pagination');
            if (pw) { pw.innerHTML = ''; pw.appendChild(pagination(total, contactPage, 25, function (p) { contactPage = p; loadContacts(); })); }
        });
    }

    function contactModal(c) {
        openModal(c ? 'Edit Contact' : 'Add Contact',
            '<div class="wacrm-form-grid">' +
            '<div class="wacrm-form-row"><label>First Name</label><input class="wacrm-input" id="cf-first" value="' + escHtml(c ? c.first_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Last Name</label><input class="wacrm-input" id="cf-last" value="' + escHtml(c ? c.last_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Phone</label><input class="wacrm-input" id="cf-phone" value="' + escHtml(c ? c.phone : '') + '" placeholder="971501234567"></div>' +
            '<div class="wacrm-form-row"><label>WhatsApp</label><input class="wacrm-input" id="cf-wa" value="' + escHtml(c ? c.whatsapp : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Email</label><input class="wacrm-input" id="cf-email" value="' + escHtml(c ? c.email : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Tags</label><input class="wacrm-input" id="cf-tags" value="' + escHtml(c ? c.tags : '') + '"></div></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_contact', {
                    id: c ? c.id : 0,
                    first_name: modal.querySelector('#cf-first').value,
                    last_name:  modal.querySelector('#cf-last').value,
                    phone:      modal.querySelector('#cf-phone').value,
                    whatsapp:   modal.querySelector('#cf-wa').value,
                    email:      modal.querySelector('#cf-email').value,
                    tags:       modal.querySelector('#cf-tags').value,
                }).done(function (r) {
                    if (resetBtn) resetBtn();
                    if (r.success) { toast(c ? 'Updated' : 'Added'); closeModal(); loadContacts(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
    }

    function importCSVModal() {
        openModal('Import Contacts from CSV',
            '<div class="wacrm-form-row"><label>CSV File</label><input type="file" class="wacrm-input" id="csv-file" accept=".csv"></div>' +
            '<p style="font-size:12px;color:var(--muted)">Required column: <code>phone</code>. Optional: <code>first_name</code> <code>last_name</code> <code>email</code> <code>whatsapp</code> <code>tags</code></p>',
            function (modal, resetBtn) {
                var file = modal.querySelector('#csv-file').files[0];
                if (!file) { toast('Choose a CSV file first', 'error'); if (resetBtn) resetBtn(); return; }
                var fd = new FormData();
                fd.append('action', 'wacrm_import_contacts_csv');
                fd.append('_nonce', N);
                fd.append('csv_file', file);
                $.ajax({ url: API, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                    .done(function (r) {
                        if (resetBtn) resetBtn();
                        if (r.success) { toast('Imported ' + (r.data.added || 0) + ' contacts'); closeModal(); loadContacts(); }
                        else toast((r.data && r.data.message) || 'Import failed', 'error');
                    }).fail(function () { if (resetBtn) resetBtn(); toast('Upload failed', 'error'); });
            }
        );
    }

    // ── Contact Fields ─────────────────────────────────────────────────────────
    function initFields() {
        loadFields();
        var btn = document.getElementById('btn-add-field');
        if (btn) btn.addEventListener('click', function () { fieldModal(null); });
    }

    function loadFields() {
        var tbody = document.getElementById('fields-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_fields').done(function (r) {
            if (!r.success) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load fields') + '</div></td></tr>';
                return;
            }
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="wacrm-empty"><div class="empty-icon">🏷️</div><h3>No custom fields yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (f) {
                return '<tr>' +
                    '<td><code style="background:var(--bg);padding:2px 6px;border-radius:4px">' + escHtml(f.field_key) + '</code></td>' +
                    '<td>' + escHtml(f.field_label) + '</td>' +
                    '<td><span class="wacrm-badge badge-blue">' + escHtml(f.field_type) + '</span></td>' +
                    '<td><code style="color:var(--muted);font-size:11.5px">{{' + escHtml(f.field_key) + '}}</code></td>' +
                    '<td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-ef" data-id="' + f.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-df" data-id="' + f.id + '">Delete</button>' +
                    '</td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-ef').forEach(function (b) {
                b.addEventListener('click', function () { fieldModal(data.find(function (f) { return f.id == b.dataset.id; })); });
            });
            tbody.querySelectorAll('.btn-df').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Delete this field and all contact values for it?')) return;
                    var self = this; setBtnLoading(self, true);
                    ajax('wacrm_delete_field', { id: b.dataset.id }).done(function (r) {
                        if (r.success) { toast('Deleted'); loadFields(); }
                        else { setBtnLoading(self, false); toast('Error', 'error'); }
                    });
                });
            });
        });
    }

    function fieldModal(f) {
        openModal(f ? 'Edit Field' : 'New Custom Field',
            '<div class="wacrm-form-row"><label>Field Key</label>' +
            '<input class="wacrm-input" id="f-key" value="' + escHtml(f ? f.field_key : '') + '" placeholder="e.g. city" ' + (f ? 'readonly style="background:var(--bg)"' : '') + '></div>' +
            '<div class="wacrm-form-row"><label>Label</label><input class="wacrm-input" id="f-label" value="' + escHtml(f ? f.field_label : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Type</label><select class="wacrm-select" id="f-type">' +
            ['text', 'number', 'date', 'dropdown', 'checkbox'].map(function (t) { return '<option value="' + t + '"' + (f && f.field_type === t ? ' selected' : '') + '>' + t + '</option>'; }).join('') +
            '</select></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_field', {
                    id:          f ? f.id : 0,
                    field_key:   modal.querySelector('#f-key').value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                    field_label: modal.querySelector('#f-label').value,
                    field_type:  modal.querySelector('#f-type').value,
                }).done(function (r) {
                    if (resetBtn) resetBtn();
                    if (r.success) { toast(f ? 'Updated' : 'Created'); closeModal(); loadFields(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
    }

    // ── Lists ──────────────────────────────────────────────────────────────────
    function initLists() {
        loadLists();
        var btn = document.getElementById('btn-add-list');
        if (btn) btn.addEventListener('click', function () { listModal(null); });
    }

    function loadLists() {
        var tbody = document.getElementById('lists-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_lists').done(function (r) {
            if (!r.success) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load lists') + '</div></td></tr>';
                return;
            }
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">📋</div><h3>No lists yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (l) {
                return '<tr><td><strong>' + escHtml(l.list_name) + '</strong></td>' +
                    '<td>' + escHtml(l.description || '—') + '</td>' +
                    '<td><span class="wacrm-badge badge-blue">' + (l.contact_count || 0) + ' contacts</span></td>' +
                    '<td><button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-el" data-id="' + l.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-dl" data-id="' + l.id + '">Delete</button></td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-el').forEach(function (b) {
                b.addEventListener('click', function () { listModal(data.find(function (l) { return l.id == b.dataset.id; })); });
            });
            tbody.querySelectorAll('.btn-dl').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Delete this list?')) return;
                    ajax('wacrm_delete_list', { id: b.dataset.id }).done(function (r) {
                        if (r.success) { toast('Deleted'); loadLists(); } else toast('Error', 'error');
                    });
                });
            });
        });
    }

    function listModal(list) {
        openModal(list ? 'Edit List' : 'New List',
            '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="ln" value="' + escHtml(list ? list.list_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Description</label><textarea class="wacrm-textarea" id="ld" rows="3">' + escHtml(list ? list.description : '') + '</textarea></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_list', { id: list ? list.id : 0, list_name: modal.querySelector('#ln').value, description: modal.querySelector('#ld').value })
                    .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(list ? 'Updated' : 'Created'); closeModal(); loadLists(); } else toast('Error', 'error'); });
            }
        );
    }

    // ── Campaigns ──────────────────────────────────────────────────────────────
    function initCampaigns() {
        loadCampaigns();
        var btn = document.getElementById('btn-add-campaign');
        if (btn) btn.addEventListener('click', function () { campaignModal(null); });
    }

    function loadCampaigns() {
        var tbody = document.getElementById('campaigns-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_campaigns').done(function (r) {
            if (!r.success) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load') + '</div></td></tr>';
                return;
            }
            var data = r.data || [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="5"><div class="wacrm-empty"><div class="empty-icon">📣</div><h3>No campaigns yet</h3></div></td></tr>'; return; }
            tbody.innerHTML = data.map(function (c) {
                return '<tr><td><strong>' + escHtml(c.campaign_name) + '</strong></td>' +
                    '<td>' + renderStatus(c.status) + '</td><td>' + escHtml(c.rate_per_hour) + '/hr</td>' +
                    '<td>' + escHtml(c.schedule_from + ' – ' + c.schedule_to) + '</td><td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-launch" data-id="' + c.id + '">▶ Launch</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-steps" data-id="' + c.id + '" data-name="' + escHtml(c.campaign_name) + '">Steps</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-ec2" data-id="' + c.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-dc2" data-id="' + c.id + '">Delete</button></td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-launch').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Queue this campaign now?')) return;
                    var self = this; setBtnLoading(self, true);
                    ajax('wacrm_launch_campaign', { id: b.dataset.id }).done(function (r) {
                        setBtnLoading(self, false);
                        toast(r.success ? ((r.data && r.data.message) || 'Launched!') : ((r.data && r.data.message) || 'Error'), r.success ? 'success' : 'error');
                        if (r.success) loadCampaigns();
                    });
                });
            });
            tbody.querySelectorAll('.btn-ec2').forEach(function (b) {
                b.addEventListener('click', function () { campaignModal(data.find(function (c) { return c.id == b.dataset.id; })); });
            });
            tbody.querySelectorAll('.btn-dc2').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Delete?')) return;
                    ajax('wacrm_delete_campaign', { id: b.dataset.id }).done(function (r) { if (r.success) { toast('Deleted'); loadCampaigns(); } });
                });
            });
            tbody.querySelectorAll('.btn-steps').forEach(function (b) {
                b.addEventListener('click', function () { stepsModal(data.find(function (c) { return c.id == b.dataset.id; })); });
            });
        });
    }

    function campaignModal(c) {
        openModal(c ? 'Edit Campaign' : 'New Campaign',
            '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="cn" value="' + escHtml(c ? c.campaign_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Max Messages/Hour</label><input class="wacrm-input" type="number" id="cr" value="' + escHtml(c ? c.rate_per_hour : '100') + '" min="10" max="500"></div>' +
            '<div class="wacrm-form-row"><label>Send From</label><input class="wacrm-input" type="time" id="cf2" value="' + escHtml(c ? c.schedule_from : '09:00') + '"></div>' +
            '<div class="wacrm-form-row"><label>Send Until</label><input class="wacrm-input" type="time" id="ct" value="' + escHtml(c ? c.schedule_to : '20:00') + '"></div>' +
            '<div class="wacrm-form-row"><label><input type="checkbox" id="crand"' + ((!c || c.randomize_delay) ? ' checked' : '') + '> Randomise send delay (anti-ban)</label></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_campaign', {
                    id: c ? c.id : 0, campaign_name: modal.querySelector('#cn').value,
                    rate_per_hour: modal.querySelector('#cr').value, schedule_from: modal.querySelector('#cf2').value,
                    schedule_to: modal.querySelector('#ct').value, randomize_delay: modal.querySelector('#crand').checked ? 1 : 0,
                }).done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(c ? 'Updated' : 'Created'); closeModal(); loadCampaigns(); } else toast('Error', 'error'); });
            }
        );
    }

    function stepsModal(c) {
        if (!c) return;
        var steps = [];
        function renderSteps() {
            return steps.length
                ? '<div id="sc">' + steps.map(function (s, i) {
                    return '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;padding:8px;background:var(--bg);border-radius:8px">' +
                        '<span class="wacrm-badge badge-blue" style="flex-shrink:0">Step ' + (i + 1) + '</span>' +
                        '<select class="wacrm-select" style="width:100px" data-si="' + i + '" data-f="msg_type">' +
                        ['text', 'image', 'voice'].map(function (t) { return '<option value="' + t + '"' + (s.msg_type === t ? ' selected' : '') + '>' + t + '</option>'; }).join('') +
                        '</select><input class="wacrm-input" style="flex:1" placeholder="Message or URL" value="' + escHtml(s.body || '') + '" data-si="' + i + '" data-f="body">' +
                        '<input class="wacrm-input" style="width:70px" type="number" placeholder="Delay" value="' + escHtml(s.delay_seconds || 5) + '" data-si="' + i + '" data-f="delay_seconds" min="1">' +
                        '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger" data-rm="' + i + '">✕</button></div>';
                }).join('') + '</div>'
                : '<p style="text-align:center;color:var(--muted);padding:16px">No steps. Click below to add one.</p>';
        }
        openModal('Steps – ' + escHtml(c.campaign_name),
            '<div id="sw">' + renderSteps() + '</div>' +
            '<button class="wacrm-btn wacrm-btn-outline" id="add-step" style="width:100%;margin-top:12px">+ Add Step</button>',
            function (modal, resetBtn) {
                ajax('wacrm_save_campaign_steps', { campaign_id: c.id, steps: JSON.stringify(steps) })
                    .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast('Steps saved'); closeModal(); } else toast('Error', 'error'); });
            }
        );
        setTimeout(function () {
            function bindSteps() {
                document.querySelectorAll('[data-f]').forEach(function (el) {
                    el.oninput = el.onchange = function () {
                        var i = parseInt(this.dataset.si), f = this.dataset.f;
                        steps[i][f] = f === 'delay_seconds' ? (parseInt(this.value) || 5) : this.value;
                    };
                });
                document.querySelectorAll('[data-rm]').forEach(function (b) {
                    b.onclick = function () { steps.splice(parseInt(this.dataset.rm), 1); document.getElementById('sw').innerHTML = renderSteps(); bindSteps(); };
                });
            }
            document.getElementById('add-step').onclick = function () {
                steps.push({ msg_type: 'text', body: '', delay_seconds: 5 });
                document.getElementById('sw').innerHTML = renderSteps(); bindSteps();
            };
            bindSteps();
        }, 50);
    }

    // ── Automations ────────────────────────────────────────────────────────────
    function initAutomations() {
        loadAutomations();
        var btn = document.getElementById('btn-add-automation');
        if (btn) btn.addEventListener('click', function () { automationModal(null); });
    }

    function loadAutomations() {
        var tbody = document.getElementById('automations-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_automations').done(function (r) {
            if (!r.success) { tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load') + '</div></td></tr>'; return; }
            var data = r.data || [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">⚡</div><h3>No automations yet</h3></div></td></tr>'; return; }
            tbody.innerHTML = data.map(function (a) {
                return '<tr><td><strong>' + escHtml(a.auto_name) + '</strong></td>' +
                    '<td><span class="wacrm-badge badge-blue">' + escHtml(a.trigger_type) + '</span></td>' +
                    '<td>' + renderStatus(a.status) + '</td><td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-ea" data-id="' + a.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-da" data-id="' + a.id + '">Delete</button></td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-ea').forEach(function (b) {
                b.addEventListener('click', function () { automationModal(data.find(function (a) { return a.id == b.dataset.id; })); });
            });
            tbody.querySelectorAll('.btn-da').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Delete?')) return;
                    ajax('wacrm_delete_automation', { id: b.dataset.id }).done(function (r) { if (r.success) { toast('Deleted'); loadAutomations(); } });
                });
            });
        });
    }

    function automationModal(a) {
        var ad = []; try { ad = JSON.parse(a ? (a.actions || '[]') : '[]') || []; } catch (e) {}
        var existMsg = (ad[0] && ad[0].message_body) ? ad[0].message_body : '';
        var triggers = ['woocommerce_order_created','woocommerce_order_updated','woocommerce_order_completed','new_contact_added','scheduled','webhook_received'];
        openModal(a ? 'Edit Automation' : 'New Automation',
            '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="an" value="' + escHtml(a ? a.auto_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Trigger</label><select class="wacrm-select" id="at2">' +
            triggers.map(function (t) { return '<option value="' + t + '"' + (a && a.trigger_type === t ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select></div>' +
            '<div class="wacrm-form-row"><label>Message</label><p style="font-size:11.5px;color:var(--muted);margin:0 0 6px">Tags: {{customer_name}} {{order_status}} {{order_id}} {{order_total}}</p>' +
            '<textarea class="wacrm-textarea" id="am" rows="4">' + escHtml(existMsg) + '</textarea></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_automation', {
                    id: a ? a.id : 0, auto_name: modal.querySelector('#an').value,
                    trigger_type: modal.querySelector('#at2').value, conditions: JSON.stringify([]),
                    actions: JSON.stringify([{ type: 'send_message', message_body: modal.querySelector('#am').value }]), status: 'active',
                }).done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(a ? 'Updated' : 'Created'); closeModal(); loadAutomations(); } else toast('Error', 'error'); });
            }
        );
    }

    // ── Templates ──────────────────────────────────────────────────────────────
    function initTemplates() {
        loadTemplates();
        var btn = document.getElementById('btn-add-template');
        if (btn) btn.addEventListener('click', function () { templateModal(null); });
    }

    function loadTemplates() {
        var tbody = document.getElementById('templates-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_templates').done(function (r) {
            if (!r.success) { tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load') + '</div></td></tr>'; return; }
            var data = r.data || [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">📝</div><h3>No templates yet</h3></div></td></tr>'; return; }
            tbody.innerHTML = data.map(function (t) {
                return '<tr><td><strong>' + escHtml(t.tpl_name) + '</strong></td>' +
                    '<td><span class="wacrm-badge badge-purple">' + escHtml(t.category) + '</span></td>' +
                    '<td style="font-size:12px;color:var(--muted);max-width:280px">' + escHtml((t.body || '').substring(0, 80)) + (t.body && t.body.length > 80 ? '…' : '') + '</td>' +
                    '<td><button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-et" data-id="' + t.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-dt" data-id="' + t.id + '">Delete</button></td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-et').forEach(function (b) {
                b.addEventListener('click', function () { templateModal(data.find(function (t) { return t.id == b.dataset.id; })); });
            });
            tbody.querySelectorAll('.btn-dt').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Delete?')) return;
                    ajax('wacrm_delete_template', { id: b.dataset.id }).done(function (r) { if (r.success) { toast('Deleted'); loadTemplates(); } });
                });
            });
        });
    }

    function templateModal(t) {
        var cats = ['order_confirmation', 'otp', 'campaign', 'manual', 'automation'];
        openModal(t ? 'Edit Template' : 'New Template',
            '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="tn" value="' + escHtml(t ? t.tpl_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Category</label><select class="wacrm-select" id="tc">' +
            cats.map(function (c) { return '<option value="' + c + '"' + (t && t.category === c ? ' selected' : '') + '>' + c + '</option>'; }).join('') + '</select></div>' +
            '<div class="wacrm-form-row"><label>Body</label><p style="font-size:11.5px;color:var(--muted);margin:0 0 6px">Tags: {{order_id}} {{customer_name}} {{order_total}} {{order_status}} {{billing_phone}} {{otp}}</p>' +
            '<textarea class="wacrm-textarea" id="tb" rows="6">' + escHtml(t ? t.body : '') + '</textarea></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_template', { id: t ? t.id : 0, tpl_name: modal.querySelector('#tn').value, category: modal.querySelector('#tc').value, body: modal.querySelector('#tb').value })
                    .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(t ? 'Updated' : 'Created'); closeModal(); loadTemplates(); } else toast('Error', 'error'); });
            }
        );
    }

    // ── WooCommerce ────────────────────────────────────────────────────────────
    function initOrders() { loadOrders(); }

    function loadOrders() {
        var tbody = document.getElementById('orders-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_orders').done(function (r) {
            if (!r.success) { tbody.innerHTML = '<tr><td colspan="8"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'WooCommerce not active or no orders.') + '</div></td></tr>'; return; }
            var data = r.data || [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="8"><div class="wacrm-empty"><div class="empty-icon">🛒</div><h3>No orders yet</h3></div></td></tr>'; return; }
            tbody.innerHTML = data.map(function (o) {
                var items = (o.items || []).map(function (i) { return escHtml(i.name) + ' ×' + i.qty; }).join('<br>');
                return '<tr><td>#' + escHtml(String(o.id)) + '</td><td>' + escHtml(o.customer_name || '—') + '</td>' +
                    '<td>' + escHtml(o.phone || '—') + '</td><td>' + escHtml(o.email || '—') + '</td>' +
                    '<td>' + escHtml(o.total || '—') + '</td><td>' + renderStatus((o.status || '').toLowerCase().replace(/\s+/g, '-')) + '</td>' +
                    '<td style="font-size:12px">' + items + '</td>' +
                    '<td><button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-wa" data-id="' + o.id + '" data-name="' + escHtml(o.customer_name || '') + '"' +
                    (o.phone ? '' : ' disabled title="No phone"') + '>WhatsApp</button></td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-wa').forEach(function (b) {
                b.addEventListener('click', function () { orderMsgModal(b.dataset.id, b.dataset.name); });
            });
        });
    }

    function orderMsgModal(orderId, name) {
        openModal('Send WhatsApp – ' + escHtml(name),
            '<div class="wacrm-form-row"><label>Message</label>' +
            '<p style="font-size:11.5px;color:var(--muted);margin:0 0 6px">Tags: {{order_id}} {{customer_name}} {{order_total}} {{order_status}} {{billing_phone}} {{order_items}}</p>' +
            '<textarea class="wacrm-textarea" id="om" rows="5">Hi {{customer_name}}, your order #{{order_id}} is {{order_status}}. Total: {{order_total}}</textarea></div>',
            function (modal, resetBtn) {
                ajax('wacrm_send_order_message', { order_id: orderId, message: modal.querySelector('#om').value })
                    .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast('Message sent!'); closeModal(); } else toast((r.data && r.data.message) || 'Failed', 'error'); });
            }
        );
    }

    // ── Logs ───────────────────────────────────────────────────────────────────
    function initLogs() {
        loadLogs();
        var btn = document.getElementById('btn-refresh-logs');
        if (btn) btn.addEventListener('click', loadLogs);
    }

    function loadLogs() {
        var tbody = document.getElementById('logs-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_logs').done(function (r) {
            if (!r.success) { tbody.innerHTML = '<tr><td colspan="7"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load') + '</div></td></tr>'; return; }
            var data = r.data || [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="wacrm-empty"><div class="empty-icon">📋</div><h3>No logs yet</h3></div></td></tr>'; return; }
            tbody.innerHTML = data.map(function (l) {
                var src = l.campaign_id ? 'Campaign #' + l.campaign_id : (l.automation_id ? 'Auto #' + l.automation_id : 'Manual');
                return '<tr><td>#' + escHtml(String(l.id)) + '</td><td>' + escHtml(l.phone || '—') + '</td>' +
                    '<td><span class="wacrm-badge badge-gray">' + escHtml(l.message_type || '—') + '</span></td>' +
                    '<td>' + escHtml(src) + '</td><td>' + renderStatus(l.status || 'unknown') + '</td>' +
                    '<td style="font-size:11.5px">' + escHtml(l.sent_at || l.created_at || '—') + '</td>' +
                    '<td style="font-size:11.5px;color:var(--danger)">' + escHtml(l.error_msg || '') + '</td></tr>';
            }).join('');
        });
    }

    // ── Settings ───────────────────────────────────────────────────────────────
    function initSettings() {
        var form = document.getElementById('settings-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault(); e.stopPropagation();
                var btn = form.querySelector('[type=submit]'); setBtnLoading(btn, true);
                ajax('wacrm_save_settings', {
                    api_url:          (document.getElementById('set-api-url')          || {}).value || '',
                    api_key:          (document.getElementById('set-api-key')          || {}).value || '',
                    rate_per_hour:    (document.getElementById('set-rate')             || {}).value || 200,
                    otp_enabled:      ((document.getElementById('set-otp-enabled')     || {}).checked) ? 1 : 0,
                    otp_expiry:       (document.getElementById('set-otp-expiry')       || {}).value || 300,
                    otp_max_attempts: (document.getElementById('set-otp-max-attempts') || {}).value || 5,
                    otp_template:     (document.getElementById('set-otp-template')     || {}).value || 0,
                }).done(function (r) {
                    setBtnLoading(btn, false);
                    if (r.success) toast('Settings saved!');
                    else toast((r.data && r.data.message) || 'Save failed', 'error');
                });
            });
        }
        var rdb = document.getElementById('btn-reinstall-db');
        if (rdb) rdb.addEventListener('click', function () {
            var self = this; setBtnLoading(self, true);
            ajax('wacrm_reinstall_db').done(function (r) {
                setBtnLoading(self, false);
                var res = document.getElementById('reinstall-result');
                if (res) res.textContent = r.success ? '✅ ' + r.data.message : '❌ Failed';
                toast(r.success ? 'Tables reinstalled!' : 'Failed', r.success ? 'success' : 'error');
            });
        });
    }

})(jQuery);