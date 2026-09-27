import React, { useState, useEffect, useRef } from 'react';
import { StyleSheet, Text, View, TextInput, TouchableOpacity, Alert, SafeAreaView, ActivityIndicator } from 'react-native';
import * as Location from 'expo-location';

const BACKEND_URL = 'http://YOUR_BACKEND_IP:3000/api';
const DRIVER_ID = 2; 

export default function DriverDashboard() {
  const [activeJob, setActiveJob] = useState({ id: 101, status: 'picked_up', pickup_address: '123 Warehouse Way', dropoff_address: '789 Residential Blvd', item_type: 'Electronics Box', estimated_fare: '24.50', barcode_id: 'TRACK123' });
  const [otpValue, setOtpValue] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const locationSubscription = useRef(null);

  useEffect(() => {
    let isMounted = true;
    const startTrackingLoop = async () => {
      let { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') return;
      locationSubscription.current = await Location.watchPositionAsync(
        { accuracy: Location.Accuracy.Balanced, timeInterval: 10000, distanceInterval: 10 },
        async (location) => {
          if (!isMounted) return;
          try {
            await fetch(`${BACKEND_URL}/drivers/${DRIVER_ID}/location`, {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ latitude: location.coords.latitude, longitude: location.coords.longitude }),
            });
          } catch (e) {}
        }
      );
    };
    if (activeJob && (activeJob.status === 'accepted' || activeJob.status === 'picked_up')) startTrackingLoop();
    return () => { isMounted = false; if (locationSubscription.current) locationSubscription.current.remove(); };
  }, [activeJob?.status]);

  const handleFinalizeDropoff = async () => {
    setSubmitting(true);
    try {
      const response = await fetch(`${BACKEND_URL}/orders/${activeJob.id}/complete`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ input_otp: otpValue }),
      });
      if (response.status === 200) {
        Alert.alert("Job Finished! 🎉");
        setActiveJob(null);
      } else {
        Alert.alert("Error completing job");
      }
    } catch (err) { Alert.alert("Network Failure"); } finally { setSubmitting(false); }
  };

  return (
    <SafeAreaView style={styles.container}>
      {activeJob && activeJob.status === 'picked_up' ? (
        <View style={styles.card}>
          <Text>Delivering to: {activeJob.dropoff_address}</Text>
          <TextInput style={styles.input} value={otpValue} onChangeText={setOtpValue} keyboardType="number-pad" placeholder="Enter Drop-off OTP" />
          <TouchableOpacity style={styles.btn} onPress={handleFinalizeDropoff}>
            {submitting ? <ActivityIndicator color="#fff" /> : <Text style={{color:'#fff'}}>Complete Run</Text>}
          </TouchableOpacity>
        </View>
      ) : <Text>No active jobs</Text>}
    </SafeAreaView>
  );
}
const styles = StyleSheet.create({ container: { flex: 1, padding: 20 }, card: { padding: 20, backgroundColor: '#fff' }, input: { borderWidth: 1, marginVertical: 10, padding: 10 }, btn: { backgroundColor: 'green', padding: 15, alignItems: 'center' } });
