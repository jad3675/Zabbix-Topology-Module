var C = '1.3.6.1.4.1.9.9.23.1.2.1.1';
var rows = tmParse(value), nb = {}, r, s, i, parts, key, n, b;
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    if ((s = tmSuffix(r.oid, C)) === null) continue;
    parts = s.split('.');
    if (parts.length < 3) continue;
    key = parts[1] + '.' + parts[2];
    n = nb[key] || (nb[key] = {lp: parts[1]});
    switch (parts[0]) {
        case '4':
            b = tmBytes(r);
            if (b.length === 4) n.ip = b.join('.');
            break;
        case '6': n.sys = r.val; break;
        case '7': n.pid = r.val; break;
        case '8': n.plat = r.val.split('\n')[0].substring(0, 64); break;
        case '9':
            b = tmBytes(r);
            var v = 0;
            if (b.length === 4) v = ((b[0] << 24) | (b[1] << 16) | (b[2] << 8) | b[3]) >>> 0;
            else v = tmInt(r.val) || 0;
            var c = '';
            if (v & 0x0e) c += 'B';
            if (v & 0x01) c += 'R';
            if (v & 0x80) c += 'T';
            if (v & 0x10) c += 'S';
            n.cap = c;
            break;
    }
}
var list = [];
for (key in nb) { if (nb.hasOwnProperty(key) && nb[key].sys) list.push(nb[key]); }
list.sort(function (x, y) { return tmNumSort(x.lp, y.lp) || (x.sys < y.sys ? -1 : 1); });
return JSON.stringify(list);
