# ⚡ WTC Pi RAM-Disk Web Server

[![Release](https://img.shields.io/badge/Release-v0.5.1-brightgreen.svg)](#whats-new-in-release-v100)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Platform](https://img.shields.io/badge/Platform-Raspberry%20Pi%20%7C%20Ubuntu%20%7C%20Debian-orange.svg)](#prerequisites)
[![Architecture](https://img.shields.io/badge/RAM--Disk-tmpfs-green.svg)](#how-it-works)
[![Web Stack](https://img.shields.io/badge/Stack-Nginx%20%7C%20PHP--FPM%20%7C%20MariaDB-blueviolet.svg)](#key-features)

An ultra-fast, zero-wear ephemeral web hosting appliance built for Raspberry Pi (and Debian/Ubuntu Linux). Websites are stored as compressed archives (`.tar.gz`) on the SD card, unpacked into a high-speed volatile RAM disk (`tmpfs`) at boot or on demand, and served through Nginx + PHP-FPM.

Enjoy memory-speed response times, minimal SD-card write wear, RAM-first hot deployments, multi-site hosting, HTTPS/TLS, Laravel & SPA automation, and full system observability from a lightweight single-worker Web Management UI.

---

## 🎉 What's New in Release v0.5.1

* **⚡ RAM-First Instant Re-upload & Background SD Catalog Rebuild**: Re-uploading files or archives replaces files **directly in RAM first** (`/mnt/ramdisk/sites/<slug>`) for immediate access to the live site, then rebuilds the SD card catalog (`.tar.gz`, rollback snapshot, and `.meta.json`) asynchronously in the background with `pigz` multi-core compression.
* **⏱️ Incremental ("Recently Modified") & Row Drag-and-Drop Uploads**: Upload only files modified in the past *N* minutes when re-uploading folders, or drag and drop folders/archives directly onto any row in **Sites & Apps** while automatically protecting `.env`, SQLite DBs, `.wtc-database.sql`, `storage/app/**`, `.gitignore` rules, and custom patterns.
* **🔒 HTTPS / TLS & Self-Signed Certificate Management**: Dedicated HTTPS Management UI on port `4486` (enabling fast browser folder picking that skips `node_modules`/`vendor`/`.git`), 1-click `.crt` download and trust guide, plus a **Force HTTPS** toggle redirecting live sites (`80`/`8080`) and dedicated-port sites to HTTPS (`443`).
* **🚀 Faster Two-Phase Boot**: `wtc-site-service --boot` mounts tmpfs and brings up the Management UI immediately before `--boot-sites` transfers active sites from SD to RAM, with cooling fan boot-progress pulsing.
* **🅰️ Laravel Artisan, Ziggy & Frontend Build Integration**: Run Artisan commands (`migrate`, `db:seed`, `route:clear`, `optimize:clear`, `storage:link`, custom commands) from the UI or CLI, auto-rebuild `tightenco/ziggy` JS/TS routes (`php artisan ziggy:generate`), and execute background `npm run build` jobs in RAM.
* **📋 Multi-Site Cloning & Dedicated Port Hosting**: Duplicate any site into a parallel instance with cloned, fresh, or shared MariaDB databases, assign dedicated Nginx listening ports, or host concurrently under `/<site-slug>/`.
* **🛡️ Service Manager & Live CVE Vulnerability Scanner**: Audit running services against live OSV/CVEDB/EPSS/NVD feeds with Debian/Ubuntu version comparison, per-service scan timing, security grading (`A+`–`F`), and 1-click package patching.
* **📜 Structured Stack & Runtime Logs Viewer**: Inspect and filter formatted logs across RAM-resident application stacks, Nginx, PHP-FPM, queue/schedule workers, build jobs, and WTC system logs via the **Logs** tab or modal.
* **⚡ Live Telemetry Dashboard & RAM Drive Explorer**: Real-time SPA dashboard (`dash-live.js`) with per-core CPU sparklines, throttling/temperature monitoring, network RX/TX rates, dirty-state indicator, and an in-memory **RAM Drive Explorer** with inline file preview and grep search.
* **🌀 Network Watchdog & Cooling Fan Alerts (`wtc-network`)**: Disables Wi-Fi power saving, auto-reconnects dropped Wi-Fi connections, and drives PWM/HWMon/GPIO cooling fans (`pulse` or `spin`) during boot or Wi-Fi disconnects.
* **⏪ Versioned Rollback Manager & UFW Firewall Tab**: Keep multiple timestamped rollback snapshots per site (`ROLLBACK_KEEP`), restore or prune snapshots individually, and manage UFW firewall rules and listening ports directly from the UI.

---

## 🌟 Key Features

* **⚡ Ultra-Fast RAM-Disk Execution**: Active websites run entirely out of RAM (`/mnt/ramdisk/sites/<slug>` and `/mnt/ramdisk/webroot`). Filesystem reads hit memory speed with zero continuous SD-card wear.
* **🚀 3 Instant Hot-Deploy Methods**:
  1. **Browser Dropzone & Re-upload Modal**: Drag and drop any folder, `.zip`, or `.tar.gz` into the Dropzone or directly onto a site row. Supports full uploads, incremental recent-file overlays, and protected-file rules.
  2. **Windows Explorer (SMB)**: Drop folders or archives into `\\<pi-ip>\dropbox`. Detected automatically by `inotify`, updated directly in RAM, and synced to the SD catalog.
  3. **FTP / SFTP**: Connect via `ftp://<pi-ip>/` (`vsftpd`) or SFTP/SCP to `/mnt/ramdisk/dropbox`.
* **🌐 Concurrent Multi-Site Hosting**: Host a primary site at `/` (Ports `80`, `8080`, `443`) and concurrently serve additional sites at `/<site-slug>/` or on dedicated ports.
* **🗄️ Automated Database Provisioning**: Every site automatically receives an isolated MariaDB database and user (`w_<site>`) with credentials injected into `.env`, `.wtc-db.php`, and PHP FastCGI parameters, plus an interactive **Database** browser & SQL query runner.
* **💾 State Persistence ("Save State")**: Ephemeral by default, persistent when you need it. Snapshot live RAM files and MariaDB dumps (`.wtc-database.sql`) back to SD storage on demand, on site switch, on shutdown, or on a scheduled interval.
* **🛡️ Atomic Swaps & Multi-Snapshot Rollbacks**: Validate Nginx configurations with `nginx -t` before switching symlinks, and roll back to any saved timestamped snapshot in one click.
* **⚙️ Built-in Operations Suite**:
  * **Single-Worker SPA Management Console**: Fast, low-overhead web console on Port `4485` (HTTP) and Port `4486` (HTTPS).
  * **Service Manager & CVE Audit**: Lifecycle control (`start`/`stop`/`restart`/`enable`/`disable`), journal logs, live CVE scanning, and 1-click patching.
  * **Firewall & Ports**: Integrated UFW manager with common-service toggles, custom TCP/UDP rules, and active listener visibility.
  * **Stack Components & Workers**: 1-click APT stack installer (`php-web`, `php-db`, `php-imaging`, `php-cache`, `mariadb`, `redis`, `memcached`, `composer`, `nodejs`) and `wtc-workers` supervisor (`queue:work`, `schedule:work`).

---

## 🏗️ How It Works

```
                                [ Persistent Storage ]
                                   /opt/wtc-web/sites/
                                  (site archives .tar.gz
                                   & .previous/ rollbacks)
                                       ▲         │
                 Background Catalog    │         │ Unpack at boot /
                 Rebuild & Save State  │         │ on site activation
                                       │         ▼
[ Ingest Dropzone ]  ───────►  [ ⚡ High-Speed RAM Disk (tmpfs) ]  ───────►  [ Nginx + PHP-FPM ]
• Web Dropzone (browser)         /mnt/ramdisk/sites/<slug>                    • Port 80 / 8080 / 443 (Sites)
• Windows SMB (\\pi\dropbox)     /mnt/ramdisk/webroot (primary)               • Port 4485 / 4486 (Web Console)
• FTP (ftp://pi/) / SFTP         /mnt/ramdisk/ui (console)
```

See [ARCHITECTURE.md](ARCHITECTURE.md) for the detailed system architecture diagram and component lifecycle.

---

## 📋 Prerequisites & Hardware

* **Hardware**:
  * Raspberry Pi 4B (2GB, 4GB, or 8GB) or Raspberry Pi 5 recommended.
  * Also compatible with Raspberry Pi 3B+, Orange Pi, and standard x86_64 / ARM64 Debian/Ubuntu servers.
* **Storage**: 16 GB+ MicroSD card (Class 10 / A1 or A2 recommended).
* **Operating System**:
  * **Ubuntu Server 24.04 LTS (64-bit)** *(Recommended)*
  * **Debian 12 Bookworm**

---

## 🚀 Quick Start & Installation

### Step 1: Flash Your OS
1. Download and run [Raspberry Pi Imager](https://www.raspberrypi.com/software/).
2. Choose **Ubuntu Server 24.04 LTS (64-bit)** (or Debian 12).
3. Click **OS Customization** to configure:
   * Hostname (e.g., `wtc-pi`)
   * Username & password
   * Enable **SSH**
   * Wireless LAN / Ethernet credentials
4. Flash the SD card and boot your Raspberry Pi.

### Step 2: Clone and Install
Connect via SSH (`ssh <user>@<pi-ip>` or `ssh <user>@wtc-pi.local`) and execute:

```bash
# Update repositories and install git
sudo apt update && sudo apt install -y git

# Clone this repository
git clone https://github.com/AnthonyWriteTheCode/PiLabWTC.git
cd PiLabWTC

# Make the scripts executable
chmod +x *.sh wtc-workers wtc-network

# Run the automated installer
sudo ./01-server-setup.sh
```

#### Custom Installation Flags
You can configure installation options using environment variables:
```bash
# Allocate a custom RAM disk size (default: 512M)
sudo RAM_SIZE=1G ./01-server-setup.sh

# Disable Samba or FTP if you do not need file sharing
sudo ENABLE_SAMBA=no ENABLE_FTP=no ./01-server-setup.sh
```

---

## 🌐 Network Ports & Access URLs

Once setup completes, access your server using your Pi's IP address or hostname:

| Service | Address / Port | Description |
|---|---|---|
| **Primary Active Site** | `http://<pi-ip>/` or `https://<pi-ip>/` | Live primary site running in RAM (Ports `80` / `443`) |
| **Direct Site Port** | `http://<pi-ip>:8080/` | Direct port `8080` listener for primary site |
| **Secondary Active Sites** | `http://<pi-ip>/<site-slug>/` | Concurrent multi-site hosting (or custom dedicated port) |
| **Web Management UI (HTTP)** | `http://<pi-ip>:4485/` or `http://<pi-ip>/manage/` | Administration and deployment console |
| **Web Management UI (HTTPS)** | `https://<pi-ip>:4486/` | TLS console (enables fast browser folder selection) |
| **Windows SMB Share** | `\\<pi-ip>\dropbox` or `\\wtc-pi.local\dropbox` | Drag-and-drop network share |
| **FTP File Server** | `ftp://<pi-ip>/` | FTP ingest (anonymous or authenticated) |
| **Terminal CLI** | `sudo wtc-manage` | Interactive `whiptail` and CLI management tool |

---

## 📖 User Guide

### 1. Deploying & Updating Applications

#### A. Web Management Dropzone & Re-upload (Recommended)
1. Open `http://<pi-ip>:4485/` (or `https://<pi-ip>:4486/` for fast folder browsing) in your browser.
2. Navigate to **Sites & Apps** or **File Transfer**.
3. Drag and drop any **folder**, **`.zip`**, or **`.tar.gz`** file into the Dropzone, click **🔄 Re-upload** on an existing site, or drop directly onto a site row.
4. For folder re-uploads, optionally enable **Only upload files saved in the past *N* minutes** to overlay only recently edited files, and review **Protected Files** (`.env`, SQLite DBs, `.wtc-database.sql`, `storage/app/**`, `.gitignore` rules, custom patterns).
5. Files are replaced **directly in RAM** so your updated site is live immediately, while the SD card catalog (`.tar.gz`) rebuilds in the background (`💾 syncing catalog...`).

#### B. Windows Explorer Network Drop (SMB)
1. In Windows Explorer, press `Ctrl+L` and enter `\\<pi-ip>\dropbox`.
2. Drag and drop any folder or `.tar.gz` into the share.
3. The `wtc-dropbox.path` watcher detects the upload, updates RAM directly if the site is active (or auto-activates if enabled), and updates the SD catalog.

---

### 2. Multi-Site Hosting, Cloning & Dedicated Ports
You can run multiple applications simultaneously in RAM:
* **Primary Site**: Served at the root `http://<pi-ip>/` and port `8080`.
* **Secondary Sites**: Accessible at `http://<pi-ip>/<site-slug>/` with automatic subpath routing and `<base href>` rewriting.
* **Dedicated Ports & Cloning**: Use **📋 Duplicate** in **Sites & Apps** or the **Stack Manager** tab to clone a site (with cloned, fresh, or shared database) and bind it to a dedicated port (e.g., `:8081`).

---

### 3. HTTPS / TLS & Certificates
* The installer and `update.sh` generate a self-signed certificate (`/etc/ssl/wtc-ui/wtc-ui.crt`) for `pilabwtc.local` and your Pi's IP.
* Click **🔐 HTTPS Cert** in the header (or in **Settings**) to download `pilabwtc-ui.crt` and view OS trust instructions.
* Toggle **Force HTTPS** in **Sites & Apps** or **Settings** to redirect HTTP (`80`/`8080`) traffic to HTTPS (`443`), enable TLS on dedicated-port sites, and update Laravel `APP_URL` values to `https://`.

---

### 4. Databases (MariaDB & SQLite) & Laravel Artisan
* Every deployed site automatically provisions an isolated MariaDB database and user (`w_<site>`).
* Credentials are written into `.wtc-db.php`, PHP FastCGI params (`$_SERVER['DB_*']`), and `.env` (for Laravel/framework apps).
* Use the **Database** tab to inspect tables, run SQL queries, and import/export dumps.
* Use the **Laravel Database & Artisan Management** panel in **Sites & Apps** to run `route:clear`, `optimize:clear`, `ziggy:generate`, `storage:link`, `migrate`, `db:seed`, or custom Artisan commands directly in RAM.

---

### 5. State Persistence ("Save State")
Because sites run on a volatile RAM disk (`tmpfs`), runtime changes (e.g., user uploads, SQLite writes, MariaDB state) should be saved to the SD card archive when you want them persisted across reboots:

* **On-Demand Save**: Click **Save State** on the **Save State** tab or run `sudo wtc-manage --save-state`.
* **Selective Persistence (`wtc.json`)**:
  Include a `wtc.json` file in your site root to declare which paths to persist:
  ```json
  {
    "persist": [
      "storage/app/public",
      "uploads",
      "database.sqlite"
    ]
  }
  ```
* **Automated Saves**: Configure automatic snapshots on shutdown, on site switch, or on a recurring interval via **Save State** / **Settings**.

---

### 6. Custom Per-Site Nginx Configurations
If your application requires custom routing or rewrites, place an `nginx.conf` file in the root of your project:
```nginx
location /custom-api/ {
    try_files $uri $uri/ /api_handler.php?$args;
}
```
During deployment, the configuration is tested with `nginx -t` before Nginx is reloaded. You can also inspect and edit Nginx configs in the **Nginx Config** tab.

---

### 7. Background Workers, Network Watchdog & Updates
* **Background Workers (`wtc-workers`)**: Automatically detects Laravel `artisan` and runs `queue:work` and `schedule:work` (plus optional `.wtc-workers` commands).
* **Network & Fan Watchdog (`wtc-network`)**: Keeps Wi-Fi power management off, auto-reconnects dropped Wi-Fi, and supports cooling fan alerts (`FAN_ON_WIFI_DISCONNECT`, `FAN_WIFI_MODE=pulse|spin`, `FAN_GPIO`) configurable in **Settings**.
* **Applying Updates**: To update server scripts, services, and the Web Management UI in place:
  ```bash
  cd PiLabWTC
  git pull
  chmod +x update.sh
  sudo ./update.sh
  ```

---

## 🛠️ CLI Reference (`wtc-manage`)

You can manage the server directly from the command line:

```bash
sudo wtc-manage                              # Open the interactive whiptail menu
sudo wtc-manage --list                       # List all available and active sites
sudo wtc-manage --current                    # Show the current primary active site
sudo wtc-manage --status                     # Display RAM disk, dropbox, and service status
sudo wtc-manage --logs [N]                   # Tail the last N lines of the WTC log
sudo wtc-manage --set <site>                 # Switch the primary active site
sudo wtc-manage --activate <site> [0|1]      # Activate site in RAM (1=primary, 0=subpath)
sudo wtc-manage --deactivate <site>          # Stop and unload site from RAM
sudo wtc-manage --create <name>              # Create a new skeleton site
sudo wtc-manage --delete <site>              # Delete an inactive site and its snapshots
sudo wtc-manage --direct-deploy <site> <dir> # Replace files directly in RAM, then rebuild SD catalog
sudo wtc-manage --rebuild-catalog <site>     # Rebuild SD card .tar.gz catalog from RAM in background
sudo wtc-manage --save-state                 # Snapshot live RAM state & DB back to SD archive
sudo wtc-manage --rollback <site> [version]  # Roll back site to the latest or specified snapshot
sudo wtc-manage --site-clone <src> <new>     # Duplicate site instance (optional port/db mode)
sudo wtc-manage --site-port <site> <port>    # Assign or clear a dedicated listening port
sudo wtc-manage --artisan <site> "<cmd>"     # Run a Laravel Artisan command in RAM
sudo wtc-manage --ziggy [site]               # Rebuild Laravel Ziggy routes
sudo wtc-manage --storage-link [site]        # Ensure Laravel public/storage symlink
sudo wtc-manage --build <site> [cmd]         # Run background npm/custom build in RAM
sudo wtc-manage --service security-scan      # Run live CVE & security audit across services
sudo wtc-manage --reload-ui                  # Reload Management UI from SD into RAM
```

---

## 🤝 Contributing

Want to become a contributor to **PiLabWTC**? Contributions, ideas, and improvements are welcome!

You can get in touch with us directly on GitHub:

* **Request Contributor Access / Say Hello**: Open an [Issue on GitHub](https://github.com/AnthonyWriteTheCode/PiLabWTC/issues/new?title=Contributor%20Request) with a brief note about what areas you'd like to help with (e.g., features, bug fixes, UI, security, documentation, or Raspberry Pi hardware testing).
* **Submit Changes**: Fork the repository and open a [Pull Request](https://github.com/AnthonyWriteTheCode/PiLabWTC/pulls).
* **Ideas & Questions**: Start a thread in [GitHub Discussions](https://github.com/AnthonyWriteTheCode/PiLabWTC/discussions) or browse existing [Issues](https://github.com/AnthonyWriteTheCode/PiLabWTC/issues).

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).