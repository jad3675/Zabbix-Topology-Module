#!/bin/sh
# Runs every offline test. Needs php-cli; the parser tests also need python3 with dukpy and pyyaml,
# and the awkward-agent test needs snmpsim and the net-snmp command line tools.
cd "$(dirname "$0")" || exit 1
fail=0
for t in sources_test uplink_test ownmac_test macname_test; do
	if php "$t.php" > /tmp/topomap_$t.out 2>&1; then echo "ok    $t ($(tail -1 /tmp/topomap_$t.out))"; else echo "FAIL  $t"; tail -5 /tmp/topomap_$t.out; fail=1; fi
done
# Print-style tests are compared with their recorded output.
for t in correlator_test lp0_test smallnet_test behind_test changelog_test; do
	if php "$t.php" 2>&1 | diff -q - "golden/$t.txt" > /dev/null; then echo "ok    $t (matches golden)"; else echo "FAIL  $t (differs from golden/$t.txt)"; fail=1; fi
done
if python3 -c "import dukpy" 2>/dev/null; then
	if python3 template/run.py > /dev/null 2>&1 && python3 template/subtree_check.py > /dev/null 2>&1 && python3 template/mibhint_check.py > /dev/null 2>&1; then echo "ok    template parsers"; else echo "FAIL  template parsers"; fail=1; fi
else
	echo "skip  template parsers (pip install dukpy pyyaml)"
fi
# Every request the template sends, against an agent that drops wide or oversized ones.
if command -v snmpsim-command-responder > /dev/null 2>&1 && command -v snmpbulkwalk > /dev/null 2>&1; then
	if python3 hostile/run.py > /tmp/topomap_hostile.out 2>&1; then echo "ok    awkward agent ($(tail -1 /tmp/topomap_hostile.out))"; else echo "FAIL  awkward agent"; grep FAIL /tmp/topomap_hostile.out; fail=1; fi
else
	echo "skip  awkward agent (needs snmpsim and net-snmp tools)"
fi
exit $fail
