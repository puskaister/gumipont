-- gumipont.hu — MySQL séma (PHP + MySQLi verzió)
-- Importáld ezt a fájlt az adatbázisodba (pl. cPanel phpMyAdmin -> Import,
-- vagy: mysql -u FELHASZNALO -p ADATBAZISNEV < install.sql)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(120) NOT NULL,
  email               VARCHAR(190) NOT NULL UNIQUE,
  password_hash       VARCHAR(255) NOT NULL,
  role                ENUM('user','admin') NOT NULL DEFAULT 'user',
  reset_token_hash    VARCHAR(64) NULL,
  reset_token_expires DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  category    ENUM('tire','rim') NOT NULL DEFAULT 'tire',
  brand       VARCHAR(120) NOT NULL,
  model       VARCHAR(120) NOT NULL,
  width       INT NULL,
  profile     INT NULL,
  rim         VARCHAR(10) NOT NULL,
  hole_count  INT NULL,
  pcd         VARCHAR(10) NULL,
  et          VARCHAR(10) NULL,
  season      ENUM('summer','winter','all-season') NULL,
  vehicle_type ENUM('car','truck') NOT NULL DEFAULT 'car',
  price       DECIMAL(10,2) NOT NULL,
  shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock       INT NOT NULL DEFAULT 0,
  speed       VARCHAR(10) NULL,
  load_index  VARCHAR(10) NULL,
  image       VARCHAR(255) NULL,
  description TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NULL,
  status            ENUM('new','confirmed','shipped','done') NOT NULL DEFAULT 'new',
  delivery_method   ENUM('courier','pickup') NOT NULL,
  payment_method    ENUM('card','transfer','cash') NOT NULL,
  customer_name     VARCHAR(200) NOT NULL DEFAULT '',
  customer_email    VARCHAR(190) NOT NULL DEFAULT '',
  customer_phone    VARCHAR(50) NOT NULL DEFAULT '',
  shipping_address  VARCHAR(500) NOT NULL DEFAULT '',
  subtotal          DECIMAL(10,2) NOT NULL,
  discount          DECIMAL(10,2) NOT NULL DEFAULT 0,
  shipping_cost     DECIMAL(10,2) NOT NULL DEFAULT 0,
  total             DECIMAL(10,2) NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NOT NULL,
  product_id  INT NOT NULL,
  qty         INT NOT NULL,
  unit_price  DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pages (
  id      VARCHAR(100) PRIMARY KEY,
  title   VARCHAR(200) NOT NULL,
  content LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_content (
  `key`   VARCHAR(100) PRIMARY KEY,
  `value` LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Egyszerű, adatbázis-alapú rate limiting a /api/auth/* végpontokhoz
-- (brute force elleni védelem, nem igényel külön szolgáltatást).
CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key       VARCHAR(190) PRIMARY KEY,
  count        INT NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
