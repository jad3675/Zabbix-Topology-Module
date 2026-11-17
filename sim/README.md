# Simulated network

Seventeen SNMP devices you can point Zabbix at, to see the topology map work without touching real equipment: two core routers, two distribution switches, five access switches, a data centre switch, five access points, two hypervisors with four virtual machines, a carrier router that is not in Zabbix, and about 130 end devices and wireless clients.

The simulator is [snmpsim](https://github.com/lextudio/snmpsim) in one container. Every device answers on the same UDP port and is told apart by its SNMP community, which is the device name. Zabbix polls them with the normal templates, exactly as it would poll real switches.

## Run it

```
cd sim
docker compose up -d --build
```

Check that a device answers (from the Zabbix server, or the proxy that will poll it):

```
snmpget -v2c -c core-rtr1 SIM_HOST:1161 1.3.6.1.2.1.1.5.0
```

Import `templates/topology_templates.yaml` if you have not already, then create the hosts. `--sim-host` is the simulator's address as the Zabbix server or proxy reaches it:

```
python3 setup.py --url http://zabbix.example/ --token API_TOKEN --sim-host 192.0.2.10 --poll
```

`--poll` waits for the server to load the new hosts and runs every walk once, so the map is ready in about two minutes. Without it the walks run on their normal schedule (1h to 6h). Add `--proxy NAME` if a proxy should do the polling. The script needs Python 3 only, and an API token for a user allowed to create hosts.

Open Monitoring > Topology and select the host group "Topology demo".

## What you should see

- All 17 devices placed by role, with 19 links between them, each labelled with the port on both ends. Links between the routers and switches are found by both LLDP and CDP and drawn once.
- `isp-pe-01` as a neighbor that is not in Zabbix.
- A device count beside every access switch, and the wireless clients beside each access point.
- The four virtual machines under their hypervisors.
- No utilisation colours. Those come from interface traffic items, which this simulator does not provide.

In Latest data, "FDB: Table walk" is not supported on the routers, access points and hypervisors. That is correct: those devices publish no MAC table, and the map treats that data as optional.

## Zabbix in Docker on the same machine

Publishing the port is enough if the server can reach the Docker host's address. Otherwise uncomment the `networks` lines in `docker-compose.yml`, set the name of your Zabbix network (`docker network ls`), and use `--sim-host topology-sim`.

## Change the network

Edit `network.py`, then:

```
python3 generate.py
docker compose restart
python3 setup.py --url ... --token ... --remove
python3 setup.py --url ... --token ... --sim-host ... --poll
```

## Remove it

```
python3 setup.py --url http://zabbix.example/ --token API_TOKEN --remove
docker compose down
```

`--remove` deletes only the hosts this script created, and the group if nothing else is in it.
