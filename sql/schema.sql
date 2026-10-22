CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  reset_token_hash CHAR(64) NULL,
  reset_expires DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(60) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  price_cents INT NOT NULL,
  trial_days INT NOT NULL DEFAULT 0,
  max_professionals INT NULL,
  max_appointments_month INT NULL,
  max_messages_month INT NOT NULL DEFAULT 0,
  feat_reminders TINYINT(1) NOT NULL DEFAULT 0,
  feat_commissions TINYINT(1) NOT NULL DEFAULT 0,
  highlights TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE shops (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(50) NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  phone VARCHAR(30) NULL,
  address VARCHAR(200) NOT NULL DEFAULT '',
  timezone VARCHAR(60) NOT NULL DEFAULT 'America/Sao_Paulo',
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  slot_grid_minutes INT NOT NULL DEFAULT 15,
  min_notice_minutes INT NOT NULL DEFAULT 60,
  max_days_ahead INT NOT NULL DEFAULT 30,
  allow_any_professional TINYINT(1) NOT NULL DEFAULT 1,
  allow_online_cancel TINYINT(1) NOT NULL DEFAULT 1,
  cancel_min_notice_minutes INT NOT NULL DEFAULT 120,
  reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
  reminder_hours VARCHAR(40) NOT NULL DEFAULT '24,2',
  created_at DATETIME NOT NULL,
  UNIQUE KEY shops_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE shop_members (
  shop_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'owner',
  PRIMARY KEY (shop_id, user_id),
  KEY sm_user (user_id),
  CONSTRAINT sm_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'incomplete',
  price_cents INT NOT NULL,
  trial_ends_at DATETIME NULL,
  current_period_end DATETIME NULL,
  grace_until DATETIME NULL,
  cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  canceled_at DATETIME NULL,
  provider_ref VARCHAR(80) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY sub_shop (shop_id),
  CONSTRAINT sub_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
  CONSTRAINT sub_plan_fk FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE billing_charges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NOT NULL,
  provider_ref VARCHAR(80) NOT NULL,
  status VARCHAR(12) NOT NULL,
  amount_cents INT NOT NULL,
  due_date DATE NULL,
  received_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY bc_ref (provider_ref),
  KEY bc_shop (shop_id),
  CONSTRAINT bc_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE billing_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(120) NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'received',
  error VARCHAR(255) NULL,
  payload MEDIUMTEXT NOT NULL,
  received_at DATETIME NOT NULL,
  UNIQUE KEY be_key (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE professionals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  bio VARCHAR(300) NOT NULL DEFAULT '',
  color VARCHAR(7) NOT NULL DEFAULT '#c8963e',
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  KEY pr_shop (shop_id),
  CONSTRAINT pr_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(300) NOT NULL DEFAULT '',
  price_cents INT NOT NULL,
  duration_min INT NOT NULL,
  buffer_before_min INT NOT NULL DEFAULT 0,
  buffer_after_min INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  bookable_online TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  KEY sv_shop (shop_id),
  CONSTRAINT sv_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE professional_services (
  professional_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (professional_id, service_id),
  CONSTRAINT ps_pro FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE,
  CONSTRAINT ps_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE working_hours (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  professional_id INT UNSIGNED NOT NULL,
  weekday TINYINT NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  KEY wh_pro (professional_id, weekday),
  CONSTRAINT wh_pro_fk FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE schedule_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  professional_id INT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'other',
  reason VARCHAR(200) NOT NULL DEFAULT '',
  KEY sb_shop (shop_id, starts_at),
  CONSTRAINT sb_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  preferences VARCHAR(500) NOT NULL DEFAULT '',
  whatsapp_optin TINYINT(1) NOT NULL DEFAULT 0,
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY cu_phone (shop_id, phone),
  CONSTRAINT cu_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  professional_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  occupied_start DATETIME NOT NULL,
  occupied_end DATETIME NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'confirmed',
  source VARCHAR(10) NOT NULL DEFAULT 'link',
  total_price_cents INT NOT NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  manage_token_hash CHAR(64) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  active_start DATETIME GENERATED ALWAYS AS (IF(status = 'confirmed', occupied_start, NULL)) STORED,
  UNIQUE KEY ap_token (manage_token_hash),
  UNIQUE KEY ap_no_dup (professional_id, active_start),
  KEY ap_shop_time (shop_id, starts_at),
  KEY ap_pro_time (professional_id, occupied_start, occupied_end),
  KEY ap_customer (customer_id),
  CONSTRAINT ap_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE appointment_services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT UNSIGNED NOT NULL,
  position INT NOT NULL,
  service_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  price_cents INT NOT NULL,
  duration_min INT NOT NULL,
  KEY as_appt (appointment_id),
  CONSTRAINT as_appt_fk FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE shop_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  appointment_id INT UNSIGNED NULL,
  professional_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'payment',
  refund_of INT UNSIGNED NULL,
  amount_cents INT NOT NULL,
  method VARCHAR(8) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'received',
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  received_at DATETIME NULL,
  notes VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY sp_shop (shop_id, received_at),
  KEY sp_appt (appointment_id),
  CONSTRAINT sp_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  description VARCHAR(120) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'outros',
  amount_cents INT NOT NULL,
  method VARCHAR(8) NULL,
  paid_on DATE NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ex_shop (shop_id, paid_on),
  CONSTRAINT ex_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE outbox (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  appointment_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  kind VARCHAR(16) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  body VARCHAR(1000) NOT NULL,
  send_at DATETIME NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'queued',
  detail VARCHAR(200) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ob_due (status, send_at),
  KEY ob_shop (shop_id),
  CONSTRAINT ob_shop_fk FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE usage_counters (
  shop_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  metric VARCHAR(20) NOT NULL,
  cnt INT NOT NULL DEFAULT 0,
  PRIMARY KEY (shop_id, period, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NULL,
  actor_user_id INT UNSIGNED NULL,
  actor_kind VARCHAR(16) NOT NULL DEFAULT 'user',
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(30) NULL,
  entity_id VARCHAR(40) NULL,
  detail VARCHAR(300) NULL,
  created_at DATETIME NOT NULL,
  KEY al_shop (shop_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE admin_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shop_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  note VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY an_shop (shop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE rate_limits (
  k VARCHAR(120) NOT NULL PRIMARY KEY,
  window_start DATETIME NOT NULL,
  cnt INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- #
CREATE TABLE meta (
  k VARCHAR(40) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
