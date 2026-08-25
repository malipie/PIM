import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AgentMessageText } from './AgentMessageText';

describe('AgentMessageText', () => {
  it('renders model emphasis without exposing raw Markdown markers', () => {
    render(<AgentMessageText text={'Czy wybrać **wszystkie produkty**, czy jedną kategorię?'} />);

    expect(screen.getByText('wszystkie produkty').tagName).toBe('STRONG');
    expect(screen.getByText(/Czy wybrać/)).not.toHaveTextContent('**');
  });

  it('keeps HTML-looking model output inert', () => {
    render(<AgentMessageText text={'<script>alert("x")</script> **bezpiecznie**'} />);

    expect(document.querySelector('script')).toBeNull();
    expect(screen.getByText(/<script>/)).toBeInTheDocument();
  });
});
