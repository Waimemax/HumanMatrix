# Human Matrix

### Medical Equipment Ordering and Delivery Management System

Human Matrix is a web-based medical equipment ordering, inventory management, and delivery management system designed to simplify how healthcare facilities and other approved customers browse medical equipment, place orders, and manage their transactions.

The system also provides an administrative interface for managing products, product categories, inventory, customer orders, and other operational activities.

> **Project Status:** 🚧 Under Active Development

---

## 📖 About the Project

Many medical equipment suppliers still rely on phone calls, physical visits, emails, paperwork, and other manual processes to receive and manage customer orders. These approaches can result in delayed order processing, inaccurate inventory information, poor order tracking, and inefficient communication between suppliers and customers.

Human Matrix aims to provide a centralized web-based platform where customers can access medical equipment information and place orders while administrators manage products, inventory, orders, and related business operations.

The project is being developed as a full-stack web application using PHP, MariaDB, HTML, CSS, and JavaScript.

---

## ✨ Key Features

### Customer Features

- User registration and authentication
- Secure customer login and logout
- Customer dashboard
- Browse available medical equipment
- Search for products
- Filter products by category
- View individual product details
- Add products to a shopping cart
- Update shopping cart quantities
- Checkout and order placement

### Administrator Features

- Role-based administrator access
- Administrative dashboard
- Product category management
- Product management
- Product image management
- Product stock management
- Product activation and deactivation
- Customer and order management *(in development)*
- Delivery status management *(planned)*
- Reports and analytics *(planned)*

---

## 🚧 Development Status

| Module | Status |
|---|---|
| User Registration | ✅ Implemented |
| User Authentication | ✅ Implemented |
| Role-Based Access | ✅ Implemented |
| Admin Dashboard | ✅ Implemented |
| Category Management | ✅ Implemented |
| Product Management | ✅ Implemented |
| Customer Dashboard | ✅ Implemented |
| Product Catalogue | ✅ Implemented |
| Product Search & Filtering | ✅ Implemented |
| Shopping Cart | 🚧 In Development |
| Checkout & Order Placement | 🚧 In Development |
| Order Management | 🚧 In Development |
| Inventory Management | 🚧 In Development |
| Delivery Tracking | 📋 Planned |
| Notifications | 📋 Planned |
| Reports & Analytics | 📋 Planned |

The status table will be updated as development progresses.

---

## 🛠️ Technology Stack

### Frontend

- HTML5
- CSS3
- JavaScript

### Backend

- PHP

### Database

- MariaDB / MySQL

### Development Environment

- XAMPP
- Apache
- phpMyAdmin
- Visual Studio Code
- Git
- GitHub
- Kali Linux

---

## 📁 Project Structure

```text
HumanMatrix/
│
├── admin/
│   ├── categories.php
│   ├── dashboard.php
│   ├── edit_product.php
│   └── products.php
│
├── assets/
│   ├── css/
│   └── images/
│       └── products/
│
├── auth/
│   ├── login.php
│   ├── logout.php
│   └── register.php
│
├── cart/
│   ├── add.php
│   ├── checkout.php
│   ├── order_success.php
│   ├── place_order.php
│   ├── update.php
│   └── view.php
│
├── config/
│   └── database.php
│
├── customer/
│   ├── dashboard.php
│   └── product.php
│
├── database/
│   └── human_matrix.sql
│
├── includes/
│   └── admin_nav.php
│
├── .gitignore
├── index.php
└── README.md
```

---

## ⚙️ Installation and Setup

### Prerequisites

Ensure you have the following installed:

- Apache
- PHP
- MariaDB or MySQL
- XAMPP or another compatible local server environment
- Git

### 1. Clone the Repository

```bash
git clone git@github.com:Waimemax/human-matrix.git
```

Move into the project directory:

```bash
cd human-matrix
```

### 2. Configure the Web Server

When using XAMPP, place the project inside the Apache `htdocs` directory.

For example:

```text
/opt/lampp/htdocs/HumanMatrix
```

### 3. Create the Database

Start Apache and MariaDB/MySQL.

Open phpMyAdmin and create/import the Human Matrix database using:

```text
database/human_matrix.sql
```

### 4. Configure the Database Connection

Check:

```text
config/database.php
```

and configure the database host, database name, username, and password for your local environment.

> Do not commit real production passwords, API keys, access tokens, or other secrets to the repository.

### 5. Run the Application

With Apache and MariaDB running, access the application through your local web server.

For a standard XAMPP configuration:

```text
http://localhost/HumanMatrix/
```

---

## 🔐 Security

Human Matrix incorporates several basic security practices, including:

- Password hashing using PHP's `password_hash()`
- Password verification using `password_verify()`
- Session-based authentication
- Role-based access control
- Input validation
- HTML output escaping where applicable
- Prepared database operations where applicable

Additional security improvements will be introduced as development continues.

---

## 📸 Screenshots

Application screenshots will be added as the user interface continues to develop.

Planned screenshots include:

- Login and registration
- Customer dashboard
- Product catalogue
- Product details
- Shopping cart
- Admin dashboard
- Category management
- Product management

---

## 🗺️ Development Roadmap

The project is being developed incrementally. Upcoming work includes:

1. Complete shopping cart functionality
2. Complete customer checkout and order placement
3. Build administrator order management
4. Improve inventory and stock management
5. Implement delivery tracking
6. Add customer order history
7. Implement system notifications
8. Build administrative reports and analytics
9. Improve responsive design
10. Perform system testing and security improvements
11. Prepare the application for deployment

---

## 🎯 Project Objectives

Human Matrix aims to:

- Automate medical equipment ordering processes
- Improve inventory visibility and management
- Reduce reliance on manual paperwork
- Improve communication between customers and suppliers
- Provide better order and delivery tracking
- Improve administrative efficiency
- Provide useful sales and operational reporting

---

## 🤝 Contributing

This project is currently under active development.

Suggestions and constructive feedback are welcome.

---

## 👨‍💻 Author

**Maxwell Waime**

GitHub: [@Waimemax](https://github.com/Waimemax)

---

## 📄 License

This project currently does not have an open-source license.

Unless a license is added, the source code remains subject to the repository owner's rights.

---

**Human Matrix — Simplifying Medical Equipment Ordering and Management**
