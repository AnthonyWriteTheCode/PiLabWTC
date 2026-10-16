# WTC Pi RAM-Disk Web Server - Architecture & System Diagram

## 1. System Architecture Diagram

```mermaid
flowchart TB
    subgraph Client["External Access (LAN / Network)"]
        BrowserPort80["🌐 Web Browser (Port 80)\nhttp://wtc-pi.local/ (or http://<pi-ip>/)"]
        BrowserManage["⚙️ Management URL\nhttp://wtc-pi.local:4485/ (or /manage/)"]
        SambaClient["📁 Windows Explorer (SMB)\n\\\\wtc-pi.local\\dropbox"]
        FTPClient["📡 Windows Explorer (FTP)\nftp://wtc-pi.local/"]
        SFTPClient["🔒 SCP / SFTP Client\n/mnt/ramdisk/dropbox"]
    end

    subgraph NginxRouter["Nginx Web Server"]
        VHost80["Port 80 / 8080: Primary Live Web Server\n(Root: /mnt/ramdisk/webroot)"]
        VHostManage["/manage/ Route\n(HTTP 302 Redirect to :4485)"]
        VHost4485["Port 4485: Dedicated Management UI\n(Root: /mnt/ramdisk/ui)"]
    end

    subgraph RAMDisk["⚡ RAM Disk (tmpfs @ /mnt/ramdisk)"]
        LiveSite["/mnt/ramdisk/webroot\nActive Site Unpacked in RAM"]
        PHPTmp["/mnt/ramdisk/php-tmp\nUploads & Sessions in RAM"]
        Dropbox["/mnt/ramdisk/dropbox\nInbox for dropped .tar.gz"]
        Rejected["/mnt/ramdisk/rejected\nRejected uploads + .error logs"]
    end

    subgraph Persistent["💾 Persistent SD Card Storage"]
        SitesRepo["/opt/wtc-web/sites/\n- site-alpha.tar.gz\n- site-beta.tar.gz\n- custom-sites.tar.gz"]
        PrevRepo["/opt/wtc-web/sites/.previous/\nRollback backups"]
        DBStorage["/var/lib/mysql\nMariaDB 10.x Database Engine"]
        ConfigState["/etc/wtc-web/\nactive_site.conf, wtc.conf, db creds"]
    end

    subgraph SystemdDaemons["⚙️ Systemd Background Daemons"]
        RamService["wtc-ramdisk.service\nMounts RAM drive & loads active site on boot"]
        PathWatcher["wtc-dropbox.path\nWatches dropbox via inotify"]
        IngestService["wtc-dropbox.service\nIngests & validates dropped archives"]
        SaveTimer["wtc-save.timer\nPeriodic / shutdown state persistence"]
        PHPServiceSite["php8.x-fpm [wtc-site]\nMulti-worker pool for live sites"]
        PHPServiceUI["php8.x-fpm [wtc-ui]\nSingle-worker (1 thread) for UI"]
        DBService["mariadb.service\nDatabase Server"]
    end

    BrowserPort80 --> VHost80
    BrowserManage --> VHostManage
    BrowserManage --> VHost4485
    VHostManage -->|Redirect :4485| VHost4485
    VHost80 --> LiveSite
    VHost80 --> PHPServiceSite
    VHost4485 --> PHPServiceUI
    PHPServiceSite --> DBService
    PHPServiceUI --> DBService
    DBService --> DBStorage

    SambaClient --> Dropbox
    FTPClient --> Dropbox
    SFTPClient --> Dropbox
    Dropbox --> PathWatcher
    PathWatcher --> IngestService
    IngestService --> SitesRepo
    IngestService --> PrevRepo
    IngestService -.->|Auto-reload if active| LiveSite

    RamService --> LiveSite
    LiveSite -.->|Save State| SitesRepo
```

---

## 2. Component Directory & Function

| Component | Port / Path | Purpose | Storage Layer |
|---|---|---|---|
| **Live Web Server** | `http://<pi>/` (Port 80 & 8080) | Serves the primary active website directly at root (`/mnt/ramdisk/webroot`). | **RAM Disk** (`tmpfs`) |
| **Management Utility** | `http://<pi>:4485/` | Dedicated administration console: swap sites, stack installer, file transfer settings, save state. Runs on single worker process. | **RAM Disk** (`/mnt/ramdisk/ui`, synced from `/opt/wtc-web/ui`) |
| **MariaDB 10.x** | Local Socket / 3306 | Dedicated database engine with automatic per-site user & DB provisioning (`w_<site>`). | **Persistent** (`/var/lib/mysql`) |
| **Dropbox Ingest** | `\\<pi>\dropbox` or `/mnt/ramdisk/dropbox` | Drop any `<site>.tar.gz` to automatically create or update swappable sites. | **RAM Ingest** $\rightarrow$ **SD Storage** |
| **PHP-FPM [wtc-site]** | `/run/php/wtc-site.sock` | Dedicated multi-worker dynamic pool for live web applications. | Process / RAM |
| **PHP-FPM [wtc-ui]** | `/run/php/wtc-ui.sock` | Dedicated single-worker static pool (1 thread) for the management UI. | Process / RAM |
| **Save State** | CLI `--save-state` / UI button | Snapshots live RAM site modifications (uploads, data) back to the SD archive. | RAM $\rightarrow$ SD Archive |
| **Terminal CLI** | `sudo wtc-manage` | Whiptail-driven terminal menu and command-line management tool. | System binary |

---

## 3. Workflow Lifecycle

1. **Boot**: `wtc-ramdisk.service` mounts the `tmpfs` RAM disk before Nginx starts, reads `/etc/wtc-web/active_site.conf`, unpacks the active site archive into `/mnt/ramdisk/www`, provisions its MariaDB credentials, and allows Nginx to start.
2. **Serving**: Nginx serves requests on port 80 directly from volatile RAM disk, resulting in zero SD-card write cycles and maximum read throughput.
3. **Drop Ingest**: Dropping a `.tar.gz` into the dropbox triggers `wtc-dropbox.path`. The archive is validated, normalized, archived to `/opt/wtc-web/sites/` on the SD card, and immediately made available in the Management UI.
4. **Site Swapping**: Swapping sites via the Web UI or `wtc-manage` unpacks the chosen site to a staging directory in RAM, tests the Nginx configuration, atomically swaps the symlink, and reloads Nginx with zero downtime.
5. **Save State**: Any persistent runtime data (uploads, sqlite databases) can be snapshotted back to the persistent SD card storage on demand, on shutdown, or on a scheduled timer.
