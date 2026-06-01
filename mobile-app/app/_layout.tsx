import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { GestureHandlerRootView } from 'react-native-gesture-handler';

import { SitesProvider } from '../src/context/SitesContext';

export default function RootLayout() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SitesProvider>
        <StatusBar style="light" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: '#080e1a' } }}>
          <Stack.Screen name="index" />
          <Stack.Screen name="add-site" />
          <Stack.Screen name="sites/index" />
          <Stack.Screen name="sites/add" options={{ presentation: 'modal' }} />
          <Stack.Screen name="s/[siteId]/index" />
        </Stack>
      </SitesProvider>
    </GestureHandlerRootView>
  );
}
