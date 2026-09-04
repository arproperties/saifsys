# Real Estate Accounting System - Design Document

## Executive Summary

This document outlines the design and implementation of a comprehensive double-entry accounting system for the Real Estate company, inspired by Tally (UAE). The system is **strictly isolated** from the Cleaning company's accounting system and uses `company_id` for data segregation.

---

## 1. Current State Analysis

### 1.1 Existing Real Estate Financial Pages

#### **Billing Page** (`billing.php`)
- **Purpose**: Invoice management hub
- **Key Tables**: 
  - `re_invoices` - Invoice headers
  - `re_invoice_items` - Invoice line items
  - `re_billing_items` - Service charges, penalties, other charges
- **Current Functionality**:
  - Create invoices
  - Track invoice status (draft, sent, paid, partial, overdue, cancelled)
  - Track outstanding amounts
  - Link to leases and tenants

#### **Payments Page** (`payments.php`)
- **Purpose**: Payment receipt management
- **Key Tables**:
  - `re_payments` - Payment receipts
  - Links to `re_lease_installments` for rent payments
- **Current Functionality**:
  - Record payments (cash, bank transfer, cheque, auto-debit, cash deposit)
  - Link payments to installments
  - Track partial payments
  - Update installment status

#### **Collections Page** (`collections.php`)
- **Purpose**: Overdue tracking and alerts
- **Key Tables**:
  - `re_lease_installments` - Rent installments
  - `re_billing_items` - Billing items
  - `re_invoices` - Invoices
  - `re_post_dated_cheques` - PDC tracking
- **Current Functionality**:
  - Track overdue installments
  - Track overdue billing items
  - Track overdue invoices
  - Bounced cheque alerts

### 1.2 Key Database Tables

```sql
-- Core Real Estate Tables
re_leases              -- Lease agreements
re_lease_installments  -- Rent payment schedule
re_payments           -- Payment receipts
re_invoices           -- Invoices
re_invoice_items      -- Invoice line items
re_billing_items      -- Service charges, penalties, etc.
re_post_dated_cheques -- PDC tracking
re_tenants            -- Tenant master
re_units              -- Unit master
re_buildings          -- Building master
```

### 1.3 Data Flow Mapping

**Invoice Creation Flow:**
```
re_billing_items → re_invoices → re_invoice_items
```

**Payment Flow:**
```
re_payments → (updates) re_lease_installments.status
```

**Current Gap:**
- No accounting entries are created
- No double-entry posting
- No general ledger
- No financial reports

---

## 2. Accounting System Architecture

### 2.1 Core Principles

1. **Double-Entry Accounting**
   - Every transaction must have equal debits and credits
   - No direct balance updates
   - All entries via journal postings

2. **Company Isolation**
   - All tables include `company_id`
   - Strict filtering by `company_id`
   - No cross-company data access

3. **Integration, Not Replacement**
   - Existing Real Estate pages remain functional
   - They act as **source documents**
   - Accounting engine posts entries automatically

4. **Audit Trail**
   - All entries are immutable (no deletion)
   - Reversals via contra entries
   - Full audit log

### 2.2 Database Schema Design

#### **2.2.1 Chart of Accounts (COA)**

```sql
CREATE TABLE re_chart_of_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    account_code VARCHAR(50) NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    account_type ENUM('Asset', 'Liability', 'Equity', 'Income', 'Expense') NOT NULL,
    parent_id INT NULL,
    normal_balance ENUM('debit', 'credit') NOT NULL,
    is_header TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_code (company_id, account_code),
    KEY idx_company (company_id),
    KEY idx_type (account_type),
    KEY idx_parent (parent_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (parent_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT
);
```

**Account Types:**
- **Assets**: Cash, Bank, Accounts Receivable, Security Deposits, Prepaid Rent
- **Liabilities**: Accounts Payable, Security Deposits Payable, VAT Payable, Accrued Rent
- **Equity**: Capital, Retained Earnings
- **Income**: Rent Income, Service Charge Income, Penalty Income, Other Income
- **Expense**: Maintenance Expense, Utilities, Salaries, Admin Expenses

#### **2.2.2 Journal Headers**

```sql
CREATE TABLE re_journal_headers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    journal_number VARCHAR(50) NOT NULL,
    journal_date DATE NOT NULL,
    journal_type ENUM('manual', 'invoice', 'payment', 'deposit', 'refund', 'adjustment', 'recurring') NOT NULL,
    reference_type VARCHAR(50) NULL, -- 'invoice', 'payment', 'lease', etc.
    reference_id INT NULL, -- ID of source document
    description TEXT,
    total_debit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_posted TINYINT(1) DEFAULT 0,
    posted_at DATETIME NULL,
    posted_by INT NULL,
    is_reversed TINYINT(1) DEFAULT 0,
    reversal_journal_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (posted_by) REFERENCES user(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES user(id) ON DELETE SET NULL,
    UNIQUE KEY uq_journal_number (company_id, journal_number)
);
```

#### **2.2.3 Journal Lines**

```sql
CREATE TABLE re_journal_lines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    journal_id INT NOT NULL,
    account_id INT NOT NULL,
    line_number INT NOT NULL,
    debit_amount DECIMAL(15,2) DEFAULT 0.00,
    credit_amount DECIMAL(15,2) DEFAULT 0.00,
    description VARCHAR(500),
    reference VARCHAR(100), -- Additional reference (invoice #, payment #, etc.)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_id) REFERENCES re_journal_headers(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT,
    KEY idx_journal (journal_id),
    KEY idx_account (account_id),
    KEY idx_company (company_id)
);
```

#### **2.2.4 General Ledger**

```sql
CREATE TABLE re_general_ledger (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    account_id INT NOT NULL,
    journal_id INT NOT NULL,
    journal_line_id INT NOT NULL,
    entry_date DATE NOT NULL,
    debit_amount DECIMAL(15,2) DEFAULT 0.00,
    credit_amount DECIMAL(15,2) DEFAULT 0.00,
    balance DECIMAL(15,2) NOT NULL, -- Running balance
    description VARCHAR(500),
    reference VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (account_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_id) REFERENCES re_journal_headers(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_line_id) REFERENCES re_journal_lines(id) ON DELETE RESTRICT,
    KEY idx_account_date (account_id, entry_date),
    KEY idx_company (company_id),
    KEY idx_journal (journal_id)
);
```

#### **2.2.5 Account Ledgers (Sub-ledgers)**

```sql
CREATE TABLE re_account_ledgers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    account_id INT NOT NULL,
    sub_account_type ENUM('tenant', 'vendor', 'bank', 'cash', 'other') NOT NULL,
    sub_account_id INT NOT NULL, -- tenant_id, vendor_id, bank_account_id, etc.
    sub_account_name VARCHAR(255) NOT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (account_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_account_sub (company_id, account_id, sub_account_type, sub_account_id),
    KEY idx_account (account_id),
    KEY idx_sub (sub_account_type, sub_account_id)
);
```

#### **2.2.6 Account Ledger Entries**

```sql
CREATE TABLE re_account_ledger_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    ledger_id INT NOT NULL,
    journal_id INT NOT NULL,
    journal_line_id INT NOT NULL,
    entry_date DATE NOT NULL,
    debit_amount DECIMAL(15,2) DEFAULT 0.00,
    credit_amount DECIMAL(15,2) DEFAULT 0.00,
    balance DECIMAL(15,2) NOT NULL,
    description VARCHAR(500),
    reference VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (ledger_id) REFERENCES re_account_ledgers(id) ON DELETE CASCADE,
    FOREIGN KEY (journal_id) REFERENCES re_journal_headers(id) ON DELETE RESTRICT,
    FOREIGN KEY (journal_line_id) REFERENCES re_journal_lines(id) ON DELETE RESTRICT,
    KEY idx_ledger_date (ledger_id, entry_date),
    KEY idx_company (company_id)
);
```

#### **2.2.7 Bank Accounts**

```sql
CREATE TABLE re_bank_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    bank_name VARCHAR(255),
    account_number VARCHAR(100),
    iban VARCHAR(50),
    swift_code VARCHAR(20),
    currency VARCHAR(3) DEFAULT 'AED',
    gl_account_id INT NOT NULL, -- Links to Chart of Accounts
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (gl_account_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT,
    KEY idx_company (company_id)
);
```

#### **2.2.8 VAT Configuration**

```sql
CREATE TABLE re_vat_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    vat_rate DECIMAL(5,2) DEFAULT 5.00, -- UAE standard VAT rate
    vat_registration_number VARCHAR(100),
    input_vat_account_id INT NOT NULL, -- Input VAT account
    output_vat_account_id INT NOT NULL, -- Output VAT account
    is_active TINYINT(1) DEFAULT 1,
    effective_from DATE,
    effective_to DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (input_vat_account_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (output_vat_account_id) REFERENCES re_chart_of_accounts(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_company_active (company_id, is_active)
);
```

#### **2.2.9 Financial Periods**

```sql
CREATE TABLE re_financial_periods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    period_name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_closed TINYINT(1) DEFAULT 0,
    closed_at DATETIME NULL,
    closed_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
    FOREIGN KEY (closed_by) REFERENCES user(id) ON DELETE SET NULL,
    KEY idx_company (company_id),
    KEY idx_dates (start_date, end_date)
);
```

---

## 3. Posting Rules & Integration

### 3.1 Invoice Posting (from `re_invoices`)

**When**: Invoice is created/updated in `billing.php`

**Journal Entry:**
```
Dr. Accounts Receivable (Tenant Ledger)    [Invoice Amount]
    Cr. Rent Income                        [Subtotal]
    Cr. Service Charge Income (if applicable)
    Cr. Output VAT                         [VAT Amount]
```

**Implementation:**
- Trigger: After invoice is created/updated
- Function: `post_invoice_to_accounting($invoiceId)`

### 3.2 Payment Posting (from `re_payments`)

**When**: Payment is recorded in `payment_add.php`

**Journal Entry:**
```
Dr. Cash / Bank Account                    [Payment Amount]
    Cr. Accounts Receivable (Tenant)       [Payment Amount]
```

**Implementation:**
- Trigger: After payment is recorded
- Function: `post_payment_to_accounting($paymentId)`

### 3.3 Security Deposit Posting

**When**: Lease is created with security deposit

**Journal Entry:**
```
Dr. Cash / Bank Account                    [Deposit Amount]
    Cr. Security Deposits Payable          [Deposit Amount]
```

**When**: Security deposit is refunded

**Journal Entry:**
```
Dr. Security Deposits Payable              [Refund Amount]
    Cr. Cash / Bank Account                [Refund Amount]
```

### 3.4 Rent Installment Recognition

**When**: Rent installment becomes due (based on `installment_date`)

**Journal Entry:**
```
Dr. Accounts Receivable (Tenant)           [Rent Amount]
    Cr. Accrued Rent Income                [Rent Amount]
```

**When**: Rent is paid (via payment)

**Journal Entry:**
```
Dr. Accrued Rent Income                    [Rent Amount]
    Cr. Rent Income                        [Rent Amount]
```

### 3.5 Maintenance Expense Posting

**When**: Maintenance expense is recorded

**Journal Entry:**
```
Dr. Maintenance Expense                   [Expense Amount]
    Dr. Input VAT (if applicable)          [VAT Amount]
    Cr. Accounts Payable (Vendor)          [Total Amount]
```

### 3.6 Manual Journal Entry

**When**: User creates manual journal entry

**Journal Entry:**
- User-defined debits and credits
- Must balance (debit = credit)
- Requires approval (optional)

---

## 4. Default Chart of Accounts Structure

### Assets (1000-1999)
```
1000 - Current Assets
  1100 - Cash and Cash Equivalents
    1110 - Cash on Hand
    1120 - Petty Cash
  1200 - Bank Accounts
    1210 - Bank Account - ADCB
    1220 - Bank Account - Emirates NBD
    1230 - Bank Account - Other
  1300 - Accounts Receivable
    1310 - Rent Receivable
    1320 - Service Charges Receivable
    1330 - Penalties Receivable
  1400 - Security Deposits Receivable
  1500 - Prepaid Expenses
    1510 - Prepaid Rent
    1520 - Prepaid Insurance
```

### Liabilities (2000-2999)
```
2000 - Current Liabilities
  2100 - Accounts Payable
    2110 - Maintenance Payable
    2120 - Utilities Payable
    2130 - Vendor Payable
  2200 - Security Deposits Payable
  2300 - VAT Payable
    2310 - Output VAT
    2320 - Input VAT (recoverable)
  2400 - Accrued Expenses
    2410 - Accrued Rent Income
```

### Equity (3000-3999)
```
3000 - Equity
  3100 - Capital
  3200 - Retained Earnings
  3300 - Current Year Earnings
```

### Income (4000-4999)
```
4000 - Operating Income
  4100 - Rent Income
    4110 - Residential Rent Income
    4120 - Commercial Rent Income
  4200 - Service Charge Income
  4300 - Penalty Income
  4400 - Other Income
```

### Expenses (5000-5999)
```
5000 - Operating Expenses
  5100 - Maintenance Expenses
    5110 - Building Maintenance
    5120 - Unit Maintenance
  5200 - Utilities
    5210 - Electricity
    5220 - Water
    5230 - Chiller
  5300 - Administrative Expenses
    5310 - Salaries
    5320 - Office Rent
    5330 - Professional Fees
  5400 - Depreciation
```

---

## 5. Integration Points

### 5.1 Billing Page Integration

**File**: `modules/realestate/billing_invoice_create.php`

**Action**: After invoice is saved
```php
// Post invoice to accounting
require_once __DIR__ . '/accounting/accounting_engine.php';
post_invoice_to_accounting($invoiceId, $currentCompanyId);
```

### 5.2 Payment Page Integration

**File**: `modules/realestate/payment_add.php`

**Action**: After payment is saved
```php
// Post payment to accounting
require_once __DIR__ . '/accounting/accounting_engine.php';
post_payment_to_accounting($paymentId, $currentCompanyId);
```

### 5.3 Lease Creation Integration

**File**: `modules/realestate/lease_add.php`

**Action**: After lease is created with security deposit
```php
// Post security deposit to accounting
require_once __DIR__ . '/accounting/accounting_engine.php';
post_security_deposit_to_accounting($leaseId, $currentCompanyId);
```

---

## 6. Financial Reports

### 6.1 Trial Balance
- All accounts with debit/credit balances
- Filter by date range
- Filter by company

### 6.2 Profit & Loss Statement
- Income accounts (Revenue)
- Expense accounts (Expenses)
- Net Profit/Loss
- Period comparison

### 6.3 Balance Sheet
- Assets
- Liabilities
- Equity
- As of specific date

### 6.4 General Ledger
- All transactions for an account
- Running balance
- Filter by date range

### 6.5 Account Ledger (Sub-ledger)
- Tenant ledger (AR)
- Vendor ledger (AP)
- Bank ledger
- Filter by date range

### 6.6 Tenant Statement
- All invoices
- All payments
- Outstanding balance
- Aging analysis

### 6.7 VAT Report
- Output VAT
- Input VAT
- Net VAT Payable
- Period summary

---

## 7. Implementation Phases

### Phase 1: Foundation (Week 1)
- [ ] Create database schema
- [ ] Create default Chart of Accounts
- [ ] Create accounting engine core functions
- [ ] Implement double-entry posting

### Phase 2: Integration (Week 2)
- [ ] Integrate with billing page
- [ ] Integrate with payments page
- [ ] Integrate with lease creation
- [ ] Test posting rules

### Phase 3: Reports (Week 3)
- [ ] Trial Balance
- [ ] General Ledger
- [ ] Account Ledger
- [ ] Tenant Statement

### Phase 4: Advanced Features (Week 4)
- [ ] Profit & Loss
- [ ] Balance Sheet
- [ ] VAT Reports
- [ ] Bank Reconciliation

### Phase 5: Controls & Audit (Week 5)
- [ ] Period locking
- [ ] Financial year close
- [ ] Audit trail
- [ ] Approval workflows

---

## 8. Security & Controls

1. **Access Control**
   - Only authorized users can post entries
   - Read-only access for reports
   - Approval required for manual journals

2. **Data Integrity**
   - All entries must balance
   - No deletion of posted entries
   - Reversals only via contra entries

3. **Audit Trail**
   - All entries logged with user and timestamp
   - Change history maintained
   - Period locking prevents backdating

---

## 9. Testing Strategy

1. **Unit Tests**
   - Double-entry validation
   - Account balance calculations
   - VAT calculations

2. **Integration Tests**
   - Invoice posting
   - Payment posting
   - Deposit posting

3. **Report Tests**
   - Trial balance accuracy
   - P&L accuracy
   - Balance sheet accuracy

---

## 10. Migration Strategy

1. **Data Migration**
   - Export existing invoices
   - Export existing payments
   - Create opening balances

2. **Parallel Run**
   - Run accounting system in parallel
   - Compare results
   - Validate accuracy

3. **Cutover**
   - Switch to accounting system
   - Archive old data
   - Train users

---

## Conclusion

This accounting system provides a comprehensive, Tally-inspired double-entry accounting solution for the Real Estate company, with strict isolation from the Cleaning company's accounting system. The system integrates seamlessly with existing Real Estate financial pages while providing advanced reporting and control capabilities.
