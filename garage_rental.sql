CREATE DATABASE IF NOT EXISTS garage_rental
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE garage_rental;


CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL COMMENT 'admin or tenant',

  -- A user is either an admin or a tenant.
  CONSTRAINT chk_users_role
    CHECK (role IN ('admin', 'tenant'))
);

CREATE TABLE tenants (
  user_id INT PRIMARY KEY COMMENT '1:1 with users',
  nik VARCHAR(16) UNIQUE KEY NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  registration_status VARCHAR(10) NOT NULL COMMENT 'pending, accepted, rejected',

  -- Registration status is pending, accepted or rejected.
  CONSTRAINT chk_tenants_registration_status
    CHECK (registration_status IN ('pending', 'accepted', 'rejected')),

  CONSTRAINT fk_tenants_user
    FOREIGN KEY (user_id) REFERENCES users (id)
);

CREATE TABLE garage_types (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(30) UNIQUE KEY NOT NULL COMMENT 'bicycle, car, electric car'
);

CREATE TABLE garages (
  id VARCHAR(4) PRIMARY KEY COMMENT 'S001, C010, E005',
  garage_type_id INT NOT NULL,
  daily_price DECIMAL(12,2) NOT NULL,
  kwh_price DECIMAL(12,2) NULL COMMENT 'only electric garages',
  status VARCHAR(10) NOT NULL COMMENT 'available or used',

  -- A garage is either available or used.
  CONSTRAINT chk_garages_status
    CHECK (status IN ('available', 'used')),

  CONSTRAINT fk_garages_garage_type
    FOREIGN KEY (garage_type_id) REFERENCES garage_types (id)
);

CREATE TABLE vehicles (
  id INT PRIMARY KEY AUTO_INCREMENT,
  vehicle_type VARCHAR(15) NOT NULL COMMENT 'bicycle, motorbike, car, electric_car',
  plate_number VARCHAR(15) UNIQUE KEY NULL COMMENT 'empty for bicycles',
  brand VARCHAR(50) NOT NULL,
  model VARCHAR(50) NOT NULL,
  battery_kwh DECIMAL(6,2) NULL COMMENT 'electric cars only',

  -- Vehicle type is bicycle, motorbike, car (petrol) or electric_car.
  CONSTRAINT chk_vehicles_type
    CHECK (vehicle_type IN ('bicycle', 'motorbike', 'car', 'electric_car')),

  -- A bicycle has no plate number; every other vehicle must have a non-blank one.
  -- TRIM stops a plate made only of spaces.
  CONSTRAINT chk_vehicles_plate_number
    CHECK (
      (vehicle_type = 'bicycle' AND plate_number IS NULL)
      OR
      (vehicle_type <> 'bicycle' AND plate_number IS NOT NULL AND TRIM(plate_number) <> '')
    ),

  -- An electric car must have a battery capacity above 0; other vehicles have none.
  CONSTRAINT chk_vehicles_battery
    CHECK (
      (vehicle_type = 'electric_car' AND battery_kwh IS NOT NULL AND battery_kwh > 0)
      OR
      (vehicle_type <> 'electric_car' AND battery_kwh IS NULL)
    )
);

CREATE TABLE rentals (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tenant_id INT NOT NULL,
  garage_id VARCHAR(4) NOT NULL,
  vehicle_id INT NOT NULL,
  rental_type VARCHAR(10) NOT NULL COMMENT 'daily or monthly',
  days INT NULL COMMENT 'daily only, 1 to (days in start month - 1)',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  daily_price_used DECIMAL(12,2) NOT NULL COMMENT 'copy of price at rental time',
  charging_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL,
  payment_proof_path VARCHAR(255) NOT NULL,

  -- Rental type is daily or monthly.
  CONSTRAINT chk_rentals_type
    CHECK (rental_type IN ('daily', 'monthly')),

  -- Days is empty, or from 1 to 30 (the longest month has 31 days, and the limit is days - 1).
  -- The exact limit per month is checked in RentalRules::validate().
  CONSTRAINT chk_rentals_days
    CHECK (days IS NULL OR (days > 0 AND days <= 30)),

  -- The end date cannot be before the start date.
  CONSTRAINT chk_rentals_end_date
    CHECK (end_date >= start_date),

  -- A daily rental has a number of days; a monthly rental has none.
  CONSTRAINT chk_rentals_days_type
    CHECK (
      (rental_type = 'daily' AND days IS NOT NULL)
      OR
      (rental_type = 'monthly' AND days IS NULL)
    ),

  CONSTRAINT fk_rentals_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (user_id),

  CONSTRAINT fk_rentals_garage
    FOREIGN KEY (garage_id) REFERENCES garages (id),

  CONSTRAINT fk_rentals_vehicle
    FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
);

CREATE TABLE rental_extensions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  rental_id INT NOT NULL,
  old_end_date DATE NOT NULL,
  new_end_date DATE NOT NULL,
  extra_amount DECIMAL(12,2) NOT NULL,
  payment_proof_path VARCHAR(255) NOT NULL,
  admin_id INT NOT NULL,

  -- An extension must move the end date later (no shortening).
  CONSTRAINT chk_rental_extensions_end_date
    CHECK (new_end_date > old_end_date),

  CONSTRAINT fk_rental_extensions_rental
    FOREIGN KEY (rental_id) REFERENCES rentals (id),

  CONSTRAINT fk_rental_extensions_admin
    FOREIGN KEY (admin_id) REFERENCES users (id)
);

-- The three garage types (their names must match the Vehicle::GARAGE_* constants).
INSERT INTO garage_types (id, name)
VALUES
  (1, 'bicycle'),
  (2, 'car'),
  (3, 'electric car');

-- The first admin account. The hash was made with PHP password_hash().
INSERT INTO users (email, password_hash, role)
VALUES (
  'admin@example.com',
  '$2y$12$mzI/8.jbN0ALmtAc890RieQSEPPdZmq0Hq5GZguTVC1zUa1rmpgnC',
  'admin'
);