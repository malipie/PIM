import { jsonFetch } from '@/lib/http';

/**
 * WFL redesign (#2515) — read helpers for the flow-settings page: the
 * built-in ObjectTypes to configure and the role/user directory for the
 * approver picker. Kept out of the page component so the page loads them
 * via useQuery without importing the raw transport (the jsonFetch +
 * useEffect stale-data guard, ADR-0021).
 */

export interface BuiltInObjectType {
  id: string;
  code: string;
  label: string;
}

export interface ApproverDirectory {
  roles: Array<{ id: string; code: string; name: string }>;
  users: Array<{ id: string; email: string; display_name: string }>;
}

interface ObjectTypesResponse {
  member?: Array<{ id: string; code: string; label?: Record<string, string> }>;
  'hydra:member'?: Array<{ id: string; code: string; label?: Record<string, string> }>;
}
interface RolesResponse {
  member?: ApproverDirectory['roles'];
  items?: ApproverDirectory['roles'];
}
interface UsersResponse {
  member?: ApproverDirectory['users'];
  items?: ApproverDirectory['users'];
}

export async function fetchBuiltInObjectTypes(lang: string): Promise<BuiltInObjectType[]> {
  const body = await jsonFetch<ObjectTypesResponse>('/api/object_types?itemsPerPage=200');
  const members = body['hydra:member'] ?? body.member ?? [];
  return members.map((type) => ({
    id: type.id,
    code: type.code,
    label: type.label?.[lang] ?? type.label?.pl ?? type.code,
  }));
}

export async function fetchApproverDirectory(): Promise<ApproverDirectory> {
  const [rolesBody, usersBody] = await Promise.all([
    jsonFetch<RolesResponse>('/api/roles'),
    jsonFetch<UsersResponse>('/api/users?itemsPerPage=500'),
  ]);
  return {
    roles: rolesBody.member ?? rolesBody.items ?? [],
    users: usersBody.member ?? usersBody.items ?? [],
  };
}
