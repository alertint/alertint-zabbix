# AlertINT for Zabbix

A Zabbix frontend module that adds [AlertINT](https://alertint.com) to the Zabbix menu.

AlertINT groups related Zabbix problems into **Situations**, investigates them, and writes a **finding**:

- what is likely wrong
- what it checked
- what it could not check

The module talks only to **your own AlertINT agent**. No third-party service sits in between.

```
Zabbix server    ── webhook :9911 ──▶  AlertINT agent
Zabbix frontend  ◀──── MCP :9912 ────  AlertINT agent
```

**Contents:** [Pages](#pages) · [Requirements](#requirements) · [Install](#install) · [Set up](#set-up) · [Troubleshooting](#troubleshooting) · [Security](#security) · [Remove](#remove)

## Pages

| Menu entry | Who sees it | What it shows |
|---|---|---|
| **AlertINT → Situations** | Every Zabbix user | Live Situations: the finding, the Zabbix problems it groups, and the timeline. |
| **AlertINT → Status** | Every Zabbix user | Whether the agent is reachable, and its last 24 h: alerts, analyses, LLM calls, tokens per model. |
| **AlertINT → Settings** | Super admins | Agent address and token, **Connect**, **Send test problem**, and **Create read access**. |

## Requirements

| What | Version / detail |
|---|---|
| Zabbix | **7.0 LTS**, **7.2** or **7.4** (tested on `7.0.32` and `7.4.16`) |
| AlertINT agent | **`v0.16.0`** or later |
| Network | Zabbix **web frontend** → agent port `9912` (MCP) |
| | Zabbix **server** → agent port `9911` (webhook) |
| Account | A Zabbix **Super admin** for setup |

## Install

### Option A: one-line installer (agent + module)

On a Linux host with systemd:

```sh
curl -fsSL https://alertint.com/zabbix/install.sh | ALERTINT_TRIAL_KEY=YOUR_KEY sh
```

- Installs and starts the AlertINT agent.
- On the Zabbix frontend host, it also installs this module.
- At the end, it prints the three values **Settings** asks for. They are also stored in `/etc/alertint/alertint.env`.

Other ways to run the agent: [Get started](https://alertint.com/get-started).

### Option B: module only (manual)

1. Find your frontend's modules directory:

   | Zabbix install | Modules directory |
   |---|---|
   | 7.0 packages (deb/rpm) | `/usr/share/zabbix/modules` |
   | 7.2 and 7.4 packages | `/usr/share/zabbix/ui/modules` |
   | Official Docker images (`zabbix-web-*`) | `/usr/share/zabbix/modules` (mount a folder there) |
   | From source | `modules/` next to the frontend's `zabbix.php` |

2. Download and unpack the release. It creates a folder named `alertint`:

   ```sh
   curl -fsSLO https://github.com/alertint/alertint-zabbix/releases/latest/download/alertint-zabbix.tar.gz

   # 7.2 / 7.4 packages
   sudo tar -xzf alertint-zabbix.tar.gz -C /usr/share/zabbix/ui/modules

   # 7.0 packages and Docker
   sudo tar -xzf alertint-zabbix.tar.gz -C /usr/share/zabbix/modules
   ```

   Or clone it:

   ```sh
   git clone https://github.com/alertint/alertint-zabbix.git <modules directory>/alertint
   ```

### Enable the module

1. In Zabbix, open **Administration → General → Modules**.
2. Press **Scan directory**.
3. Enable **AlertINT**.

An **AlertINT** section appears in the main menu, after **Monitoring**.

## Set up

Open **AlertINT → Settings** as a Super admin. Do sections 1 to 3 in order; section 4 is optional.

### 1. Agent

| Field | Value |
|---|---|
| **Agent MCP address** | Where the web frontend reaches the agent, e.g. `http://agent-host:9912/mcp` |
| **MCP token** | `ALERTINT_MCP_TOKEN` from `/etc/alertint/alertint.env` on the agent host |
| **Webhook address** | Leave empty unless the Zabbix server reaches the agent by another address. Empty means same host, port `9911`, path `/webhook/zabbix`. |

Press **Test connection**, then **Save**.

> Saved tokens are never sent back to the browser.

### 2. Connect Zabbix

1. Paste the webhook token: `ALERTINT_ZABBIX_WEBHOOK_TOKEN` from the same file.
2. Press **Connect**.

Connect creates (or repairs) everything Zabbix needs:

| Object | Name | Purpose |
|---|---|---|
| Media type | `AlertINT` | Webhook to the agent. Holds the webhook token. |
| User group | `AlertINT service` | No frontend login. Read access on every host group, because Zabbix only notifies users who can see the host. |
| User | `alertint-svc` | Receives every problem through the `AlertINT` media type. |
| Trigger action | `Send to AlertINT` | Sends every problem and its recovery. |
| Global macro | `{$ZABBIX_URL}` | Lets AlertINT link back to each problem. Created only if missing. |
| Host | `AlertINT test` (group `AlertINT`) | Trapper item and trigger for the test problem. |

Good to know:

- Connect ends with a **webhook check run by the Zabbix server**. A wrong token or a blocked port shows up right away. The check creates no alert.
- Connect is safe to run again. It only adds what is missing.
- **Run Connect again after you add host groups**, so the service group can read them.

### 3. Send a test problem

Press **Send test problem**. It raises `AlertINT test problem` on the `AlertINT test` host, the same way any trigger fires, and follows it through five stages:

| # | Stage |
|---|---|
| 1 | Problem in Zabbix |
| 2 | Sent by Zabbix |
| 3 | Received by AlertINT |
| 4 | Situation |
| 5 | Finding (the first analysis usually takes about a minute) |

Then it resolves the problem and follows the recovery.

> The test is a real analysis, so it uses one call on your model.

### 4. Let AlertINT read Zabbix (optional)

With read access, AlertINT also reads item history, other open problems and host details while it investigates.

1. Check **Zabbix address for the agent**: where the agent host opens this frontend, e.g. `https://zabbix.example.com`.
2. Press **Create read access**. It creates or updates:

   | Object | Name | Purpose |
   |---|---|---|
   | User role | `AlertINT read-only API` | API only, and only `event.get`, `history.get`, `host.get`, `hostgroup.get`, `item.get`, `problem.get`, `trend.get`, `trigger.get`. Zabbix enforces it. |
   | User | `alertint-svc` | Gets that role. It reads the host groups Connect gave it. |
   | API token | `AlertINT read-only API` | No expiry. Pressing the button again replaces it. |

3. Run the command it prints on the **agent host**:

   ```sh
   curl -fsSL https://alertint.com/zabbix/install.sh | ZABBIX_URL='https://zabbix.example.com' ZABBIX_API_TOKEN='…' sh
   ```

   The installer keeps the agent, its tokens and `config.yaml`, turns on the Zabbix API (old file kept as `config.yaml.bak`), and checks that the token can read hosts.
4. Press **Check**. The agent reads the open problems on `AlertINT test` with the token: the same path an investigation uses.

> The token is shown once and never stored by the module. Agent not installed with `install.sh`? Put the token in the agent's environment and set `zabbix.api` in its config ([docs](https://alertint.com/docs/integrations/zabbix#pull-the-zabbix-context-zabbixapi)).

## Troubleshooting

| You see | Do this |
|---|---|
| Module missing after **Scan directory** | The `alertint` folder must sit directly in the modules directory and contain `manifest.json`. On 7.2+ packages that is `/usr/share/zabbix/ui/modules`, not `/usr/share/zabbix/modules`. |
| Settings: *agent unreachable* | The web frontend cannot reach port `9912`. Check the address, the agent service and the firewall. |
| Settings: *refused the MCP token* | Copy `ALERTINT_MCP_TOKEN` again. The agent was reinstalled or the token changed. |
| Connect: *Zabbix server could not reach…* | The **server** host (not the frontend) cannot reach port `9911`. In split setups, set **Webhook address** in section 1. |
| Connect: *refused the webhook token* | Copy `ALERTINT_ZABBIX_WEBHOOK_TOKEN` again and press **Connect again**. |
| Test: *has not loaded the test host yet* | The Zabbix server picks up new hosts within a minute. Try again shortly. |
| Test stops at *Sent by Zabbix* | Zabbix's own error is shown. Also check **Reports → Action log**. |
| Problems on some hosts never reach AlertINT | Their host group was added after Connect. Press **Connect again**. |
| Check: *does not read Zabbix yet* | Run the command from **Create read access** on the agent host. |
| Check fails with *Not authorized* or *session* | Wrong token, or Apache drops the `Authorization` header: add `CGIPassAuth On` to the Zabbix Apache config. |
| An old Situation picks up a new test after you rebuilt Zabbix | Event ids restarted. Set a new `zabbix.instance_id` in `/etc/alertint/config.yaml`, then run `sudo systemctl restart alertint`. |

## Security

- The **MCP token** is stored in the module's config in the Zabbix database. Super admins can read it through the API.
- The **webhook token** is stored only in the `AlertINT` media type.
- The **read-access token** is shown once and kept only by Zabbix and the agent (`/etc/alertint/alertint.env`). Its role allows read methods only.
- The service user `alertint-svc` cannot log in to the frontend.
- Settings, Connect and the test are **Super admin only**, and every change is CSRF-protected.

## Remove

1. Open **Administration → General → Modules**, disable **AlertINT**, then delete the `alertint` folder.
2. Optionally, delete what Connect created:
   - trigger action `Send to AlertINT`
   - user `alertint-svc`
   - user group `AlertINT service`
   - media type `AlertINT`
   - host `AlertINT test` and host group `AlertINT`
   - API token and user role `AlertINT read-only API` (delete the user first)
   - global macro `{$ZABBIX_URL}`, if nothing else uses it

## License

[AGPL-3.0](LICENSE), the same licence as Zabbix 7.0 and later. The AlertINT agent is a separate program with its own licence.
