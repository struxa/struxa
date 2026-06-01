import type { Ionicons } from '@expo/vector-icons';

export function tabIconName(type: string, active: boolean): keyof typeof Ionicons.glyphMap {
  switch (type) {
    case 'home':
      return active ? 'home' : 'home-outline';
    case 'content':
      return active ? 'layers' : 'layers-outline';
    case 'search':
      return active ? 'search' : 'search-outline';
    case 'shop':
      return active ? 'bag' : 'bag-outline';
    case 'account':
      return active ? 'person' : 'person-outline';
    case 'link':
      return active ? 'link' : 'link-outline';
    case 'plugin':
      return active ? 'apps' : 'apps-outline';
    default:
      return active ? 'ellipse' : 'ellipse-outline';
  }
}
