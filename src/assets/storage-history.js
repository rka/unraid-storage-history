(function () {
  'use strict';

  const endpoint = '/plugins/storage-history/include/api.php';
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  const ranges = ['24h', '7d', '30d', '1y'];

  function bytes(value) {
    let i = 0;
    value = Number(value || 0);
    while (value >= 1000 && i < units.length - 1) { value /= 1000; i++; }
    return value.toFixed(value >= 100 || i === 0 ? 0 : 2) + ' ' + units[i];
  }

  function signedBytes(value) {
    if (!Number.isFinite(value)) return 'Building history';
    if (Math.abs(value) < 1) return 'No net growth';
    return (value > 0 ? '+' : '−') + bytes(Math.abs(value)) + '/day';
  }

  function escape(value) {
    return String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  }

  function rangeButtons(active) {
    return '<div class="sh-ranges" role="group" aria-label="History range">' + ranges.map(range =>
      '<button type="button" data-range="' + range + '" class="' + (range === active ? 'active' : '') + '" aria-pressed="' + (range === active) + '">' + range + '</button>'
    ).join('') + '</div>';
  }

  function historyStatus(history) {
    const status = history && history.status ? history.status : 'collecting';
    const labels = {ready:'Current', collecting:'Collecting', stale:'Stale', paused:'Paused'};
    const last = history && history.last_sample_at ? 'Last sample ' + new Date(history.last_sample_at * 1000).toLocaleString() : 'Waiting for the first scheduled samples';
    return '<span class="sh-status sh-status-' + status + '" title="' + escape(last) + '"><i></i>' + (labels[status] || 'Collecting') + '</span>';
  }

  function growthContext(payload, current) {
    const daily = Number(payload.growth_per_day);
    const span = Number(payload.history && payload.history.range_span_seconds || 0);
    let text = signedBytes(daily);
    if (Number.isFinite(daily) && daily > 0 && span >= 7 * 86400 && Number(current.free) > 0) {
      const days = Number(current.free) / daily;
      const remaining = days < 730 ? Math.max(1, Math.round(days)) + ' days remaining' : (days / 365).toFixed(days < 3650 ? 1 : 0) + ' years remaining';
      text += ' · ~' + remaining;
    }
    return text;
  }

  function graph(samples) {
    if (samples.length < 2) return '<div class="sh-empty sh-empty-chart">No history in this range yet. Try a longer range.</div>';

    const width = 720, height = 170, left = 22, right = 12, top = 18, bottom = 22;
    const used = samples.map(sample => Number(sample.used || 0));
    const total = Math.max(...samples.map(sample => Number(sample.total || 0)), ...used, 1);
    const observedMin = Math.min(...used), observedMax = Math.max(...used);
    const desiredSpan = Math.max((observedMax - observedMin) * 1.45, total * 0.005, 1000000000);
    let minY = Math.max(0, observedMin - desiredSpan * 0.22);
    let maxY = Math.min(total, Math.max(observedMax + desiredSpan * 0.22, minY + desiredSpan));
    if (maxY - minY < desiredSpan) minY = Math.max(0, maxY - desiredSpan);
    const ySpan = Math.max(1, maxY - minY);
    const minX = Number(samples[0].timestamp), maxX = Number(samples[samples.length - 1].timestamp);
    const plotWidth = width - left - right, plotHeight = height - top - bottom;
    const coords = samples.map(sample => [
      left + (Number(sample.timestamp) - minX) / Math.max(1, maxX - minX) * plotWidth,
      top + (maxY - Number(sample.used || 0)) / ySpan * plotHeight
    ]);
    const points = coords.map(point => point[0].toFixed(1) + ',' + point[1].toFixed(1)).join(' ');
    const area = left + ',' + (height - bottom) + ' ' + points + ' ' + (width - right) + ',' + (height - bottom);
    const summary = bytes(used[used.length - 1]) + ' used on ' + new Date(maxX * 1000).toLocaleString();

    return '<div class="sh-graph-wrap"><svg class="sh-graph" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="Storage used over time"><title>' + escape(summary) + '</title>' +
      '<defs><linearGradient id="sh-fill" x1="0" x2="0" y1="0" y2="1"><stop stop-color="currentColor" stop-opacity=".28"/><stop offset="1" stop-color="currentColor" stop-opacity=".025"/></linearGradient></defs>' +
      '<line x1="' + left + '" y1="' + top + '" x2="' + (width - right) + '" y2="' + top + '" class="sh-grid"/><line x1="' + left + '" y1="' + (top + plotHeight / 2) + '" x2="' + (width - right) + '" y2="' + (top + plotHeight / 2) + '" class="sh-grid"/><line x1="' + left + '" y1="' + (height - bottom) + '" x2="' + (width - right) + '" y2="' + (height - bottom) + '" class="sh-axis"/>' +
      '<polygon points="' + area + '" class="sh-area"/><polyline points="' + points + '" class="sh-line"/>' +
      '<g class="sh-crosshair"><line y1="' + top + '" y2="' + (height - bottom) + '"/><circle r="4"/></g>' +
      '<circle cx="' + coords[coords.length - 1][0].toFixed(1) + '" cy="' + coords[coords.length - 1][1].toFixed(1) + '" r="3.5" class="sh-dot"/>' +
      '<text x="' + left + '" y="12" class="sh-label">' + bytes(maxY) + '</text><text x="' + left + '" y="' + (height - 5) + '" class="sh-label">' + new Date(minX * 1000).toLocaleDateString() + '</text><text x="' + (width - right) + '" y="' + (height - 5) + '" text-anchor="end" class="sh-label">Now</text></svg><div class="sh-tooltip" role="status"></div></div>';
  }

  function bindGraph(root, samples) {
    const svg = root.querySelector('.sh-graph');
    const tooltip = root.querySelector('.sh-tooltip');
    const crosshair = root.querySelector('.sh-crosshair');
    if (!svg || !tooltip || !crosshair || samples.length < 2) return;
    const marker = crosshair.querySelector('circle');
    const line = crosshair.querySelector('line');
    const minTime = Number(samples[0].timestamp), maxTime = Number(samples[samples.length - 1].timestamp);
    const viewWidth = 720, left = 22, right = 12, top = 18, bottom = 22, viewHeight = 170;
    const values = samples.map(sample => Number(sample.used || 0));
    const total = Math.max(...samples.map(sample => Number(sample.total || 0)), ...values, 1);
    const observedMin = Math.min(...values), observedMax = Math.max(...values);
    const desiredSpan = Math.max((observedMax - observedMin) * 1.45, total * 0.005, 1000000000);
    let minY = Math.max(0, observedMin - desiredSpan * .22);
    let maxY = Math.min(total, Math.max(observedMax + desiredSpan * .22, minY + desiredSpan));
    if (maxY - minY < desiredSpan) minY = Math.max(0, maxY - desiredSpan);

    function show(event) {
      const bounds = svg.getBoundingClientRect();
      const pointerX = Math.max(0, Math.min(bounds.width, event.clientX - bounds.left));
      const time = minTime + pointerX / Math.max(1, bounds.width) * (maxTime - minTime);
      let low = 0, high = samples.length - 1;
      while (low < high) {
        const middle = Math.floor((low + high) / 2);
        if (Number(samples[middle].timestamp) < time) low = middle + 1; else high = middle;
      }
      let index = low;
      if (index > 0 && Math.abs(Number(samples[index - 1].timestamp) - time) <= Math.abs(Number(samples[index].timestamp) - time)) index--;
      const sample = samples[index], previous = index ? samples[index - 1] : null;
      const x = left + (Number(sample.timestamp) - minTime) / Math.max(1, maxTime - minTime) * (viewWidth - left - right);
      const y = top + (maxY - Number(sample.used || 0)) / Math.max(1, maxY - minY) * (viewHeight - top - bottom);
      line.setAttribute('x1', x); line.setAttribute('x2', x); marker.setAttribute('cx', x); marker.setAttribute('cy', y);
      const change = previous ? Number(sample.used || 0) - Number(previous.used || 0) : null;
      tooltip.innerHTML = '<strong>' + escape(new Date(Number(sample.timestamp) * 1000).toLocaleString()) + '</strong><span>Used ' + bytes(sample.used) + ' · Free ' + bytes(sample.free) + '</span>' + (change === null ? '' : '<span>Change ' + (change >= 0 ? '+' : '−') + bytes(Math.abs(change)) + '</span>');
      crosshair.classList.add('visible'); tooltip.classList.add('visible');
      const tooltipWidth = tooltip.offsetWidth;
      tooltip.style.left = Math.max(6, Math.min(bounds.width - tooltipWidth - 6, x / viewWidth * bounds.width - tooltipWidth / 2)) + 'px';
    }
    svg.addEventListener('pointermove', show);
    svg.addEventListener('pointerleave', () => { crosshair.classList.remove('visible'); tooltip.classList.remove('visible'); });
  }

  function dotGraph(values) {
    const padded = Array(Math.max(0, 32 - values.length)).fill(0).concat(values).slice(-32);
    const glyphs = '⣀⣀⣄⣆⣇⣧⣷⣿';
    const max = Math.max(...padded, 1), idle = Math.max(...padded) < 1;
    return '<span class="sh-dotgraph' + (idle ? ' idle' : '') + '" aria-hidden="true">' + padded.map((value, index) => {
      const level = idle ? 0 : Math.min(glyphs.length - 1, Math.round(value / max * (glyphs.length - 1)));
      return '<i style="opacity:' + (.28 + .72 * index / (padded.length - 1)).toFixed(2) + '">' + glyphs[level] + '</i>';
    }).join('') + '</span>';
  }

  function render(root, payload) {
    const current = payload.current;
    if (!current) { root.innerHTML = '<div class="sh-empty">Storage capacity is unavailable while the array or pools are stopped.</div>'; return; }
    const ioHistory = root._shIo || {read:[], write:[], currentRead:0, currentWrite:0}; root._shIo = ioHistory;
    const percent = current.total ? Math.max(0, Math.min(100, Number(current.used) / Number(current.total) * 100)) : 0;
    const growthText = growthContext(payload, current);
    const growthNeutral = !Number.isFinite(Number(payload.growth_per_day)) || Math.abs(Number(payload.growth_per_day)) < 1;

    root.innerHTML = '<div class="sh-summary"><div class="sh-capacity"><span class="sh-kicker">Capacity</span><strong>' + Math.round(percent) + '%</strong><span>used</span></div><div class="sh-growth' + (growthNeutral ? ' neutral' : '') + '">' + escape(growthText) + '</div></div>' +
      '<div class="sh-utilization" role="progressbar" aria-label="Storage capacity used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(percent) + '"><i style="width:' + percent.toFixed(2) + '%"></i></div>' +
      '<div class="sh-chart-head"><span class="sh-chart-title">Used-space history</span>' + rangeButtons(payload.range) + historyStatus(payload.history) + '</div>' +
      graph(payload.samples || []) +
      '<div class="sh-stats"><div><span>Used</span><strong>' + bytes(current.used) + '</strong></div><div><span>Free</span><strong>' + bytes(current.free) + '</strong></div><div><span>Total</span><strong>' + bytes(current.total) + '</strong></div></div>' +
      '<div class="sh-live"><span class="sh-live-title">Live I/O</span><span class="sh-io sh-io-read"><b class="sh-io-value">↓ ' + bytes(ioHistory.currentRead) + '/s</b>' + dotGraph(ioHistory.read) + '</span><span class="sh-io sh-io-write"><b class="sh-io-value">↑ ' + bytes(ioHistory.currentWrite) + '/s</b>' + dotGraph(ioHistory.write) + '</span></div>';
    root.dataset.loaded = 'yes';
    root.querySelectorAll('[data-range]').forEach(button => button.addEventListener('click', () => { root.dataset.range = button.dataset.range; loadHistory(root, button.dataset.range); }));
    bindGraph(root, payload.samples || []);
  }

  function updateIo(root, io) {
    const read = Number(io.read_per_second || 0), write = Number(io.write_per_second || 0);
    const ioHistory = root._shIo || {read:[], write:[], currentRead:0, currentWrite:0};
    ioHistory.currentRead = read; ioHistory.currentWrite = write;
    ioHistory.read.push(read); ioHistory.write.push(write);
    ioHistory.read = ioHistory.read.slice(-32); ioHistory.write = ioHistory.write.slice(-32); root._shIo = ioHistory;
    const readRoot = root.querySelector('.sh-io-read'), writeRoot = root.querySelector('.sh-io-write');
    if (!readRoot || !writeRoot) return;
    readRoot.querySelector('.sh-io-value').textContent = '↓ ' + bytes(read) + '/s';
    writeRoot.querySelector('.sh-io-value').textContent = '↑ ' + bytes(write) + '/s';
    readRoot.querySelector('.sh-dotgraph').outerHTML = dotGraph(ioHistory.read);
    writeRoot.querySelector('.sh-dotgraph').outerHTML = dotGraph(ioHistory.write);
  }

  function loadHistory(root, range) {
    if (document.hidden) return;
    if (!root.dataset.loaded) root.innerHTML = '<div class="sh-empty">Loading…</div>';
    if (root._shHistoryController) root._shHistoryController.abort();
    const controller = new AbortController(); root._shHistoryController = controller;
    fetch(endpoint + '?mode=history&range=' + encodeURIComponent(range), {cache:'no-store', signal:controller.signal})
      .then(response => response.ok ? response.json() : Promise.reject())
      .then(payload => { render(root, payload); loadIo(root); })
      .catch(error => { if (error.name !== 'AbortError' && !root.dataset.loaded) root.innerHTML = '<div class="sh-empty">Unable to load storage history.</div>'; });
  }

  function loadIo(root) {
    if (document.hidden || !root.dataset.loaded || root._shIoPending) return;
    root._shIoPending = true;
    fetch(endpoint + '?mode=io', {cache:'no-store'})
      .then(response => response.ok ? response.json() : Promise.reject())
      .then(payload => updateIo(root, payload.io || {}))
      .catch(() => {})
      .finally(() => { root._shIoPending = false; });
  }

  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-storage-history]').forEach(root => {
    root.dataset.range = root.dataset.range || '30d';
    loadHistory(root, root.dataset.range);
    if (root.dataset.compact === 'yes') {
      setInterval(() => loadHistory(root, root.dataset.range), 60000);
      setInterval(() => loadIo(root), 5000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { loadHistory(root, root.dataset.range); loadIo(root); }
      });
    }
  }));
}());
