-- 1. Create Roles Enum
CREATE TYPE user_role AS ENUM ('customer', 'driver', 'admin');
CREATE TYPE order_status AS ENUM ('pending', 'accepted', 'picked_up', 'delivered', 'cancelled');

-- 2. Users Table
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role user_role DEFAULT 'customer',
    current_latitude NUMERIC(10, 8) DEFAULT NULL,
    current_longitude NUMERIC(11, 8) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Delivery Orders Table
CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES users(id),
    driver_id INT REFERENCES users(id) DEFAULT NULL,
    status order_status DEFAULT 'pending',
    pickup_address TEXT NOT NULL,
    dropoff_address TEXT NOT NULL,
    pickup_latitude NUMERIC(10, 8) NOT NULL,
    pickup_longitude NUMERIC(11, 8) NOT NULL,
    dropoff_latitude NUMERIC(10, 8) NOT NULL,
    dropoff_longitude NUMERIC(11, 8) NOT NULL,
    item_type VARCHAR(100) NOT NULL,
    estimated_fare NUMERIC(10, 2) NOT NULL,
    secure_otp VARCHAR(6) NOT NULL,
    barcode_id VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
