# Demo stack

A complete, throwaway Zabbix with the topology module installed and a simulated network to map. One command, nothing to configure, and it does not touch an existing Zabbix.

What starts:

| Container | What it is |
|---|---|
| `postgres` | Database for the demo Zabbix |
| `zabbix-server` | Zabbix server 7.4 (official image) |
| `zabbix-web` | Zabbix frontend 7.4 (official image) with this module mounted in |
| `snmpsim` | 17 simulated SNMP devices on one UDP port, one community per device |
| `bootstrap` | Imports the templates, enables the module, creates the hosts, runs the first walks |

The simulated network: two core routers, two distribution switches, five access switches, a data centre switch, five access points, two hypervisors with four virtual machines, a carrier router that is not in Zabbix, and about 130 end devices and wireless clients. Every name and address is invented.

## Run it

Needs Docker with Compose, about 400 MB of memory and internet access for the first start (images are pulled, and the simulator image is built).

```
cd sim
docker compose up -d --build
docker compose logs -f bootstrap
```

When the log says `Ready`, about three to four minutes after a first start, open:

```
http://localhost:8080        Admin / zabbix
```

Go to Monitoring > Topology and select the host group "Topology demo".

## What you should see

- All 17 devices placed by role, with 19 links between them and the port on each end. Links between routers and switches are found by both LLDP and CDP and drawn once.
- `isp-pe-01` as a neighbor that is not in Zabbix.
- A device count beside every access switch, and the wireless clients beside each access point.
- The four virtual machines under their hypervisors.
- Three links coloured for utilisation: two over 50%, one over 80%.

The devices are polled by the real templates through the real Zabbix server, so walks, parsers and map are all the shipped code.

Two things are not real, and are worth saying in a demo:

- **Link utilisation is invented.** The simulator serves the topology tables, not traffic counters. The bootstrap container sends made-up traffic figures for the link ports every five minutes (set in `network.py`), under the item keys the stock interface templates use.
- **"FDB: Table walk" is not supported on the routers, access points and hypervisors.** That is correct: those devices publish no MAC table, and the map treats that data as optional.

## Settings

| Variable | Default | Purpose |
|---|---|---|
| `TOPO_WEB_PORT` | `8080` | Port for the web interface |
| `TOPO_WEB_BIND` | `0.0.0.0` | Address it listens on. Set `127.0.0.1` to keep it off the network. |
| `TZ` | `UTC` | Time zone shown in the frontend |

```
TOPO_WEB_PORT=8090 TOPO_WEB_BIND=127.0.0.1 docker compose up -d --build
```

This is a lab: it runs with Zabbix's default login and a fixed database password. Do not put it on a network you do not trust.

## Change the network

Edit `network.py`, then:

```
python3 generate.py
docker compose down -v
docker compose up -d --build
```

## Stop and remove

```
docker compose down        # stop, keep the data
docker compose down -v     # remove everything, database included
```

## Use only the simulator, with your own Zabbix

If you would sooner point an existing Zabbix at the simulated devices:

1. Uncomment the `ports` lines under `snmpsim` in `docker-compose.yml`, then `docker compose up -d --build snmpsim`.
2. Import `templates/topology_templates.yaml` into your Zabbix.
3. Create the hosts, where `--sim-host` is the simulator's address as your Zabbix server or proxy reaches it:

```
python3 setup.py --url http://zabbix.example/ --token API_TOKEN --sim-host 192.0.2.10 --poll
```

`--proxy NAME` assigns the hosts to a proxy. `--remove` deletes only the hosts this script created, and the group if nothing else is in it. In this mode there are no utilisation colours.
