# 🛒 Smart POS & Inventory Management System

A fast, lightweight, and modern **Point of Sale (POS) and Warehouse Inventory Management Web Application** designed for retail shops, grocery stores, and wholesale businesses.

Built with a responsive single-file PHP architecture, instant zero-reload SPA navigation, offline Progressive Web App (PWA) caching, AI-powered demand forecasting, multi-unit inventory tracking, hardware barcode scanning, and raw ESC/POS thermal printing with cash drawer automation.

> **Author:** Arnolfo Reyes Asidoy Jr.  
> **Compatibility:** PHP 8.0+ | PostgreSQL (Supabase / Render) | MySQL / MariaDB (Local XAMPP)

---

## 🚀 Key Features

### 1. 💳 Cashier POS Terminal & Checkout
- **Instant Product Search & Filter**: Real-time barcode and keyword search, category filter pills, and quick-add cards with live store stock indicators.
- **Cart & Fast Payment Hotkeys**: Purpose-built no-mouse keyboard navigation for high-volume cashier throughput:
  - `↑` / `↓`: Navigate line items
  - `←` / `→`: Step quantity down / up
  - `0-9`: Direct quantity input on focused line
  - `Enter`: Instant payment processing & cash tendered input
  - `H`: Hold order / `E`: Recall held carts
  - `C`: Clear cart / `X`: Exit cart modal
- **Discounts & Price Overrides**: On-the-spot cart line item markdown modal.
- **Universal Smart Auto-Print & Thermal Receipts**:
  - **Zero-Click Windows 11 Silent Printing**: Automatically triggers receipt printing immediately after completing checkout without requiring manual user clicks.
  - **Smart Dual-Engine Pipeline**: Probes the local ESC/POS Thermal Print Agent (`pos-print-agent.ps1` on port 9100) and seamlessly cascades to the isolated browser thermal print engine (`executeUniversalReceiptPrint`) if unreachable or on HTTPS origins.
  - **Hardware Drawer Automation**: Triggers the solenoid cash drawer kick pulse automatically via ESC/POS command (`1B 70 00 19 FA`) upon checkout.
  - **One-Click Desktop Launcher**: Run `CREATE-DESKTOP-SHORTCUT.bat` to place a pre-configured Windows 11 desktop shortcut with `--kiosk-printing` and local agent permissions.

### 2. 📷 Barcode Scanning & Hardware Integration
- **Draggable Camera Scanner**: Movable floating camera scanner widget on the dashboard with back/front camera switching and flashlight/torch toggle.
- **Multi-Engine Detection**: Employs native `BarcodeDetector` API with automatic fallbacks to `ZXing-js` and `jsQR` for Code128, EAN-13, EAN-8, UPC-A, UPC-E, and QR codes.
- **Physical Handheld Scanner Support**: Seamless auto-detection for USB and Bluetooth HID barcode scanners without requiring input focus or modal popups.
- **Scan-to-Add**: Scan physical product barcodes directly into the Add/Edit form, featuring automatic product detail lookup via the public Open Food Facts database.
- **Label Generation & Printing**: Built-in Code128 barcode generator with customizable sticker layout presets (5-per-row sheets, 3-in-1 rolls, single labels).

### 3. 📦 Multi-Unit Warehouse & Stock Management
- **Packaging Hierarchy**: Configure base **Pieces (pcs)**, **Bundles/Packs**, and **Cases** with automated conversion between units.
- **Dual Stock Tracking**: Separate balances for **Store Shelf** and **Warehouse Backroom**.
- **FEFO Batch & Lot Tracking**: First-Expired, First-Out lot assignment on supplier deliveries and automatic stock deduction.
- **Stock Operations**: One-click Warehouse-to-Store transfers, Supplier Deliveries, and Pull-Out & Returns logging (damaged goods, customer returns, supplier pull-outs).
- **Daily Alert Banners**: Automated daily warnings for critically low stock and expiring/expired batches right on the Warehouse page.
- **Audit Trails & Excel Export**: Complete stock movement history with instant `.xlsx` spreadsheet export powered by SheetJS.

### 4. 🧠 Analytics & AI Sales Forecasting
- **Machine Learning Sales Forecasting**: Cloud ML-powered demand predictions and reorder suggestions across daily, weekly, and monthly time horizons.
- **Market Basket Analysis**: Co-purchase pair association (Apriori algorithm) revealing customer buying patterns and recommending product combos.
- **Financial Metrics**: Real-time sales revenue, cost of goods, net profit, average order value, and top-selling products.

### 5. 🔒 Shift Lifecycle & Cash Drawer Security
- **"No Count, No Transaction"**: Mandatory opening denomination cash count before cashiers can open the register and ring up sales.
- **Mid-Shift Cash Movements**: Log Cash In and Cash Out with detailed reason notes.
- **Z-Read End of Shift**: Closing denomination count with automated expected cash computation and variance reporting (Cash Over / Short).
- **Shift Monitor**: Owner-only live monitoring dashboard tracking cashier performance, voids, and cash discrepancies.

### 6. ⚙️ Multi-Store & System Customization
- **Multi-Tenant Architecture**: Multi-store isolation with per-store settings, categories, and inventory.
- **Store Branding**: Upload custom shop logos with built-in crop tool, set custom receipt headers and footers, and choose local currency symbols.
- **System Themes**: Toggle between Dark and Light mode themes with automatic persistence.

---

## ⌨️ Cashier Keyboard Shortcuts

Press <kbd>?</kbd> anywhere in the application to display the interactive keyboard shortcuts cheat sheet.

### Global Shortcuts
| Key | Action |
| :--- | :--- |
| <kbd>Space</kbd> / <kbd>F2</kbd> | Open Shopping Cart |
| <kbd>V</kbd> | Open Void Order Modal (Dashboard & Sales) |
| <kbd>N</kbd> | Add New Product (Products page) |
| <kbd>D</kbd> | New Supplier Delivery (Warehouse page) |
| <kbd>?</kbd> | Open Keyboard Shortcuts Cheat Sheet |
| <kbd>Esc</kbd> | Close any active modal |

### Cart Modal Hotkeys
| Key | Action |
| :--- | :--- |
| <kbd>↑</kbd> / <kbd>↓</kbd> | Navigate between cart items |
| <kbd>←</kbd> / <kbd>→</kbd> | Decrease / Increase item quantity |
| <kbd>0</kbd>–<kbd>9</kbd> | Direct quantity input on active line |
| <kbd>Enter</kbd> | Proceed to Payment / Confirm Tendered Cash |
| <kbd>H</kbd> | Hold active cart |
| <kbd>E</kbd> | Open held orders list |
| <kbd>C</kbd> | Clear current cart |
| <kbd>X</kbd> | Exit / Close cart |

---

## 🛠️ Architecture & Tech Stack

- **Backend**: Single-file PHP 8.0+ (`index.php`) featuring a unified REST-like API router, session-hardened authentication, CSRF validation, and rate limiting.
- **Database Layer**: PDO abstraction supporting both **PostgreSQL** (Supabase / Render) and **MySQL / MariaDB** (Local XAMPP / Apache).
- **Automatic Migration**: `installDB()` executes automatically on the initial request, creating all necessary tables, indexes, and seeded records without requiring manual SQL imports.
- **Frontend**: Vanilla JavaScript (ES6+) and modern CSS3 custom properties with zero external UI frameworks for sub-50ms page responsiveness.
- **Single Page Application (SPA)**: Custom lightweight client-side PJAX router (`pageCache`) providing instant, reload-free transitions between views.
- **Offline PWA**: Service Worker (`sw.php` / `sw.js`) implementing a Cache-First strategy for static assets and CDN libraries, accompanied by a Web App Manifest.
- **Peripheral Hardware**: QZ Tray bridge for direct raw ESC/POS thermal printing and solenoid cash drawer kicks.

---

## 💻 Local Development Setup (XAMPP / Windows)

1. **Prerequisites**: Install [XAMPP](https://www.apachefriends.org/) with PHP 8.0+ and MySQL.
2. **Installation**: Place the project folder into your web root:
   ```text
   C:\xampp\htdocs\pos_system\
   ```
3. **Start Servers**: Start Apache and MySQL from the XAMPP Control Panel.
4. **Launch Application**:
   - Double-click `START-POS.bat` or execute `start-local.ps1` in PowerShell.
   - Alternatively, open your browser and navigate to:
     ```text
     http://localhost/pos_system/
     ```
5. **Initial Login**: The database and tables are provisioned automatically on first load:
   - **Username**: `admin`
   - **Password**: `admin123`

---

## ☁️ Cloud Deployment (Render + Supabase)

### 1. Repository Files
Commit the following files to your GitHub repository:
```text
index.php
Dockerfile
render.yaml
.dockerignore
.gitignore
.env.example
README.md
manifest.json
sw.php
sw.js
uploads/shop/.htaccess
uploads/shop/index.html
```

### 2. Supabase Database Configuration
1. Create a project on [Supabase](https://supabase.com/).
2. Navigate to **Project Settings** → **Database** → **Connection string** (URI).
3. Select **Session Pooler** (or Direct connection) and copy the URI. Ensure `sslmode=require` is present at the end:
   ```text
   postgresql://postgres:[YOUR-PASSWORD]@db.[PROJECT-REF].supabase.co:5432/postgres?sslmode=require
   ```
4. *No manual SQL script import is needed.* `index.php` executes `installDB()` automatically on deployment.

### 3. Render Web Service Deployment
1. Connect your repository to [Render](https://render.com/) as a new **Web Service** using Docker.
2. **Persistent Disk (Crucial)**: Add a persistent disk mounted at:
   ```text
   /var/www/html/uploads
   ```
   *This ensures uploaded product photos and custom store logos are retained across restarts and redeployments.*
3. **Environment Variables**:
   | Variable | Description |
   | :--- | :--- |
   | `DATABASE_URL` | Your Supabase PostgreSQL connection URI |
   | `BREVO_API_KEY` | *(Optional)* Brevo API key for password reset emails |
   | `BREVO_SENDER_EMAIL` | *(Optional)* Verified sender email in Brevo |
   | `BREVO_SENDER_NAME` | *(Optional)* Sender name displayed on emails |

---

## 📁 Directory Structure

```text
pos_system/
├── index.php             # Core single-file application (Backend API + Frontend SPA + Templates)
├── sw.php / sw.js        # Service Worker scripts for offline PWA asset caching
├── manifest.json         # Web App Manifest for mobile/desktop PWA installation
├── Dockerfile            # Container configuration for Render/Docker deployment
├── render.yaml           # Infrastructure-as-code configuration for Render
├── START-POS.bat         # Windows one-click local development launcher
├── start-local.ps1       # PowerShell local development startup script
├── .env.example          # Sample environment variables configuration
├── assets/               # Static icons, branding assets, and mirror files
└── uploads/              # Persistent user uploads (product images and shop logos)
    ├── products/         # Product catalog photos
    └── shop/             # Store profile logos
```

---

## 🖨️ Windows 11 Auto-Print & Kiosk Setup (Online & Local)

To enable 100% silent, zero-click thermal printing for receipts on Windows 11 / Windows 10 (Google Chrome & Microsoft Edge):

### Option A: One-Click Desktop Shortcut (Recommended)
1. Double-click `CREATE-DESKTOP-SHORTCUT.bat`.
2. A shortcut named **"ProCast POS - Auto Print"** will be created directly on your Windows Desktop.
3. Double-click this shortcut anytime to launch the POS. It automatically starts the background ESC/POS print agent and opens the POS with `--kiosk-printing` enabled.

### Option B: Direct Batch Launchers
- **Cloud / Online POS**: Run `START-POS-ONLINE.bat` to launch the live Render system (`https://pos-system-9f0n.onrender.com/?page=dashboard`).
- **Local Offline POS**: Run `START-POS-KIOSK.bat` to launch the local XAMPP/Apache system.

### Option C: Installed PWA / Standard Browser Mode
- Receipts automatically invoke the browser thermal print sequence upon payment completion across all Windows devices without blocking transactions or stalling the cashier.

---

## 📄 License & Attribution

Developed by **Arnolfo Reyes Asidoy Jr.**  
Licensed for production use and distribution within commercial POS environments.
