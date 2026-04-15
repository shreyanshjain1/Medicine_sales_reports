<?php
// Copy this file to config.php and update the values for your environment.

const APP_NAME = 'Medicine Sales CRM';
const COMPANY_NAME = 'Pharmastar';
const APP_ENV = 'production'; // local | staging | production

const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'medicine_sales_crm';

// Example: http://localhost/Medicine_sales_reports-main/public
const BASE_URL = 'http://localhost/Medicine_sales_reports-main/public';

// Change these secrets before production use.
const CSRF_SECRET = 'change-this-csrf-secret';
const SETUP_KEY = 'change-this-setup-key';
const DEV_TOOL_KEY = 'change-this-dev-tool-key';

// Security toggles
const ALLOW_SETUP = false;      // Enable only during first-time installation
const ALLOW_DEV_TOOLS = false;  // Enable only temporarily in local/dev environments

const ATTACH_DIR = __DIR__ . '/uploads/attachments';
const ATTACH_URL = BASE_URL . '/uploads/attachments';
const SIGNATURE_DIR = __DIR__ . '/uploads/signatures';
const SIGNATURE_URL = BASE_URL . '/uploads/signatures';



// Mail delivery
const MAIL_ENABLED = false;                 // true to send real emails
const MAIL_DRIVER = 'log';                  // log | mail | smtp
const MAIL_FROM_EMAIL = 'no-reply@example.com';
const MAIL_FROM_NAME = 'Medicine Sales CRM';
const MAIL_REPLY_TO = 'support@example.com';

// SMTP settings (used when MAIL_DRIVER = 'smtp')
const SMTP_HOST = 'smtp.example.com';
const SMTP_PORT = 587;
const SMTP_SECURE = 'tls';                  // tls | ssl | ''
const SMTP_USERNAME = 'smtp-user@example.com';
const SMTP_PASSWORD = 'change-me';
const SMTP_TIMEOUT = 15;

const BASE_URL_EFFECTIVE = BASE_URL;
