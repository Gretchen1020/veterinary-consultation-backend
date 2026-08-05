CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,

    email VARCHAR(255) NOT NULL UNIQUE,
    pin_hash VARCHAR(255) NOT NULL,
    failed_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    role ENUM('admin', 'doctor', 'patient') NOT NULL,

    status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',/*change in phpmyadmin*/

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE patient_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    contact VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE doctor_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    degree VARCHAR(255),
    specialization VARCHAR(255),
    experience INT,
    about TEXT,
    contact VARCHAR(50),
    profile_photo VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    approval_status ENUM('pending', 'approved', 'rejected')
        NOT NULL DEFAULT 'pending',

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE pets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    breed VARCHAR(100),
    date_of_birth DATE,
    photo_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (patient_id)
        REFERENCES patient_profiles(id)
        ON DELETE CASCADE
);

CREATE TABLE doctor_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    doctor_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255),
    mime_type VARCHAR(100),
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (doctor_id)
        REFERENCES doctor_profiles(id)
        ON DELETE CASCADE
);

CREATE TABLE doctor_approvals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    doctor_id INT NOT NULL,
    admin_id INT NOT NULL,

    decision ENUM('approved', 'rejected') NOT NULL,

    remark TEXT,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (doctor_id)
        REFERENCES doctor_profiles(id)
        ON DELETE CASCADE,

    FOREIGN KEY (admin_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
);

CREATE TABLE doctor_availability (
    id INT PRIMARY KEY AUTO_INCREMENT,

    doctor_id INT NOT NULL UNIQUE,

    is_online BOOLEAN NOT NULL DEFAULT FALSE,

    last_seen_at DATETIME,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (doctor_id)
        REFERENCES doctor_profiles(id)
        ON DELETE CASCADE
);

CREATE TABLE wallet_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    patient_id INT NOT NULL UNIQUE,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (patient_id)
        REFERENCES patient_profiles(id)
        ON DELETE RESTRICT
);

CREATE TABLE wallet_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,

    wallet_id INT NOT NULL,
    transaction_type VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    balance_before DECIMAL(10,2) NOT NULL,
    balance_after DECIMAL(10,2) NOT NULL,
    reference_type VARCHAR(50),
    reference_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_transaction_amount
        CHECK (amount > 0),

    FOREIGN KEY (wallet_id)
        REFERENCES wallet_accounts(id)
        ON DELETE RESTRICT
);

CREATE TABLE admin_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rate_per_minute DECIMAL(10,2) NOT NULL,
    commission_percent DECIMAL(5,2) NOT NULL,
    minimum_balance DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,

    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
);

CREATE TABLE chat_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,

    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    pet_id INT NOT NULL,

    status ENUM('pending', 'accepted', 'rejected') 
    NOT NULL DEFAULT 'pending',

    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME,

    FOREIGN KEY (patient_id)
        REFERENCES patient_profiles(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (doctor_id)
        REFERENCES doctor_profiles(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (pet_id)
        REFERENCES pets(id)
        ON DELETE RESTRICT
);

CREATE TABLE chat_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,

    request_id INT NOT NULL UNIQUE,

    status ENUM('active', 'ended') 
    NOT NULL DEFAULT 'active',

    started_at DATETIME,
    ended_at DATETIME,

    rate_per_minute DECIMAL(10,2) NOT NULL,

    FOREIGN KEY (request_id)
        REFERENCES chat_requests(id)
        ON DELETE RESTRICT
);

CREATE TABLE chat_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,

    session_id INT NOT NULL,
    sender_id INT NOT NULL,

    message_text TEXT NOT NULL,

    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    is_read BOOLEAN NOT NULL DEFAULT FALSE,

    FOREIGN KEY (session_id)
        REFERENCES chat_sessions(id)
        ON DELETE CASCADE,

    FOREIGN KEY (sender_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
);

CREATE TABLE billing_records (
    id INT PRIMARY KEY AUTO_INCREMENT,

    session_id INT NOT NULL UNIQUE,

    duration_seconds INT NOT NULL,

    rate_per_minute DECIMAL(10,2) NOT NULL,

    gross_amount DECIMAL(10,2) NOT NULL,
    commission_amount DECIMAL(10,2) NOT NULL,
    doctor_amount DECIMAL(10,2) NOT NULL,

    billing_status ENUM('pending', 'finalized') 
    NOT NULL DEFAULT 'pending',

    end_reason ENUM('manual', 'auto_low_balance') NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (session_id)
        REFERENCES chat_sessions(id)
        ON DELETE RESTRICT
);

CREATE TABLE doctor_earnings (
    id INT PRIMARY KEY AUTO_INCREMENT,

    doctor_id INT NOT NULL,
    billing_id INT NOT NULL UNIQUE,

    amount DECIMAL(10,2) NOT NULL,

    /*status ENUM('credited') 
    NOT NULL DEFAULT 'credited', Not required*/

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (doctor_id)
        REFERENCES doctor_profiles(id)
        ON DELETE RESTRICT,

    FOREIGN KEY (billing_id)
        REFERENCES billing_records(id)
        ON DELETE RESTRICT
);

CREATE TABLE support_enquiries (
    id INT PRIMARY KEY AUTO_INCREMENT,

    user_id INT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    contact VARCHAR(50),

    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,

    status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);