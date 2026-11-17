// Input: walk of ipNetToMediaPhysAddress or ipNetToPhysicalPhysAddress. IPv4 only.
// Output: {ip: mac} sorted, filtered by {$TOPO.ARP.NET.MATCH}, capped by {$TOPO.VALUE.MAX.BYTES}.
var MATCH = '{$TOPO.ARP.NET.MATCH}';
var MAX_BYTES = tmMacro('{$TOPO.VALUE.MAX.BYTES}', 60000);
var re = null;
if (MATCH.charAt(0) !== '{' && MATCH !== '') { try { re = new RegExp(MATCH); } catch (e) { re = null; } }
var M1 = '1.3.6.1.2.1.4.22.1.2', M2 = '1.3.6.1.2.1.4.35.1.4';
var rows = tmParse(value), out = {}, r, s, i, p, ip, b;
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    ip = null;
    if ((s = tmSuffix(r.oid, M1)) !== null) {
        p = s.split('.');
        if (p.length === 5) ip = p.slice(1).join('.');
    }
    else if ((s = tmSuffix(r.oid, M2)) !== null) {
        p = s.split('.');
        if (p.length === 7 && p[1] === '1' && p[2] === '4') ip = p.slice(3).join('.');
    }
    if (!ip || (re && !re.test(ip))) continue;
    b = tmBytes(r);
    if (b.length !== 6) continue;
    var mac = tmHex(b);
    if (mac === '000000000000' || mac === 'ffffffffffff') continue;
    out[ip] = mac;
}
function ipNum(a) { var q = a.split('.'); return ((+q[0]) * 16777216) + ((+q[1]) << 16) + ((+q[2]) << 8) + (+q[3]); }
var k = tmKeys(out).sort(function (a, c) { return ipNum(a) - ipNum(c); }), res = {}, len = 2;
for (i = 0; i < k.length; i++) {
    len += k[i].length + 20;
    if (len > MAX_BYTES) { res['_truncated'] = String(k.length - i); break; }
    res[k[i]] = out[k[i]];
}
return JSON.stringify(res);
