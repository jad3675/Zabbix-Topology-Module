var R = '1.0.8802.1.1.2.1.4.1.1', M = '1.0.8802.1.1.2.1.4.2.1.3';
var rows = tmParse(value), nb = {}, r, s, i, parts, key, col, n;
function get(k) { if (!nb[k]) nb[k] = {lp: k.split('.')[0]}; return nb[k]; }
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    if ((s = tmSuffix(r.oid, M)) !== null) {
        parts = s.split('.');
        if (parts.length < 7) continue;
        key = parts[1] + '.' + parts[2];
        var a = parts.slice(3);
        if (a[0] === '1' && a[1] === '4' && a.length >= 6) a = a.slice(2, 6);
        else if (a[0] === '1' && a.length === 5) a = a.slice(1, 5);
        else continue;
        n = get(key);
        n.ip = n.ip || a.join('.');
        continue;
    }
    if ((s = tmSuffix(r.oid, R)) === null) continue;
    parts = s.split('.');
    if (parts.length < 4) continue;
    col = parts[0];
    key = parts[2] + '.' + parts[3];
    n = get(key);
    if (col === '4') n.cst = tmInt(r.val);
    else if (col === '5') n._c = r;
    else if (col === '6') n.pst = tmInt(r.val);
    else if (col === '7') n._p = r;
    else if (col === '8') n.pd = r.val;
    else if (col === '9') n.sys = r.val;
    else if (col === '10') n.sd = r.val.split('\n')[0].substring(0, 48);
    else if (col === '12') {
        var b = tmBytes(r), c = '', bits = b.length ? b[0] : 0;
        if (bits & 0x20) c += 'B';
        if (bits & 0x10) c += 'W';
        if (bits & 0x08) c += 'R';
        if (bits & 0x04) c += 'T';
        if (bits & 0x01) c += 'S';
        n.cap = c;
    }
}
var list = [];
for (key in nb) {
    if (!nb.hasOwnProperty(key)) continue;
    n = nb[key];
    if (n._c) n.cid = tmLldpId(n._c, n.cst, 'c');
    if (n._p) n.pid = tmLldpId(n._p, n.pst, 'p');
    delete n._c; delete n._p;
    if (!n.cid && !n.sys) continue;
    list.push(n);
}
list.sort(function (x, y) { return tmNumSort(x.lp, y.lp) || ((x.cid || '') < (y.cid || '') ? -1 : 1); });
return JSON.stringify(list);
