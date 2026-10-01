# 📁 ProCast POS — Project Structure & Deployment Guide

This document clarifies the structure of this project, distinguishing between:
1. **The Online Cloud System** (what to upload to your Git repository for Render / Cloud hosting)
2. **The Local Offline System** (what runs locally on your Windows machine with XAMPP)

---

## 🧭 Quick Summary

```
offline_POS-System/
├── Online_Pos_System/          <─── 🚀 UPLOAD THIS TO YOUR GIT REPO (Cloud / Render)
│   ├── assets/                 ├── Static assets (logo, placeholder images, sounds)
│   ├── icons/                  ├── PWA app icons (192px, 512px, maskable)
│   ├── uploads/                ├── User uploads directory skeleton (.htaccess, index.html)
│   ├── .dockerignore           ├── Docker build exclusions
│   ├── .env.example            ├── Template for environment variables (DATABASE_URL, etc.)
│   ├── .gitignore              ├── Git exclusions (ignores .env and uploaded media)
│   ├── .htaccess               ├── Apache routing and security configuration
│   ├── CREATE-DESKTOP-SHORTCUT.bat ├── Windows 11 Desktop shortcut generator
│   ├── Dockerfile              ├── Docker container configuration for Render
│   ├── index.php               ├── Main Cloud Web Application (PostgreSQL / Supabase)
│   ├── manifest.json           ├── PWA install manifest
│   ├── manifest.webmanifest    ├── PWA standard manifest
│   ├── pos-print-agent.ps1     ├── Raw thermal printing agent (ESC/POS port 9100)
│   ├── README.md               ├── System feature documentation
│   ├── render.yaml             ├── Render.com Infrastructure-as-Code Blueprint
│   ├── START-POS-ONLINE.bat    ├── 0-Click silent auto-print desktop launcher
│   ├── start-print-agent-silent.vbs ├── Background launcher for print agent
│   ├── sw.js                   ├── Service Worker for PWA functionality
│   └── sw.php                  ├── Dynamic Service Worker header wrapper
│
└── Offline_Pos_System/ (Root)   <─── 💻 LOCAL SYSTEM (Runs locally on XAMPP)
    ├── assets/                 ├── Static UI assets
    ├── icons/                  ├── PWA app icons
    ├── upload/ & uploads/      ├── Local product image cache from cloud sync
    ├── .env.local              ├── 🔒 PRIVATE local XAMPP database credentials (ignored by git)
    ├── .env.example            ├── Example environment configuration
    ├── .gitignore              ├── Ignores local database credentials & image cache
    ├── .htaccess               ├── Local Apache routing
    ├── CREATE-DESKTOP-SHORTCUT.bat ├── Windows 11 Desktop shortcut generator
    ├── index.php               ├── Offline POS Engine with MySQL/SQLite & Cloud Sync
    ├── pos-print-agent.ps1     ├── Local Thermal Print Agent
    ├── START-POS.bat           ├── Launch local XAMPP POS (http://localhost:8000)
    ├── START-POS-KIOSK.bat     ├── Launch local POS in full-screen silent kiosk mode
    ├── START-POS-ONLINE.bat    ├── Launch cloud POS in 0-click auto-print mode
    ├── start-print-agent-silent.vbs ├── Silent print agent starter
    └── sw.js & sw.php          ├── Local Service Worker
```

---

## 🚀 1. Online System: What to Upload to Git Repository

When you create or push to your Git repository (for example, on GitHub or GitLab) to deploy to **Render**, you should upload the contents of **`Online_Pos_System/`**.

### Exact List of Files for the Online Git Repo:
| File / Directory | Purpose |
|---|---|
| **`index.php`** | The core cloud web application. Connects to PostgreSQL (Supabase/Render), handles authentication, sales, inventory, and thermal printing. |
| **`Dockerfile`** | Tells Render how to build and run the PHP 8.2 + Apache web server container. |
| **`render.yaml`** | Render Blueprint defining the web service, environment variables, and build commands. |
| **`.dockerignore`** | Prevents local cache, git history, and secrets from entering the Docker build image. |
| **`.gitignore`** | Ensures secrets (`.env`, `.env.*`) and uploaded product photos are never committed to Git. |
| **`.htaccess`** | Apache server rules for URL rewriting, security headers, and caching. |
| **`.env.example`** | Safe template showing required environment variables (`DATABASE_URL`, `JWT_SECRET`, etc.). |
| **`assets/`** | Icons, logos, and sounds needed by the user interface. |
| **`icons/`** | Progressive Web App (PWA) icons required for installing on Windows, Android, and iOS. |
| **`manifest.json` & `manifest.webmanifest`** | PWA manifests that enable "Install ProCast POS" on desktop and mobile. |
| **`sw.js` & `sw.php`** | Service Worker scripts that manage offline navigation and fast asset caching. |
| **`uploads/`** | Contains skeleton folders (`products/` and `shop/`) with protective `.htaccess` and `index.html` files. Real images uploaded by users are excluded by `.gitignore`. |
| **`CREATE-DESKTOP-SHORTCUT.bat`** | Windows 11 utility: creates a desktop shortcut configured for 0-click silent thermal printing. |
| **`START-POS-ONLINE.bat`** | Windows launcher that opens the cloud POS in standalone PWA window mode with `--kiosk-printing`. |
| **`pos-print-agent.ps1`** | Thermal print listener running on port 9100 for direct ESC/POS hardware receipt printing. |
| **`start-print-agent-silent.vbs`** | Runs `pos-print-agent.ps1` in the background with no visible console window. |
| **`README.md`** | Complete user guide, keyboard shortcuts list, and deployment documentation. |

### How to Initialize and Push the Online Repo to GitHub:
Open PowerShell or Command Prompt inside `Online_Pos_System`:
```bash
cd "c:\xampp\htdocs\offline_POS-System\pos_system-main\Offline_Pos_System\Online_Pos_System"
git init
git add .
git commit -m "Initial commit: ProCast Online POS with Windows 11 Auto-Print"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
git push -u origin main
```

---

## 💻 2. Local System: How it Works on Local XAMPP

The root directory (`Offline_Pos_System/`) is designed to run directly inside your local **XAMPP environment** (`c:\xampp\htdocs\...`).

### Key Differences in the Local System:
1. **`index.php` (Local / Offline Engine)**:
   - Contains the **Offline Database Engine** (supports local MySQL in XAMPP or SQLite).
   - Features the **Fast Cloud Sync Engine** (`pullCloudProducts`, `pushLocalSalesToCloud`, batch migrations) so cashiers can make sales even when internet is disconnected.
   - Synchronizes sales and stock back to your online cloud database when connection returns.
2. **`.env.local`**:
   - Stores your local database credentials (`DB_HOST=127.0.0.1`, `DB_NAME=pos_system`, `DB_USER=root`, `DB_PASS=`, `CLOUD_DATABASE_URL=...`, `SYNC_TOKEN=...`).
   - This file is automatically excluded by `.gitignore` so your private credentials are never exposed.
3. **`upload/` and `uploads/`**:
   - Stores cached product images downloaded from the cloud during sync operations.
   - Excluded by `.gitignore` to keep repositories lightweight.
4. **Desktop Batch Launchers**:
   - `START-POS.bat` — Launches local POS in standard browser at `http://localhost/offline_POS-System/...`
   - `START-POS-KIOSK.bat` — Launches local POS in 0-click silent kiosk mode.
   - `START-POS-ONLINE.bat` — Launches the deployed cloud POS with 0-click auto-printing.
   - `CREATE-DESKTOP-SHORTCUT.bat` — Generates a desktop shortcut on Windows 11.

---

## 🧹 3. Cleaned Up Files (Completed)

To make your project clean, fast, and clutter-free, the following were deleted:
1. **`scratch/` folder**: Deleted all 19 temporary debug, performance, and benchmark scripts (`apply_optimizations.php`, `check_batches.php`, `check_passwords.php`, `check_scripts.js`, `find_error*.js`, `test_*.php`, etc.).
2. **Planning markdown files**: Deleted `fast_cloud_sync_and_autoconfig_plan.md` and `implementation_plan.md`.
3. **Standardized `.gitignore`**:
   - Configured `.gitignore` in both folders to safely preserve the `uploads/products` and `uploads/shop` folder structures while ignoring user-uploaded media and cached images (`upload/products/*`).
   - Explicitly ignored `.env.local` to protect local credentials.
