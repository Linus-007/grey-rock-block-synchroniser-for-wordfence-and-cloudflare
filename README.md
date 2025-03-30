# Wordfence Cloudflare Firewall Sync

Syncs Wordfence IP blocks to Cloudflare's WAF for high-performance, DNS-level security.

![Version](https://img.shields.io/badge/version-1.0.0-blue)
![Built for WordPress](https://img.shields.io/badge/WordPress-6.0+-blueviolet)
![License](https://img.shields.io/badge/license-GPLv2-blue)

---

## 🔥 Features

- ✅ Syncs IP blocks from Wordfence to Cloudflare Firewall Rules
- ✅ DNS-level blocking to reduce server resource usage
- ✅ Automatic cron-based syncing
- ✅ Manual "Sync Now" + "Cleanup Now" buttons
- ✅ Cloudflare rule reconciliation (detect drift)
- ✅ Expired block cleanup and retry logic
- ✅ Built-in logging and admin UI
- ✅ Multisite-compatible (per-site sync)

---

## 🧠 How It Works

- On sync, the plugin reads Wordfence's current block list
- It pushes valid IPs to Cloudflare's WAF using their API
- Expired or removed blocks are cleaned up from Cloudflare
- A database table tracks block history, sync logs, and retry attempts

---

## 🛠 Installation

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

5. Enter your Cloudflare API Token and Zone ID

---

## 📦 GitHub Releases

You can also install the plugin from the `.zip` file attached to each [GitHub Release](https://github.com/yourname/wordfence-cloudflare-firewall-sync/releases).

---

## 🧪 Dev Features

- Admin panel with sync status and logs
- CLI-ready internal architecture
- GitHub Actions for automatic zipping & releases
- Makefile for clean versioned tagging
- VS Code Dev Container

---

## 🚧 Roadmap

- [ ] Rule reconciliation fixes
- [ ] Visual sync/block stats
- [ ] Cloudflare error alerting
- [ ] Translation contributions

---

## 🤝 Contributions

PRs welcome. Please ensure coding style follows PSR-12 with the exception of following 1TBS.

To test:

```bash
make format
make pot
```

---

## 📝 License

GPLv2 — same as WordPress.

---

## 🔒 Disclaimer

This plugin is not officially affiliated with Wordfence or Cloudflare. Use at your own risk.
