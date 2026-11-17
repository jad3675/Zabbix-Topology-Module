// ---- shared walk helpers (ES5 / Duktape) ----
function tmParse(text) {
    var lines = String(text).split(/\r?\n/), out = [], cur = null, i, m, v;
    var re = /^\.?((?:\d+\.)+\d+)\s*=\s*(?:([A-Za-z0-9\-]+):\s*)?(.*)$/;
    for (i = 0; i < lines.length; i++) {
        m = re.exec(lines[i]);
        if (m) {
            cur = {oid: m[1], type: m[2] || '', val: m[3]};
            out.push(cur);
        }
        else if (cur && lines[i] !== '') {
            cur.val += (cur.type === 'Hex-STRING' ? ' ' : '\n') + lines[i];
        }
    }
    var res = [];
    for (i = 0; i < out.length; i++) {
        v = out[i].val;
        if (/^No (Such|more)/.test(v) || /^No Such/.test(out[i].type)) continue;
        if (v.length >= 2 && v.charAt(0) === '"' && v.charAt(v.length - 1) === '"') {
            v = v.substring(1, v.length - 1).replace(/\\(["\\])/g, '$1');
        }
        out[i].val = v;
        res.push(out[i]);
    }
    return res;
}
function tmSuffix(oid, prefix) {
    if (oid.indexOf(prefix + '.') !== 0) return null;
    return oid.substring(prefix.length + 1);
}
function tmInt(v) {
    var m = /\((-?\d+)\)/.exec(v) || /-?\d+/.exec(v);
    return m ? parseInt(m[1] !== undefined ? m[1] : m[0], 10) : null;
}
function tmBytes(e) {
    var b = [], i, s = e.val;
    if (e.type === 'Hex-STRING') {
        s = s.replace(/[^0-9A-Fa-f]/g, '');
        for (i = 0; i + 1 < s.length; i += 2) b.push(parseInt(s.substr(i, 2), 16));
        return b;
    }
    // A server or proxy that has MIB files loaded prints binary values the way the MIB's
    // display hint says, as text: a MAC arrives as STRING "00:1a:2b:3c:4d:5e" (or unpadded,
    // "0:50:56:1:2:3") where a server without MIBs gives Hex-STRING. Same bytes either way.
    if (/^[0-9A-Fa-f]{1,2}([:\- ][0-9A-Fa-f]{1,2})+$/.test(s)) {
        var parts = s.split(/[:\- ]/);
        for (i = 0; i < parts.length; i++) b.push(parseInt(parts[i], 16));
        return b;
    }
    for (i = 0; i < s.length; i++) b.push(s.charCodeAt(i) & 0xff);
    return b;
}
function tmHex(b) {
    var s = '', i, h;
    for (i = 0; i < b.length; i++) { h = b[i].toString(16); s += (h.length < 2 ? '0' : '') + h; }
    return s;
}
function tmPrintable(b) {
    for (var i = 0; i < b.length; i++) { if (b[i] < 32 || b[i] > 126) return false; }
    return b.length > 0;
}
function tmText(e) {
    var b = tmBytes(e);
    if (e.type !== 'Hex-STRING') return e.val;
    while (b.length && b[b.length - 1] === 0) b.pop();
    if (tmPrintable(b)) { var s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return s; }
    return tmHex(b);
}
// LLDP chassis/port id decode. kind 'c' chassis (mac=4, net=5) / 'p' port (mac=3, net=4)
function tmLldpId(e, subtype, kind) {
    var b = tmBytes(e);
    var macSt = kind === 'c' ? 4 : 3, netSt = kind === 'c' ? 5 : 4;
    if (subtype === macSt && b.length === 6) return tmHex(b);
    if (subtype === netSt && b.length === 5 && b[0] === 1) return b[1] + '.' + b[2] + '.' + b[3] + '.' + b[4];
    if (e.type === 'Hex-STRING' && b.length === 6 && !tmPrintable(b)) return tmHex(b);
    return tmText(e);
}
function tmMacFromIdx(parts) {
    if (parts.length < 6) return null;
    var p = parts.slice(parts.length - 6), s = '', i, h;
    for (i = 0; i < 6; i++) { h = parseInt(p[i], 10).toString(16); s += (h.length < 2 ? '0' : '') + h; }
    return s;
}
function tmMacro(v, def) {
    var n = parseInt(v, 10);
    return isNaN(n) ? def : n;
}
function tmKeys(o) { var k = []; for (var x in o) if (o.hasOwnProperty(x)) k.push(x); return k; }
function tmNumSort(a, b) { return (parseInt(a, 10) - parseInt(b, 10)) || (a < b ? -1 : a > b ? 1 : 0); }
// ---- end helpers ----
