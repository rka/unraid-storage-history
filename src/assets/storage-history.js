(function () {
  'use strict';
  const endpoint = '/plugins/storage-history/include/api.php';
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  function bytes(value) { let i = 0; value = Number(value || 0); while (value >= 1000 && i < units.length - 1) { value /= 1000; i++; } return value.toFixed(value >= 100 || i === 0 ? 0 : 2) + ' ' + units[i]; }
  function growth(value) { if (!Number.isFinite(value)) return '—'; return (value >= 0 ? '+' : '−') + bytes(Math.abs(value)) + '/day'; }
  function escape(v) { return String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function graph(samples) {
    if (samples.length < 2) return '<div class="sh-empty">History will appear after two samples.</div>';
    const width = 720, height = 210, pad = 14;
    const minX = samples[0].timestamp, maxX = samples[samples.length - 1].timestamp;
    const maxY = Math.max(...samples.map(s => Number(s.total || s.used)), 1);
    const points = samples.map(s => ((pad + (s.timestamp - minX) / Math.max(1, maxX - minX) * (width - 2 * pad)).toFixed(1) + ',' + (height - pad - Number(s.used) / maxY * (height - 2 * pad)).toFixed(1))).join(' ');
    const title = escape(new Date(samples[samples.length - 1].timestamp * 1000).toLocaleString());
    return '<svg class="sh-graph" viewBox="0 0 '+width+' '+height+'" role="img" aria-label="Storage used over time"><title>'+title+'</title><line x1="'+pad+'" y1="'+(height-pad)+'" x2="'+(width-pad)+'" y2="'+(height-pad)+'" class="sh-axis"/><polyline points="'+points+'" class="sh-line"/></svg>';
  }
  function render(root, payload, compact) {
    const current = payload.current;
    if (!current) { root.innerHTML = '<div class="sh-empty">Array capacity is unavailable while the array is stopped.</div>'; return; }
    root.innerHTML = (compact ? '' : '<div class="sh-ranges">'+['24h','7d','30d','90d','1y','all'].map(r => '<button data-range="'+r+'" class="'+(r===payload.range?'active':'')+'">'+r+'</button>').join('')+'</div>')+
      graph(payload.samples) + '<div class="sh-stats"><div><span>Used</span><strong>'+bytes(current.used)+'</strong></div><div><span>Free</span><strong>'+bytes(current.free)+'</strong></div><div><span>Total</span><strong>'+bytes(current.total)+'</strong></div><div><span>Growth</span><strong>'+growth(payload.growth_per_day)+'</strong></div></div>';
    root.querySelectorAll('[data-range]').forEach(button => button.addEventListener('click', () => load(root, button.dataset.range, compact)));
  }
  function load(root, range, compact) { root.innerHTML = '<div class="sh-empty">Loading…</div>'; fetch(endpoint + '?range=' + encodeURIComponent(range), {cache:'no-store'}).then(r => r.ok ? r.json() : Promise.reject()).then(p => render(root, p, compact)).catch(() => root.innerHTML = '<div class="sh-empty">Unable to load storage history.</div>'); }
  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-storage-history]').forEach(root => load(root, root.dataset.range || '30d', root.dataset.compact === 'yes')));
}());

