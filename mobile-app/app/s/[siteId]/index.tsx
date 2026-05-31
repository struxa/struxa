import { useLocalSearchParams } from 'expo-router';

import { SiteShell } from '../../../src/components/SiteShell';

export default function SiteScreen() {
  const { siteId } = useLocalSearchParams<{ siteId: string }>();
  const id = typeof siteId === 'string' ? siteId : '';

  if (!id) {
    return null;
  }

  return <SiteShell siteId={id} />;
}
