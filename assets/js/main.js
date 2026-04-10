'use strict';

function initPasswordStrength() {
  const inputs = document.querySelectorAll('[data-password-strength]');
  inputs.forEach(input => {
    const targetId = input.dataset.passwordStrength;
    const bar = document.getElementById(targetId + '_bar');
    const label = document.getElementById(targetId + '_label');
    if (!bar) return;
    input.addEventListener('input', () => {
      const val = input.value;
      const strength = getPasswordStrength(val);
      const fill = bar.querySelector('.strength-fill');
      if (!fill) return;
      fill.className = 'strength-fill';
      if (val.length === 0) { fill.style.width = '0'; if (label) label.textContent = ''; return; }
      fill.classList.add(strength.class);
      if (label) { label.textContent = strength.label; label.style.color = strength.color; }
    });
  });
}

function getPasswordStrength(password) {
  let score = 0;
  if (password.length >= 8) score++;
  if (password.length >= 12) score++;
  if (/[A-Z]/.test(password)) score++;
  if (/[a-z]/.test(password)) score++;
  if (/[0-9]/.test(password)) score++;
  if (/[^A-Za-z0-9]/.test(password)) score++;
  if (score <= 2) return { class: 'weak', label: 'Weak', color: '#ff4444' };
  if (score <= 3) return { class: 'fair', label: 'Fair', color: '#ffaa00' };
  if (score <= 4) return { class: 'good', label: 'Good', color: '#0099ff' };
  return { class: 'strong', label: 'Strong', color: '#00cc66' };
}

function initDbTest() {
  const btn = document.getElementById('testDbBtn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const form = document.getElementById('dbForm');
    if (!form) return;
    const data = new FormData(form);
    const resultEl = document.getElementById('dbTestResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-accent" style="width:16px;height:16px;border-width:2px;vertical-align:middle;"></span> Testing...';
    try {
      const resp = await fetch('ajax/test_db.php', { method: 'POST', body: data });
      const json = await resp.json();
      if (resultEl) {
        if (json.success) { resultEl.className = 'alert-dark-success mt-3'; resultEl.innerHTML = '&#10003; ' + (json.message || 'Connection successful!'); }
        else { resultEl.className = 'alert-dark-danger mt-3'; resultEl.innerHTML = '&#10007; ' + (json.message || 'Connection failed.'); }
        resultEl.style.display = 'block';
      }
    } catch (e) {
      if (resultEl) { resultEl.className = 'alert-dark-danger mt-3'; resultEl.textContent = 'Request failed. Please try again.'; resultEl.style.display = 'block'; }
    }
    btn.disabled = false;
    btn.innerHTML = 'Test Connection';
  });
}

function initCountrySearch() {
  const searchInput = document.getElementById('countrySearch');
  if (!searchInput) return;
  searchInput.addEventListener('input', () => {
    const query = searchInput.value.toLowerCase().trim();
    document.querySelectorAll('.country-row').forEach(row => {
      const name = (row.dataset.country || '').toLowerCase();
      const code = (row.dataset.code || '').toLowerCase();
      row.style.display = (!query || name.includes(query) || code.includes(query)) ? '' : 'none';
    });
  });
}

function initTableSearch() {
  document.querySelectorAll('[data-table-search]').forEach(input => {
    const targetId = input.dataset.tableSearch;
    const tbody = document.querySelector('#' + targetId + ' tbody');
    if (!tbody) return;
    input.addEventListener('input', () => {
      const query = input.value.toLowerCase().trim();
      tbody.querySelectorAll('tr').forEach(row => {
        row.style.display = (!query || row.textContent.toLowerCase().includes(query)) ? '' : 'none';
      });
    });
  });
}

function initConfirmDialogs() {
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', (e) => {
      if (!confirm(el.dataset.confirm || 'Are you sure?')) { e.preventDefault(); e.stopPropagation(); }
    });
  });
}

async function ajaxPost(url, data) {
  const resp = await fetch(url, {
    method: 'POST',
    body: data instanceof FormData ? data : JSON.stringify(data),
    headers: data instanceof FormData ? {} : { 'Content-Type': 'application/json' }
  });
  return resp.json();
}

function showToast(message, type = 'success') {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  const colors = { success: '#00cc66', danger: '#ff4444', warning: '#ffaa00', info: '#0099ff' };
  toast.style.cssText = `background:#111;border:1px solid ${colors[type]||colors.info};color:${colors[type]||colors.info};padding:12px 20px;border-radius:8px;font-size:0.875rem;font-weight:500;max-width:320px;box-shadow:0 4px 20px rgba(0,0,0,0.5);animation:fadeInUp 0.3s ease;`;
  toast.textContent = message;
  container.appendChild(toast);
  setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.3s ease'; setTimeout(() => toast.remove(), 300); }, 3500);
}

function initSecuritySettings() {
  const form = document.getElementById('securitySettingsForm');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type="submit"]');
    btn.disabled = true; btn.textContent = 'Saving...';
    try {
      const data = new FormData(form);
      data.append('action', 'save_security_settings');
      const json = await ajaxPost('ajax/security_actions.php', data);
      showToast(json.message || 'Settings saved.', json.success ? 'success' : 'danger');
    } catch (e) { showToast('Failed to save settings.', 'danger'); }
    btn.disabled = false; btn.textContent = 'Save Settings';
  });
}

function initCountryRules() {
  document.querySelectorAll('.country-status-select').forEach(select => {
    select.addEventListener('change', async () => {
      const code = select.dataset.code;
      const status = select.value;
      const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
      try {
        const data = new FormData();
        data.append('action', 'save_country_rule');
        data.append('country_code', code);
        data.append('status', status);
        data.append('csrf_token', csrf);
        const json = await ajaxPost('ajax/security_actions.php', data);
        if (json.success) { const row = select.closest('tr'); if (row) { row.style.background = 'rgba(0,204,102,0.05)'; setTimeout(() => row.style.background = '', 1000); } }
        else showToast(json.message || 'Failed to save.', 'danger');
      } catch (e) { showToast('Request failed.', 'danger'); }
    });
  });
}

function initIpActions() {
  document.querySelectorAll('[data-ip-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.ipAction;
      const ip = btn.dataset.ip;
      const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
      if (!confirm(`Confirm ${action} for IP: ${ip}?`)) return;
      btn.disabled = true;
      try {
        const data = new FormData();
        data.append('action', action); data.append('ip', ip); data.append('csrf_token', csrf);
        const json = await ajaxPost('ajax/security_actions.php', data);
        showToast(json.message || 'Done.', json.success ? 'success' : 'danger');
        if (json.success) setTimeout(() => location.reload(), 1000);
      } catch (e) { showToast('Request failed.', 'danger'); }
      btn.disabled = false;
    });
  });
}

function initAccountUnlock() {
  document.querySelectorAll('[data-unlock-user]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const username = btn.dataset.unlockUser;
      const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
      if (!confirm(`Unlock account for: ${username}?`)) return;
      btn.disabled = true;
      try {
        const data = new FormData();
        data.append('action', 'unlock_account'); data.append('username', username); data.append('csrf_token', csrf);
        const json = await ajaxPost('ajax/security_actions.php', data);
        showToast(json.message || 'Done.', json.success ? 'success' : 'danger');
        if (json.success) setTimeout(() => location.reload(), 1000);
      } catch (e) { showToast('Request failed.', 'danger'); }
      btn.disabled = false;
    });
  });
}

function initMobileSidebar() {
  const toggle = document.getElementById('sidebarToggle');
  const sidebar = document.querySelector('.admin-sidebar');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  document.addEventListener('click', (e) => { if (!sidebar.contains(e.target) && !toggle.contains(e.target)) sidebar.classList.remove('open'); });
}

document.addEventListener('DOMContentLoaded', () => {
  initPasswordStrength();
  initDbTest();
  initCountrySearch();
  initTableSearch();
  initConfirmDialogs();
  initSecuritySettings();
  initCountryRules();
  initIpActions();
  initAccountUnlock();
  initMobileSidebar();
});
