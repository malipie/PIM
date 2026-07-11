import { useCallback, useEffect, useState } from 'react';

/**
 * GRID-P2-03 (#2391) — row density for the list views. Persisted per
 * ObjectType next to the other quick-prefs
 * (`pim.objectList.{id}.density`); the shape is ready for the
 * SavedView.config round-trip in M4.
 */
export type GridDensity = 'normal' | 'compact';

function storageKey(objectTypeId: string): string {
  return `pim.objectList.${objectTypeId}.density`;
}

function read(objectTypeId: string | undefined): GridDensity {
  if (typeof window === 'undefined' || objectTypeId === undefined) return 'normal';
  return window.localStorage.getItem(storageKey(objectTypeId)) === 'compact' ? 'compact' : 'normal';
}

export function useGridDensity(
  objectTypeId: string | undefined,
): [GridDensity, (next: GridDensity) => void] {
  const [density, setDensityState] = useState<GridDensity>(() => read(objectTypeId));

  useEffect(() => {
    setDensityState(read(objectTypeId));
  }, [objectTypeId]);

  const setDensity = useCallback(
    (next: GridDensity) => {
      setDensityState(next);
      if (typeof window === 'undefined' || objectTypeId === undefined) return;
      try {
        window.localStorage.setItem(storageKey(objectTypeId), next);
      } catch {
        // Quota/private mode — session-only.
      }
    },
    [objectTypeId],
  );

  return [density, setDensity];
}
