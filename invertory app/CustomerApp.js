import React, { useState, useEffect } from 'react';
import { StyleSheet, Text, View, TextInput, Button, TouchableOpacity, Alert, SafeAreaView } from 'react-native';
import { CameraView, useCameraPermissions } from 'expo-camera';

export default function CustomerApp() {
  const [permission, requestPermission] = useCameraPermissions();
  const [isScanning, setIsScanning] = useState(false);
  const [pickupAddress, setPickupAddress] = useState('');
  const [dropoffAddress, setDropoffAddress] = useState('');
  const [itemType, setItemType] = useState('');
  const [barcode, setBarcode] = useState('');

  useEffect(() => { if (!permission?.granted) requestPermission(); }, []);

  const handleBarcodeScanned = ({ data }) => {
    setIsScanning(false);
    setBarcode(data);
    Alert.alert("Barcode Scanned", `Tracking ID: ${data}`);
  };

  const handleCreateOrder = async () => {
    if (!pickupAddress || !dropoffAddress || !barcode) {
      Alert.alert("Error", "Please fill in all mandatory fields and scan the parcel barcode.");
      return;
    }
    try {
      const response = await fetch('http://YOUR_BACKEND_IP:3000/api/orders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          customer_id: 1, pickup_address: pickupAddress, dropoff_address: dropoffAddress,
          pickup_lat: 45.4215, pickup_lng: -75.6972, dropoff_lat: 45.4115, dropoff_lng: -75.6872,
          item_type: itemType || 'General Box Parcel', barcode_id: barcode
        }),
      });
      const result = await response.json();
      if (response.status === 201) {
        Alert.alert("Success", `Order Created! OTP: ${result.order.secure_otp}`);
        setPickupAddress(''); setDropoffAddress(''); setItemType(''); setBarcode('');
      } else {
        Alert.alert("Booking Failed", result.error);
      }
    } catch (error) {
      Alert.alert("Network Fault", "Could not link to backend server.");
    }
  };

  if (isScanning) {
    return (
      <View style={styles.scannerContainer}>
        <CameraView style={StyleSheet.absoluteFillObject} barcodeScannerSettings={{ barcodeTypes: ["qr", "ean13", "code128"] }} onBarcodeScanned={handleBarcodeScanned} />
        <View style={styles.overlayMask}>
          <View style={styles.targetFrame} />
          <TouchableOpacity style={styles.cancelButton} onPress={() => setIsScanning(false)}><Text style={{ color: 'white' }}>Cancel</Text></TouchableOpacity>
        </View>
      </View>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <View style={styles.formCard}>
        <Text style={styles.header}>New Delivery Run</Text>
        <TextInput style={styles.input} value={pickupAddress} onChangeText={setPickupAddress} placeholder="Pickup Address" />
        <TextInput style={styles.input} value={dropoffAddress} onChangeText={setDropoffAddress} placeholder="Drop-off Address" />
        <TextInput style={styles.input} value={itemType} onChangeText={setItemType} placeholder="Item Type" />
        <View style={styles.barcodeRow}>
          <TextInput style={[styles.input, { flex: 1, backgroundColor: '#f0f0f0' }]} value={barcode} editable={false} placeholder="Scan Barcode" />
          <Button title="Scan" onPress={() => setIsScanning(true)} />
        </View>
        <Button title="Book Delivery" color="teal" onPress={handleCreateOrder} />
      </View>
    </SafeAreaView>
  );
}
const styles = StyleSheet.create({ container: { flex: 1 }, scannerContainer: { flex: 1 }, formCard: { padding: 24 }, header: { fontSize: 22, fontWeight: 'bold' }, input: { borderWidth: 1, padding: 10, marginBottom: 10 }, barcodeRow: { flexDirection: 'row' }, overlayMask: { ...StyleSheet.absoluteFillObject, justifyContent: 'center', alignItems: 'center' }, targetFrame: { width: 250, height: 150, borderWidth: 2, borderColor: 'cyan' }, cancelButton: { marginTop: 20, backgroundColor: 'red', padding: 10 } });
