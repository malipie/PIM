import { useQuery } from '@tanstack/react-query';
import { jsonFetch } from '@/lib/http';

/**
 * AICG-P5-02 (#2340) — the recipe catalogue for provenance tooltips
 * ("przepis: <nazwa>") and future recipe pickers. One cached read of
 * /api/content-recipes (AICG-P1-03); transport isolated in this hook
 * per the jsonFetch/useEffect lint guard.
 */
export interface ContentRecipeDto {
  id: string;
  code: string;
  name: string;
  targetAttribute: string;
  builtIn: boolean;
}

export function useContentRecipes(enabled = true) {
  const query = useQuery<{ member?: ContentRecipeDto[]; 'hydra:member'?: ContentRecipeDto[] }>({
    queryKey: ['content-recipes'],
    queryFn: () => jsonFetch('/api/content-recipes'),
    enabled,
    staleTime: 5 * 60_000,
    retry: 1,
  });

  const recipes = query.data?.member ?? query.data?.['hydra:member'] ?? [];
  const nameById = new Map(recipes.map((recipe) => [recipe.id, recipe.name]));

  return { recipes, nameById, isLoading: query.isPending, error: query.error };
}
