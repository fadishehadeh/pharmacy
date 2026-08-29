# PharmaSys - Pharmacy Management System

A comprehensive pharmacy management system built for the Lebanese market. Supports dual currency (USD/LBP), MoPH compliance, NSSF/Army/ISF insurance processing, controlled substance tracking, and full inventory-to-sales workflow.

## Features

### Inventory Management (25+ tools)
- Medicine catalog with barcode scanning (camera + manual)
- Batch tracking with FEFO (First Expiry, First Out)
- Categories, shelves & cabinet organization
- Medicine alternatives & substitutes
- Stock movements, transfers, and adjustments
- Full physical stocktake with discrepancy reporting
- Smart reorder levels with auto-calculation
- Barcode label generator (Code128/EAN13)
- CSV bulk import/export
- Price history tracking
- Near-expiry promotional deals
- Expiry calendar and forecast
- Medicine photo gallery
- Waste & disposal management

### Point of Sale
- Fast POS interface with barcode scanning
- Customer management with loyalty program
- Multiple payment methods (cash, card, credit, insurance)
- Dual currency support (USD/LBP with live exchange rate)
- Customizable receipt templates (5 formats)
- Quotation/proforma system
- Enhanced returns processing with store credit
- Delivery scheduling

### Prescriptions
- Prescription management with Rx numbers
- Refill tracking and management
- Drug interaction checking (15+ common interactions)
- Controlled substance logging

### Patient Management
- Patient profiles with medical history
- Chronic condition & allergy tracking
- Medication timeline
- Vaccination record tracking
- Refill reminders
- Loyalty program with tier system (Bronze/Silver/Gold/Platinum)

### Finance
- Cash register with denomination counting (USD & LBP)
- Daily cash summary / end-of-day report
- Profit & loss with period comparison
- Expense tracking by category
- Customer credit/debt management
- Tax reporting (Lebanese VAT/TVA)

### Suppliers
- Supplier management with performance scoring
- Purchase order workflow
- Supplier returns management
- Product catalog with cross-supplier price comparison

### Insurance (Lebanese)
- NSSF, Lebanese Army, ISF, Public Sector providers
- Insurance claim creation and tracking
- Reconciliation with aging report
- Bulk payment processing

### MoPH Compliance
- MoPH price list import (CSV)
- Controlled substance log
- Subsidy tracking
- Compliance checklist with auto-checks
- Pharmacy profile for wall posting

### Reports & Analytics (15+ reports)
- Daily and monthly reports
- Sales analytics with peak hours
- Customer analytics with segmentation
- Supplier analytics with delivery scoring
- ABC inventory analysis (Pareto)
- Margin analysis with alerts
- Inventory valuation
- Inventory movement tracking
- Expiry forecast
- Waste & loss report
- Tax report
- Period comparison
- Printable dashboard summary

### Administration
- Role-based access (Admin, Pharmacist, Cashier, Viewer)
- User management
- Employee shift scheduling
- Audit logging
- Login activity tracking
- Database backup
- Data cleanup utilities
- Notification center

## Requirements

- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- Apache (XAMPP recommended)
- PDO MySQL extension
- Modern web browser

## Installation

1. Copy files to your web server (e.g., `C:\xampp\htdocs\pharmacy\`)
2. Start Apache and MySQL in XAMPP
3. Open `http://localhost/pharmacy/install.php`
4. Enter database credentials and click Install
5. Login with: **admin** / **password**
6. Change the default password immediately

## Configuration

- **Exchange Rate**: Settings > General > USD/LBP Exchange Rate
- **VAT Rate**: Settings > General > VAT Rate (default 11%)
- **Alerts**: Settings > General > Low Stock Threshold & Expiry Alert Days
- **Receipt**: Settings > General > Receipt tab for customization
- **Pharmacy Info**: Settings > Pharmacy Profile

## Tech Stack

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL/MariaDB
- **Frontend**: Bootstrap 5.3.3, Bootstrap Icons 1.11.3
- **Charts**: Chart.js 4.4.3
- **Tables**: DataTables 2.0.8
- **Barcode**: QuaggaJS (scanner), native BarcodeDetector API, PHP SVG generation

## Directory Structure

```
pharmacy/
├── api/              # JSON API endpoints
├── assets/
│   ├── css/          # Custom styles
│   └── js/           # Custom JavaScript
├── config/           # App & database configuration
├── database/         # SQL schema
├── includes/         # Header, footer, sidebar, functions
├── modules/
│   ├── finance/      # Cash register, P&L, expenses, credits
│   ├── insurance/    # Providers, claims, reconciliation
│   ├── interactions/ # Drug interaction checker
│   ├── inventory/    # Medicine management (20+ pages)
│   ├── moph/         # MoPH compliance & price lists
│   ├── notifications/# Alert dashboard & notification center
│   ├── patients/     # Patient profiles, vaccinations, reminders
│   ├── pos/          # Point of sale, receipts, returns
│   ├── prescriptions/# Rx management & refills
│   ├── reports/      # 15+ analytics & reports
│   ├── sales/        # Sales history, quotations, deliveries
│   ├── settings/     # System settings, users, shifts, backup
│   └── suppliers/    # Suppliers, POs, returns, catalog
├── uploads/          # Medicine photos
├── index.php         # Dashboard
├── login.php         # Authentication
├── install.php       # Installation wizard
└── README.md
```

## License

Proprietary - All rights reserved.
