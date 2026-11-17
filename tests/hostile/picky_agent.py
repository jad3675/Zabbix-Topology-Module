#!/usr/bin/env python3
"""A deliberately awkward SNMP agent, for testing the template against.

It sits in front of a real agent (snmpsim in the tests) and behaves like the
devices that have caused trouble: it says nothing at all when a request is
wider than it likes, and nothing when the answer would not fit in one
unfragmented datagram. No error, no partial reply, just silence, which is what
makes these devices expensive: every such request costs the poller a full
timeout and holds up the other items on the host.

    picky_agent.py LISTEN_PORT UPSTREAM_PORT [max_varbinds] [max_rows] [max_reply_bytes]
"""
import socket
import sys

from pyasn1.codec.ber import decoder
from pysnmp.proto import api


def shape(data):
    """(varbinds, max-repetitions or 1) of a request, or None if it is not one we understand."""
    try:
        mod = api.PROTOCOL_MODULES[int(api.decodeMessageVersion(data))]
        msg, _ = decoder.decode(data, asn1Spec=mod.Message())
        pdu = mod.apiMessage.get_pdu(msg)
        n = len(mod.apiPDU.get_varbinds(pdu))
        reps = 1
        if hasattr(mod, 'GetBulkRequestPDU') and pdu.isSameTypeWith(mod.GetBulkRequestPDU()):
            reps = int(mod.apiBulkPDU.get_max_repetitions(pdu))
        return n, reps
    except Exception:
        return None


def serve(listen, upstream, max_vb=4, max_rows=40, max_reply=1400, stats=None):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind(('127.0.0.1', listen))
    up = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    up.settimeout(3)
    while True:
        data, peer = s.recvfrom(65535)
        sh = shape(data)
        if sh is None or sh[0] > max_vb or sh[0] * sh[1] > max_rows:
            print('drop request  %s' % (sh,), flush=True)
            continue
        up.sendto(data, ('127.0.0.1', upstream))
        try:
            reply, _ = up.recvfrom(65535)
        except socket.timeout:
            continue
        if len(reply) > max_reply:
            print('drop reply    %d bytes for %s' % (len(reply), sh), flush=True)
            continue
        s.sendto(reply, peer)


if __name__ == '__main__':
    a = [int(x) for x in sys.argv[1:]]
    serve(*a)
