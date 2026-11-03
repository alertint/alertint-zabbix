# AlertINT for Zabbix

A Zabbix frontend module that brings [AlertINT](https://alertint.com) into the Zabbix menu. AlertINT groups related Zabbix problems into **Situations**, investigates them, and writes a finding: what is likely wrong, what it checked, and what it could not check.

The module talks to your own AlertINT agent. Zabbix problems go to the agent through a webhook, and the module reads Situations back over MCP. Nothing goes through a third-party service.

| Page | Who sees it | What it does |
|---|---|---|
| **AlertINT → Situations** | every Zabbix user | Live Situations: the finding, the Zabbix problems it groups, and its timeline. |
| **AlertINT → Status** | every Zabbix user | Is the agent reachable, and what it did in the last 24 h (alerts, analyses, LLM calls, tokens per model). |
| **AlertINT → Settings** | Super admins | Agent address and token, **Connect** (sets Zabbix up), and **Send test problem**. |

## Requirements

- Zabbix **7.0 LTS, 7.2 or 7.4**. Tested on 7.0.32 and 7.4.16.
- An AlertINT agent, **v0.16.0 or later**. On a Linux host with systemd, one line installs it:

  ```sh
  curl -fsSL https://alertint.com/zabbix/zabbix.sh | KEY=sk-ant-... sh
  ```

  Run it on the Zabbix frontend host and it installs this module too. At the end it prints the three values Settings asks for. They are also in `/etc/alertint/alertint.env`. Other ways to run the agent: [Get started](https://alertint.com/get-started).
  - The Zabbix **web frontend** must reach the agent's MCP port (default `9912`).
  - The Zabbix **server** must reach the agent's webhook port (default `9911`).
- A Zabbix **Super admin** account for setup.

## Install

The installer above does this step for you when it runs on the Zabbix frontend host. Otherwise:

1. Download `alertint-zabbix.tar.gz` from [Releases](https://github.com/alertint/alertint-zabbix/releases) and unpack it into the frontend's `modules` directory. It creates a folder named `alertint`.

   | Zabbix install | Modules directory |
   |---|---|
   | 7.0 packages (deb/rpm) | `/usr/share/zabbix/modules` |
   | 7.2 and 7.4 packages | `/usr/share/zabbix/ui/modules` |
   | Official Docker images (`zabbix-web-*`) | `/usr/share/zabbix/modules` (mount the folder there) |
   | From source | `modules/` next to the frontend's `zabbix.php` |

   ```sh
   # 7.2 / 7.4 packages; use /usr/share/zabbix/modules on 7.0
   curl -fsSLO https://github.com/alertint/alertint-zabbix/releases/latest/download/alertint-zabbix.tar.gz
   sudo tar -xzf alertint-zabbix.tar.gz -C /usr/share/zabbix/ui/modules
   ```

   Or clone it: `git clone https://github.com/alertint/alertint-zabbix.git <modules directory>/alertint`

2. In Zabbix, open **Administration → General → Modules**, press **Scan directory**, and enable **AlertINT**.
3. An **AlertINT** section appears in the main menu, after Monitoring.

## Set up

Open **AlertINT → Settings** as a Super admin. The page has three sections; do them in order.

### 1. Agent

| Field | Value |
|---|---|
| Agent MCP address | Where the web frontend reaches the agent, e.g. `http://agent-host:9912/mcp`. |
| MCP token | `ALERTINT_MCP_TOKEN` from `/etc/alertint/alertint.env` on the agent host. |
| Webhook address | Leave empty unless the Zabbix server reaches the agent by another address. Empty means: same host, port `9911`, `/webhook/zabbix`. |

Press **Test connection**, then **Save**. Saved tokens are never sent back to the browser.

### 2. Connect Zabbix

Enter the webhook token (`ALERTINT_ZABBIX_WEBHOOK_TOKEN` from the same file) and press **Connect**. It creates, or repairs, everything Zabbix needs:

| Object | Purpose |
|---|---|
| Media type **AlertINT** | Webhook to the agent. Holds the webhook token. |
| User group **AlertINT service** | No frontend login. Read access on every host group, because Zabbix only notifies users who can see the host. |
| User **alertint-svc** | Receives every problem through the AlertINT media type. |
| Trigger action **Send to AlertINT** | Sends every problem and its recovery. |
| Global macro `{$ZABBIX_URL}` | Lets AlertINT link back to each problem. Created only if missing. |
| Host **AlertINT test** (group **AlertINT**) | Trapper item and trigger for the test problem. |

Connect ends with a **webhook check run by the Zabbix server**, so a wrong token or a blocked port shows up right away. The check creates no alert.

Connect is safe to run again: it only adds what is missing. **Run it again after you add host groups**, so the service group can read them.

### 3. Send a test problem

Press **Send test problem**. It raises *AlertINT test problem* on the *AlertINT test* host, the same way any trigger fires, and follows it:

1. Problem in Zabbix
2. Sent by Zabbix
3. Received by AlertINT
4. Situation
5. Finding (the first analysis usually takes about a minute)

Then it resolves the problem again and follows the recovery. The test is a real analysis, so it uses one call on your model.

## Troubleshooting

| You see | Do this |
|---|---|
| Module missing after **Scan directory** | The folder must sit directly in the modules directory and contain `manifest.json`. On 7.2+ packages that is `/usr/share/zabbix/ui/modules`, not `/usr/share/zabbix/modules`. |
| Settings: *agent unreachable* | The web frontend cannot reach port `9912`: check the address, the agent service and the firewall. |
| Settings: *refused the MCP token* | Copy `ALERTINT_MCP_TOKEN` again; the agent was reinstalled or the token changed. |
| Connect: *Zabbix server could not reach…* | The **server** host (not the frontend) cannot reach port `9911`. In split setups, set **Webhook address** in section 1. |
| Connect: *refused the webhook token* | Copy `ALERTINT_ZABBIX_WEBHOOK_TOKEN` again and press **Connect again**. |
| Test: *has not loaded the test host yet* | The Zabbix server picks up new hosts within a minute. Try again shortly. |
| Test stops at *Sent by Zabbix* | Zabbix's own error is shown. Also see **Reports → Action log**. |
| Problems on some hosts never reach AlertINT | Their host group was added after Connect. Press **Connect again**. |
| An old Situation picks up a new test after you rebuilt Zabbix | Event ids restarted. Give `zabbix.instance_id` in `/etc/alertint/config.yaml` a new value and restart the agent (`sudo systemctl restart alertint`). |

## Security

- The MCP token is stored in the module's config in the Zabbix database. Super admins can read it through the API.
- The webhook token is stored only in the AlertINT media type.
- The service user cannot log in to the frontend.
- Settings, Connect and the test are Super admin only, and every change is CSRF-protected.

## Remove

1. **Administration → General → Modules**: disable AlertINT, then delete its folder.
2. Optionally delete what Connect created: trigger action *Send to AlertINT*, user *alertint-svc*, user group *AlertINT service*, media type *AlertINT*, host *AlertINT test*, host group *AlertINT*, and the global macro `{$ZABBIX_URL}` if nothing else uses it.

## License

[AGPL-3.0](LICENSE), the same licence as Zabbix 7.0 and later. The AlertINT agent is a separate program with its own licence.
