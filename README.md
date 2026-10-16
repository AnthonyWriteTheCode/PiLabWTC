# ⚡ WTC Pi RAM-Disk Web Server

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Platform](https://img.shields.io/badge/Platform-Raspberry%20Pi%20%7C%20Ubuntu%20%7C%20Debian-orange.svg)](#prerequisites)
[![Architecture](https://img.shields.io/badge/RAM--Disk-tmpfs-green.svg)](#how-it-works)
[![Web Stack](https://img.shields.io/badge/Stack-Nginx%20%7C%20PHP--FPM%20%7C%20MariaDB-blueviolet.svg)](#features)

An ultra-fast, zero-wear ephemeral web hosting appliance built for Raspberry Pi (and Debian/Ubuntu Linux). Websites are stored as compressed archives (`.tar.gz`) on the SD card, unpacked into a high-speed volatile RAM disk (`tmpfs`) at boot or on-demand, and served through Nginx + PHP-FPM.

Enjoy blazing-fast response times, zero SD-card write exhaustion, instant drag-and-drop deployments, and full state snapshotting.

---

## 🌟 Key Features

* **⚡ Ultra-Fast RAM-Disk Execution**: Active websites run entirely out of RAM (`/mnt/ramdisk/webroot`). Filesystem reads hit memory speed with zero wear on your SD card.
* **🚀 3 Instant Hot-Deploy Methods**:
  1. **Browser Dropzone**: Drag and drop any folder, `.zip`, or `.tar.gz` directly into the web management console.
  2. **Windows Explorer (SMB)**: Drop files straight into `\\<pi-ip>\dropbox`. Detected automatically by inotify, packaged, and spun up live.
  3. **FTP / SFTP**: Connect via `ftp://<pi-ip>/` or SFTP to deploy archives remotely.
* **🌐 Concurrent Multi-Site Hosting**: Host a primary site at `/` and concurrently serve additional sites under sub-paths (`/<site-slug>/`).
* **🗄️ Automated Database Provisioning**: Every site automatically gets a dedicated MariaDB database and user (`w_<site>`) with auto-generated credentials injected into `.env` and `.wtc-db.php`.
* **💾 State Persistence ("Save State")**: Ephemeral by default, persistent when you need it. Snapshot user uploads, SQLite databases, or runtime modifications back to the SD card on demand, on shutdown, or on an interval timer.
* **🛡️ Zero-Downtime Atomic Swaps & Rollbacks**: New deployments unpack to staging, validate with `nginx -t`, and swap atomically. Roll back to the previous revision in one click.
* **🛠️ Dual Management Interfaces**:
  * **Web Console**: Modern, responsive dashboard running on dedicated port `4485` (and `/manage/`).
  * **Terminal CLI**: Interactive whiptail menu via `sudo wtc-manage`.
* **⚙️ Built-in Tooling**: Integrated firewall manager (`ufw`), background queue daemon (`wtc-workers` for Laravel queue/scheduler), Nginx vhost editor, and 1-click stack installer (Redis, Node.js, Composer, etc.).

---

## 🏗️ How It Works

```
                                [ Persistent Storage ]
                                   /opt/wtc-web/sites/
                                  (site archives .tar.gz)
                                       ▲         │
                           Save State  │         │ Unpack at boot /
                           Snapshot    │         │ on site switch
                                       │         ▼
[ Ingest Dropzone ]  ───────►  [ ⚡ High-Speed RAM Disk (tmpfs) ]  ───────►  [ Nginx + PHP-FPM ]
• Web Dropzone (browser)         /mnt/ramdisk/webroot                         • Port 80 / 8080 (Sites)
• Windows SMB (\\pi\dropbox)                                                  • Port 4485 (Web Console)
• FTP (ftp://pi/)
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
3. Click the gear icon (**OS Customization**) to configure:
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
git clone https://github.com/your-username/wtc-piLab.git wtc-piLab
cd wtc-piLab

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
| **Primary Active Site** | `http://<pi-ip>/` or `http://wtc-pi.local/` | Live site running in RAM (Port 80) |
| **Direct Site Port** | `http://<pi-ip>:8080/` | Direct port 8080 fallback |
| **Web Management UI** | `http://<pi-ip>:4485/` or `http://<pi-ip>/manage/` | Administration and deployment dashboard |
| **Windows SMB Share** | `\\<pi-ip>\dropbox` or `\\wtc-pi.local\dropbox` | Drag-and-drop network share |
| **FTP File Server** | `ftp://<pi-ip>/` | FTP ingest (anonymous or authenticated) |
| **Terminal CLI** | `sudo wtc-manage` | Interactive curses management menu |

---

## 📖 User Guide

### 1. Deploying Applications

#### A. Web Management Dropzone (Recommended)
1. Open `http://<pi-ip>:4485/` in your browser.
2. Navigate to **Sites & Apps** or **File Transfer**.
3. Drag and drop any **folder**, **`.zip`**, or **`.tar.gz`** file into the dropzone.
4. If a `.env` or application stack is detected (such as Laravel or WordPress), the UI allows you to review environment variables before deployment.
5. Click **Upload & Deploy** — the site is extracted into RAM and immediately available live!

#### B. Windows Explorer Network Drop (SMB)
1. In Windows Explorer, press `Ctrl+L` and enter `\\<pi-ip>\dropbox`.
2. Drag and drop any folder or `.tar.gz` into the share.
3. The server watches the dropbox folder using `inotify`. Once copying completes, the archive is ingested, archived to the SD card, and launched live.

---

### 2. Multi-Site Hosting
You can keep multiple sites active simultaneously:
* **Primary Site**: Served at the root `http://<pi-ip>/`.
* **Secondary Sites**: Automatically accessible at sub-paths: `http://<pi-ip>/<site-slug>/`.
* Change primary site or toggle sites on/off anytime from the **Sites & Apps** tab in the web UI.

---

### 3. Databases (MariaDB & SQLite)
* Every deployed site automatically provisions an isolated MariaDB database and user:
  * Database Name: `w_<site_name>`
  * User: `w_<site_name>`
  * Password: Auto-generated secure random key
* Credentials are automatically written into `.wtc-db.php` in the site root:
  ```php
  <?php
  // Auto-injected by WTC Pi
  return [
      'host' => 'localhost',
      'name' => 'w_mysite',
      'user' => 'w_mysite',
      'pass' => 'generated_password',
  ];
  ```
* For framework applications (Laravel, Symfony, etc.), `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` are automatically populated in `.env`.
* View and edit database tables, run custom SQL queries, and export dumps directly in the **Database** tab.

---

### 4. State Persistence ("Save State")
Because sites run on a volatile RAM disk (`tmpfs`), file changes made at runtime will be lost on a hard reboot unless saved back to the SD card archive.

* **On-Demand Save**: Click **Save State** on the Dashboard or run `sudo wtc-manage --save-state`.
* **Selective Persistence (`wtc.json`)**:
  Include a `wtc.json` file in your site root to declare which folders or files to persist:
  ```json
  {
    "persist": [
      "storage/app/public",
      "uploads",
      "database.sqlite"
    ]
  }
  ```
* **Automated Saves**: Configure automatic snapshots on shutdown, on site switch, or on a recurring cron interval via the **Settings** tab.

---

### 5. Custom Per-Site Nginx Configurations
If your application requires custom routing or rewrites, place an `nginx.conf` file in the root of your project:
```nginx
location /custom-api/ {
    try_files $uri $uri/ /api_handler.php?$args;
}
```
During deployment, the configuration is tested with `nginx -t`. If validation passes, it is automatically included in the live vhost; if it fails, the deployment safely rolls back.

---

### 6. Background Workers & Queues
The built-in `wtc-workers` daemon handles background tasks:
* Automatically detects Laravel `artisan queue:work` and scheduler tasks.
* Manage, restart, and inspect worker processes directly from the **Dashboard** and **Settings** tabs.

---

### 7. Applying Updates
To update your server scripts, management UI, and system services to the latest release without re-running initial setup:

```bash
cd wtc-piLab
git pull
sudo ./update.sh
```

---

## 🛠️ CLI Reference (`wtc-manage`)

You can manage the server directly from the command line:

```bash
sudo wtc-manage                  # Open the interactive whiptail menu
sudo wtc-manage --list           # List all available and active sites
sudo wtc-manage --status         # Display RAM disk and system health metrics
sudo wtc-manage --set <site>     # Switch the active primary site
sudo wtc-manage --create <name>  # Create a new skeleton site
sudo wtc-manage --save-state     # Snapshot live RAM state back to SD archive
sudo wtc-manage --rollback <site># Roll back site to the previous version
sudo wtc-manage --reload-ui      # Reload management UI files into RAM
```

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).