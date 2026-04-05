# J&J Grocery POS

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=flat&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?style=flat&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6-F7DF1E?style=flat&logo=javascript&logoColor=black)
![License](https://img.shields.io/badge/License-MIT-green?style=flat)
![Version](https://img.shields.io/badge/Version-1.0.0-blue?style=flat)
![Locale](https://img.shields.io/badge/Locale-Philippines%20%7C%20PHP%20%E2%82%B1-red?style=flat)

A full-featured, vanilla PHP point-of-sale system built for a Philippine grocery store. No framework — pure PHP, MySQL, HTML, CSS, and JavaScript. Philippine-specific: 12% VAT, GCash payments, peso (₱) currency, `Asia/Manila` timezone.

---

## Description

J&J Grocery POS is a complete retail point-of-sale solution designed for small to mid-sized grocery stores in the Philippines. It handles the full sales workflow — from product catalog management and barcode-based checkout to refunds, inventory tracking, sales reporting, and multi-role staff access control.

Built as a no-framework PHP system for easy deployment on any PHP + MySQL server — no Composer, no npm, no build step.

---

## Features

### POS Terminal
- Barcode scan or product name search for fast checkout
- Cart with real-time quantity adjustment (+/−)
- Cash, GCash, and Credit/Debit Card payment methods
- Automatic change calculation for cash payments
- 12% VAT (configurable: inclusive or exclusive)
- Printable receipts with auto-generated receipt numbers
- Hold/resume cart support

### Inventory & Products
- Product catalog with categories, barcodes, and price tiers (retail/wholesale)
- Real-time stock level tracking — auto-decrements on sale
- Soft delete (inactive flag) — no permanent product removal
- Barcode auto-generation if none provided

### Sales & Reporting
- Daily sales dashboard (total revenue, transaction count)
- Sales history with full transaction detail
- Refund management with optimistic locking (prevents double-refund)
- Void transactions (never deleted — full audit trail)
- Sales export and printable reports
- Z-Read / day-close locking

### Manager Portal
- Manager-specific views and controls
- Double-entry accounting journal (auto-generated on sale, void, refund)
- Business analytics and CLV tracking

### User & Role Management
- Four roles: **Admin**, **Manager**, **Cashier**, **Inventory Checker**
- Permission-based access control (not just role-level)
- Per-permission: `dashboard`, `pos`, `products`, `inventory`, `master_data`, `users`, `manager_portal`, `reports`, `settings`, `audit_trail`
- Audit trail with severity levels (`info`, `warning`, `critical`)

### Security
- CSRF token validation on all POST endpoints
- Session-based auth with 8-hour timeout
- Password minimum length enforcement
- Activity logging with old/new value tracking

---

## Tech Stack

| Technology | Role |
|---|---|
| PHP 8.x | Backend / server-side logic |
| MySQL 8.x | Relational database |
| HTML5 / CSS3 | Frontend structure and styling |
| Vanilla JavaScript (ES6) | AJAX, cart logic, UI interactions |
| `mysqli` (PHP extension) | Database driver |

No frameworks. No Composer. No npm. No build tools.

---

## Installation

### Prerequisites

- PHP 8.0+ (`brew install php` on macOS)
- MySQL 8.0+ (`brew install mysql` on macOS)
- Git

### macOS (Homebrew) — Recommended

```bash
# 1. Install PHP and MySQL if not already installed
brew install php mysql

# 2. Start MySQL
brew services start mysql

# 3. Clone the repository
git clone https://github.com/johnchrisley/grocery-pos.git
cd grocery-pos

# 4. Create the database
mysql -u root -p -e "CREATE DATABASE grocery_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 5. Import the schema and all migrations (order matters)
mysql -u root -p grocery_pos < database/database.sql
mysql -u root -p grocery_pos < database/migration_v2.sql
mysql -u root -p grocery_pos < database/migration_v3.sql
mysql -u root -p grocery_pos < database/migration_v4.sql

# 6. Configure database credentials
#    Edit config/constants.php — update DB_HOST, DB_USER, DB_PASS if needed

# 7. Start the PHP dev server
php -S localhost:8000

# 8. Open in browser
open http://localhost:8000
```

### Linux (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install php8.2 php8.2-mysqli mysql-server git

sudo systemctl start mysql
sudo mysql -e "CREATE DATABASE grocery_pos CHARACTER SET utf8mb4;"
sudo mysql -e "CREATE USER 'posuser'@'localhost' IDENTIFIED BY 'yourpassword';"
sudo mysql -e "GRANT ALL ON grocery_pos.* TO 'posuser'@'localhost';"

git clone https://github.com/johnchrisley/grocery-pos.git
cd grocery-pos

mysql -u posuser -p grocery_pos < database/database.sql
mysql -u posuser -p grocery_pos < database/migration_v2.sql
mysql -u posuser -p grocery_pos < database/migration_v3.sql
mysql -u posuser -p grocery_pos < database/migration_v4.sql

# Update config/constants.php with your credentials
php -S localhost:8000
```

---

## Configuration

Edit `config/constants.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // Your MySQL password
define('DB_NAME', 'grocery_pos');
define('DB_PORT', 3306);
```

Key business settings (also configurable via the Settings page in-app):

| Constant | Default | Description |
|---|---|---|
| `VAT_RATE` | `0.12` | 12% Philippine VAT (fallback) |
| `VAT_INCLUSIVE` | `true` | Prices include VAT |
| `APP_TIMEZONE` | `Asia/Manila` | Philippine timezone |
| `CURRENCY_SYMBOL` | `₱` | Philippine Peso |
| `SESSION_TIMEOUT` | `480` | 8 hours (in minutes) |

---

## Default Credentials

> **Change these immediately after first login.**

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `admin123` |
| Cashier | `cashier` | `cashier123` |

---

## Usage

### Keyboard Shortcuts

| Shortcut | Action |
|---|---|
| `Alt + S` | Save (in modals) |
| `Alt + C` | Cancel / close modal |
| `Esc` | Close modal |
| `Enter` | Submit search (in POS) |

### Processing a Sale (Cashier)
1. Go to **Sales / POS**
2. Click product buttons or type name/barcode in search → press `Enter`
3. Adjust quantities with `+`/`−`
4. Select payment method (Cash / GCash / Card)
5. Enter amount received (cash) → change is calculated automatically
6. Click **Complete Sale**
7. Print receipt or start new sale

### Adding Products (Admin)
1. Go to **Products** → **+ Add Product**
2. Enter name, price (₱), quantity, category
3. Leave barcode blank for auto-generation
4. Click **Add Product**

### End of Day (Admin/Manager)
1. Check **Dashboard** for daily revenue and transaction count
2. Run **Z-Read** to close the day (locks further POS sales for that date)
3. Export or print the daily sales report from **Reports**

---

## Screenshots

### Dashboard
![Dashboard](./screenshots/dashboard.png)

### POS Terminal
![POS Terminal](./screenshots/pos.png)

### Product Catalog
![Products](./screenshots/products.png)

### Inventory
![Inventory](./screenshots/inventory.png)

### Sales History
![Sales](./screenshots/sales.png)

### Sales Report
![Reports](./screenshots/reports.png)

### Manager Portal
![Manager Portal](./screenshots/manager.png)

### User Management
![Users](./screenshots/users.png)

### Receipt Preview
![Receipt](./screenshots/receipt.png)

> **Note:** Create a `screenshots/` folder and add images to populate the above.

---

## Folder Structure

```
grocery-pos/
├── index.php                   # Login page / entry point
├── reset-password.php          # Password reset page
├── troubleshoot.php            # DB connection diagnostic
├── QUICK_START.txt             # Quick setup guide
│
├── config/
│   ├── constants.php           # DB credentials, app config, permission keys
│   ├── database.php            # Database class (mysqli wrapper)
│   ├── db.php                  # DB connection bootstrap
│   └── helpers.php             # Auth helpers, VAT calc, receipt number gen, logging
│
├── auth/
│   ├── login.php               # Session creation
│   └── logout.php              # Session destruction
│
├── pages/
│   ├── dashboard.php           # Home dashboard (KPIs, quick actions)
│   ├── pos.php                 # POS terminal / checkout
│   ├── products.php            # Product catalog management
│   ├── inventory.php           # Stock levels and adjustments
│   ├── sales.php               # Sales history
│   ├── sales-report.php        # Detailed sales report
│   ├── sales-export.php        # CSV/print export
│   ├── reports.php             # Analytics and reports
│   ├── manager.php             # Manager portal
│   ├── master-data.php         # Categories and lookup data
│   └── users.php               # User and role management
│
├── api/
│   ├── login.php               # Auth API
│   ├── logout.php              # Logout API
│   ├── get-product.php         # Product lookup (barcode/name)
│   ├── search-product.php      # Product search
│   ├── inventory.php           # Inventory API
│   ├── sales.php               # Sales API (create, void, refund)
│   ├── sales-analytics.php     # Analytics data
│   ├── held-carts.php          # Hold/resume cart
│   ├── refunds.php             # Refund processing
│   ├── roles.php               # Role/permission management
│   ├── master-data.php         # Categories API
│   ├── get-user.php            # User lookup
│   └── settings.php            # Business settings API
│
├── templates/
│   ├── header.php              # HTML head + auth check
│   ├── navbar.php              # Permission-based navigation
│   └── footer.php              # Closing HTML
│
├── database/
│   ├── database.sql            # Initial schema (v1)
│   ├── migration_v2.sql        # Schema additions (v2)
│   ├── migration_v3.sql        # Schema additions (v3)
│   └── migration_v4.sql        # Permission system + business settings (v4)
│
└── public/
    ├── css/
    │   ├── main.css            # Primary stylesheet
    │   └── theme.css           # Color theme (J&J branding)
    ├── js/
    │   └── main.js             # Frontend JavaScript
    └── images/
        ├── logo.jpg
        └── logo-nobg.png
```

---

## Deployment

### Self-Hosted — Nginx + PHP-FPM (Linux)

```bash
sudo apt install nginx php8.2-fpm php8.2-mysqli mysql-server

# Copy project
sudo cp -r grocery-pos /var/www/grocery-pos
sudo chown -R www-data:www-data /var/www/grocery-pos
```

```nginx
# /etc/nginx/sites-available/grocery-pos
server {
    listen 80;
    server_name pos.yourdomain.com;
    root /var/www/grocery-pos;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/grocery-pos /etc/nginx/sites-enabled/
sudo systemctl reload nginx
```

### HTTPS with Let's Encrypt

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d pos.yourdomain.com
```

---

## Future Improvements

- [ ] Barcode scanner hardware integration (USB HID input)
- [ ] Thermal receipt printer support (ESC/POS protocol)
- [ ] Low stock alerts / reorder notifications
- [ ] Supplier and purchase order management
- [ ] Mobile-responsive POS view for tablet use
- [ ] Automated daily database backup
- [ ] Multi-branch / multi-store support
- [ ] BIR (Bureau of Internal Revenue) compliant receipt formatting

---

## Author

**John Chrisley**
- GitHub: [@johnchrisley](https://github.com/johnchrisley)

---

## License

MIT License — see [LICENSE](./LICENSE) for details.
