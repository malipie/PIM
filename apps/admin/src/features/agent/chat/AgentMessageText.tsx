import { Fragment } from 'react';

/**
 * Tiny, safe subset of Markdown used by model replies. We intentionally do
 * not parse HTML; paired `**` markers become React <strong> nodes and every
 * other character remains escaped by React.
 */
export function AgentMessageText({ text }: { text: string }) {
  const fragments = [];
  const emphasis = /\*\*([\s\S]+?)\*\*/g;
  let cursor = 0;

  for (const match of text.matchAll(emphasis)) {
    const start = match.index;
    if (start > cursor) {
      fragments.push(<Fragment key={`text-${cursor}`}>{text.slice(cursor, start)}</Fragment>);
    }
    fragments.push(<strong key={`strong-${start}`}>{match[1]}</strong>);
    cursor = start + match[0].length;
  }
  if (cursor < text.length) {
    fragments.push(<Fragment key={`text-${cursor}`}>{text.slice(cursor)}</Fragment>);
  }

  return <>{fragments}</>;
}
