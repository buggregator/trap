// The viewer of a binary event on its page in the Trap web UI. It runs in a sandboxed frame without an origin:
// it reads only the bytes the page carries, and links to the download of all of them.
(function () {
  'use strict';

  var ROW = 16;
  var PAGE = 256 * ROW;
  var MAX_MATCHES = 10000;
  var MAX_STRINGS = 2000;
  // Bytes the Text tab decodes: a megabyte of control symbols, each an element, would stall the tab
  var MAX_TEXT = 256 * 1024;

  var meta = JSON.parse(document.getElementById('bin-meta').textContent);
  var raw = atob(document.getElementById('bin-data').getAttribute('data-bytes'));
  var data = new Uint8Array(raw.length);
  for (var r = 0; r < raw.length; r++) data[r] = raw.charCodeAt(r);
  raw = null;

  var pages = Math.max(1, Math.ceil(data.length / PAGE));
  var state = {page: 0, sel: -1, matches: [], match: -1, needle: 0, tab: 'hex'};

  var esc = function (s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  };
  var hex2 = function (b) { return (b < 16 ? '0' : '') + b.toString(16); };
  var off = function (n) { return ('0000000' + n.toString(16)).slice(-8); };
  var num = function (n) { return n.toLocaleString('en-US'); };
  var size = function (n) {
    var units = ['bytes', 'KiB', 'MiB', 'GiB'];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(2)) + ' ' + units[i];
  };
  var cls = function (b) { return b === 0 ? 'z' : b < 32 || b === 127 ? 'c' : b > 127 ? 'h' : ''; };
  var printable = function (b) { return b >= 32 && b < 127; };

  // Layout

  var root = document.createElement('div');
  root.className = 'v';
  root.innerHTML =
    '<h1>' + esc(meta.title) + '</h1>' +
    '<div class="facts">' + num(meta.size) + ' bytes' +
    (data.length < meta.size ? ' · this page has the first ' + size(data.length) : '') +
    ' · <a href="' + esc(meta.download) + '" target="_blank" rel="noopener">Download all bytes</a></div>' +
    (meta.format ? '<div class="hint"><b>' + esc(meta.format.name) + '</b>' +
      (meta.format.hint ? ': ' + esc(meta.format.hint) : '') + '</div>' : '') +
    '<nav role="tablist">' +
    ['hex:Hex', 'text:Text', 'strings:Strings', 'stats:Statistics'].map(function (t) {
      var p = t.split(':');
      return '<button role="tab" data-tab="' + p[0] + '">' + p[1] + '</button>';
    }).join('') +
    '</nav>' +
    '<section data-panel="hex">' +
    '<div class="bar">' +
    '<span><button data-go="first" title="First page">«</button><button data-go="prev" title="Previous page">‹</button>' +
    '<span class="note" id="pageinfo"></span>' +
    '<button data-go="next" title="Next page">›</button><button data-go="last" title="Last page">»</button></span>' +
    '<span><label for="offset">Offset</label><input type="text" id="offset" placeholder="0x1f0 or 496">' +
    '<button data-go="offset">Go</button></span>' +
    '<span><label for="find">Find</label><input type="text" id="find" placeholder="text or bytes">' +
    '<select id="findmode"><option value="text">Text</option><option value="hex">Hex</option></select>' +
    '<button data-go="find-prev" title="Previous match">‹</button><button data-go="find-next" title="Next match">›</button>' +
    '<span class="note" id="findinfo"></span></span>' +
    '</div>' +
    '<pre class="dump" id="dump"></pre>' +
    '<p class="note">Click a byte to read the numbers that start at it.</p>' +
    '</section>' +
    '<section data-panel="text" hidden>' +
    '<div class="bar"><span><label for="enc">Encoding</label><select id="enc">' +
    '<option value="utf-8">UTF-8</option><option value="windows-1252">Latin-1 (windows-1252)</option></select></span>' +
    '<span><input type="checkbox" id="ctl" checked><label for="ctl">Show control characters</label></span></div>' +
    '<p class="note" id="textinfo"></p><pre class="text" id="text"></pre>' +
    '</section>' +
    '<section data-panel="strings" hidden>' +
    '<div class="bar"><span><label for="minlen">Shortest</label><input type="number" id="minlen" min="2" max="64" value="4">' +
    '<span>printable ASCII characters</span></span><span class="note" id="strinfo"></span></div>' +
    '<table class="strings mono" id="strings"></table>' +
    '</section>' +
    '<section data-panel="stats" hidden><div id="stats"></div></section>';

  var fallback = document.getElementById('fallback');
  fallback && fallback.remove();
  document.body.appendChild(root);

  var $ = function (id) { return document.getElementById(id); };

  // Tabs

  var rendered = {};
  var showTab = function (tab) {
    state.tab = tab;
    root.querySelectorAll('nav button').forEach(function (b) {
      b.setAttribute('aria-selected', b.getAttribute('data-tab') === tab ? 'true' : 'false');
    });
    root.querySelectorAll('section').forEach(function (s) {
      s.hidden = s.getAttribute('data-panel') !== tab;
    });
    if (!rendered[tab]) {
      rendered[tab] = true;
      ({hex: renderHex, text: renderText, strings: renderStrings, stats: renderStats})[tab]();
    }
  };
  root.querySelector('nav').addEventListener('click', function (e) {
    var tab = e.target.getAttribute && e.target.getAttribute('data-tab');
    tab && showTab(tab);
  });

  // Hex

  var isMatch = function (i) {
    // The matches are sorted by offset: a binary search for the last one starting at or before i
    var lo = 0, hi = state.matches.length - 1, found = -1;
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      if (state.matches[mid] <= i) { found = mid; lo = mid + 1; } else { hi = mid - 1; }
    }
    return found >= 0 && i < state.matches[found] + state.needle;
  };

  var inspect = function (i) {
    var view = new DataView(data.buffer);
    var left = data.length - i;
    var parts = ['offset 0x' + i.toString(16) + ' (' + i + ')', 'u8 ' + data[i], 'i8 ' + view.getInt8(i)];
    if (left >= 2) parts.push('u16 LE ' + view.getUint16(i, true), 'u16 BE ' + view.getUint16(i));
    if (left >= 4) {
      parts.push('u32 LE ' + view.getUint32(i, true), 'u32 BE ' + view.getUint32(i),
        'i32 LE ' + view.getInt32(i, true), 'i32 BE ' + view.getInt32(i),
        'f32 LE ' + view.getFloat32(i, true).toPrecision(7));
    }
    if (left >= 8) {
      parts.push('u64 LE ' + view.getBigUint64(i, true), 'f64 LE ' + view.getFloat64(i, true).toPrecision(15));
    }
    var ch = Array.from(new TextDecoder().decode(data.subarray(i, Math.min(i + 4, data.length))))[0];
    ch && ch !== '�' && parts.push('UTF-8 ' + JSON.stringify(ch));

    return parts.join('   ');
  };

  var renderHex = function () {
    var start = state.page * PAGE;
    var end = Math.min(start + PAGE, data.length);
    var out = [];
    for (var row = start; row < end; row += ROW) {
      var hex = '', ascii = '';
      for (var j = 0; j < ROW; j++) {
        var i = row + j;
        j === 8 && (hex += ' ');
        if (i >= end) { hex += '   '; continue; }
        var b = data[i];
        var c = cls(b) + (i === state.sel ? ' s' : '') + (state.needle && isMatch(i) ? ' m' : '');
        var attr = ' data-i="' + i + '"' + (c.trim() ? ' class="' + c.trim() + '"' : '');
        hex += '<span' + attr + '>' + hex2(b) + '</span> ';
        ascii += '<span' + attr + '>' + (printable(b) ? esc(String.fromCharCode(b)) : '.') + '</span>';
      }
      out.push('<span class="o">' + off(row) + '</span>  ' + hex + ' ' + ascii);
      if (state.sel >= row && state.sel < row + ROW) {
        out.push('<span class="insp">' + esc(inspect(state.sel)) + '</span>');
      }
    }
    $('dump').innerHTML = data.length ? out.join('\n') : 'No bytes.';
    $('pageinfo').textContent = 'page ' + (state.page + 1) + ' of ' + pages;
    root.querySelector('[data-go=first]').disabled = root.querySelector('[data-go=prev]').disabled = state.page === 0;
    root.querySelector('[data-go=last]').disabled = root.querySelector('[data-go=next]').disabled = state.page >= pages - 1;
  };

  var select = function (i) {
    state.sel = i;
    state.page = Math.floor(i / PAGE);
    renderHex();
    var el = $('dump').querySelector('[data-i="' + i + '"]');
    el && el.scrollIntoView && el.scrollIntoView({block: 'center'});
  };

  var parseOffset = function (s) {
    s = s.trim().toLowerCase().replace(/_/g, '');
    if (/^0x[0-9a-f]+$/.test(s)) return parseInt(s.slice(2), 16);
    if (/^[0-9a-f]+h$/.test(s)) return parseInt(s.slice(0, -1), 16);
    if (/^\d+$/.test(s)) return parseInt(s, 10);
    if (/^[0-9a-f]+$/.test(s)) return parseInt(s, 16);
    return NaN;
  };

  var needleBytes = function () {
    var q = $('find').value;
    if ($('findmode').value === 'text') return new TextEncoder().encode(q);
    var digits = q.replace(/0x/gi, '').replace(/[\s,:-]/g, '');
    if (!/^([0-9a-f]{2})*$/i.test(digits)) return null;
    var bytes = new Uint8Array(digits.length / 2);
    for (var k = 0; k < bytes.length; k++) bytes[k] = parseInt(digits.substr(k * 2, 2), 16);
    return bytes;
  };

  var search = function () {
    var needle = needleBytes();
    state.matches = [];
    state.match = -1;
    state.needle = 0;
    if (needle === null) { $('findinfo').textContent = 'hex digits in pairs, as 16 03 01'; renderHex(); return false; }
    if (!needle.length) { $('findinfo').textContent = ''; renderHex(); return false; }
    var first = needle[0];
    outer:
    for (var i = data.indexOf(first); i !== -1 && i <= data.length - needle.length; i = data.indexOf(first, i + 1)) {
      for (var k = 1; k < needle.length; k++) {
        if (data[i + k] !== needle[k]) continue outer;
      }
      state.matches.push(i);
      if (state.matches.length >= MAX_MATCHES) break;
    }
    state.needle = needle.length;
    state.lastQuery = $('find').value + '\u0000' + $('findmode').value;
    return true;
  };

  var step = function (dir) {
    var query = $('find').value + '\u0000' + $('findmode').value;
    if (query !== state.lastQuery && !search()) return;
    var n = state.matches.length;
    if (!n) { $('findinfo').textContent = 'not found'; renderHex(); return; }
    if (state.match === -1) {
      // From the selection, else from the page in view
      var from = state.sel >= 0 ? state.sel : state.page * PAGE;
      state.match = 0;
      for (var m = 0; m < n; m++) {
        if (dir > 0 ? state.matches[m] >= from : state.matches[m] <= from) { state.match = m; if (dir > 0) break; }
      }
    } else {
      state.match = (state.match + dir + n) % n;
    }
    $('findinfo').textContent = (state.match + 1) + ' of ' + (n >= MAX_MATCHES ? num(n) + '+' : num(n));
    select(state.matches[state.match]);
  };

  root.querySelector('[data-panel=hex]').addEventListener('click', function (e) {
    var t = e.target;
    var i = t.getAttribute && t.getAttribute('data-i');
    if (i !== null && i !== undefined) {
      i = +i;
      state.sel = state.sel === i ? -1 : i;
      renderHex();
      return;
    }
    switch (t.getAttribute && t.getAttribute('data-go')) {
      case 'first': state.page = 0; renderHex(); break;
      case 'prev': state.page = Math.max(0, state.page - 1); renderHex(); break;
      case 'next': state.page = Math.min(pages - 1, state.page + 1); renderHex(); break;
      case 'last': state.page = pages - 1; renderHex(); break;
      case 'offset': goOffset(); break;
      case 'find-next': step(1); break;
      case 'find-prev': step(-1); break;
    }
  });

  var goOffset = function () {
    var at = parseOffset($('offset').value);
    if (isNaN(at)) { $('offset').setCustomValidity('0x1f0, 1f0h or 496'); $('offset').reportValidity(); return; }
    if (at >= data.length) {
      $('offset').setCustomValidity(data.length < meta.size
        ? 'Past the bytes this page has: download all of them'
        : 'Past the end: the last byte is at 0x' + Math.max(0, data.length - 1).toString(16));
      $('offset').reportValidity();
      return;
    }
    $('offset').setCustomValidity('');
    select(at);
  };
  $('offset').addEventListener('input', function () { $('offset').setCustomValidity(''); });
  $('offset').addEventListener('keydown', function (e) { e.key === 'Enter' && goOffset(); });
  $('find').addEventListener('keydown', function (e) { e.key === 'Enter' && step(e.shiftKey ? -1 : 1); });
  $('findmode').addEventListener('change', function () { state.lastQuery = null; $('findinfo').textContent = ''; });

  // Text

  var renderText = function () {
    var text = new TextDecoder($('enc').value).decode(data.subarray(0, MAX_TEXT));
    $('textinfo').textContent = data.length > MAX_TEXT ? 'The first ' + size(MAX_TEXT) + ' of ' + size(data.length) + '.' : '';
    if (!$('ctl').checked) { $('text').textContent = text; return; }
    // Controls as the symbols of their Unicode block, line feeds and tabs kept for the layout
    var html = esc(text).replace(/[\u0000-\u0008\u000b-\u001f\u007f]/g, function (c) {
      var code = c.charCodeAt(0);
      return '<span class="ctl">' + String.fromCharCode(code === 127 ? 0x2421 : 0x2400 + code) + '</span>';
    });
    $('text').innerHTML = html;
  };
  $('enc').addEventListener('change', renderText);
  $('ctl').addEventListener('change', renderText);

  // Strings

  var renderStrings = function () {
    var min = Math.max(2, Math.min(64, parseInt($('minlen').value, 10) || 4));
    var rows = [];
    var total = 0;
    var start = -1;
    for (var i = 0; i <= data.length; i++) {
      var b = i < data.length ? data[i] : 0;
      if (printable(b) || b === 9) { start === -1 && (start = i); continue; }
      if (start !== -1 && i - start >= min) {
        total++;
        if (rows.length < MAX_STRINGS) {
          rows.push('<tr data-at="' + start + '"><td class="o">' + off(start) + '</td><td>' +
            esc(String.fromCharCode.apply(null, data.subarray(start, Math.min(i, start + 500)))) +
            (i - start > 500 ? '…' : '') + '</td></tr>');
        }
      }
      start = -1;
    }
    $('strings').innerHTML = rows.join('');
    $('strinfo').textContent = total ? num(total) + ' found' + (total > rows.length ? ', the first ' + num(rows.length) + ' shown' : '') +
      ' · click one to see it in Hex' : 'none found';
  };
  $('minlen').addEventListener('change', renderStrings);
  $('strings').addEventListener('click', function (e) {
    var tr = e.target.closest && e.target.closest('tr');
    if (!tr) return;
    showTab('hex');
    select(+tr.getAttribute('data-at'));
  });

  // Statistics

  var entropy = function (from, to) {
    var counts = new Uint32Array(256);
    for (var i = from; i < to; i++) counts[data[i]]++;
    var e = 0, n = to - from;
    for (var k = 0; k < 256; k++) {
      if (counts[k]) { var p = counts[k] / n; e -= p * Math.log2(p); }
    }
    return e;
  };

  var renderStats = function () {
    var counts = new Uint32Array(256);
    for (var i = 0; i < data.length; i++) counts[data[i]]++;
    var n = data.length || 1;
    var e = entropy(0, data.length);
    var verdict = e > 7.5 ? 'compressed or encrypted' : e > 6 ? 'binary structures, or text with binary' :
      e > 3 ? 'text, or binary with much repetition' : 'highly repetitive';
    var classes = {z: 0, c: 0, '': 0, h: 0};
    for (var b = 0; b < 256; b++) classes[cls(b)] += counts[b];
    var pct = function (c) { return num(c) + ' (' + (100 * c / n).toFixed(1) + '%)'; };
    var top = Array.prototype.slice.call(counts).map(function (c, v) { return [v, c]; })
      .filter(function (x) { return x[1]; }).sort(function (x, y) { return y[1] - x[1]; }).slice(0, 8);

    $('stats').innerHTML =
      '<p class="note">Of ' + (data.length < meta.size ? 'the first ' : 'all ') + num(data.length) + ' bytes.</p>' +
      '<table class="kv"><tr><td>Entropy</td><td>' + e.toFixed(2) + ' bits per byte: ' + verdict + '</td></tr>' +
      '<tr><td><span class="z">■</span> NUL</td><td>' + pct(classes.z) + '</td></tr>' +
      '<tr><td><span class="c">■</span> Controls and whitespace</td><td>' + pct(classes.c) + '</td></tr>' +
      '<tr><td>■ Printable ASCII</td><td>' + pct(classes['']) + '</td></tr>' +
      '<tr><td><span class="h">■</span> Over 0x7f</td><td>' + pct(classes.h) + '</td></tr>' +
      '<tr><td>Most frequent</td><td class="mono">' + top.map(function (x) {
        return '0x' + hex2(x[0]) + (printable(x[0]) ? ' ' + esc(JSON.stringify(String.fromCharCode(x[0]))) : '') + ' ×' + num(x[1]);
      }).join(', ') + '</td></tr></table>' +
      '<h2>Byte values, 0x00 to 0xff</h2><canvas id="hist" width="768" height="120"></canvas>' +
      '<h2>Entropy along the data</h2><p class="note">A tall bar is a compressed or encrypted part, a low one text or padding.</p>' +
      '<canvas id="strip" width="768" height="60"></canvas>';

    var css = getComputedStyle(document.documentElement);
    var color = function (name) { return css.getPropertyValue(name).trim(); };
    var colors = {z: color('--zero'), c: color('--ctl'), '': color('--fg'), h: color('--high')};

    var hist = $('hist').getContext('2d');
    var max = Math.max.apply(null, counts) || 1;
    for (var v = 0; v < 256; v++) {
      if (!counts[v]) continue;
      // Square root: a few frequent bytes would flatten the others
      var h = Math.max(1, Math.round(118 * Math.sqrt(counts[v] / max)));
      hist.fillStyle = colors[cls(v)];
      hist.fillRect(v * 3, 120 - h, 2, h);
    }

    var strip = $('strip').getContext('2d');
    // Blocks of 256 bytes at least: a shorter one can't reach 8 bits
    var blocks = Math.min(256, Math.max(1, Math.floor(data.length / 256)));
    var width = 768 / blocks;
    strip.fillStyle = color('--accent');
    for (var k = 0; k < blocks; k++) {
      var from = Math.floor(k * data.length / blocks);
      var to = Math.floor((k + 1) * data.length / blocks);
      var bh = Math.round(58 * entropy(from, to) / 8);
      strip.fillRect(k * width, 60 - bh, Math.max(1, width - 1), bh);
    }
  };

  showTab('hex');
})();
