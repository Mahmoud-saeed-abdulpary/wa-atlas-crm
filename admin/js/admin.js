/**
 * WA Atlas CRM Pro – admin.js  v1.2.0
 *
 * FIXES vs original v1.0.x:
 * 1. contactModal: adds list selector + custom fields, styled identically to default fields
 * 2. stepsModal: loads existing steps from DB (wacrm_get_campaign_steps), uses correct
 *    field names message_type/message_body matching WACRM_Campaigns::save_steps(), adds template picker
 * 3. templateModal: category is already a <select> (kept), tpl_name is correct field name
 * 4. dashboardData: uses exact stat element IDs from page-dashboard.php
 * 5. settings: reads set-otp-max-attempts (exact ID from page-settings.php)
 * 6. Custom fields available as {{key}} tags in all message/template textareas
 * 7. New fields styled with wacrm-form-row/wacrm-input (same as defaults)
 * 8. campaignModal: list multi-select added
 * 9. All AJAX calls use _nonce (matching PHP check_ajax_referer/wp_verify_nonce)
 */
(function ($) {
    'use strict';

    /* ── Helpers ─────────────────────────────────────────────────────────── */
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function ajax(action, data) {
        var payload = $.extend({ action: action, _nonce: waCRM.nonce }, data || {});
        return $.post(waCRM.ajax_url, payload);
    }

    function debounce(fn, ms) {
        var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); };
    }

    function setBtnLoading(btn, on) {
        if (!btn) return;
        if (on) {
            btn._orig = btn.innerHTML;
            btn.innerHTML = '<span class="wacrm-spinner" style="width:14px;height:14px;border-width:2px;vertical-align:middle;display:inline-block"></span>';
            btn.disabled = true;
        } else {
            btn.innerHTML = btn._orig || btn.innerHTML;
            btn.disabled = false;
        }
    }

    function toast(msg, type) {
        type = type || 'success';
        var el = document.createElement('div');
        el.className = 'wacrm-toast';
        el.textContent = msg;
        el.style.cssText = [
            'position:fixed','bottom:24px','right:24px','z-index:99999',
            'padding:12px 20px','border-radius:8px','font-size:14px','font-weight:600',
            'color:#fff','opacity:0','transition:opacity .3s','max-width:320px',
            'background:' + (type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#10b981')
        ].join(';');
        document.body.appendChild(el);
        setTimeout(function () { el.style.opacity = '1'; }, 10);
        setTimeout(function () { el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 300); }, 3800);
    }

    function renderStatus(st) {
        var map = {
            open:'success', connected:'success', active:'success', sent:'success', completed:'success',
            close:'danger', disconnected:'danger', failed:'danger', 'whatsapp_not_connected':'warning',
            pending:'warning', running:'warning',
            draft:'gray', inactive:'gray', unknown:'gray'
        };
        var color = map[st] || 'gray';
        return '<span class="wacrm-badge badge-' + color + '">' + escHtml(st || '—') + '</span>';
    }

    function pagination(total, current, perPage, onChange) {
        var pages = Math.max(1, Math.ceil(total / perPage));
        var wrap = document.createElement('div');
        wrap.className = 'wacrm-pagination';
        if (pages <= 1) return wrap;
        for (var i = 1; i <= pages; i++) {
            (function (p) {
                var btn = document.createElement('button');
                btn.textContent = p;
                btn.className = 'wacrm-btn wacrm-btn-sm ' + (p === current ? 'wacrm-btn-primary' : 'wacrm-btn-outline');
                btn.style.margin = '0 2px';
                btn.addEventListener('click', function () { onChange(p); });
                wrap.appendChild(btn);
            })(i);
        }
        return wrap;
    }

    /* ── In-memory caches for speed ─────────────────────────────────────── */
    var _cache = {};
    function getCache(k) { return _cache[k]; }
    function setCache(k, v) { _cache[k] = v; }
    function clearCache(k) { if (k) delete _cache[k]; else _cache = {}; }

    /* ── Modal engine ────────────────────────────────────────────────────── */
    function openModal(title, bodyHtml, onConfirm, opts) {
        opts = opts || {};
        closeModal();
        var overlay = document.createElement('div');
        overlay.id = 'wacrm-modal-overlay';
        overlay.className = 'wacrm-modal-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:20px;';
        var maxW = opts.wide ? '720px' : '540px';
        overlay.innerHTML =
            '<div class="wacrm-modal" style="background:var(--surface);border-radius:12px;width:100%;max-width:' + maxW + ';max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
            '<div class="wacrm-modal-header" style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--surface);z-index:1;">' +
            '<h3 style="margin:0;font-size:16px;font-weight:700">' + escHtml(title) + '</h3>' +
            '<button class="wacrm-modal-close" id="wm-x" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--muted);line-height:1;padding:2px 6px;">✕</button></div>' +
            '<div class="wacrm-modal-body" style="padding:24px;" id="wm-body">' + bodyHtml + '</div>' +
            (onConfirm ? '<div class="wacrm-modal-footer" style="padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:var(--surface);">' +
            '<button class="wacrm-btn wacrm-btn-outline" id="wm-cancel">Cancel</button>' +
            '<button class="wacrm-btn wacrm-btn-primary" id="wm-confirm">Save</button></div>' : '') +
            '</div>';
        document.body.appendChild(overlay);

        function doClose() { var m = document.getElementById('wacrm-modal-overlay'); if (m) m.remove(); }
        document.getElementById('wm-x').onclick = doClose;
        var cc = document.getElementById('wm-cancel');
        if (cc) cc.onclick = doClose;
        overlay.addEventListener('click', function (e) { if (e.target === overlay) doClose(); });

        if (onConfirm) {
            document.getElementById('wm-confirm').onclick = function () {
                var self = this;
                self.disabled = true; self.textContent = 'Saving…';
                onConfirm(overlay, function () { self.disabled = false; self.textContent = 'Save'; });
            };
        }
    }

    function closeModal() {
        var el = document.getElementById('wacrm-modal-overlay');
        if (el) el.remove();
    }

    /* ── Dynamic tag reference panel ─────────────────────────────────────── */
    function buildTagPanel(textareaId) {
        var customFields = getCache('fields') || [];
        var contact = [
            ['first_name','First name'],['last_name','Last name'],['full_name','Full name'],
            ['phone','Phone'],['whatsapp','WhatsApp'],['email','Email'],['tags','Tags']
        ];
        var woo = [
            ['order_id','Order ID'],['order_number','Order #'],['order_total','Total'],
            ['order_status','Order status'],['order_date','Date'],['order_items','Items list'],
            ['customer_name','Customer name'],['billing_first_name','Billing first'],
            ['billing_last_name','Billing last'],['billing_phone','Billing phone'],
            ['billing_email','Billing email'],['billing_address','Address'],
            ['billing_city','City'],['billing_state','State'],['billing_postcode','Postcode'],
            ['billing_country','Country'],['billing_company','Company'],
            ['shipping_address','Ship address'],['shipping_city','Ship city'],
            ['shipping_method','Ship method'],['shipping_total','Ship total'],
            ['payment_method','Payment'],['coupon_code','Coupon'],
            ['discount_amount','Discount'],['tax_total','Tax'],
            ['tracking_number','Tracking #'],['tracking_url','Tracking URL']
        ];
        function btn(key, label) {
            return '<button type="button" class="wacrm-tag-btn" data-tag="{{' + key + '}}" data-tid="' + escHtml(textareaId) + '" title="' + escHtml(label) + '" ' +
                'style="cursor:pointer;font-size:11px;font-family:monospace;padding:2px 7px;margin:2px;background:var(--bg);border:1px solid var(--border);border-radius:4px;">{{' + escHtml(key) + '}}</button>';
        }
        var html = '<details style="margin-top:10px;"><summary style="cursor:pointer;font-size:12px;font-weight:600;color:var(--accent);user-select:none;">📎 Insert dynamic tag</summary>' +
            '<div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;margin-top:6px;">';
        if (customFields.length) {
            html += '<p style="font-size:11px;font-weight:700;color:var(--muted);margin:0 0 4px;">CUSTOM FIELDS</p><div style="margin-bottom:8px;">';
            customFields.forEach(function (f) { html += btn(f.field_key, f.field_label); });
            html += '</div>';
        }
        html += '<p style="font-size:11px;font-weight:700;color:var(--muted);margin:0 0 4px;">CONTACT</p><div style="margin-bottom:8px;">';
        contact.forEach(function (t) { html += btn(t[0], t[1]); });
        html += '</div><p style="font-size:11px;font-weight:700;color:var(--muted);margin:0 0 4px;">WOOCOMMERCE</p><div>';
        woo.forEach(function (t) { html += btn(t[0], t[1]); });
        html += '</div></div></details>';
        return html;
    }

    function bindTagBtns(container) {
        (container || document).querySelectorAll('.wacrm-tag-btn').forEach(function (btn) {
            btn.onclick = function (e) {
                e.preventDefault();
                var tid = btn.dataset.tid;
                var ta = tid ? document.getElementById(tid) : null;
                if (!ta) ta = (container || document).querySelector('textarea');
                if (!ta) return;
                var tag = btn.dataset.tag;
                var p = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
                ta.value = ta.value.slice(0, p) + tag + ta.value.slice(p);
                ta.focus();
                ta.selectionStart = ta.selectionEnd = p + tag.length;
            };
        });
    }

    /* ── Quota badge ─────────────────────────────────────────────────────── */
    function initQuotaBadge() {
        var q = waCRM.quota || {}, max = parseInt(waCRM.quota_max) || 5000;
        var used = parseInt(waCRM.quota_used) || 0;
        var remaining = max - used;
        var pct = max > 0 ? Math.round((used / max) * 100) : 0;
        var wrap = document.querySelector('.wacrm-quota-badge');
        if (!wrap) return;
        if (pct >= 90) wrap.classList.add('danger');
        else if (pct >= 70) wrap.classList.add('warning');
        var fill = wrap.querySelector('.quota-bar-fill');
        if (fill) fill.style.width = pct + '%';
        var txt = wrap.querySelector('.quota-text');
        if (txt) txt.textContent = remaining.toLocaleString() + ' msgs left';
    }

    /* ════════════════════════════════════════════════════════════════════════
       DASHBOARD — exact stat IDs from page-dashboard.php
    ════════════════════════════════════════════════════════════════════════ */
    function initDashboard() {
        ajax('wacrm_get_dashboard_data').done(function (r) {
            if (!r.success) return;
            var d = r.data;
            function set(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }
            set('stat-sent-today',  d.sent_today  || 0);
            set('stat-sent-month',  d.sent_month  || 0);
            set('stat-quota-used',  (d.quota_used || 0) + ' / ' + (d.quota_max || 5000));
            set('stat-failed',      d.failed      || 0);
            set('stat-contacts',    d.total_contacts   || 0);
            set('stat-campaigns',   d.active_campaigns || 0);
            set('stat-automations', d.active_automations || 0);
            var ie = document.getElementById('stat-instance');
            if (ie) ie.innerHTML = renderStatus(d.instance_status || 'unknown');
            // Chart — canvas ID is wacrm-daily-chart
            var ctx = document.getElementById('wacrm-daily-chart');
            if (ctx && d.daily_data && window.Chart) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: d.daily_data.map(function (x) { return x.date; }),
                        datasets: [{ label: 'Messages Sent', data: d.daily_data.map(function (x) { return x.count; }), backgroundColor: '#4ade80', borderRadius: 4 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });
            }
        });
    }

    /* ════════════════════════════════════════════════════════════════════════
       INSTANCES
    ════════════════════════════════════════════════════════════════════════ */
    var qrTimer = null;

    function initInstances() {
        loadInstances();
        // button ID is btn-create-instance (from page-instances.php)
        var btn = document.getElementById('btn-create-instance');
        if (btn) btn.addEventListener('click', function () {
            openModal('Create New WhatsApp Instance',
                '<div class="wacrm-form-row"><label>Instance Name</label>' +
                '<input class="wacrm-input" id="inst-name" placeholder="e.g. my-store" autocomplete="off"></div>' +
                '<p style="font-size:12px;color:var(--muted);margin-top:8px">Use lowercase letters, numbers and hyphens only.</p>',
                function (modal, resetBtn) {
                    var name = (modal.querySelector('#inst-name').value || '').trim().toLowerCase().replace(/[^a-z0-9\-]/g, '');
                    if (!name) { toast('Instance name is required', 'error'); if (resetBtn) resetBtn(); return; }
                    ajax('wacrm_create_instance', { instance_name: name }).done(function (r) {
                        if (resetBtn) resetBtn();
                        if (r.success) { toast('Instance created!'); closeModal(); loadInstances(); }
                        else toast((r.data && r.data.message) || 'Error', 'error');
                    });
                }
            );
        });
    }

    function loadInstances(fresh) {
        // container ID is wacrm-instances-grid (from page-instances.php)
        var grid = document.getElementById('wacrm-instances-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="wacrm-empty"><span class="wacrm-spinner"></span></div>';
        ajax('wacrm_fetch_instances', fresh ? { fresh: 1 } : {}).done(function (r) {
            if (!r.success) {
                grid.innerHTML = '<div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Could not load instances.') + '</div>';
                return;
            }
            var data = r.data || [];
            if (!data.length) {
                grid.innerHTML = '<div class="wacrm-empty"><div class="empty-icon">📱</div><h3>No instances yet</h3><p>Click <strong>+ New Instance</strong> to connect WhatsApp.</p></div>';
                return;
            }
            grid.innerHTML = data.map(function (inst) {
                var isOpen = inst.status === 'open';
                return '<div class="wacrm-instance-card">' +
                    '<div class="instance-header">' +
                    '<span class="instance-icon">📱</span>' +
                    '<div><h3 class="instance-name">' + escHtml(inst.instance_name) + '</h3>' +
                    (inst.connected_num ? '<small style="color:var(--muted)">📞 ' + escHtml(inst.connected_num) + '</small>' : '') +
                    '</div>' + renderStatus(inst.status || 'pending') + '</div>' +
                    '<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">' +
                    (!isOpen ? '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-qr" data-name="' + escHtml(inst.instance_name) + '">📷 Scan QR</button>' : '') +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-restart" data-name="' + escHtml(inst.instance_name) + '">↺ Restart</button>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-inst" data-name="' + escHtml(inst.instance_name) + '">Delete</button>' +
                    '</div></div>';
            }).join('');
            grid.querySelectorAll('.btn-qr').forEach(function (b) {
                b.addEventListener('click', function () { startQRPolling(b.dataset.name); });
            });
            grid.querySelectorAll('.btn-restart').forEach(function (b) {
                b.addEventListener('click', function () {
                    var self = this; setBtnLoading(self, true);
                    ajax('wacrm_restart_instance', { instance_name: b.dataset.name }).done(function (r) {
                        setBtnLoading(self, false);
                        toast(r.success ? 'Restarted' : ((r.data && r.data.message) || 'Error'), r.success ? 'success' : 'error');
                        if (r.success) setTimeout(function () { loadInstances(true); }, 2000);
                    });
                });
            });
            grid.querySelectorAll('.btn-del-inst').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Delete instance "' + b.dataset.name + '"?')) return;
                    ajax('wacrm_delete_instance', { instance_name: b.dataset.name }).done(function (r) {
                        toast(r.success ? 'Deleted' : 'Error', r.success ? 'success' : 'error');
                        if (r.success) loadInstances(true);
                    });
                });
            });
        });
    }

    function startQRPolling(instanceName) {
        clearInterval(qrTimer);
        var attempts = 0, done = false;
        openModal('Scan QR – ' + instanceName,
            '<div style="text-align:center;padding:12px">' +
            '<p style="color:var(--muted);margin-bottom:16px">WhatsApp → Linked Devices → Link a Device</p>' +
            '<div id="qr-wrap" style="min-height:280px;display:flex;align-items:center;justify-content:center;">' +
            '<span class="wacrm-spinner"></span>&nbsp;Connecting…</div></div>', null
        );
        // trigger QR generation
        ajax('wacrm_connect_instance', { instance_name: instanceName }).always(function () {
            setTimeout(poll, 1500);
        });
        function poll() {
            if (done || !document.getElementById('qr-wrap')) { clearInterval(qrTimer); return; }
            ajax('wacrm_get_qr', { instance_name: instanceName }).done(function (r) {
                var wrap = document.getElementById('qr-wrap');
                if (!wrap || done) return;
                if (r.success && r.data) {
                    if (r.data.connected) {
                        done = true; clearInterval(qrTimer);
                        wrap.innerHTML = '<div style="font-size:52px">✅</div><p style="color:var(--success);font-weight:700">Connected!</p>';
                        setTimeout(function () { closeModal(); loadInstances(true); }, 1800);
                        return;
                    }
                    if (r.data.qr) {
                        var src = r.data.qr;
                        if (src.indexOf('data:') !== 0) src = 'data:image/png;base64,' + src;
                        wrap.innerHTML = '<img src="' + src + '" style="max-width:260px;border-radius:8px">';
                    }
                }
                ajax('wacrm_instance_status', { instance_name: instanceName }).done(function (sr) {
                    var state = sr.success && sr.data ? (sr.data.instance && sr.data.instance.state) || sr.data.state || sr.data.status || '' : '';
                    var wrap2 = document.getElementById('qr-wrap');
                    if (wrap2 && state === 'open') {
                        done = true; clearInterval(qrTimer);
                        wrap2.innerHTML = '<div style="font-size:52px">✅</div><p style="color:var(--success);font-weight:700">Connected!</p>';
                        setTimeout(function () { closeModal(); loadInstances(true); }, 1800);
                    }
                });
            });
        }
        qrTimer = setInterval(function () {
            attempts++;
            if (attempts >= 40) { clearInterval(qrTimer); return; }
            poll();
        }, 3000);
    }

    /* ════════════════════════════════════════════════════════════════════════
       CONTACTS — adds list select + custom fields (FIX #1, #3, #6)
    ════════════════════════════════════════════════════════════════════════ */
    var contactPage = 1;

    function initContacts() {
        loadContacts();
        var btnAdd = document.getElementById('btn-add-contact');
        var btnCsv = document.getElementById('btn-import-csv');
        var search = document.getElementById('contact-search');
        if (btnAdd) btnAdd.addEventListener('click', function () { contactModal(null); });
        if (btnCsv) btnCsv.addEventListener('click', function () {
            var fi = document.getElementById('csv-file-input');
            if (fi) fi.click();
        });
        var fileInput = document.getElementById('csv-file-input');
        if (fileInput) fileInput.addEventListener('change', function () {
            var file = this.files[0]; if (!file) return;
            var fd = new FormData();
            fd.append('action', 'wacrm_import_contacts_csv');
            fd.append('_nonce', waCRM.nonce);
            fd.append('csv_file', file);
            toast('Importing…', 'warning');
            $.ajax({ url: waCRM.ajax_url, type: 'POST', data: fd, processData: false, contentType: false })
                .done(function (r) {
                    if (r.success) { toast((r.data && r.data.message) || 'Imported!'); clearCache('contacts'); loadContacts(); }
                    else toast((r.data && r.data.message) || 'Import failed', 'error');
                });
            fileInput.value = '';
        });
        if (search) search.addEventListener('input', debounce(function () { contactPage = 1; clearCache('contacts'); loadContacts(); }, 350));
    }

    function loadContacts() {
        var tbody = document.getElementById('contacts-tbody');
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
                            if (r.success) { toast('Deleted'); clearCache('contacts'); loadContacts(); }
                            else { setBtnLoading(self, false); toast('Error', 'error'); }
                        });
                    });
                });
            }
            var pw = document.getElementById('contacts-pagination');
            if (pw) { pw.innerHTML = ''; pw.appendChild(pagination(total, contactPage, 25, function (p) { contactPage = p; loadContacts(); })); }
        });
    }

    /* FIX #1 — contactModal with list select + custom fields, same styling */
    function contactModal(c) {
        var baseHtml =
            '<div class="wacrm-form-grid">' +
            '<div class="wacrm-form-row"><label>First Name</label><input class="wacrm-input" id="cf-first" value="' + escHtml(c ? c.first_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Last Name</label><input class="wacrm-input" id="cf-last" value="' + escHtml(c ? c.last_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Phone</label><input class="wacrm-input" id="cf-phone" value="' + escHtml(c ? c.phone : '') + '" placeholder="971501234567"></div>' +
            '<div class="wacrm-form-row"><label>WhatsApp</label><input class="wacrm-input" id="cf-wa" value="' + escHtml(c ? c.whatsapp : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Email</label><input class="wacrm-input" id="cf-email" value="' + escHtml(c ? c.email : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Tags</label><input class="wacrm-input" id="cf-tags" value="' + escHtml(c ? c.tags : '') + '"></div>' +
            '<div class="wacrm-form-row" id="cf-list-row"><label>Add to List</label>' +
            '<select class="wacrm-select" id="cf-list"><option value="">— Loading lists… —</option></select></div>' +
            '</div>' +
            '<div id="cf-custom-fields" style="margin-top:4px"></div>';

        openModal(c ? 'Edit Contact' : 'Add Contact', baseHtml, function (modal, resetBtn) {
            var payload = {
                id:         c ? c.id : 0,
                first_name: (modal.querySelector('#cf-first') || {}).value || '',
                last_name:  (modal.querySelector('#cf-last')  || {}).value || '',
                phone:      (modal.querySelector('#cf-phone') || {}).value || '',
                whatsapp:   (modal.querySelector('#cf-wa')    || {}).value || '',
                email:      (modal.querySelector('#cf-email') || {}).value || '',
                tags:       (modal.querySelector('#cf-tags')  || {}).value || ''
            };
            // collect custom meta fields (prefixed meta_)
            modal.querySelectorAll('[data-meta-key]').forEach(function (el) {
                payload['meta_' + el.dataset.metaKey] = el.value;
            });
            ajax('wacrm_save_contact', payload).done(function (r) {
                if (resetBtn) resetBtn();
                if (!r.success) { toast((r.data && r.data.message) || 'Error', 'error'); return; }
                var contactId = (r.data && r.data.id) ? r.data.id : (c ? c.id : 0);
                var listSel = modal.querySelector('#cf-list');
                var listId = listSel ? listSel.value : '';
                if (listId && contactId) {
                    ajax('wacrm_assign_list', { contact_id: contactId, list_id: listId }).always(function () {
                        toast(c ? 'Contact updated' : 'Contact added');
                        closeModal(); clearCache('contacts'); loadContacts();
                    });
                } else {
                    toast(c ? 'Contact updated' : 'Contact added');
                    closeModal(); clearCache('contacts'); loadContacts();
                }
            });
        }, { wide: false });

        // populate list select
        getLists(function (lists) {
            var sel = document.getElementById('cf-list');
            if (!sel) return;
            sel.innerHTML = '<option value="">— None —</option>' +
                lists.map(function (l) { return '<option value="' + l.id + '">' + escHtml(l.list_name) + '</option>'; }).join('');
        });

        // load custom fields + existing meta values
        /* FIX #6 — same wacrm-form-row / wacrm-input styling as default fields */
        getFields(function (fields) {
            var wrap = document.getElementById('cf-custom-fields');
            if (!wrap || !fields.length) return;
            var loadMeta = function (meta) {
                var html = '<div class="wacrm-form-grid" style="border-top:1px solid var(--border);margin-top:8px;padding-top:12px;">';
                html += '<div class="wacrm-form-row" style="grid-column:1/-1"><p style="font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:0">Custom Fields</p></div>';
                fields.forEach(function (f) {
                    var val = escHtml(meta[f.field_key] || '');
                    var input;
                    if (f.field_type === 'textarea') {
                        input = '<textarea class="wacrm-textarea" rows="2" data-meta-key="' + escHtml(f.field_key) + '">' + val + '</textarea>';
                    } else if (f.field_type === 'dropdown' || f.field_type === 'select') {
                        var opts = (f.field_opts || '').split(',').map(function (o) { return o.trim(); }).filter(Boolean);
                        input = '<select class="wacrm-select" data-meta-key="' + escHtml(f.field_key) + '">' +
                            '<option value="">— Select —</option>' +
                            opts.map(function (o) { return '<option value="' + escHtml(o) + '"' + (meta[f.field_key] === o ? ' selected' : '') + '>' + escHtml(o) + '</option>'; }).join('') +
                            '</select>';
                    } else if (f.field_type === 'checkbox') {
                        input = '<label><input type="checkbox" data-meta-key="' + escHtml(f.field_key) + '"' + (meta[f.field_key] === '1' ? ' checked' : '') + ' onchange="this.value=this.checked?\'1\':\'0\'"> ' + escHtml(f.field_label) + '</label>';
                    } else {
                        var itype = f.field_type === 'number' ? 'number' : (f.field_type === 'date' ? 'date' : 'text');
                        input = '<input class="wacrm-input" type="' + itype + '" data-meta-key="' + escHtml(f.field_key) + '" value="' + val + '">';
                    }
                    html += '<div class="wacrm-form-row"><label>' + escHtml(f.field_label) +
                        ' <code style="font-size:10px;opacity:.5;font-weight:normal">{{' + escHtml(f.field_key) + '}}</code></label>' +
                        input + '</div>';
                });
                html += '</div>';
                wrap.innerHTML = html;
            };
            if (c && c.id) {
                ajax('wacrm_get_contact_meta', { contact_id: c.id }).done(function (mr) {
                    loadMeta((mr.success && mr.data) ? mr.data : {});
                }).fail(function () { loadMeta({}); });
            } else {
                loadMeta({});
            }
        });
    }

    /* ════════════════════════════════════════════════════════════════════════
       FIELDS — FIX #5: show {{key}} tag, exact IDs from page-fields.php
    ════════════════════════════════════════════════════════════════════════ */
    function getFields(cb) {
        var cached = getCache('fields');
        if (cached) { cb(cached); return; }
        ajax('wacrm_get_fields').done(function (r) {
            var data = (r.success && r.data) ? r.data : [];
            setCache('fields', data); cb(data);
        }).fail(function () { cb([]); });
    }

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
            setCache('fields', data);
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
                        if (r.success) { toast('Deleted'); clearCache('fields'); loadFields(); }
                        else { setBtnLoading(self, false); toast('Error', 'error'); }
                    });
                });
            });
        });
    }

    function fieldModal(f) {
        openModal(f ? 'Edit Field' : 'New Custom Field',
            '<div class="wacrm-form-row"><label>Field Key <small style="font-weight:normal;color:var(--muted)">(lowercase, no spaces)</small></label>' +
            '<input class="wacrm-input" id="f-key" value="' + escHtml(f ? f.field_key : '') + '" placeholder="e.g. city" ' + (f ? 'readonly style="background:var(--bg)"' : '') + '></div>' +
            '<div class="wacrm-form-row"><label>Label</label><input class="wacrm-input" id="f-label" value="' + escHtml(f ? f.field_label : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Type</label><select class="wacrm-select" id="f-type">' +
            ['text', 'number', 'date', 'dropdown', 'checkbox', 'textarea'].map(function (t) {
                return '<option value="' + t + '"' + (f && f.field_type === t ? ' selected' : '') + '>' + t + '</option>';
            }).join('') + '</select></div>' +
            '<div class="wacrm-form-row" id="f-opts-row" style="' + (f && (f.field_type === 'dropdown' || f.field_type === 'select') ? '' : 'display:none') + '">' +
            '<label>Options <small>(comma-separated)</small></label>' +
            '<input class="wacrm-input" id="f-opts" value="' + escHtml(f ? f.field_opts || '' : '') + '" placeholder="Option A, Option B, Option C"></div>',
            function (modal, resetBtn) {
                ajax('wacrm_save_field', {
                    id:          f ? f.id : 0,
                    field_key:   (modal.querySelector('#f-key').value || '').trim().toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                    field_label: modal.querySelector('#f-label').value,
                    field_type:  modal.querySelector('#f-type').value,
                    field_opts:  (modal.querySelector('#f-opts') || {}).value || ''
                }).done(function (r) {
                    if (resetBtn) resetBtn();
                    if (r.success) { toast(f ? 'Updated' : 'Field created'); closeModal(); clearCache('fields'); loadFields(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
        setTimeout(function () {
            var ft = document.getElementById('f-type');
            if (ft) ft.addEventListener('change', function () {
                var row = document.getElementById('f-opts-row');
                if (row) row.style.display = (this.value === 'dropdown' || this.value === 'select') ? '' : 'none';
            });
        }, 50);
    }

    /* ════════════════════════════════════════════════════════════════════════
       LISTS
    ════════════════════════════════════════════════════════════════════════ */
    function getLists(cb) {
        var cached = getCache('lists');
        if (cached) { cb(cached); return; }
        ajax('wacrm_get_lists').done(function (r) {
            var data = (r.success && r.data) ? r.data : [];
            setCache('lists', data); cb(data);
        }).fail(function () { cb([]); });
    }

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
            setCache('lists', data);
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
                        if (r.success) { toast('Deleted'); clearCache('lists'); loadLists(); } else toast('Error', 'error');
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
                    .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(list ? 'Updated' : 'Created'); closeModal(); clearCache('lists'); loadLists(); } else toast('Error', 'error'); });
            }
        );
    }

    /* ════════════════════════════════════════════════════════════════════════
       CAMPAIGNS — FIX #10: stepsModal loads from DB, correct field names
    ════════════════════════════════════════════════════════════════════════ */
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
                tbody.innerHTML = '<tr><td colspan="5"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed') + '</div></td></tr>';
                return;
            }
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="5"><div class="wacrm-empty"><div class="empty-icon">📣</div><h3>No campaigns yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (c) {
                return '<tr>' +
                    '<td><strong>' + escHtml(c.campaign_name) + '</strong></td>' +
                    '<td>' + renderStatus(c.status) + '</td>' +
                    '<td>' + escHtml(c.rate_per_hour) + '/hr</td>' +
                    '<td>' + escHtml(c.schedule_from + ' – ' + c.schedule_to) + '</td>' +
                    '<td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-launch" data-id="' + c.id + '">▶ Launch</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-steps" data-id="' + c.id + '">Steps</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-ec2" data-id="' + c.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-dc2" data-id="' + c.id + '">Delete</button>' +
                    '</td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-launch').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('Launch this campaign now?')) return;
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

    /* FIX #8 — campaignModal adds list multi-select */
    function campaignModal(c) {
        getLists(function (lists) {
            var selectedLists = [];
            if (c && c.target_lists) {
                try { selectedLists = (typeof c.target_lists === 'string' ? JSON.parse(c.target_lists) : c.target_lists) || []; }
                catch (e) { selectedLists = []; }
                if (!Array.isArray(selectedLists)) selectedLists = [];
                selectedLists = selectedLists.map(Number);
            }
            var listHtml = lists.length
                ? '<select class="wacrm-select" id="c-lists" multiple style="height:' + Math.min(120, lists.length * 32 + 8) + 'px">' +
                  lists.map(function (l) {
                      return '<option value="' + l.id + '"' + (selectedLists.indexOf(Number(l.id)) > -1 ? ' selected' : '') + '>' + escHtml(l.list_name) + '</option>';
                  }).join('') +
                  '</select><p style="font-size:11.5px;color:var(--muted);margin:4px 0 0">Hold Ctrl/Cmd to select multiple</p>'
                : '<p style="font-size:12px;color:var(--muted);margin:0"><a href="?page=wacrm-lists">Create a list first →</a></p>';

            openModal(c ? 'Edit Campaign' : 'New Campaign',
                '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="cn" value="' + escHtml(c ? c.campaign_name : '') + '"></div>' +
                '<div class="wacrm-form-row"><label>Target Lists</label>' + listHtml + '</div>' +
                '<div class="wacrm-form-row"><label>Max Messages/Hour</label><input class="wacrm-input" type="number" id="cr" value="' + escHtml(c ? c.rate_per_hour : '100') + '" min="10" max="500"></div>' +
                '<div class="wacrm-form-row"><label>Send From</label><input class="wacrm-input" type="time" id="cf2" value="' + escHtml(c ? c.schedule_from : '09:00') + '"></div>' +
                '<div class="wacrm-form-row"><label>Send Until</label><input class="wacrm-input" type="time" id="ct" value="' + escHtml(c ? c.schedule_to : '20:00') + '"></div>' +
                '<div class="wacrm-form-row"><label><input type="checkbox" id="crand"' + ((!c || c.randomize_delay) ? ' checked' : '') + '> Randomise send delay (anti-ban)</label></div>',
                function (modal, resetBtn) {
                    var selEl = modal.querySelector('#c-lists');
                    var tLists = selEl ? Array.from(selEl.options).filter(function (o) { return o.selected; }).map(function (o) { return parseInt(o.value); }) : [];
                    ajax('wacrm_save_campaign', {
                        id: c ? c.id : 0,
                        campaign_name:   modal.querySelector('#cn').value,
                        target_lists:    JSON.stringify(tLists),
                        rate_per_hour:   modal.querySelector('#cr').value,
                        schedule_from:   modal.querySelector('#cf2').value,
                        schedule_to:     modal.querySelector('#ct').value,
                        randomize_delay: modal.querySelector('#crand').checked ? 1 : 0
                    }).done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(c ? 'Updated' : 'Created'); closeModal(); loadCampaigns(); } else toast('Error', 'error'); });
                }
            );
        });
    }

    /* FIX #10 — stepsModal loads existing steps, uses message_type/message_body
       FIX #5  — tag reference panel in each step textarea
       FIX     — template picker per step */
    function stepsModal(c) {
        if (!c) return;
        // Show loading
        openModal('Steps – ' + escHtml(c.campaign_name),
            '<div style="text-align:center;padding:32px"><span class="wacrm-spinner"></span> Loading…</div>', null
        );
        // Load existing steps + templates in parallel
        $.when(
            ajax('wacrm_get_campaign_steps', { campaign_id: c.id }),
            ajax('wacrm_get_templates')
        ).done(function (sr, tr) {
            var rawSteps = (sr[0] && sr[0].success && sr[0].data) ? sr[0].data : [];
            var templates = (tr[0] && tr[0].success && tr[0].data) ? tr[0].data : [];
            // normalise: DB returns message_type, message_body, delay_seconds
            var steps = rawSteps.map(function (s) {
                return {
                    message_type:  s.message_type || 'text',
                    message_body:  s.message_body || '',
                    media_url:     s.media_url || '',
                    delay_seconds: parseInt(s.delay_seconds) || 5,
                    template_id:   s.template_id || 0
                };
            });
            closeModal();
            renderStepsModal(c, steps, templates);
        }).fail(function () {
            closeModal();
            renderStepsModal(c, [], []);
        });
    }

    function renderStepsModal(c, steps, templates) {
        function stepHtml(s, i) {
            var taId = 'step-body-' + i;
            var tplPickerBtn = templates.length
                ? '<button type="button" class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-pick-tpl" data-si="' + i + '" title="Pick a saved template" style="flex-shrink:0">📄 Template</button>'
                : '';
            return '<div class="step-item" data-si="' + i + '" style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:10px;">' +
                '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">' +
                '<span class="wacrm-badge badge-blue" style="flex-shrink:0">Step ' + (i + 1) + '</span>' +
                '<select class="wacrm-select" style="width:110px" data-si="' + i + '" data-f="message_type">' +
                ['text', 'image', 'voice'].map(function (t) { return '<option value="' + t + '"' + (s.message_type === t ? ' selected' : '') + '>' + t + '</option>'; }).join('') +
                '</select>' +
                '<input class="wacrm-input" type="number" style="width:80px" placeholder="Delay s" value="' + escHtml(s.delay_seconds || 5) + '" min="1" data-si="' + i + '" data-f="delay_seconds" title="Delay in seconds">' +
                tplPickerBtn +
                '<button type="button" class="wacrm-btn wacrm-btn-sm wacrm-btn-danger" data-rm="' + i + '" style="margin-left:auto">✕</button>' +
                '</div>' +
                '<textarea class="wacrm-textarea" id="' + taId + '" rows="3" style="width:100%;box-sizing:border-box" data-si="' + i + '" data-f="message_body" placeholder="Message body (or media URL for image/voice)">' + escHtml(s.message_body || '') + '</textarea>' +
                buildTagPanel(taId) +
                '</div>';
        }

        function renderAll() {
            return steps.length
                ? '<div id="steps-container">' + steps.map(function (s, i) { return stepHtml(s, i); }).join('') + '</div>'
                : '<div id="steps-container"><p style="text-align:center;color:var(--muted);padding:24px 0">No steps yet. Click <strong>+ Add Step</strong> below.</p></div>';
        }

        openModal('Steps – ' + escHtml(c.campaign_name),
            renderAll() +
            '<button type="button" class="wacrm-btn wacrm-btn-outline" id="btn-add-step" style="width:100%;margin-top:12px">+ Add Step</button>',
            function (modal, resetBtn) {
                // re-read steps from DOM before saving (they may have changed)
                var finalSteps = steps.map(function (s, i) {
                    var bodyEl = modal.querySelector('[data-si="' + i + '"][data-f="message_body"]');
                    var typeEl = modal.querySelector('[data-si="' + i + '"][data-f="message_type"]');
                    var delEl  = modal.querySelector('[data-si="' + i + '"][data-f="delay_seconds"]');
                    return {
                        message_type:  typeEl ? typeEl.value : s.message_type,
                        message_body:  bodyEl ? bodyEl.value : s.message_body,
                        media_url:     s.media_url || '',
                        delay_seconds: delEl ? (parseInt(delEl.value) || 5) : s.delay_seconds,
                        template_id:   s.template_id || 0
                    };
                });
                ajax('wacrm_save_campaign_steps', { campaign_id: c.id, steps: JSON.stringify(finalSteps) })
                    .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast('Steps saved!'); closeModal(); } else toast((r.data && r.data.message) || 'Error', 'error'); });
            },
            { wide: true }
        );

        function bindAll() {
            var overlay = document.getElementById('wacrm-modal-overlay');
            if (!overlay) return;
            // live update steps array on change
            overlay.querySelectorAll('[data-f]').forEach(function (el) {
                el.oninput = el.onchange = function () {
                    var i = parseInt(this.dataset.si), f = this.dataset.f;
                    if (isNaN(i) || !steps[i]) return;
                    steps[i][f] = f === 'delay_seconds' ? (parseInt(this.value) || 5) : this.value;
                };
            });
            // remove step
            overlay.querySelectorAll('[data-rm]').forEach(function (btn) {
                btn.onclick = function (e) {
                    e.preventDefault();
                    steps.splice(parseInt(btn.dataset.rm), 1);
                    rerender();
                };
            });
            // template picker
            overlay.querySelectorAll('.btn-pick-tpl').forEach(function (btn) {
                btn.onclick = function (e) {
                    e.preventDefault();
                    var si = parseInt(btn.dataset.si);
                    // open inner modal listing templates
                    var prevOverlay = document.getElementById('wacrm-modal-overlay');
                    var tplHtml = '<div style="max-height:400px;overflow-y:auto;">' +
                        templates.map(function (t) {
                            return '<div class="tpl-pick-row" data-body="' + escHtml(t.body || '') + '" style="padding:12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer;transition:background .15s" onmouseover="this.style.background=\'var(--bg)\'" onmouseout="this.style.background=\'\'">' +
                                '<strong>' + escHtml(t.tpl_name) + '</strong> <span class="wacrm-badge badge-purple">' + escHtml(t.category) + '</span>' +
                                '<p style="margin:6px 0 0;font-size:12px;color:var(--muted)">' + escHtml((t.body || '').slice(0, 100)) + (t.body && t.body.length > 100 ? '…' : '') + '</p>' +
                                '</div>';
                        }).join('') +
                        '</div>';
                    // temporarily show a picker overlay
                    var picker = document.createElement('div');
                    picker.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:20px';
                    picker.innerHTML = '<div style="background:var(--surface);border-radius:12px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);">' +
                        '<h3 style="margin:0;font-size:15px;font-weight:700">Choose Template</h3>' +
                        '<button id="picker-close" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--muted)">✕</button></div>' +
                        '<div style="padding:16px">' + tplHtml + '</div></div>';
                    document.body.appendChild(picker);
                    picker.querySelector('#picker-close').onclick = function () { picker.remove(); };
                    picker.querySelectorAll('.tpl-pick-row').forEach(function (row) {
                        row.onclick = function () {
                            steps[si].message_body = row.dataset.body;
                            picker.remove();
                            rerender();
                        };
                    });
                };
            });
            bindTagBtns(overlay);
        }

        function rerender() {
            var sc = document.getElementById('steps-container');
            if (sc) {
                sc.outerHTML = steps.length
                    ? '<div id="steps-container">' + steps.map(function (s, i) { return stepHtml(s, i); }).join('') + '</div>'
                    : '<div id="steps-container"><p style="text-align:center;color:var(--muted);padding:24px 0">No steps. Click <strong>+ Add Step</strong>.</p></div>';
                bindAll();
            }
        }

        setTimeout(function () {
            bindAll();
            var addBtn = document.getElementById('btn-add-step');
            if (addBtn) addBtn.addEventListener('click', function () {
                steps.push({ message_type: 'text', message_body: '', media_url: '', delay_seconds: 5, template_id: 0 });
                rerender();
            });
        }, 80);
    }

    /* ════════════════════════════════════════════════════════════════════════
       TEMPLATES — FIX #4: category is <select>, FIX #11: tpl_name correct
    ════════════════════════════════════════════════════════════════════════ */
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
            if (!r.success) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-alert warning">' + escHtml((r.data && r.data.message) || 'Failed to load') + '</div></td></tr>';
                return;
            }
            var data = r.data || [];
            setCache('templates', data);
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">📝</div><h3>No templates yet</h3></div></td></tr>';
                return;
            }
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
                    ajax('wacrm_delete_template', { id: b.dataset.id }).done(function (r) { if (r.success) { toast('Deleted'); clearCache('templates'); loadTemplates(); } });
                });
            });
        });
    }

    /* FIX #4 — category is <select> (already was in original, kept)
       FIX #11 — sends tpl_name (correct DB column, already correct in original)
       FIX #5  — tag panel in body textarea */
    function templateModal(t) {
        var cats = ['order_confirmation', 'otp', 'campaign', 'manual', 'automation'];
        var taId = 'tpl-body';
        openModal(t ? 'Edit Template' : 'New Template',
            '<div class="wacrm-form-row"><label>Template Name</label><input class="wacrm-input" id="tpl-name" value="' + escHtml(t ? t.tpl_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Category</label>' +
            '<select class="wacrm-select" id="tpl-cat">' +
            cats.map(function (cat) { return '<option value="' + cat + '"' + (t && t.category === cat ? ' selected' : '') + '>' + cat + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="wacrm-form-row"><label>Message Body</label>' +
            '<textarea class="wacrm-textarea" id="' + taId + '" rows="7" placeholder="Hi {{first_name}}, your order #{{order_id}} is {{order_status}}…">' + escHtml(t ? t.body || '' : '') + '</textarea></div>' +
            buildTagPanel(taId),
            function (modal, resetBtn) {
                var name = (modal.querySelector('#tpl-name') || {}).value || '';
                if (!name.trim()) { toast('Template name required', 'error'); if (resetBtn) resetBtn(); return; }
                ajax('wacrm_save_template', {
                    id:       t ? t.id : 0,
                    tpl_name: name,
                    category: (modal.querySelector('#tpl-cat') || {}).value || 'manual',
                    body:     (modal.querySelector('#' + taId) || {}).value || ''
                }).done(function (r) {
                    if (resetBtn) resetBtn();
                    if (r.success) { toast(t ? 'Updated' : 'Template saved'); closeModal(); clearCache('templates'); loadTemplates(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            },
            { wide: false }
        );
        setTimeout(function () { bindTagBtns(document.getElementById('wacrm-modal-overlay')); }, 80);
    }

    /* ════════════════════════════════════════════════════════════════════════
       AUTOMATIONS
    ════════════════════════════════════════════════════════════════════════ */
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
            var data = (r.success && r.data) ? r.data : [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">⚡</div><h3>No automations yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (a) {
                return '<tr><td><strong>' + escHtml(a.auto_name) + '</strong></td>' +
                    '<td><span class="wacrm-badge badge-blue">' + escHtml(a.trigger_type) + '</span></td>' +
                    '<td>' + renderStatus(a.status) + '</td>' +
                    '<td><button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-ea" data-id="' + a.id + '">Edit</button> ' +
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
        var existingMsg = '';
        if (a && a.actions) {
            try {
                var acts = typeof a.actions === 'string' ? JSON.parse(a.actions) : a.actions;
                if (Array.isArray(acts) && acts[0]) existingMsg = acts[0].message_body || '';
            } catch (e) {}
        }
        var taId = 'auto-msg';
        openModal(a ? 'Edit Automation' : 'New Automation',
            '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="an" value="' + escHtml(a ? a.auto_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Trigger</label><select class="wacrm-select" id="at2">' +
            ['woocommerce_order_created', 'woocommerce_order_updated', 'woocommerce_order_completed'].map(function (t) {
                return '<option value="' + t + '"' + (a && a.trigger_type === t ? ' selected' : '') + '>' + t + '</option>';
            }).join('') + '</select></div>' +
            '<div class="wacrm-form-row"><label>Message Body</label>' +
            '<textarea class="wacrm-textarea" id="' + taId + '" rows="5" placeholder="Hi {{customer_name}}, order #{{order_id}} is {{order_status}}">' + escHtml(existingMsg) + '</textarea></div>' +
            buildTagPanel(taId),
            function (modal, resetBtn) {
                ajax('wacrm_save_automation', {
                    id:           a ? a.id : 0,
                    auto_name:    modal.querySelector('#an').value,
                    trigger_type: modal.querySelector('#at2').value,
                    conditions:   JSON.stringify([]),
                    actions:      JSON.stringify([{ type: 'send_message', message_body: modal.querySelector('#' + taId).value }]),
                    status:       'active'
                }).done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast(a ? 'Updated' : 'Created'); closeModal(); loadAutomations(); } else toast('Error', 'error'); });
            }
        );
        setTimeout(function () { bindTagBtns(document.getElementById('wacrm-modal-overlay')); }, 80);
    }

    /* ════════════════════════════════════════════════════════════════════════
       SETTINGS — FIX #12: exact element IDs from page-settings.php
    ════════════════════════════════════════════════════════════════════════ */
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
                    otp_max_attempts: (document.getElementById('set-otp-attempts')     || {}).value || 5,
                    otp_template:     (document.getElementById('set-otp-template')     || {}).value || 0
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

    /* ════════════════════════════════════════════════════════════════════════
       WOOCOMMERCE ORDERS
    ════════════════════════════════════════════════════════════════════════ */
    function initOrders() {
        var tbody = document.getElementById('orders-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_orders').done(function (r) {
            var data = (r.success && r.data) ? r.data : [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="6"><div class="wacrm-empty"><div class="empty-icon">🛒</div><h3>No orders</h3></div></td></tr>'; return; }
            tbody.innerHTML = data.map(function (o) {
                return '<tr><td>#' + escHtml(String(o.id)) + '</td><td>' + escHtml(o.customer_name) + '</td>' +
                    '<td>' + escHtml(o.phone || '—') + '</td><td>' + renderStatus(o.status) + '</td>' +
                    '<td>' + escHtml(o.total) + '</td>' +
                    '<td><button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-send-o" data-id="' + o.id + '">Send WA</button></td></tr>';
            }).join('');
            tbody.querySelectorAll('.btn-send-o').forEach(function (b) {
                b.addEventListener('click', function () {
                    var taId = 'order-msg';
                    openModal('Send WhatsApp – Order #' + b.dataset.id,
                        '<div class="wacrm-form-row"><label>Message</label><textarea class="wacrm-textarea" id="' + taId + '" rows="5" placeholder="Hi {{customer_name}}…"></textarea></div>' +
                        buildTagPanel(taId),
                        function (modal, resetBtn) {
                            ajax('wacrm_send_order_message', { order_id: b.dataset.id, message: (modal.querySelector('#' + taId) || {}).value || '' })
                                .done(function (r) { if (resetBtn) resetBtn(); if (r.success) { toast('Sent!'); closeModal(); } else toast((r.data && r.data.message) || 'Error', 'error'); });
                        }
                    );
                    setTimeout(function () { bindTagBtns(document.getElementById('wacrm-modal-overlay')); }, 80);
                });
            });
        });
    }

    /* ════════════════════════════════════════════════════════════════════════
       LOGS
    ════════════════════════════════════════════════════════════════════════ */
    function initLogs() {
        var tbody = document.getElementById('logs-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_logs').done(function (r) {
            var data = (r.success && r.data) ? r.data : [];
            if (!data.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">No messages logged yet.</td></tr>'; return; }
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

    /* ════════════════════════════════════════════════════════════════════════
       BOOT — reads data-wacrm-page from #wacrm-app (NOT body)
       FIX #7: dashboard data keys match exactly
    ════════════════════════════════════════════════════════════════════════ */
    $(document).ready(function () {
        initQuotaBadge();
        // Pre-load custom fields so tag panels work on every page
        ajax('wacrm_get_fields').done(function (r) {
            if (r.success && r.data) setCache('fields', r.data);
        });

        var appEl = document.getElementById('wacrm-app');
        if (!appEl) return;
        var page = appEl.getAttribute('data-wacrm-page');
        switch (page) {
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

})(jQuery);