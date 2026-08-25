import { describe, expect, it } from 'vitest';

import { analyseFlow, isFlowUsable } from '../flow-analysis';
import { FLOW_TEMPLATES, templateById } from '../flow-templates';
import { isCanonicalTransition } from '../flow-vocabulary';

describe('starter templates', () => {
  it.each(FLOW_TEMPLATES.map((template) => template.id))(
    'template %s produces a flow with no errors',
    (id) => {
      const template = templateById(id);
      if (template === undefined) throw new Error(`missing template ${id}`);
      const draft = template.build();
      draft.name = 'Test';

      const findings = analyseFlow(draft);
      expect(isFlowUsable(findings)).toBe(true);
    },
  );

  it('starts every flow at draft and reaches published', () => {
    for (const template of FLOW_TEMPLATES) {
      const draft = template.build();
      const names = draft.places.map((place) => place.name);
      expect(names).toContain('draft');
      expect(names).toContain('published');
    }
  });

  // The regression this guards: automation matches canonical transition
  // names, so a template that renamed them would hand out a review step
  // that creates no task and sends no notification.
  it('keeps built-in steps on canonical names', () => {
    for (const template of FLOW_TEMPLATES) {
      const custom = template
        .build()
        .transitions.filter((transition) => !isCanonicalTransition(transition.name));

      if (template.id === 'approval_then_publish') {
        // The deliberate exception — a separate publishing step cannot be
        // canonical, and the chooser says it carries no task.
        expect(custom.map((transition) => transition.name)).toEqual(['publish_approved']);
      } else {
        expect(custom).toEqual([]);
      }
    }
  });

  it('gates every action on a permission', () => {
    for (const template of FLOW_TEMPLATES) {
      for (const transition of template.build().transitions) {
        expect(transition.permission).not.toBe('');
      }
    }
  });
});
