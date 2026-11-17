// Input: walk of an FDB port column (Q-BRIDGE or BRIDGE-MIB) + dot1dBasePortIfIndex.
// Output: {"e":{ifIndex:[mac,...]}, "u":{ifIndex:count}, "t":total}
var EDGE_MAX = tmMacro('{$TOPO.FDB.EDGE.MAX}', 8);
var MAX_BYTES = tmMacro('{$TOPO.VALUE.MAX.BYTES}', 60000);
var BP = '1.3.6.1.2.1.17.1.4.1.2';
var rows = tmParse(value), map = {}, ports = {}, seen = {}, r, s, i, port, mac, total = 0;
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    if ((s = tmSuffix(r.oid, BP)) !== null) { map[s] = String(tmInt(r.val)); continue; }
}
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    if (r.oid.indexOf(BP + '.') === 0) continue;
    port = tmInt(r.val);
    if (!port) continue;
    mac = tmMacFromIdx(r.oid.split('.'));
    if (!mac || mac === '000000000000') continue;
    port = map[port] || ('bp' + port);
    if (seen[port + mac]) continue;
    seen[port + mac] = 1;
    total++;
    (ports[port] || (ports[port] = [])).push(mac);
}
var out = {e: {}, u: {}, t: total}, k = tmKeys(ports).sort(tmNumSort);
for (i = 0; i < k.length; i++) {
    if (ports[k[i]].length > EDGE_MAX) out.u[k[i]] = ports[k[i]].length;
    else out.e[k[i]] = ports[k[i]].sort();
}
var json = JSON.stringify(out);
if (json.length > MAX_BYTES) {
    // Size guard: demote the busiest edge ports to counts until it fits.
    var ek = tmKeys(out.e).sort(function (a, c) { return out.e[c].length - out.e[a].length; });
    var est = json.length;
    for (i = 0; i < ek.length && est > MAX_BYTES; i++) {
        est -= out.e[ek[i]].length * 15;
        out.u[ek[i]] = out.e[ek[i]].length;
        delete out.e[ek[i]];
    }
    out.x = 1;
    json = JSON.stringify(out);
}
return json;
