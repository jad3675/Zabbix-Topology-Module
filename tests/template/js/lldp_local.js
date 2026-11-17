var B = '1.0.8802.1.1.2.1.3';
var rows = tmParse(value), r, s, i, parts, out = {cst: null, cid: '', sys: '', p: {}}, cidE = null, pst = {}, pidE = {};
for (i = 0; i < rows.length; i++) {
    r = rows[i];
    if ((s = tmSuffix(r.oid, B + '.1')) !== null) out.cst = tmInt(r.val);
    else if ((s = tmSuffix(r.oid, B + '.2')) !== null) cidE = r;
    else if ((s = tmSuffix(r.oid, B + '.3')) !== null) out.sys = r.val;
    else if ((s = tmSuffix(r.oid, B + '.7.1.2')) !== null) pst[s] = tmInt(r.val);
    else if ((s = tmSuffix(r.oid, B + '.7.1.3')) !== null) pidE[s] = r;
    else if ((s = tmSuffix(r.oid, B + '.7.1.4')) !== null) { out.p[s] = out.p[s] || {}; out.p[s].d = r.val; }
}
if (cidE) out.cid = tmLldpId(cidE, out.cst, 'c');
for (s in pidE) {
    if (!pidE.hasOwnProperty(s)) continue;
    out.p[s] = out.p[s] || {};
    out.p[s].id = tmLldpId(pidE[s], pst[s], 'p');
}
var ports = {}, k = tmKeys(out.p).sort(tmNumSort);
for (i = 0; i < k.length; i++) ports[k[i]] = out.p[k[i]];
out.p = ports;
return JSON.stringify(out);
