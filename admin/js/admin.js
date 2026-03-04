/* WA Atlas CRM – Admin JS  v1.0.1
   ================================ */

(function ($) {
    'use strict';

    const API = waCRM.ajax_url;
    const N   = waCRM.nonce;

    // ── Core AJAX helper ───────────────────────────────────────────────────────
    function ajax(action, data) {
        return $.ajax({
            url:    API,
            method: 'POST',
            data:   $.extend({ action: action, _nonce: N }, data || {}),
        });
    }

    // ── Toast notifications ────────────────────────────────────────────────────
    function toast(msg, type) {
        type = type || 'success';
        var wrap = document.getElementById('wacrm-toast');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'wacrm-toast';
            document.body.appendChild(wrap);
        }
        var el = document.createElement('div');
        el.className = 'wacrm-toast-item ' + type;
        el.innerHTML = '<span>' + (type === 'success' ? '✓' : '✕') + '</span> ' + escHtml(msg);
        wrap.appendChild(el);
        setTimeout(function () { el.remove(); }, 4000);
    }

    // ── Modal ──────────────────────────────────────────────────────────────────
    function openModal(title, bodyHtml, onConfirm) {
        closeModal();
        var o = document.createElement('div');
        o.className = 'wacrm-modal-overlay';
        o.id = 'wacrm-modal-overlay';
        var footer = onConfirm
            ? '<div class="wacrm-modal-footer">' +
              '<button class="wacrm-btn wacrm-btn-outline" id="wacrm-modal-cancel">Cancel</button>' +
              '<button class="wacrm-btn wacrm-btn-primary" id="wacrm-modal-confirm">Save</button>' +
              '</div>'
            : '';
        o.innerHTML =
            '<div class="wacrm-modal">' +
            '<div class="wacrm-modal-header"><h3>' + title + '</h3>' +
            '<button class="wacrm-modal-close" id="wacrm-modal-x">✕</button></div>' +
            '<div class="wacrm-modal-body">' + bodyHtml + '</div>' +
            footer + '</div>';
        document.body.appendChild(o);
        document.getElementById('wacrm-modal-x').onclick = closeModal;
        var cancelBtn = document.getElementById('wacrm-modal-cancel');
        if (cancelBtn) cancelBtn.onclick = closeModal;
        if (onConfirm) {
            document.getElementById('wacrm-modal-confirm').onclick = function () { onConfirm(o); };
        }
        o.addEventListener('click', function (e) { if (e.target === o) closeModal(); });
    }

    function closeModal() {
        var el = document.getElementById('wacrm-modal-overlay');
        if (el) el.remove();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = String(str || '');
        return d.innerHTML;
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }

    function renderStatus(status) {
        var map = {
            open:         ['badge-green',  'Connected'],
            connected:    ['badge-green',  'Connected'],
            close:        ['badge-red',    'Closed'],
            disconnected: ['badge-red',    'Disconnected'],
            pending:      ['badge-yellow', 'Pending'],
            sent:         ['badge-green',  'Sent'],
            failed:       ['badge-red',    'Failed'],
            running:      ['badge-blue',   'Running'],
            draft:        ['badge-gray',   'Draft'],
            active:       ['badge-green',  'Active'],
            inactive:     ['badge-gray',   'Inactive'],
            completed:    ['badge-green',  'Completed'],
            processing:   ['badge-blue',   'Processing'],
            'on-hold':    ['badge-yellow', 'On Hold'],
            cancelled:    ['badge-red',    'Cancelled'],
            refunded:     ['badge-gray',   'Refunded'],
            whatsapp_not_connected: ['badge-yellow', 'No WhatsApp'],
        };
        var entry = map[status] || ['badge-gray', status || '—'];
        return '<span class="wacrm-badge ' + entry[0] + '">' + escHtml(entry[1]) + '</span>';
    }

    function pagination(total, page, perPage, callback) {
        var pages = Math.ceil(total / perPage);
        if (pages <= 1) return document.createDocumentFragment();
        var wrap = document.createElement('div');
        wrap.className = 'wacrm-pagination';
        for (var i = 1; i <= pages; i++) {
            (function (pageNum) {
                var btn = document.createElement('button');
                btn.textContent = pageNum;
                if (pageNum === page) btn.className = 'active';
                btn.addEventListener('click', function () { callback(pageNum); });
                wrap.appendChild(btn);
            })(i);
        }
        return wrap;
    }

    // ── Quota badge ────────────────────────────────────────────────────────────
    function initQuotaBadge() {
        var q   = waCRM.quota || {};
        var max = q.max || 5000;
        var used = q.used || 0;
        var pct = Math.round((used / max) * 100);
        var wrap = document.querySelector('.wacrm-quota-badge');
        if (!wrap) return;
        if (pct >= 90) wrap.classList.add('danger');
        else if (pct >= 70) wrap.classList.add('warning');
        var fill = wrap.querySelector('.quota-bar-fill');
        if (fill) fill.style.width = pct + '%';
        var txt = wrap.querySelector('.quota-text');
        if (txt) txt.textContent = ((q.remaining || 0)).toLocaleString() + ' msgs left';
    }

    // ── Dashboard ──────────────────────────────────────────────────────────────
    function initDashboard() {
        ajax('wacrm_get_dashboard_data').done(function (r) {
            if (!r.success) return;
            var d = r.data;
            function set(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }
            set('stat-sent-today',  d.sent_today);
            set('stat-sent-month',  d.sent_month);
            set('stat-quota-used',  d.quota_used + ' / ' + d.quota_max);
            set('stat-failed',      d.failed);
            set('stat-contacts',    d.total_contacts);
            set('stat-campaigns',   d.active_campaigns);
            set('stat-automations', d.active_automations);

            var instEl = document.getElementById('stat-instance');
            if (instEl) instEl.innerHTML = renderStatus(d.instance_status);

            var canvas = document.getElementById('wacrm-daily-chart');
            if (canvas && d.daily_chart && window.Chart) {
                var labels = d.daily_chart.map(function (r) { return r.date; });
                var data   = d.daily_chart.map(function (r) { return parseInt(r.count); });
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Messages Sent',
                            data: data,
                            backgroundColor: 'rgba(99,91,255,.18)',
                            borderColor: '#635bff',
                            borderWidth: 2,
                            borderRadius: 6,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: '#e3e8ef' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
        });
    }

    // ── Instances ──────────────────────────────────────────────────────────────
    function initInstances() {
        loadInstances();
        var btn = document.getElementById('btn-create-instance');
        if (btn) btn.addEventListener('click', function () {
            openModal('Create WhatsApp Instance',
                '<div class="wacrm-form-row"><label>Instance Name</label>' +
                '<input class="wacrm-input" id="new-instance-name" placeholder="e.g. my-whatsapp-1" autocomplete="off"></div>',
                function (modal) {
                    var name = modal.querySelector('#new-instance-name').value.trim();
                    if (!name) { toast('Enter an instance name', 'error'); return; }
                    ajax('wacrm_create_instance', { instance_name: name }).done(function (r) {
                        if (r.success) { toast('Instance created!'); closeModal(); loadInstances(); }
                        else toast((r.data && r.data.message) || 'Error creating instance', 'error');
                    });
                }
            );
        });
    }

    function loadInstances() {
        var grid = document.getElementById('wacrm-instances-grid');
        if (!grid) return;
        grid.innerHTML = '<div style="padding:40px;text-align:center"><span class="wacrm-spinner"></span></div>';

        // Fetch instance list from Evolution API via our AJAX proxy
        ajax('wacrm_fetch_instances').done(function (r) {
            grid.innerHTML = '';
            var instances = (r.success && Array.isArray(r.data)) ? r.data : [];
            if (!instances.length) {
                grid.innerHTML =
                    '<div class="wacrm-empty" style="grid-column:1/-1">' +
                    '<div class="empty-icon">📱</div>' +
                    '<h3>No instances yet</h3>' +
                    '<p>Click "New Instance" to connect a WhatsApp number.</p></div>';
                return;
            }
            instances.forEach(function (inst) { renderInstanceCard(grid, inst); });
        }).fail(function () {
            grid.innerHTML = '<div class="wacrm-alert error">Failed to load instances. Check your API settings.</div>';
        });
    }

    function renderInstanceCard(grid, inst) {
        var name   = inst.instance_name || inst.instance || '—';
        var status = (inst.instance && inst.instance.state) ? inst.instance.state : (inst.status || 'unknown');
        var phone  = (inst.instance && inst.instance.profileName) ? inst.instance.profileName : (inst.connected_num || '');
        var enabled = inst.enabled !== 0;

        var card = document.createElement('div');
        card.className = 'wacrm-instance-card';
        card.innerHTML =
            '<div class="instance-head">' +
            '<div><div class="instance-name">' + escHtml(name) + '</div>' +
            '<div class="instance-num">' + escHtml(phone || 'Not connected') + '</div></div>' +
            '<span><span class="status-dot ' + escHtml(status) + '"></span>' + escHtml(status) + '</span>' +
            '</div>' +
            '<div class="instance-actions">' +
            '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-qr" data-name="' + escHtml(name) + '">📷 QR Code</button>' +
            '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-restart" data-name="' + escHtml(name) + '">↺ Restart</button>' +
            '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-inst" data-name="' + escHtml(name) + '">🗑 Delete</button>' +
            '</div>';

        card.querySelector('.btn-qr').addEventListener('click', function () { showQR(this.dataset.name); });
        card.querySelector('.btn-restart').addEventListener('click', function () {
            var n = this.dataset.name;
            ajax('wacrm_restart_instance', { instance_name: n }).done(function (r) {
                toast(r.success ? 'Instance restarting…' : ((r.data && r.data.message) || 'Error'), r.success ? 'success' : 'error');
                setTimeout(loadInstances, 2000);
            });
        });
        card.querySelector('.btn-del-inst').addEventListener('click', function () {
            var n = this.dataset.name;
            if (!confirm('Delete instance "' + n + '" permanently?')) return;
            ajax('wacrm_delete_instance', { instance_name: n }).done(function () { toast('Deleted'); loadInstances(); });
        });
        grid.appendChild(card);
    }

    function showQR(name) {
        openModal('Scan QR Code – ' + name,
            '<div class="wacrm-qr-wrap" id="qr-container">' +
            '<span class="wacrm-spinner"></span>' +
            '<div class="qr-status">Fetching QR code…</div></div>'
        );
        pollQR(name, 0);
    }

    function pollQR(name, attempt) {
        var container = document.getElementById('qr-container');
        if (!container || !container.isConnected || attempt > 40) return;
        ajax('wacrm_get_qr', { instance_name: name }).done(function (r) {
            var c2 = document.getElementById('qr-container');
            if (!c2 || !c2.isConnected) return;
            if (r.success && r.data) {
                var state = (r.data.instance && r.data.instance.state) ? r.data.instance.state : '';
                if (state === 'open') {
                    c2.innerHTML = '<div style="font-size:48px">✅</div><h3 style="margin:8px 0 0">Connected!</h3>';
                    setTimeout(loadInstances, 1500);
                    return;
                }
                var qr = (r.data.qrcode && r.data.qrcode.base64) ? r.data.qrcode.base64
                       : (r.data.base64 || r.data.qr || '');
                if (qr) {
                    c2.innerHTML =
                        '<img src="data:image/png;base64,' + qr + '" alt="QR Code" style="max-width:220px;border:6px solid #fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.12)">' +
                        '<p class="qr-status" style="margin:12px 0 0;color:var(--muted)">Open WhatsApp → Linked Devices → Link a Device</p>';
                }
            }
            setTimeout(function () { pollQR(name, attempt + 1); }, 3000);
        });
    }

    // ── Contacts ───────────────────────────────────────────────────────────────
    var contactPage = 1;

    function initContacts() {
        loadContacts();
        var addBtn = document.getElementById('btn-add-contact');
        if (addBtn) addBtn.addEventListener('click', function () { contactModal(null); });

        var importBtn = document.getElementById('btn-import-csv');
        var fileInput = document.getElementById('csv-file-input');
        if (importBtn && fileInput) {
            importBtn.addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', function () {
                if (!this.files[0]) return;
                var fd = new FormData();
                fd.append('action', 'wacrm_import_contacts_csv');
                fd.append('_nonce', N);
                fd.append('csv_file', this.files[0]);
                $.ajax({ url: API, method: 'POST', data: fd, processData: false, contentType: false })
                    .done(function (r) {
                        if (r.success) toast('Imported ' + r.data.added + ' contacts');
                        else toast((r.data && r.data.message) || 'Import failed', 'error');
                        loadContacts();
                    });
            });
        }

        var search = document.getElementById('contact-search');
        if (search) search.addEventListener('input', debounce(function () { contactPage = 1; loadContacts(); }, 350));
    }

    function loadContacts() {
        var tbody  = document.getElementById('contacts-tbody');
        var search = document.getElementById('contact-search');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';

        ajax('wacrm_get_contacts', { page: contactPage, search: search ? search.value : '' }).done(function (r) {
            if (!r.success) { tbody.innerHTML = '<tr><td colspan="7">Error loading contacts.</td></tr>'; return; }
            var contacts = r.data.contacts || [];
            var total    = r.data.total || 0;

            if (!contacts.length) {
                tbody.innerHTML = '<tr><td colspan="7"><div class="wacrm-empty"><div class="empty-icon">👤</div><h3>No contacts found</h3></div></td></tr>';
            } else {
                tbody.innerHTML = contacts.map(function (c) {
                    var tags = c.tags ? c.tags.split(',').map(function (t) {
                        return '<span class="wacrm-tag">' + escHtml(t.trim()) + '</span>';
                    }).join('') : '';
                    var wa = c.whatsapp
                        ? '<span class="status-dot open"></span>' + escHtml(c.whatsapp)
                        : '<span class="status-dot disconnected"></span><em style="color:var(--muted)">None</em>';
                    return '<tr>' +
                        '<td><input type="checkbox" class="contact-cb" value="' + c.id + '"></td>' +
                        '<td>' + escHtml(c.first_name) + ' ' + escHtml(c.last_name) + '</td>' +
                        '<td>' + escHtml(c.phone) + '</td>' +
                        '<td>' + escHtml(c.email) + '</td>' +
                        '<td>' + wa + '</td>' +
                        '<td><div class="wacrm-tags">' + tags + '</div></td>' +
                        '<td>' +
                        '<button class="wacrm-btn wacrm-btn-icon btn-edit-contact" data-id="' + c.id + '" title="Edit">✏️</button> ' +
                        '<button class="wacrm-btn wacrm-btn-icon btn-del-contact" data-id="' + c.id + '" title="Delete">🗑️</button>' +
                        '</td></tr>';
                }).join('');

                tbody.querySelectorAll('.btn-edit-contact').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var id = parseInt(this.dataset.id);
                        contactModal(contacts.find(function (c) { return c.id == id; }));
                    });
                });
                tbody.querySelectorAll('.btn-del-contact').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete this contact?')) return;
                        ajax('wacrm_delete_contact', { id: this.dataset.id }).done(function (r) {
                            if (r.success) { toast('Contact deleted'); loadContacts(); }
                            else toast((r.data && r.data.message) || 'Error', 'error');
                        });
                    });
                });
            }

            var pagEl = document.getElementById('contacts-pagination');
            if (pagEl) { pagEl.innerHTML = ''; pagEl.appendChild(pagination(total, contactPage, 25, function (p) { contactPage = p; loadContacts(); })); }
        });
    }

    function contactModal(contact) {
        var isEdit = !!(contact && contact.id);
        openModal(isEdit ? 'Edit Contact' : 'Add Contact',
            '<div class="wacrm-form-grid">' +
            '<div class="wacrm-form-row"><label>First Name</label><input class="wacrm-input" id="cf-first" value="' + escHtml(contact ? contact.first_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Last Name</label><input class="wacrm-input" id="cf-last" value="' + escHtml(contact ? contact.last_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Phone</label><input class="wacrm-input" id="cf-phone" value="' + escHtml(contact ? contact.phone : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>WhatsApp Number</label><input class="wacrm-input" id="cf-whatsapp" value="' + escHtml(contact ? contact.whatsapp : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Email</label><input class="wacrm-input" id="cf-email" value="' + escHtml(contact ? contact.email : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Tags <small style="font-weight:normal;color:var(--muted)">(comma-separated)</small></label><input class="wacrm-input" id="cf-tags" value="' + escHtml(contact ? contact.tags : '') + '"></div>' +
            '</div>',
            function (modal) {
                ajax('wacrm_save_contact', {
                    id:         isEdit ? contact.id : 0,
                    first_name: modal.querySelector('#cf-first').value,
                    last_name:  modal.querySelector('#cf-last').value,
                    phone:      modal.querySelector('#cf-phone').value,
                    whatsapp:   modal.querySelector('#cf-whatsapp').value,
                    email:      modal.querySelector('#cf-email').value,
                    tags:       modal.querySelector('#cf-tags').value,
                }).done(function (r) {
                    if (r.success) { toast(isEdit ? 'Contact updated' : 'Contact added'); closeModal(); loadContacts(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
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
            if (!r.success) return;
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
                    '<td>' + escHtml(c.schedule_from) + ' – ' + escHtml(c.schedule_to) + '</td>' +
                    '<td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-launch" data-id="' + c.id + '">▶ Launch</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-steps" data-id="' + c.id + '" data-name="' + escHtml(c.campaign_name) + '">Steps</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-edit-camp" data-id="' + c.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-camp" data-id="' + c.id + '">Delete</button>' +
                    '</td></tr>';
            }).join('');

            tbody.querySelectorAll('.btn-launch').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Launch this campaign now?')) return;
                    var id = this.dataset.id;
                    ajax('wacrm_launch_campaign', { id: id }).done(function (r) {
                        if (r.success) toast('Queued ' + r.data.queued + ' messages (skipped ' + r.data.skipped + ')');
                        else toast((r.data && r.data.message) || 'Error', 'error');
                        loadCampaigns();
                    });
                });
            });
            tbody.querySelectorAll('.btn-steps').forEach(function (btn) {
                btn.addEventListener('click', function () { stepsModal(this.dataset.id, this.dataset.name); });
            });
            tbody.querySelectorAll('.btn-edit-camp').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.dataset.id;
                    campaignModal(data.find(function (c) { return c.id == id; }));
                });
            });
            tbody.querySelectorAll('.btn-del-camp').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Delete this campaign?')) return;
                    ajax('wacrm_delete_campaign', { id: this.dataset.id }).done(function () { toast('Deleted'); loadCampaigns(); });
                });
            });
        });
    }

    function campaignModal(c) {
        var isEdit = !!(c && c.id);
        openModal(isEdit ? 'Edit Campaign' : 'New Campaign',
            '<div class="wacrm-form-row"><label>Campaign Name</label><input class="wacrm-input" id="camp-name" value="' + escHtml(c ? c.campaign_name : '') + '"></div>' +
            '<div class="wacrm-form-grid">' +
            '<div class="wacrm-form-row"><label>Max Messages / Hour</label><input class="wacrm-input" type="number" id="camp-rate" value="' + (c ? c.rate_per_hour : 200) + '" min="1"></div>' +
            '<div class="wacrm-form-row"><label>Send From</label><input class="wacrm-input" type="time" id="camp-from" value="' + (c ? c.schedule_from : '09:00') + '"></div>' +
            '<div class="wacrm-form-row"><label>Send Until</label><input class="wacrm-input" type="time" id="camp-to" value="' + (c ? c.schedule_to : '20:00') + '"></div>' +
            '<div class="wacrm-form-row"><label class="wacrm-toggle"><input type="checkbox" id="camp-rand" ' + (c && c.randomize_delay ? 'checked' : '') + '><span class="track"></span> Randomize delay (anti-ban)</label></div>' +
            '</div>',
            function (modal) {
                ajax('wacrm_save_campaign', {
                    id:              isEdit ? c.id : 0,
                    campaign_name:   modal.querySelector('#camp-name').value,
                    rate_per_hour:   modal.querySelector('#camp-rate').value,
                    schedule_from:   modal.querySelector('#camp-from').value,
                    schedule_to:     modal.querySelector('#camp-to').value,
                    randomize_delay: modal.querySelector('#camp-rand').checked ? 1 : 0,
                }).done(function (r) {
                    if (r.success) { toast(isEdit ? 'Campaign updated' : 'Campaign created'); closeModal(); loadCampaigns(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
    }

    var stepCount = 0;
    function stepsModal(campaignId, name) {
        stepCount = 0;
        openModal('Message Steps – ' + escHtml(name),
            '<div id="step-builder" class="step-builder"></div>' +
            '<button type="button" class="wacrm-btn wacrm-btn-outline" id="btn-add-step" style="margin-top:12px">+ Add Step</button>',
            function (modal) {
                var steps = [];
                modal.querySelectorAll('.step-item').forEach(function (item, i) {
                    steps.push({
                        step_order:    i,
                        message_type:  item.querySelector('.step-type').value,
                        message_body:  item.querySelector('.step-body').value,
                        media_url:     (item.querySelector('.step-media-url') || {}).value || '',
                        delay_seconds: parseInt(item.querySelector('.step-delay').value) || 0,
                    });
                });
                ajax('wacrm_save_campaign_steps', { campaign_id: campaignId, steps: JSON.stringify(steps) }).done(function (r) {
                    if (r.success) { toast('Steps saved'); closeModal(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
        document.getElementById('btn-add-step').addEventListener('click', addStep);
        addStep();
    }

    function addStep() {
        stepCount++;
        var builder = document.getElementById('step-builder');
        if (!builder) return;
        var item = document.createElement('div');
        item.className = 'step-item';
        item.innerHTML =
            '<div class="step-header">' +
            '<span class="step-num">' + stepCount + '</span>' +
            '<strong>Step ' + stepCount + '</strong>' +
            '<button type="button" class="wacrm-btn wacrm-btn-icon wacrm-btn-sm step-remove" style="margin-left:auto">✕</button>' +
            '</div>' +
            '<div class="wacrm-form-grid">' +
            '<div class="wacrm-form-row"><label>Type</label>' +
            '<select class="wacrm-select step-type"><option value="text">Text</option><option value="image">Image</option><option value="voice">Voice</option></select></div>' +
            '<div class="wacrm-form-row"><label>Delay before this step (seconds)</label><input class="wacrm-input step-delay" type="number" value="0" min="0"></div>' +
            '<div class="wacrm-form-row" style="grid-column:1/-1"><label>Message Body</label>' +
            '<textarea class="wacrm-textarea step-body" rows="3" placeholder="Use {{customer_name}}, {{order_id}}, etc."></textarea></div>' +
            '<div class="wacrm-form-row step-media-row" style="display:none;grid-column:1/-1"><label>Media URL</label>' +
            '<input class="wacrm-input step-media-url" placeholder="https://…"></div>' +
            '</div>';
        item.querySelector('.step-remove').onclick = function () { item.remove(); };
        item.querySelector('.step-type').addEventListener('change', function () {
            item.querySelector('.step-media-row').style.display = this.value !== 'text' ? 'block' : 'none';
        });
        builder.appendChild(item);
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
            if (!r.success) return;
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">📝</div><h3>No templates yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (t) {
                var preview = t.body ? t.body.substring(0, 80) + (t.body.length > 80 ? '…' : '') : '';
                return '<tr>' +
                    '<td><strong>' + escHtml(t.tpl_name) + '</strong></td>' +
                    '<td><span class="wacrm-badge badge-blue">' + escHtml(t.category) + '</span></td>' +
                    '<td style="color:var(--muted);font-size:12.5px">' + escHtml(preview) + '</td>' +
                    '<td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-edit-tpl" data-id="' + t.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-tpl" data-id="' + t.id + '">Delete</button>' +
                    '</td></tr>';
            }).join('');

            tbody.querySelectorAll('.btn-edit-tpl').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.dataset.id;
                    templateModal(data.find(function (t) { return t.id == id; }));
                });
            });
            tbody.querySelectorAll('.btn-del-tpl').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Delete this template?')) return;
                    ajax('wacrm_delete_template', { id: this.dataset.id }).done(function () { toast('Deleted'); loadTemplates(); });
                });
            });
        });
    }

    function templateModal(t) {
        var cats = ['order_confirmation', 'otp', 'campaign', 'manual', 'automation'];
        var catOpts = cats.map(function (c) {
            return '<option value="' + c + '"' + (t && t.category === c ? ' selected' : '') + '>' + c + '</option>';
        }).join('');
        openModal(t ? 'Edit Template' : 'New Template',
            '<div class="wacrm-form-row"><label>Template Name</label><input class="wacrm-input" id="tpl-name" value="' + escHtml(t ? t.tpl_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Category</label><select class="wacrm-select" id="tpl-cat">' + catOpts + '</select></div>' +
            '<div class="wacrm-form-row"><label>Body</label>' +
            '<p style="font-size:11.5px;color:var(--muted);margin:0 0 6px">Tags: {{order_id}} {{customer_name}} {{order_total}} {{order_status}} {{billing_phone}} {{order_items}} {{otp}}</p>' +
            '<textarea class="wacrm-textarea" id="tpl-body" rows="6">' + escHtml(t ? t.body : '') + '</textarea></div>',
            function (modal) {
                ajax('wacrm_save_template', {
                    id:       t ? t.id : 0,
                    tpl_name: modal.querySelector('#tpl-name').value,
                    category: modal.querySelector('#tpl-cat').value,
                    body:     modal.querySelector('#tpl-body').value,
                }).done(function (r) {
                    if (r.success) { toast(t ? 'Template updated' : 'Template created'); closeModal(); loadTemplates(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
    }

    // ── WooCommerce Orders ─────────────────────────────────────────────────────
    function initOrders() { loadOrders(); }

    function loadOrders() {
        var tbody = document.getElementById('orders-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_orders').done(function (r) {
            if (!r.success) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="wacrm-alert warning">WooCommerce not active or no orders found.</div></td></tr>';
                return;
            }
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="8"><div class="wacrm-empty"><div class="empty-icon">🛒</div><h3>No orders yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (o) {
                var items = o.items.map(function (i) { return escHtml(i.name) + ' ×' + i.qty; }).join('<br>');
                return '<tr>' +
                    '<td>#' + o.id + '</td>' +
                    '<td>' + escHtml(o.customer_name) + '</td>' +
                    '<td>' + escHtml(o.phone) + '</td>' +
                    '<td>' + escHtml(o.email) + '</td>' +
                    '<td>' + o.total + '</td>' +
                    '<td>' + renderStatus((o.status || '').toLowerCase().replace(/\s+/g, '-')) + '</td>' +
                    '<td style="font-size:12.5px">' + items + '</td>' +
                    '<td><button class="wacrm-btn wacrm-btn-sm wacrm-btn-green btn-send-wa" data-id="' + o.id + '" data-name="' + escHtml(o.customer_name) + '"' + (o.phone ? '' : ' disabled') + '>📱 Message</button></td>' +
                    '</tr>';
            }).join('');

            tbody.querySelectorAll('.btn-send-wa').forEach(function (btn) {
                btn.addEventListener('click', function () { orderMessageModal(this.dataset.id, this.dataset.name); });
            });
        });
    }

    function orderMessageModal(orderId, name) {
        openModal('Send WhatsApp to ' + escHtml(name),
            '<p style="font-size:12.5px;color:var(--muted);margin-top:0">Tags: {{order_id}} {{customer_name}} {{order_total}} {{order_status}} {{billing_phone}} {{order_items}}</p>' +
            '<div class="wacrm-form-row"><textarea class="wacrm-textarea" id="order-msg" rows="5" placeholder="Hi {{customer_name}}, your order #{{order_id}} is {{order_status}}."></textarea></div>',
            function (modal) {
                ajax('wacrm_send_order_message', {
                    order_id: orderId,
                    message:  modal.querySelector('#order-msg').value,
                }).done(function (r) {
                    if (r.success) { toast('Message sent!'); closeModal(); }
                    else toast((r.data && r.data.message) || (r.data && r.data.error) || 'Send failed', 'error');
                });
            }
        );
    }

    // ── Logs ───────────────────────────────────────────────────────────────────
    function initLogs() { loadLogs(1); }

    function loadLogs(page) {
        var tbody = document.getElementById('logs-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px"><span class="wacrm-spinner"></span></td></tr>';
        ajax('wacrm_get_logs', { page: page }).done(function (r) {
            if (!r.success) return;
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="7"><div class="wacrm-empty"><div class="empty-icon">📋</div><h3>No logs yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (l) {
                var src = l.campaign_id ? 'Campaign #' + l.campaign_id : (l.automation_id ? 'Auto #' + l.automation_id : 'Manual');
                return '<tr>' +
                    '<td>#' + l.id + '</td>' +
                    '<td>' + escHtml(l.phone) + '</td>' +
                    '<td><span class="wacrm-badge badge-gray">' + escHtml(l.message_type) + '</span></td>' +
                    '<td>' + src + '</td>' +
                    '<td>' + renderStatus(l.status) + '</td>' +
                    '<td>' + escHtml(l.sent_at || '—') + '</td>' +
                    '<td style="color:var(--danger);font-size:12px">' + escHtml(l.error_msg || '') + '</td>' +
                    '</tr>';
            }).join('');
        });
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
            if (!r.success) return;
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">📋</div><h3>No lists yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (l) {
                return '<tr>' +
                    '<td><strong>' + escHtml(l.list_name) + '</strong></td>' +
                    '<td>' + escHtml(l.description || '') + '</td>' +
                    '<td><span class="wacrm-badge badge-blue">' + (l.contact_count || 0) + ' contacts</span></td>' +
                    '<td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-edit-list" data-id="' + l.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-list" data-id="' + l.id + '">Delete</button>' +
                    '</td></tr>';
            }).join('');

            tbody.querySelectorAll('.btn-edit-list').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.dataset.id;
                    listModal(data.find(function (l) { return l.id == id; }));
                });
            });
            tbody.querySelectorAll('.btn-del-list').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Delete this list?')) return;
                    ajax('wacrm_delete_list', { id: this.dataset.id }).done(function () { toast('Deleted'); loadLists(); });
                });
            });
        });
    }

    function listModal(list) {
        openModal(list ? 'Edit List' : 'New List',
            '<div class="wacrm-form-row"><label>List Name</label><input class="wacrm-input" id="list-name" value="' + escHtml(list ? list.list_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Description</label><textarea class="wacrm-textarea" id="list-desc" rows="3">' + escHtml(list ? list.description : '') + '</textarea></div>',
            function (modal) {
                ajax('wacrm_save_list', {
                    id:          list ? list.id : 0,
                    list_name:   modal.querySelector('#list-name').value,
                    description: modal.querySelector('#list-desc').value,
                }).done(function (r) {
                    if (r.success) { toast(list ? 'List updated' : 'List created'); closeModal(); loadLists(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
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
            if (!r.success) return;
            var data = r.data || [];
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="wacrm-empty"><div class="empty-icon">⚡</div><h3>No automations yet</h3></div></td></tr>';
                return;
            }
            tbody.innerHTML = data.map(function (a) {
                return '<tr>' +
                    '<td><strong>' + escHtml(a.auto_name) + '</strong></td>' +
                    '<td><span class="wacrm-badge badge-blue">' + escHtml(a.trigger_type) + '</span></td>' +
                    '<td>' + renderStatus(a.status) + '</td>' +
                    '<td>' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-outline btn-edit-auto" data-id="' + a.id + '">Edit</button> ' +
                    '<button class="wacrm-btn wacrm-btn-sm wacrm-btn-danger btn-del-auto" data-id="' + a.id + '">Delete</button>' +
                    '</td></tr>';
            }).join('');

            tbody.querySelectorAll('.btn-edit-auto').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.dataset.id;
                    automationModal(data.find(function (a) { return a.id == id; }));
                });
            });
            tbody.querySelectorAll('.btn-del-auto').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Delete this automation?')) return;
                    ajax('wacrm_delete_automation', { id: this.dataset.id }).done(function () { toast('Deleted'); loadAutomations(); });
                });
            });
        });
    }

    function automationModal(a) {
        var actions_decoded = [];
        try { actions_decoded = JSON.parse(a ? a.actions : '[]') || []; } catch (e) {}
        var existingMsg = (actions_decoded[0] && actions_decoded[0].message_body) ? actions_decoded[0].message_body : '';
        var triggers = ['woocommerce_order_created','woocommerce_order_updated','woocommerce_order_completed','new_contact_added','scheduled','webhook_received'];
        var tOpts = triggers.map(function (t) {
            return '<option value="' + t + '"' + (a && a.trigger_type === t ? ' selected' : '') + '>' + t + '</option>';
        }).join('');
        openModal(a ? 'Edit Automation' : 'New Automation',
            '<div class="wacrm-form-row"><label>Name</label><input class="wacrm-input" id="auto-name" value="' + escHtml(a ? a.auto_name : '') + '"></div>' +
            '<div class="wacrm-form-row"><label>Trigger Event</label><select class="wacrm-select" id="auto-trigger">' + tOpts + '</select></div>' +
            '<div class="wacrm-form-row"><label>Message to Send</label>' +
            '<p style="font-size:11.5px;color:var(--muted);margin:0 0 6px">Tags: {{customer_name}} {{order_status}} {{order_id}} {{order_total}}</p>' +
            '<textarea class="wacrm-textarea" id="auto-msg" rows="4" placeholder="Hi {{customer_name}}, your order is {{order_status}}">' + escHtml(existingMsg) + '</textarea></div>',
            function (modal) {
                var actions = [{ type: 'send_message', message_body: modal.querySelector('#auto-msg').value }];
                ajax('wacrm_save_automation', {
                    id:           a ? a.id : 0,
                    auto_name:    modal.querySelector('#auto-name').value,
                    trigger_type: modal.querySelector('#auto-trigger').value,
                    conditions:   JSON.stringify([]),
                    actions:      JSON.stringify(actions),
                    status:       'active',
                }).done(function (r) {
                    if (r.success) { toast(a ? 'Automation updated' : 'Automation created'); closeModal(); loadAutomations(); }
                    else toast((r.data && r.data.message) || 'Error', 'error');
                });
            }
        );
    }

    // ── Settings ───────────────────────────────────────────────────────────────
    function initSettings() {
        var form = document.getElementById('settings-form');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            // Collect only the fields we want — avoid pulling in display-only fields
            ajax('wacrm_save_settings', {
                api_url:          document.getElementById('set-api-url') ? document.getElementById('set-api-url').value : '',
                api_key:          document.getElementById('set-api-key') ? document.getElementById('set-api-key').value : '',
                rate_per_hour:    document.getElementById('set-rate') ? document.getElementById('set-rate').value : 200,
                otp_enabled:      document.getElementById('set-otp-enabled') && document.getElementById('set-otp-enabled').checked ? 1 : 0,
                otp_expiry:       document.getElementById('set-otp-expiry') ? document.getElementById('set-otp-expiry').value : 300,
                otp_max_attempts: document.getElementById('set-otp-attempts') ? document.getElementById('set-otp-attempts').value : 5,
                otp_template:     document.getElementById('set-otp-template') ? document.getElementById('set-otp-template').value : 0,
            }).done(function (r) {
                if (r.success) toast('Settings saved successfully');
                else toast((r.data && r.data.message) || 'Save failed', 'error');
            }).fail(function () {
                toast('Network error – settings not saved', 'error');
            });
            return false;
        });
    }

    // ── Boot ────────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        initQuotaBadge();

        // Read page ID from #wacrm-app div, NOT from body
        var appEl = document.getElementById('wacrm-app');
        var page  = appEl ? appEl.getAttribute('data-wacrm-page') : '';

        switch (page) {
            case 'dashboard':   initDashboard();   break;
            case 'instances':   initInstances();   break;
            case 'contacts':    initContacts();    break;
            case 'lists':       initLists();       break;
            case 'campaigns':   initCampaigns();   break;
            case 'automations': initAutomations(); break;
            case 'templates':   initTemplates();   break;
            case 'woocommerce': initOrders();      break;
            case 'logs':        initLogs();        break;
            case 'settings':    initSettings();    break;
        }
    });

})(jQuery);

/* ── Global AJAX error catcher (v1.0.2 patch) ──────────────────────────── */
(function ($) {
    $(document).ajaxError(function (event, xhr, settings) {
        // Only catch our own AJAX calls
        if (settings && settings.data && typeof settings.data === 'string' && settings.data.indexOf('wacrm_') !== -1) {
            var msg = 'Request failed';
            try {
                var r = JSON.parse(xhr.responseText);
                if (r && r.data && r.data.message) msg = r.data.message;
            } catch (e) {
                if (xhr.status === 0)   msg = 'Network error – check your connection.';
                if (xhr.status === 403) msg = 'Permission denied – please reload the page.';
                if (xhr.status === 400) msg = 'Bad request – nonce may have expired. Please reload.';
            }
            // Show toast if the toast function is available
            var wrap = document.getElementById('wacrm-toast');
            if (!wrap) { wrap = document.createElement('div'); wrap.id = 'wacrm-toast'; document.body.appendChild(wrap); }
            var el = document.createElement('div');
            el.className = 'wacrm-toast-item error';
            el.innerHTML = '<span>✕</span> ' + msg;
            wrap.appendChild(el);
            setTimeout(function () { el.remove(); }, 5000);
        }
    });
})(jQuery);
