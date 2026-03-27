# J&J Grocery POS System 🏪

A modern, production-ready Point of Sale (POS) system built with PHP and MySQL, fully localized for Philippine small grocery stores.

## Features ✨

### Core Functionality
- ✅ **User Authentication** - Secure login with bcrypt password hashing
- ✅ **Role-Based Access Control** - Admin, Manager, and Cashier roles
- ✅ **Product Management** - Complete CRUD with barcode generation and stock tracking
- ✅ **POS Checkout** - Fast, intuitive shopping cart with real-time calculations
- ✅ **User Management** - Add, edit, and manage staff members
- ✅ **Sales Reporting** - Track transactions with detailed receipt generation
- ✅ **Inventory Tracking** - Automatic stock decrement on sales

### Philippine Localization 🇵🇭
- **Currency**: Philippine Peso (₱) - All prices and transactions
- **Taxes**: 12% VAT automatically calculated on all sales
- **Payment Methods**: Cash, GCash (mobile wallet), and Credit/Debit Cards
- **Date Format**: ISO 8601 (Y-m-d) with display format (d/m/yyyy)
- **Timezone**: Asia/Manila
- **Sample Data**: Filipino grocery items and names

### Design & UX
- Modern, clean interface inspired by Apple design principles
- Fully responsive (mobile, tablet, desktop)
- Fast performance with vanilla JavaScript (no heavy frameworks)
- Smooth animations and transitions
- Intuitive navigation and modal dialogs

## Installation 🚀

### Requirements
- PHP 8.0 or higher
- MySQL 8.0 or higher
- Apache/Nginx with mod_rewrite enabled
- Modern web browser

### Step 1: Download & Setup

```bash
# Clone or download the project
cd grocery-pos

# Create a database
mysql -u root -p
CREATE DATABASE grocery_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# Import the schema
mysql -u root -p grocery_pos < database/database.sql
```

### Step 2: Configure Database

Edit `config/constants.php` and update database credentials:

```php
define('DB_HOST', 'localhost');      // Your database host
define('DB_USER', 'root');           // Your database user
define('DB_PASSWORD', '');           // Your database password
define('DB_NAME', 'grocery_pos');    // Database name
```

### Step 3: Set Up Web Server

**For Apache:**
```
DocumentRoot should point to the grocery-pos folder
Enable mod_rewrite for clean URLs
```

**For Nginx:**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Step 4: Start Using

1. Open your browser to `http://localhost/grocery-pos`
2. Login with default credentials:
   - **Admin**: `admin` / `admin123`
   - **Cashier**: `cashier` / `cashier123`
3. Change passwords immediately in your profile

## Folder Structure 📁

```
grocery-pos/
├── config/                    # Configuration & helpers
│   ├── constants.php         # All configuration settings
│   ├── database.php          # Database connection class
│   └── helpers.php           # Utility functions (25+)
│
├── auth/                     # Authentication handlers
│   ├── login.php            # Login form processor
│   └── logout.php           # Logout handler
│
├── pages/                    # Main application pages
│   ├── dashboard.php        # Main dashboard & statistics
│   ├── products.php         # Product inventory management
│   ├── users.php            # Staff user management
│   └── sales.php            # POS checkout system
│
├── templates/               # Reusable HTML components
│   ├── header.php          # HTML head section
│   ├── footer.php          # Script includes
│   └── navbar.php          # Navigation bar
│
├── public/                  # Assets (CSS, JS, images)
│   ├── css/
│   │   └── main.css        # Complete stylesheet
│   ├── js/
│   │   └── main.js         # Core JavaScript
│   └── images/
│       └── (logos & assets)
│
├── database/               # Database schema
│   └── database.sql        # MySQL schema & sample data
│
├── api/                    # AJAX endpoints
│   ├── get-product.php    # Fetch product by ID
│   └── get-user.php       # Fetch user by ID
│
├── index.php              # Login page entry point
└── README.md              # This file
```

## Usage Guide 📖

### For Admin Users

**Dashboard** - Overview of key metrics:
- Total products in inventory
- Sales count from today
- Revenue from today
- Total users

**Products** (`/pages/products.php`)
- Add new products with name, barcode, price, and stock
- Auto-generates barcode if left empty
- Edit existing products (barcode becomes locked after creation)
- Search products by name or barcode
- Delete (soft delete) products
- Track inventory quantities

**Users** (`/pages/users.php`)
- Add new staff members with roles
- Edit user details and roles
- Change user passwords
- Remove inactive users
- Manage admin, manager, and cashier accounts

**Sales** (`/pages/sales.php`)
- View sales history and reports
- Track daily/weekly/monthly revenue
- Export reports as CSV

### For Cashier Users

**Dashboard** - Quick access buttons:
- Start new sale/checkout
- View inventory (read-only)
- Logout

**POS Checkout** (`/pages/sales.php`)
- Search products by name or barcode
- Click/tap products to add to cart
- Adjust quantities with +/- buttons
- Real-time total calculation with automatic VAT (12%)
- Select payment method: Cash, GCash, Card
- Calculate change if paying with cash
- Generate and print receipts
- Stock automatically decrements on checkout

## Database Schema 📊

### Users Table
```sql
- id (Primary Key)
- name (Staff member name)
- username (Login username, unique)
- password (Bcrypt hashed)
- role (admin, manager, cashier)
- active (Boolean, soft delete flag)
- created_at, updated_at
```

### Products Table
```sql
- id (Primary Key)
- name (Product name)
- barcode (Unique identifier)
- description
- price (DECIMAL 10,2) - in ₱
- quantity (Current stock)
- category (Product category)
- active (Boolean, soft delete flag)
- created_at, updated_at
```

### Sales Table
```sql
- id (Primary Key)
- cashier_id (Foreign Key to users)
- subtotal (DECIMAL 10,2) - before tax
- tax_amount (DECIMAL 10,2) - 12% VAT
- total_amount (DECIMAL 10,2) - after tax
- payment_method (cash, gcash, card)
- amount_paid (DECIMAL 10,2)
- change_amount (DECIMAL 10,2)
- created_at
```

### Sale Items Table
```sql
- id (Primary Key)
- sale_id (Foreign Key to sales)
- product_id (Foreign Key to products)
- quantity (Items sold)
- unit_price (Price at time of sale)
- subtotal (quantity × unit_price)
```

## Key Functions 🔧

### Currency Formatting (config/helpers.php)
```php
formatCurrency($amount);  // Returns "₱1,234.56"
```

### VAT Calculation
```php
calculateVAT($subtotal);  // Returns subtotal × 0.12
calculateTotal($subtotal); // Returns subtotal + VAT
```

### Authentication
```php
isLoggedIn();              // Check if user logged in
hasRole('admin');          // Check user role
getCurrentUser();          // Get current user data
setUserSession($user);     // Set user session after login
```

### Date Formatting
```php
formatDate($date);         // Returns "d/m/Y" format
formatDateTime($datetime); // Returns "d/m/Y H:i" format
```

### Utility Functions
```php
sanitize($input);          // Remove XSS and trim whitespace
validateEmail($email);     // Validate email format
generateBarcode();         // Create unique barcode
redirect($url);            // Redirect with headers
```

## Security Features 🔒

- **Password Hashing**: Uses PHP's `password_hash()` with BCRYPT algorithm
- **SQL Injection Prevention**: Prepared statements on all database queries
- **XSS Protection**: `htmlspecialchars()` on all user input display
- **Session Management**: Secure session handling with role checks
- **Input Validation**: Server-side validation on all forms
- **CSRF Protection**: Can be added via additional tokens if needed

## Configuration 🔧

All configuration is centralized in `config/constants.php`:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'grocery_pos');

// Philippine Settings
define('CURRENCY_SYMBOL', '₱');
define('VAT_RATE', 0.12);        // 12% VAT
define('APP_TIMEZONE', 'Asia/Manila');

// Colors (J&J Grocery branding)
define('COLOR_PRIMARY', '#E53935');    // Red
define('COLOR_SECONDARY', '#1E3A8A');  // Blue
define('COLOR_ACCENT', '#FFFAF0');     // Cream

// Payment Methods
$PAYMENT_METHODS = ['cash', 'gcash', 'card'];

// Other
define('APP_NAME', 'J&J Grocery POS');
define('APP_VERSION', '1.0.0');
```

## Troubleshooting 🔧

### White Screen / No Content
- Check PHP error logs: `php_error_log` or `error.log`
- Verify database connection in `config/constants.php`
- Ensure database.sql was imported correctly

### Login Not Working
- Verify database users table has default admin user
- Check password is hashed with bcrypt
- Clear browser cookies/cache

### Products Not Showing in POS
- Verify products table has entries with `active = 1`
- Check database connection
- Ensure sufficient stock quantity

### Slow Performance
- Optimize product search with database indexes
- Clear browser cache
- Check database query performance

## Development Notes 👨‍💻

### Adding New Pages

1. Create `pages/mypage.php`
2. Include at top:
   ```php
   session_start();
   require_once __DIR__ . '/../config/constants.php';
   require_once __DIR__ . '/../config/database.php';
   require_once __DIR__ . '/../config/helpers.php';
   ```
3. Include navbar in HTML:
   ```php
   <?php include __DIR__ . '/../templates/navbar.php'; ?>
   ```
4. Use CSS classes from `public/css/main.css`

### Database Transactions

For multi-step operations, implement transactions:
```php
$db->execute("START TRANSACTION");
try {
    // Multiple operations
    $db->execute(...);
    $db->execute(...);
    $db->execute("COMMIT");
} catch (Exception $e) {
    $db->execute("ROLLBACK");
}
```

### Adding New Helpers

Add functions to `config/helpers.php`:
```php
function myHelper() {
    // Implementation
}
```

## File Paths & Includes

All includes use relative paths with `__DIR__`:
```php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
```

All URLs use BASE_URL constant:
```php
<a href="<?php echo BASE_URL; ?>/pages/dashboard.php">Dashboard</a>
```

## Future Enhancements 🚀

- [ ] More detailed reporting (PDF export)
- [ ] Supplier management module
- [ ] Advanced inventory alerts
- [ ] Multi-branch support
- [ ] Customer loyalty program
- [ ] API for mobile app integration

## Support & Maintenance 💬

For issues or questions:
1. Check the Troubleshooting section
2. Review code comments in relevant files
3. Check database logs for errors
4. Verify all configuration in `config/constants.php`

## License 📜

This project is created for J&J Grocery Store. All rights reserved.

---

**Project Version**: 1.0  
**PHP Version Required**: 8.0+  
**MySQL Version Required**: 8.0+
│   └── auth.php                # Authentication & session class
├── views/
│   ├── login.php               # Login page
│   ├── dashboard.php           # Main dashboard
│   ├── pos.php                 # POS checkout (placeholder)
│   ├── inventory.php           # Inventory management (placeholder)
│   ├── sales.php               # Sales reports (placeholder)
│   ├── users.php               # Users management (placeholder)
│   └── 404.php                 # Error page
├── api/
│   ├── login.php               # Login API endpoint
│   └── logout.php              # Logout API endpoint
├── assets/
│   ├── css/
│   │   └── style.css           # Complete styling (responsive)
│   └── js/
│       └── script.js           # Utility functions & initialization
├── sql/
│   └── schema.sql              # Database schema & sample data
└── SETUP.md                    # Setup instructions

```

## 🚀 Quick Start

### Step 1: Clone & Setup

```bash
# Navigate to your web root (Laragon/XAMPP)
cd C:\laragon\www
# or
cd C:\xampp\htdocs

# Clone or download the project
git clone <repository> grocery-pos
cd grocery-pos
```

### Step 2: Create Database

```bash
# Option A: Using MySQL CLI
mysql -u root -p
CREATE DATABASE grocery_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE grocery_pos;
SOURCE sql/schema.sql;
EXIT;

# Option B: Using phpMyAdmin
# 1. Open http://localhost/phpmyadmin
# 2. Create new database: grocery_pos
# 3. Import sql/schema.sql file
```

### Step 3: Configure (if needed)

Edit `includes/config.php` if your MySQL credentials are different:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Empty for Laragon, your password for XAMPP
define('DB_NAME', 'grocery_pos');
```

### Step 4: Start Application

1. Ensure Laragon/XAMPP is running
2. Open browser: `http://localhost/grocery-pos`
3. Login with demo credentials (see below)

## 🔐 Default Test Credentials

### Admin Account
- **Username**: `admin`
- **Password**: `admin123`
- **Permissions**: Full system access, user management

### Cashier Account
- **Username**: `cashier`
- **Password**: `cashier123`
- **Permissions**: POS, inventory view, sales view

> ⚠️ **Change these passwords in production!**

## 📊 Database Schema

### Users Table
```sql
users (
  user_id INT PRIMARY KEY,
  name VARCHAR(100),
  username VARCHAR(50) UNIQUE,
  password VARCHAR(255) -- bcrypt hashed,
  role ENUM('admin', 'cashier'),
  is_active BOOLEAN,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### Products Table
```sql
products (
  product_id INT PRIMARY KEY,
  name VARCHAR(150),
  barcode VARCHAR(50) UNIQUE,
  price DECIMAL(10, 2),
  stock INT,
  expiry_date DATE,
  category VARCHAR(50),
  is_active BOOLEAN,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### Sales Table
```sql
sales (
  sale_id INT PRIMARY KEY,
  date DATETIME,
  total DECIMAL(12, 2),
  payment_method ENUM('cash', 'card', 'check'),
  amount_paid DECIMAL(12, 2),
  change_amount DECIMAL(12, 2),
  cashier_id INT FOREIGN KEY,
  notes TEXT,
  created_at TIMESTAMP
)
```

### Sale Items Table
```sql
sale_items (
  id INT PRIMARY KEY,
  sale_id INT FOREIGN KEY,
  product_id INT FOREIGN KEY,
  quantity INT,
  unit_price DECIMAL(10, 2),
  total_price DECIMAL(12, 2),
  created_at TIMESTAMP
)
```

## 🔒 Security Features

### Implemented
- ✅ SQL Injection Prevention: Prepared statements with parameterized queries
- ✅ Password Security: bcrypt hashing with salt
- ✅ Session Protection: Validates session against database, 1-hour timeout
- ✅ CSRF Tokens: Ready for implementation in forms
- ✅ Input Sanitization: htmlspecialchars() for output encoding
- ✅ XSS Prevention: Proper output escaping
- ✅ Brute Force Protection: 5 attempts, 15-minute lockout
- ✅ Database: Transactions, foreign keys, character set validation

### Best Practices
- Error logging without exposing sensitive info
- Secure session handling with PHP sessions
- Role-based access control (RBAC)
- Principle of least privilege

## 🎨 Frontend Design

### Features
- **Responsive Design**: Mobile, tablet, desktop
- **Clean UI**: Modern, minimalist design
- **Accessibility**: Semantic HTML, WCAG compliant
- **Color Scheme**:
  - Primary: Blue (#2563eb)
  - Success: Green (#16a34a)
  - Danger: Red (#dc2626)
  - Warning: Orange (#ea580c)

### Built-in Components
- Alerts (danger, success, warning, info)
- Forms with validation UI
- Buttons (primary, secondary, success, danger)
- Tables
- Cards
- Navigation
- Sidebar menu

## 📝 Sample Code Usage

### Database Query
```php
$db = Database::getInstance();

// SELECT one row
$user = $db->selectOne(
    "SELECT * FROM users WHERE user_id = ?",
    [1],
    'i'
);

// SELECT multiple rows
$users = $db->select(
    "SELECT * FROM users WHERE role = ?",
    ['cashier'],
    's'
);

// INSERT/UPDATE/DELETE
$db->execute(
    "INSERT INTO products (name, price, stock) VALUES (?, ?, ?)",
    ['Milk', 85.50, 50],
    'sdi'
);
```

### Authentication
```php
require_once 'includes/auth.php';

// Check if logged in
if ($auth->isLoggedIn()) {
    $user = $auth->getUser();
    echo "Hello " . $user['name'];
}

// Check role
if ($auth->isAdmin()) {
    // Show admin features
}

// Require authentication
Auth::requireLogin();

// Require admin
Auth::requireAdmin();
```

## 🧪 Testing

### Test Scenarios
1. **Login Test**
   - Valid credentials → Dashboard
   - Invalid password → Error message
   - Lockout after 5 attempts → "Too many attempts"

2. **Session Test**
   - Close browser → Session destroyed
   - Inactive for 1 hour → Auto logout
   - Edit user in database → Session invalidated

3. **Security Test**
   - SQL injection in username field → No impact
   - Direct URL access without login → Redirect to login
   - Admin access by cashier → Redirect

## 📱 Browser Support

- Chrome/Chromium 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Mobile browsers (iOS Safari, Chrome Android)

## ⚙️ Configuration Options

### Session
```php
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes
```

### Database
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'grocery_pos');
```

### Timezone
```php
define('TIMEZONE', 'Asia/Manila'); // Change as needed
```

## 🐛 Troubleshooting

### "Database connection failed"
- Check MySQL is running
- Verify credentials in `includes/config.php`
- Ensure database exists: `grocery_pos`

### "Login page shows blank or 500 error"
- Check PHP error logs in `error_log` file
- Verify all files are in correct directories
- Ensure PHP 8.0+ is installed

### "Always redirects to login"
- Clear browser cookies/cache
- Check `session.save_path` is writable
- Verify database has users table

## 📚 API Endpoints

### POST `/api/login.php`
Login user
- **Parameters**: `username`, `password`
- **Response**: Redirect to dashboard or error

### GET `/api/logout.php`
Logout user
- **Response**: Redirect to login page

## 🤝 Contributing

Steps to contribute:
1. Create a new branch for your feature
2. Make changes following code style
3. Test thoroughly
4. Submit pull request with description

## 📄 License

This project is provided as-is for educational and commercial use.

## 📞 Support

For issues or questions:
1. Check the [SETUP.md](SETUP.md) file
2. Review error logs
3. Test with demo credentials first

## 🔄 Version History

- **v1.0.0** (2026-02-05): Initial release with authentication and database setup

---

## 🎯 Next Steps

After confirming Phase 1 is working:
1. **Phase 2**: Build POS Checkout System
2. **Phase 3**: Inventory Management
3. **Phase 4**: Sales Reports
4. **Phase 5**: User Management

Each phase will be built incrementally with testing before proceeding to the next.

**Keep the code clean, maintainable, and production-ready!** 🚀
