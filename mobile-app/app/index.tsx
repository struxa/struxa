import { Redirect } from 'expo-router';

import { LoadingView } from '../src/components/StatusViews';
import { useSites } from '../src/context/SitesContext';
import { buildSiteTheme } from '../src/theme/siteTheme';

export default function Index() {
  const { ready, sites, activeSiteId } = useSites();
  const theme = buildSiteTheme();

  if (!ready) {
    return <LoadingView label="Starting Struxa…" theme={theme} />;
  }

  if (sites.length === 0) {
    return <Redirect href="/sites/add" />;
  }

  const targetId = activeSiteId && sites.some((site) => site.id === activeSiteId)
    ? activeSiteId
    : sites[0].id;

  return <Redirect href={`/s/${targetId}`} />;
}
