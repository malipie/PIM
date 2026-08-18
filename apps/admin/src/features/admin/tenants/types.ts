/**
 * RBAC-P5-019 (#709) — wire shape for `/api/admin/tenants`.
 * Mirrors {@link SuperAdminTenantResponseBuilder} on the API side.
 *
 * **Privacy boundary:** this contract carries metadata only — never
 * per-tenant domain rows. Adding a field that exposes products /
 * attributes / values would breach PRD §11.
 */

/**
 * TNT-P4-03 (#2904) — stany cyklu życia INSTANCJI, nie danych.
 *
 * `pending` / `provisioning` / `failed` opisują instancję, która dopiero
 * powstaje albo nie powstała. Żaden z nich NIE oznacza instancji zdatnej do
 * pracy — tym jest wyłącznie `active`.
 */
export type TenantStatus =
  | 'pending'
  | 'provisioning'
  | 'active'
  | 'failed'
  | 'suspended'
  | 'deleted';

export interface AdminTenantSummary {
  id: string;
  code: string;
  name: string;
  domain: string | null;
  plan: string;
  status: TenantStatus;
  primary_locale: string;
  enabled_locales: string[];
  active_users: number;
  suspended_at: string | null;
  deleted_at: string | null;
  created_at: string;
  /** TNT-P4-05 (#2906) — obecne tylko w odpowiedzi 202 ze ścieżki panelowej. */
  provisioning_job_id?: string;
}

/**
 * TNT-P4-05/06 (#2906/#2907) — postęp zlecenia provisioningu.
 *
 * `queued` oznacza, że provisioner jeszcze nie przejął zlecenia. To NIE jest
 * błąd ani brak zadania, więc panel pokazuje „w kolejce", a nie komunikat
 * o niepowodzeniu.
 */
export type ProvisioningState = 'queued' | 'running' | 'done' | 'failed' | 'rejected';

export interface ProvisioningStep {
  step: string;
  ok: boolean;
  detail?: string;
}

export interface ProvisioningStatus {
  job_id: string;
  state: ProvisioningState;
  action?: string;
  code?: string;
  steps?: ProvisioningStep[];
  exit_code?: number;
  error?: string;
  updated_at?: string;
}
