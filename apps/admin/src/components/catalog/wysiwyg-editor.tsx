import {
  ParagraphPlugin,
  Plate,
  PlateContent,
  PlateElement,
  PlateLeaf,
  usePlateEditor,
} from '@udecode/plate/react';
import { H2Plugin, H3Plugin } from '@udecode/plate-basic-elements/react';
import { BoldPlugin, ItalicPlugin, UnderlinePlugin } from '@udecode/plate-basic-marks/react';
import { LinkPlugin } from '@udecode/plate-link/react';
import DOMPurify from 'dompurify';
import { Bold, Italic, Underline as UnderlineIcon } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface WysiwygEditorProps {
  value: string;
  onChange: (next: string) => void;
  readOnly?: boolean;
  className?: string;
  ariaLabel?: string;
}

type SlateNode = {
  type?: string;
  text?: string;
  bold?: boolean;
  italic?: boolean;
  underline?: boolean;
  url?: string;
  children?: SlateNode[];
};

/**
 * VIEW-07.2 (#423) — Plate-backed rich-text editor used as the
 * renderer for `AttributeType::Wysiwyg`.
 *
 * The backend stores values as plain HTML strings (see
 * `WysiwygValidator`). Plate v49 ships an HTML serializer only via
 * `@udecode/plate-serializer-html@21.x` whose API is incompatible with
 * v49 — so this component uses two thin local helpers
 * (`htmlToSlate` + `slateToHtml`) that cover the ~5 marks/elements we
 * support. Adding more plugins later means widening these helpers, not
 * pulling in a mismatched serializer package.
 *
 * The agent extension (`@udecode/plate-ai`) is a Faza 2 follow-up
 * tied to the agent layer (epik 0.7) — out of scope for VIEW-07.2.
 */
export function WysiwygEditor({
  value,
  onChange,
  readOnly,
  className,
  ariaLabel,
}: WysiwygEditorProps) {
  const initialValue = useMemo(() => htmlToSlate(value), [value]);
  const editor = usePlateEditor({
    plugins: [
      ParagraphPlugin,
      BoldPlugin,
      ItalicPlugin,
      UnderlinePlugin,
      H2Plugin,
      H3Plugin,
      LinkPlugin,
    ],
    value: initialValue as never,
    override: {
      components: {
        bold: (props) => (
          <PlateLeaf {...props} as="strong">
            {props.children}
          </PlateLeaf>
        ),
        italic: (props) => (
          <PlateLeaf {...props} as="em">
            {props.children}
          </PlateLeaf>
        ),
        underline: (props) => (
          <PlateLeaf {...props} as="u">
            {props.children}
          </PlateLeaf>
        ),
        h2: (props) => (
          <PlateElement {...props} as="h2" className="mt-3 text-[16px] font-semibold">
            {props.children}
          </PlateElement>
        ),
        h3: (props) => (
          <PlateElement {...props} as="h3" className="mt-2 text-[14px] font-semibold">
            {props.children}
          </PlateElement>
        ),
        a: (props) => (
          <PlateElement
            {...props}
            as="a"
            attributes={{ ...props.attributes, href: (props.element as { url?: string }).url }}
            className="text-orange-700 underline"
          >
            {props.children}
          </PlateElement>
        ),
      },
    },
  });

  const lastEmittedRef = useRef(value);

  useEffect(() => {
    if (normalizeHtml(value) !== normalizeHtml(lastEmittedRef.current)) {
      lastEmittedRef.current = value;
      editor.tf.setValue(htmlToSlate(value) as never);
    }
  }, [value, editor]);

  if (readOnly) {
    return (
      <div
        // biome-ignore lint/security/noDangerouslySetInnerHtml: sanitised by DOMPurify
        dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(value || '') }}
        className={cn(
          'prose prose-sm max-w-none rounded-xl border border-transparent px-3 py-2 text-[13.5px]',
          className,
        )}
      />
    );
  }

  return (
    <div className={cn('rounded-xl border border-zinc-200 bg-white p-2', className)}>
      <Plate
        editor={editor}
        onChange={({ value: next }) => {
          const html = slateToHtml(next as SlateNode[]);
          // An empty document serialises to `<p></p>`, but an untouched field
          // holds `''`. Comparing the normalised forms stops an empty editor
          // from emitting a spurious change on mount/normalisation — which used
          // to dirty the field with `<p></p>` and mask a freshly-refetched
          // value (e.g. an accepted Ask AI proposal) until a page reload.
          if (normalizeHtml(html) === normalizeHtml(lastEmittedRef.current)) return;
          lastEmittedRef.current = html;
          onChange(html);
        }}
      >
        <Toolbar editor={editor} />
        <PlateContent
          aria-label={ariaLabel}
          className="min-h-[120px] px-2 py-1.5 text-[13.5px] outline-none focus:outline-none"
        />
      </Plate>
    </div>
  );
}

function Toolbar({ editor }: { editor: NonNullable<ReturnType<typeof usePlateEditor>> }) {
  return (
    <div className="mb-1 flex items-center gap-1 border-b border-zinc-100 px-1 pb-1.5">
      <Button
        type="button"
        size="icon"
        variant="ghost"
        className="size-7 rounded-md"
        onMouseDown={(event) => event.preventDefault()}
        onClick={() => editor.tf.toggle.mark({ key: 'bold' })}
        aria-label="Bold"
      >
        <Bold className="size-3.5" />
      </Button>
      <Button
        type="button"
        size="icon"
        variant="ghost"
        className="size-7 rounded-md"
        onMouseDown={(event) => event.preventDefault()}
        onClick={() => editor.tf.toggle.mark({ key: 'italic' })}
        aria-label="Italic"
      >
        <Italic className="size-3.5" />
      </Button>
      <Button
        type="button"
        size="icon"
        variant="ghost"
        className="size-7 rounded-md"
        onMouseDown={(event) => event.preventDefault()}
        onClick={() => editor.tf.toggle.mark({ key: 'underline' })}
        aria-label="Underline"
      >
        <UnderlineIcon className="size-3.5" />
      </Button>
    </div>
  );
}

/**
 * Minimal HTML → Slate value parser covering the elements/marks the
 * editor exposes. Anything else is dropped to plain text — keeps the
 * pipeline lossless for our supported subset and predictable for the
 * unsupported tail.
 */
function htmlToSlate(html: string): SlateNode[] {
  if (typeof window === 'undefined' || !html) {
    return [{ type: 'p', children: [{ text: html ?? '' }] }];
  }
  const sanitised = DOMPurify.sanitize(html);
  const parser = new DOMParser();
  const doc = parser.parseFromString(`<body>${sanitised}</body>`, 'text/html');
  const nodes = walkBlocks(doc.body);
  return nodes.length > 0 ? nodes : [{ type: 'p', children: [{ text: '' }] }];
}

function walkBlocks(parent: Element): SlateNode[] {
  const out: SlateNode[] = [];
  parent.childNodes.forEach((child) => {
    if (child.nodeType === Node.TEXT_NODE) {
      const text = child.textContent ?? '';
      if (text.trim() !== '') {
        out.push({ type: 'p', children: [{ text }] });
      }
      return;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) return;
    const el = child as HTMLElement;
    const tag = el.tagName.toLowerCase();
    if (tag === 'p') {
      out.push({ type: 'p', children: inlineNodes(el) });
    } else if (tag === 'h2') {
      out.push({ type: 'h2', children: inlineNodes(el) });
    } else if (tag === 'h3') {
      out.push({ type: 'h3', children: inlineNodes(el) });
    } else if (tag === 'ul' || tag === 'ol') {
      const items: SlateNode[] = [];
      el.querySelectorAll(':scope > li').forEach((li) => {
        items.push({ type: 'li', children: inlineNodes(li as HTMLElement) });
      });
      out.push({ type: tag, children: items });
    } else {
      out.push({ type: 'p', children: inlineNodes(el) });
    }
  });
  return out;
}

function inlineNodes(parent: HTMLElement): SlateNode[] {
  const out: SlateNode[] = [];
  parent.childNodes.forEach((child) => {
    out.push(...flatten(child, {}));
  });
  if (out.length === 0) out.push({ text: '' });
  return out;
}

function flatten(node: Node, marks: Pick<SlateNode, 'bold' | 'italic' | 'underline'>): SlateNode[] {
  if (node.nodeType === Node.TEXT_NODE) {
    return [{ ...marks, text: node.textContent ?? '' }];
  }
  if (node.nodeType !== Node.ELEMENT_NODE) return [];
  const el = node as HTMLElement;
  const tag = el.tagName.toLowerCase();
  const next: typeof marks = { ...marks };
  if (tag === 'strong' || tag === 'b') next.bold = true;
  if (tag === 'em' || tag === 'i') next.italic = true;
  if (tag === 'u') next.underline = true;
  if (tag === 'a') {
    return [
      {
        type: 'a',
        url: (el as HTMLAnchorElement).getAttribute('href') ?? '',
        children: Array.from(el.childNodes).flatMap((c) => flatten(c, next)),
      },
    ];
  }
  return Array.from(el.childNodes).flatMap((c) => flatten(c, next));
}

function slateToHtml(nodes: SlateNode[]): string {
  return nodes.map(serializeNode).join('');
}

/**
 * Collapse the "empty document" serialisations (`''`, `<p></p>`, or a single
 * whitespace-only paragraph) to `''` so the editor treats them as equal — an
 * untouched field must not report itself as changed.
 */
function normalizeHtml(html: string): string {
  const stripped = html.replace(/<p>(\s|&nbsp;)*<\/p>/gi, '').trim();
  return stripped;
}

function serializeNode(node: SlateNode): string {
  if (typeof node.text === 'string') {
    let out = escapeHtml(node.text);
    if (node.bold) out = `<strong>${out}</strong>`;
    if (node.italic) out = `<em>${out}</em>`;
    if (node.underline) out = `<u>${out}</u>`;
    return out;
  }
  const inner = (node.children ?? []).map(serializeNode).join('');
  switch (node.type) {
    case 'p':
      return `<p>${inner}</p>`;
    case 'h2':
      return `<h2>${inner}</h2>`;
    case 'h3':
      return `<h3>${inner}</h3>`;
    case 'ul':
      return `<ul>${inner}</ul>`;
    case 'ol':
      return `<ol>${inner}</ol>`;
    case 'li':
      return `<li>${inner}</li>`;
    case 'a':
      return `<a href="${escapeAttribute(node.url ?? '')}">${inner}</a>`;
    default:
      return inner;
  }
}

function escapeHtml(text: string): string {
  return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

function escapeAttribute(value: string): string {
  return escapeHtml(value).replaceAll('"', '&quot;');
}
