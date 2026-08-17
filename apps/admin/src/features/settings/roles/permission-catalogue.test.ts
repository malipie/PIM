import { describe, expect, it } from 'vitest';

import { visibleGroups } from './permission-catalogue';

/**
 * #2881 — the role builder offers the PRD catalogue only.
 *
 * The risk this pins is not "does it hide things" but the two ways hiding
 * goes wrong: hiding a PRD code the operator needs, and hiding a legacy
 * code a role already holds — the second silently strips grants on save,
 * because the editor sends back exactly what it rendered.
 */
describe('visibleGroups', () => {
  const groups = [
    {
      module: 'products',
      permissions: [
        { code: 'products.view', catalogue: 'prd' as const },
        { code: 'products.add', catalogue: 'prd' as const },
      ],
    },
    {
      module: 'object',
      permissions: [
        // The ULV verbs are PRD codes even though `object` is also a legacy
        // resource name — this is the pair that cannot be told apart by
        // reading the code, and the one an operator needs to grant a custom
        // module.
        { code: 'object.view', catalogue: 'prd' as const },
        { code: 'object.read', catalogue: 'legacy' as const },
        { code: 'object.write', catalogue: 'legacy' as const },
      ],
    },
    {
      module: 'integration',
      permissions: [
        { code: 'integration.read', catalogue: 'legacy' as const },
        { code: 'integration.write', catalogue: 'legacy' as const },
      ],
    },
  ];

  it('keeps the PRD codes and drops the legacy ones', () => {
    const visible = visibleGroups(groups, new Set<string>());

    expect(visible.map((g) => g.module)).toEqual(['products', 'object']);
    expect(visible[1]?.permissions.map((p) => p.code)).toEqual(['object.view']);
  });

  it('drops a group that is entirely legacy', () => {
    const visible = visibleGroups(groups, new Set<string>());

    expect(visible.map((g) => g.module)).not.toContain('integration');
  });

  it('still shows a legacy code the role already holds', () => {
    // Otherwise the editor would render a set that omits the grant and
    // save that set back, removing a permission nobody asked to remove.
    const visible = visibleGroups(groups, new Set(['integration.read']));

    const integration = visible.find((g) => g.module === 'integration');
    expect(integration?.permissions.map((p) => p.code)).toEqual(['integration.read']);
  });

  it('leaves an unmarked catalogue alone', () => {
    // An older backend does not send the field; behaving as before beats
    // hiding everything.
    const legacyShaped = [{ module: 'user', permissions: [{ code: 'user.admin' }] }];

    expect(visibleGroups(legacyShaped, new Set<string>())).toHaveLength(1);
  });
});
