(function() {
  var btn = document.getElementById('adminThemeToggle');
  if (!btn) return;
  var csrf = document.querySelector('meta[name="csrf"]')?.content || '';

  function current() { return document.documentElement.dataset.theme || 'dark'; }
  function setIcon() { btn.textContent = current() === 'dark' ? '☀️' : '🌙'; }
  setIcon();

  btn.addEventListener('click', function() {
    var next = current() === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('clipaza_theme', next);
    setIcon();

    // Sync to database
    var fd = new FormData();
    fd.append('action', 'update_default_theme');
    fd.append('default_theme', next);
    fd.append('csrf_token', csrf);

    fetch('ajax/settings_actions.php', {
      method: 'POST',
      body: fd
    }).catch(function(err) { console.error('Theme sync failed', err); });
  });
})();
