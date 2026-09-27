const express = require('express');
const { Pool } = require('pg');
const crypto = require('crypto');

const app = express();
app.use(express.json());

const pool = new Pool({
  user: 'your_db_user',
  host: 'localhost',
  database: 'delivery_app',
  password: 'your_db_password',
  port: 5432,
});

// Book delivery order
app.post('/api/orders', async (req, res) => {
  const { customer_id, pickup_address, dropoff_address, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, item_type, barcode_id } = req.body;
  if (!customer_id || !pickup_address || !dropoff_address || !barcode_id) {
    return res.status(400).json({ error: 'Missing mandatory booking details or Barcode ID.' });
  }
  try {
    const baseFare = 5.00;
    const estimatedDistance = Math.abs(pickup_lat - dropoff_lat) + Math.abs(pickup_lng - dropoff_lng);
    const estimatedFare = parseFloat((baseFare + (estimatedDistance * 10)).toFixed(2));
    const secureOtp = crypto.randomInt(1000, 9999).toString();

    const queryText = `
      INSERT INTO orders (customer_id, pickup_address, dropoff_address, pickup_latitude, pickup_longitude, dropoff_latitude, dropoff_longitude, item_type, estimated_fare, secure_otp, barcode_id)
      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11) RETURNING id, status, estimated_fare, secure_otp, barcode_id;
    `;
    const values = [customer_id, pickup_address, dropoff_address, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, item_type || 'General Parcel', estimated_fare, secureOtp, barcode_id];
    const result = await pool.query(queryText, values);
    return res.status(201).json({ message: 'Delivery requested successfully.', order: result.rows[0] });
  } catch (error) {
    console.error(error);
    return res.status(500).json({ error: 'Internal system fault.' });
  }
});

// Fetch pending orders
app.get('/api/orders/pending', async (req, res) => {
  try {
    const unassignedOrders = await pool.query("SELECT id, pickup_address, dropoff_address, item_type, estimated_fare, barcode_id FROM orders WHERE status = 'pending' ORDER BY id DESC");
    return res.status(200).json({ orders: unassignedOrders.rows });
  } catch (error) {
    return res.status(500).json({ error: "Failed to read broadcast pool." });
  }
});

// Driver accepts job
app.put('/api/orders/:id/accept', async (req, res) => {
  const orderId = req.params.id;
  const { driver_id } = req.body;
  try {
    const checkOrder = await pool.query("SELECT status FROM orders WHERE id = $1", [orderId]);
    if (checkOrder.rows.length === 0) return res.status(404).json({ error: "Order not found." });
    if (checkOrder.rows[0].status !== 'pending') return res.status(409).json({ error: "Job already claimed." });

    const assignedRecord = await pool.query("UPDATE orders SET driver_id = $1, status = 'accepted', updated_at = CURRENT_TIMESTAMP WHERE id = $2 RETURNING id, status, pickup_address, dropoff_address, item_type, estimated_fare, barcode_id", [driver_id, orderId]);
    return res.status(200).json({ order: assignedRecord.rows[0] });
  } catch (error) {
    return res.status(500).json({ error: "Internal transaction failure." });
  }
});

// Driver confirms pickup
app.put('/api/orders/:id/pickup', async (req, res) => {
  const orderId = req.params.id;
  try {
    await pool.query("UPDATE orders SET status = 'picked_up', updated_at = CURRENT_TIMESTAMP WHERE id = $1", [orderId]);
    return res.status(200).json({ success: true, message: "Order picked up." });
  } catch (error) {
    return res.status(500).json({ error: "Database mapping update vector drop code fault." });
  }
});

// Update driver telemetry location
app.put('/api/drivers/:id/location', async (req, res) => {
  const driverId = req.params.id;
  const { latitude, longitude } = req.body;
  try {
    await pool.query("UPDATE users SET current_latitude = $1, current_longitude = $2 WHERE id = $3", [latitude, longitude, driverId]);
    return res.status(200).json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: 'Telemetry logging failure.' });
  }
});

// Verify dropoff OTP and complete order
app.put('/api/orders/:id/complete', async (req, res) => {
  const orderId = req.params.id;
  const { input_otp } = req.body;
  try {
    const orderCheck = await pool.query("SELECT secure_otp, status FROM orders WHERE id = $1", [orderId]);
    if (orderCheck.rows.length === 0) return res.status(404).json({ error: 'Order not found.' });
    if (orderCheck.rows[0].status !== 'picked_up') return res.status(400).json({ error: 'Order must be picked up first.' });
    if (orderCheck.rows[0].secure_otp !== input_otp.toString().trim()) return res.status(403).json({ error: 'Invalid OTP.' });

    await pool.query("UPDATE orders SET status = 'delivered', updated_at = CURRENT_TIMESTAMP WHERE id = $1", [orderId]);
    return res.status(200).json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: 'System fault closing transaction.' });
  }
});

app.listen(3000, () => console.log('Listening live on port 3000'));
