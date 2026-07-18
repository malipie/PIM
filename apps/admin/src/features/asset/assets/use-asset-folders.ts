import { useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

export interface FolderEntry {
  code: string;
  displayName: string;
  assetCount: number;
}

export interface FoldersResponse {
  member: FolderEntry[];
  totalItems: number;
}

/**
 * #2621 — folder list shared through the React Query cache so a remount of
 * the Multimedia page renders folders and files in the same frame. The local
 * useState + useEffect fetch this replaces started from an empty list on
 * every mount, so on warm re-entry the cached asset cards rendered first and
 * the arriving folder tiles pushed them below the fold (the "files flash,
 * then slide away" flicker). staleTime stays 0 so counts revalidate in the
 * background; retry is 1 so a failing endpoint does not hold the page in its
 * loading skeleton through the default three retries.
 */
export function useAssetFolders() {
  return useQuery({
    queryKey: ['asset-folders'],
    queryFn: () => jsonFetch<FoldersResponse>('/api/asset-folders', { accept: 'application/json' }),
    retry: 1,
  });
}
