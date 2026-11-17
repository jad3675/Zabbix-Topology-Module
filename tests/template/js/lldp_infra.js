// Infrastructure-only neighbor fingerprint: drops phones and stations so reboots
// of desk gear do not fire the change trigger.
var l = JSON.parse(value), o = [], i, n;
for (i = 0; i < l.length; i++) {
    n = l[i];
    if (n.cap && (n.cap.indexOf('T') !== -1 || !/[BRW]/.test(n.cap))) continue;
    o.push(n.lp + ' ' + (n.sys || n.cid || '?') + ' ' + (n.pid || n.pd || ''));
}
return o.sort().join('\n');
