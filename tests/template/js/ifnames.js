// Input: walk of ifName, ifDescr, ifAlias.
// Output: {ifIndex: [name, alias]} sorted by ifIndex. Only near-static data, so the
// value is stored on change and rarely changes. Status and speed are read from the
// host's existing interface items by the module, not collected here.
var MAX_BYTES = tmMacro('{$TOPO.VALUE.MAX.BYTES}', 60000);
var NAME = '1.3.6.1.2.1.31.1.1.1.1', DESCR = '1.3.6.1.2.1.2.2.1.2', ALIAS = '1.3.6.1.2.1.31.1.1.1.18';
var rows = tmParse(value), p = {}, r, s, i;
function get(k) { return p[k] || (p[k] = {n: '', d: '', a: ''}); }
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    if ((s = tmSuffix(r.oid, NAME)) !== null) get(s).n = r.val;
    else if ((s = tmSuffix(r.oid, DESCR)) !== null) get(s).d = r.val;
    else if ((s = tmSuffix(r.oid, ALIAS)) !== null) get(s).a = r.val;
}
var k = tmKeys(p).sort(tmNumSort), out = {}, x;
for (i = 0; i < k.length; i++) {
    x = p[k[i]];
    out[k[i]] = x.a !== '' ? [x.n !== '' ? x.n : x.d, x.a] : [x.n !== '' ? x.n : x.d];
}
var json = JSON.stringify(out);
if (json.length > MAX_BYTES) {
    for (i = 0; i < k.length; i++) out[k[i]] = [out[k[i]][0]];
    json = JSON.stringify(out);
}
return json;
