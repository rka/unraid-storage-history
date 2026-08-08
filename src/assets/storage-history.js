(function () {
  'use strict';
  const endpoint = '/plugins/storage-history/include/api.php';
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  function bytes(value) { let i = 0; value = Number(value || 0); while (value >= 1000 && i < units.length - 1) { value /= 1000; i++; } return value.toFixed(value >= 100 || i === 0 ? 0 : 2) + ' ' + units[i]; }
  function growth(value) { if (!Number.isFinite(value)) return '—'; return (value >= 0 ? '+' : '−') + bytes(Math.abs(value)) + '/day'; }
  function escape(v) { return String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function graph(samples) {
    if (samples.length < 2) return '<div class="sh-empty">History will appear after two samples.</div>';
    const width = 720, height = 190, pad = 22;
    const minX = samples[0].timestamp, maxX = samples[samples.length - 1].timestamp;
    const maxY = Math.max(...samples.map(s => Number(s.total || s.used)), 1);
    const coords = samples.map(s => [pad + (s.timestamp - minX) / Math.max(1, maxX - minX) * (width - 2 * pad), height - pad - Number(s.used) / maxY * (height - 2 * pad)]);
    const points = coords.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
    const area = pad+','+(height-pad)+' '+points+' '+(width-pad)+','+(height-pad);
    const title = escape(new Date(samples[samples.length - 1].timestamp * 1000).toLocaleString());
    return '<svg class="sh-graph" viewBox="0 0 '+width+' '+height+'" role="img" aria-label="Storage used over time"><title>'+title+'</title><defs><linearGradient id="sh-fill" x1="0" x2="0" y1="0" y2="1"><stop stop-color="#22a7f0" stop-opacity=".34"/><stop offset="1" stop-color="#22a7f0" stop-opacity=".02"/></linearGradient></defs><line x1="'+pad+'" y1="'+(height*.34)+'" x2="'+(width-pad)+'" y2="'+(height*.34)+'" class="sh-grid"/><line x1="'+pad+'" y1="'+(height*.66)+'" x2="'+(width-pad)+'" y2="'+(height*.66)+'" class="sh-grid"/><line x1="'+pad+'" y1="'+(height-pad)+'" x2="'+(width-pad)+'" y2="'+(height-pad)+'" class="sh-axis"/><polygon points="'+area+'" class="sh-area"/><polyline points="'+points+'" class="sh-line"/><circle cx="'+coords[coords.length-1][0].toFixed(1)+'" cy="'+coords[coords.length-1][1].toFixed(1)+'" r="4" class="sh-dot"/><text x="'+pad+'" y="14" class="sh-label">'+bytes(maxY)+'</text><text x="'+pad+'" y="'+(height-3)+'" class="sh-label">'+new Date(minX*1000).toLocaleDateString()+'</text><text x="'+(width-pad)+'" y="'+(height-3)+'" text-anchor="end" class="sh-label">Now</text></svg>';
  }
  function spark(values, tone) { const max = Math.max(...values, 1), w = 80, h = 22; return '<svg class="sh-spark '+tone+'" viewBox="0 0 '+w+' '+h+'"><polyline points="'+values.map((v,i) => (i/(values.length-1||1)*w).toFixed(1)+','+(h-2-v/max*(h-4)).toFixed(1)).join(' ')+'"/></svg>'; }
  function render(root, payload, compact) {
    const current = payload.current;
    if (!current) { root.innerHTML = '<div class="sh-empty">Storage capacity is unavailable while the array or pools are stopped.</div>'; return; }
    const io = payload.io || {}; const history = root._shIo || {read:[], write:[]}; history.read.push(Number(io.read_per_second || 0)); history.write.push(Number(io.write_per_second || 0)); history.read = history.read.slice(-24); history.write = history.write.slice(-24); root._shIo = history;
    root.innerHTML = (compact ? '' : '<div class="sh-ranges">'+['24h','7d','30d','90d','1y','all'].map(r => '<button data-range="'+r+'" class="'+(r===payload.range?'active':'')+'">'+r+'</button>').join('')+'</div>')+
      graph(payload.samples) + '<div class="sh-stats"><div><span>Used</span><strong>'+bytes(current.used)+'</strong></div><div><span>Free</span><strong>'+bytes(current.free)+'</strong></div><div><span>Total</span><strong>'+bytes(current.total)+'</strong></div><div><span>Growth</span><strong>'+growth(payload.growth_per_day)+'</strong></div></div><div class="sh-live"><span class="sh-live-title">Live disk I/O</span><span>↓ '+bytes(io.read_per_second)+'/s '+spark(history.read,'read')+'</span><span>↑ '+bytes(io.write_per_second)+'/s '+spark(history.write,'write')+'</span></div>';
    root.querySelectorAll('[data-range]').forEach(button => button.addEventListener('click', () => load(root, button.dataset.range, compact)));
  }
  function load(root, range, compact) { root.innerHTML = '<div class="sh-empty">Loading…</div>'; fetch(endpoint + '?range=' + encodeURIComponent(range), {cache:'no-store'}).then(r => r.ok ? r.json() : Promise.reject()).then(p => render(root, p, compact)).catch(() => root.innerHTML = '<div class="sh-empty">Unable to load storage history.</div>'); }
  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-storage-history]').forEach(root => { const range = root.dataset.range || '30d', compact = root.dataset.compact === 'yes'; load(root, range, compact); if (compact) setInterval(() => load(root, range, compact), 5000); }));
}());
