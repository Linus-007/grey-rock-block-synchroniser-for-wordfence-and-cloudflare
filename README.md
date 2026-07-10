# Wordfence Cloudflare Firewall Sync

Syncs Wordfence IP blocks to Cloudflare's WAF for high-performance, DNS-level security.

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![Built for WordPress](https://img.shields.io/badge/WordPress-6.0+-blueviolet)
![License](https://img.shields.io/badge/license-GPLv2-blue)

---

## Features

- Syncs IP blocks from Wordfence to Cloudflare Zone Access Rules or a Cloudflare account-level IP list
- Edge-level blocking through Cloudflare to reduce server resource usage
- Automatic cron-based syncing
- Manual "Sync Now" + "Cleanup Now" buttons
- Cloudflare rule reconciliation (detect drift)
- Expired block cleanup and retry logic
- Built-in logging and admin UI
- Multisite-compatible (per-site sync)

---

## How It Works

- On sync, the plugin reads Wordfence's current block list
- It pushes valid IPs to Cloudflare using the selected mode: per-zone Access Rules or an account-level IP list
- Expired or removed blocks are cleaned up from Cloudflare
- A database table tracks block history, sync logs, and retry attempts

---

## Installation

1. Clone/download this repo:
   ```bash
   git clone https://github.com/yourname/wordfence-cloudflare-firewall-sync.git
   ```

2. Copy the `src/` folder into:
   ```
   /wp-content/plugins/wordfence-cloudflare-firewall-sync/
   ```

3. Activate the plugin from the WordPress admin panel

4. Go to:
   ```
   Settings → Firewall Sync
   ```

5. Enter your Cloudflare API token and choose a Cloudflare mode:
   - **Zone Access Rules**: requires a Cloudflare Zone ID
   - **Account IP List**: requires a Cloudflare Account ID and List ID

---

## Cloudflare Modes

The plugin supports two Cloudflare blocking modes.

### Zone Access Rules

This is the original behavior. Wordfence blocks are copied to Cloudflare Access Rules for one Cloudflare zone.

Use this mode when you only want to protect one zone.

Required settings:

- Cloudflare API Token
- Cloudflare Zone ID

### Account IP List

This mode copies Wordfence blocks to a Cloudflare account-level IP list. That list can then be referenced by Cloudflare rules across multiple zones in the same Cloudflare account.

Use this mode when you want a centralized IP block list that can be referenced by multiple Cloudflare zones in the same account.

Required settings:

- Cloudflare API Token
- Cloudflare Account ID
- Cloudflare List ID

Optional setting:

- Cloudflare List Name

The list name is currently used as an administrator-facing label only. The Cloudflare API calls use the List ID.

---

## Cloudflare Token Permissions

For **Zone Access Rules** mode, this plugin requires a restricted Cloudflare API token with:

- `Zone → Firewall Services: Edit`
- `Zone → Zone Settings: Read`
- `Zone → Zone: Read`

For **Account IP List** mode, use a token that can read and edit account-level Rules Lists for the Cloudflare account that owns the list.

To generate a token:

1. Visit: [https://dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens)
2. Click “Create Token”
3. Set the permissions above for your domain
4. Copy and paste the token into the plugin settings

Do not share this token — treat it like a password.


## GitHub Releases

You can also install the plugin from the `.zip` file attached to each [GitHub Release](https://github.com/yourname/wordfence-cloudflare-firewall-sync/releases).

---

## Dev Features

- Admin panel with sync status and logs
- CLI-ready internal architecture
- GitHub Actions for automatic zipping & releases
- Makefile for clean versioned tagging
- VS Code Dev Container

---

## Roadmap

- [ ] Rule reconciliation fixes
- [ ] Visual sync/block stats
- [ ] Cloudflare error alerting
- [ ] Translation contributions

---

## Contributions

PRs welcome. Please ensure coding style follows PSR-12 with the exception of following 1TBS.

To test:

```bash
make format
make pot
```

---

## License

GPLv2 — same as WordPress.

---

## Disclaimer

This plugin is not officially affiliated with Wordfence or Cloudflare. Use at your own risk.

## Multisite configuration

When network activated, the plugin supports two configuration sources:

- **Network Admin configuration:** Shared Cloudflare defaults managed by a network administrator.
- **Site-specific configuration:** Independent Cloudflare settings managed within an individual site when Network Admin permits overrides.

Sites inheriting Network Admin settings synchronize their Wordfence blocks additively to the shared Cloudflare destination. Cleanup and reconciliation are disabled for inherited configurations because a site's local synchronization log cannot determine whether another site still requires the same Cloudflare entry.

Sites using site-specific settings retain full synchronization, cleanup and reconciliation because the destination and ownership records are isolated to that site.
