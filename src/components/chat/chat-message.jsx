import { memo, useEffect, useRef, useState } from 'react'
import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { AnimatePresence, motion } from 'motion/react'
import {
  Check,
  Copy,
  FileSpreadsheet,
  Loader2,
  Pencil,
  TriangleAlert,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { NovaMark } from '@/components/nova-mark'
import { downloadCsv, readTable, toTsv } from '@/lib/table-export'
import { cn } from '@/lib/utils'

const ENTER = {
  initial: { opacity: 0, y: 8 },
  animate: { opacity: 1, y: 0 },
  transition: { duration: 0.22, ease: 'easeOut' },
}

// The status line swaps between "thinking" and one tool label after another
// during a single turn. Crossfading keeps those swaps from reading as the reply
// restarting.
const STATUS = {
  initial: { opacity: 0, y: 4 },
  animate: { opacity: 1, y: 0 },
  exit: { opacity: 0, y: -4 },
  transition: { duration: 0.16, ease: 'easeOut' },
}

// Shown while a tool runs, so staff can see the answer is being looked up
// rather than made up.
const TOOL_LABELS = {
  search_tours: 'กำลังค้นหาทัวร์',
  get_tour_details: 'กำลังดูรายละเอียดทัวร์',
  search_hotels: 'กำลังค้นหาโรงแรม',
  get_hotel_rates: 'กำลังดูราคาห้องพัก',
  get_hotel_details: 'กำลังดูรายละเอียดโรงแรม',
  search_suppliers: 'กำลังค้นหาซัพพลายเออร์',
  // Server-side: the stream stalls while Anthropic searches, so this label is
  // the only thing on screen for a few seconds. It has to be accurate —
  // "searching the web" and "adding up our own prices" are very different
  // claims to someone reading a price off the screen.
  web_search: 'กำลังค้นหาข้อมูลจากเว็บ',
  // Should not appear while web search is called directly (see
  // nova_server_tool_definitions), but mapped so that turning dynamic filtering
  // back on cannot make code execution read as a web search.
  code_execution: 'กำลังคำนวณ',
  bash_code_execution: 'กำลังคำนวณ',
}

/**
 * One turn in the conversation. User turns are bubbles on the right; assistant
 * turns are plain markdown on the left so tables can use the full width.
 *
 * Memoised: every text delta replaces the messages array, and without this each
 * update re-parsed the markdown of every earlier turn in the thread as well as
 * the one actually growing.
 */
export const ChatMessage = memo(function ChatMessage({
  id,
  role,
  content,
  tool,
  pending,
  editable,
  trailing,
  onEdit,
}) {
  const [editing, setEditing] = useState(false)

  if (role === 'user') {
    if (editing) {
      return (
        <QuestionEditor
          content={content}
          trailing={trailing}
          onCancel={() => setEditing(false)}
          onSubmit={(text) => {
            setEditing(false)
            onEdit?.(id, text)
          }}
        />
      )
    }

    return (
      <motion.div {...ENTER} className="group/msg flex items-center justify-end gap-1">
        {editable && (
          <button
            onClick={() => setEditing(true)}
            aria-label="แก้ไขคำถาม"
            className={cn(
              'flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground',
              'opacity-0 transition-all duration-150 hover:bg-accent hover:text-foreground',
              'group-hover/msg:opacity-100 focus-visible:opacity-100',
            )}
          >
            <Pencil className="size-3.5" />
          </button>
        )}
        <div className="max-w-[85%] rounded-2xl rounded-br-md bg-secondary px-4 py-2.5 text-[15px] leading-7 break-words whitespace-pre-wrap">
          {content}
        </div>
      </motion.div>
    )
  }

  return (
    <motion.div {...ENTER} className="group/msg flex gap-3">
      <div className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full border bg-card">
        <NovaMark className="size-4 text-foreground/70" />
      </div>
      <div className="min-w-0 flex-1 pt-0.5">
        <AnimatePresence mode="wait" initial={false}>
          {tool ? (
            // Keyed by tool name so moving from one lookup to the next replays
            // the transition instead of silently swapping the words.
            <motion.div key={tool} {...STATUS}>
              <ToolIndicator name={tool} />
            </motion.div>
          ) : !content && pending ? (
            <motion.div key="thinking" {...STATUS}>
              <ThinkingDots />
            </motion.div>
          ) : null}
        </AnimatePresence>

        {content && (
          <div
            className={cn(
              'prose-nova text-[15px] leading-7',
              // Blinking caret at the end of the text while deltas are still
              // arriving, so a slow stream reads as working rather than stuck.
              pending && !tool && 'nova-streaming',
            )}
          >
            <Markdown remarkPlugins={[remarkGfm]} components={MARKDOWN_COMPONENTS}>
              {content}
            </Markdown>
          </div>
        )}

        {content && !pending && <CopyButton text={content} />}
      </div>
    </motion.div>
  )
})

const MAX_EDIT_HEIGHT = 200

/**
 * Rewrites an earlier question in place.
 *
 * The warning sits inside the box, above the button that acts on it, rather
 * than behind a modal: every edit deletes something, so a dialog on every one
 * would be a click to dismiss and nothing more — and staff would learn to
 * dismiss it. Here the count is on screen while the question is being typed,
 * which is when it can still change the decision.
 */
function QuestionEditor({ content, trailing, onCancel, onSubmit }) {
  const ref = useRef(null)
  const [value, setValue] = useState(content)

  // Same grow-to-fit as the composer, so a long question is not edited through
  // a two-line window.
  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, MAX_EDIT_HEIGHT)}px`
  }, [value])

  // Caret at the end rather than selecting the text: this is usually a small
  // correction to the question, not a replacement of it.
  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.focus()
    el.setSelectionRange(el.value.length, el.value.length)
  }, [])

  const canSend = value.trim().length > 0

  function handleKeyDown(e) {
    if (e.key === 'Escape') {
      e.preventDefault()
      onCancel()
      return
    }
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      if (canSend) onSubmit(value)
    }
  }

  return (
    <motion.div {...ENTER} className="flex justify-end">
      <div
        className={cn(
          'w-full max-w-[85%] rounded-2xl border bg-card p-2 shadow-sm',
          'transition-colors focus-within:border-ring/60',
        )}
      >
        <textarea
          ref={ref}
          rows={1}
          value={value}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={handleKeyDown}
          aria-label="แก้ไขคำถาม"
          className="w-full resize-none bg-transparent px-2 py-1.5 text-[15px] leading-7 focus-visible:outline-none"
        />

        <div className="mt-1 flex flex-wrap items-center justify-end gap-2 px-2 pb-1">
          {trailing > 0 && (
            <p className="mr-auto flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500">
              <TriangleAlert className="size-3.5 shrink-0" />
              ข้อความหลังจากนี้ {trailing} ข้อความจะถูกลบถาวร
            </p>
          )}
          <Button size="sm" variant="ghost" onClick={onCancel}>
            ยกเลิก
          </Button>
          <Button size="sm" disabled={!canSend} onClick={() => onSubmit(value)}>
            ส่งใหม่
          </Button>
        </div>
      </div>
    </motion.div>
  )
}

/** Copying a rate table into a quote is the most common next step after a reply. */
function CopyButton({ text }) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 1600)
    } catch {
      // Clipboard is blocked outside a secure context; nothing useful to say.
    }
  }

  return (
    <button
      onClick={copy}
      aria-label={copied ? 'คัดลอกแล้ว' : 'คัดลอกคำตอบ'}
      className={cn(
        'mt-2 flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground',
        'opacity-0 transition-all duration-150 hover:bg-accent hover:text-foreground',
        'group-hover/msg:opacity-100 focus-visible:opacity-100',
      )}
    >
      {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
      {copied ? 'คัดลอกแล้ว' : 'คัดลอก'}
    </button>
  )
}

/**
 * Rate and tour tables are the whole point of this app. Each gets its own
 * scroll container so a wide table never widens the page, plus the two things
 * staff do with one: drop it into Excel, or paste it somewhere else.
 */
function DataTable(props) {
  const ref = useRef(null)
  const [copied, setCopied] = useState(false)

  function exportCsv() {
    if (ref.current) downloadCsv(readTable(ref.current))
  }

  async function copyTable() {
    if (!ref.current) return
    try {
      // Tab-separated, so pasting into Excel or Sheets lands in real columns
      // instead of one long cell.
      await navigator.clipboard.writeText(toTsv(readTable(ref.current)))
      setCopied(true)
      setTimeout(() => setCopied(false), 1600)
    } catch {
      // Clipboard is blocked outside a secure context; nothing useful to say.
    }
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: 'easeOut' }}
      className="group/table mb-3 last:mb-0"
    >
      <div className="mb-1 flex justify-end gap-1">
        <TableAction onClick={exportCsv} icon={FileSpreadsheet} label="Export Excel" />
        <TableAction
          onClick={copyTable}
          icon={copied ? Check : Copy}
          label={copied ? 'คัดลอกแล้ว' : 'คัดลอกตาราง'}
        />
      </div>
      <div className="overflow-x-auto rounded-lg border">
        <table ref={ref} className="w-full border-collapse text-sm" {...props} />
      </div>
    </motion.div>
  )
}

function TableAction({ onClick, icon: Icon, label }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground',
        // Same reveal-on-hover treatment as the reply-level copy button, but
        // the table keeps its own hover group so only the one you point at
        // shows its controls.
        'opacity-0 transition-all duration-150 hover:bg-accent hover:text-foreground',
        'group-hover/table:opacity-100 focus-visible:opacity-100',
      )}
    >
      <Icon className="size-3.5" />
      {label}
    </button>
  )
}

// Tailwind v4 ships no typography plugin here, so the handful of elements the
// model actually emits are styled directly.
const MARKDOWN_COMPONENTS = {
  p: (props) => <p className="mb-3 last:mb-0" {...props} />,
  ul: (props) => <ul className="mb-3 list-disc space-y-1 pl-5 last:mb-0" {...props} />,
  ol: (props) => <ol className="mb-3 list-decimal space-y-1 pl-5 last:mb-0" {...props} />,
  strong: (props) => <strong className="font-semibold" {...props} />,
  h1: (props) => <h1 className="mt-4 mb-2 text-lg font-semibold first:mt-0" {...props} />,
  h2: (props) => <h2 className="mt-4 mb-2 text-base font-semibold first:mt-0" {...props} />,
  h3: (props) => <h3 className="mt-4 mb-2 text-[15px] font-semibold first:mt-0" {...props} />,
  a: (props) => (
    <a
      className="underline underline-offset-2 hover:text-foreground"
      target="_blank"
      rel="noreferrer"
      {...props}
    />
  ),
  code: ({ className, children, ...props }) =>
    className ? (
      <code className={cn('block', className)} {...props}>
        {children}
      </code>
    ) : (
      <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[13px]" {...props}>
        {children}
      </code>
    ),
  pre: (props) => (
    <pre
      className="mb-3 overflow-x-auto rounded-lg bg-muted p-3 font-mono text-[13px] last:mb-0"
      {...props}
    />
  ),
  table: (props) => <DataTable {...props} />,
  thead: (props) => <thead className="bg-muted/60" {...props} />,
  th: (props) => (
    <th
      className="border-b px-3 py-2 text-left font-medium whitespace-nowrap"
      {...props}
    />
  ),
  td: (props) => <td className="border-b px-3 py-2 align-top last:border-0" {...props} />,
  blockquote: (props) => (
    <blockquote
      className="mb-3 border-l-2 pl-3 text-muted-foreground last:mb-0"
      {...props}
    />
  ),
  hr: () => <hr className="my-4" />,
}

function ToolIndicator({ name }) {
  return (
    <div className="mb-2 flex items-center gap-2 text-sm text-muted-foreground">
      <Loader2 className="size-3.5 animate-spin" />
      {/* A web search can stall the stream for several seconds; the sweep says
          the wait is still alive without a second spinner. */}
      <span className="nova-shimmer">
        {TOOL_LABELS[name] ?? `กำลังเรียก ${name}`}…
      </span>
    </div>
  )
}

function ThinkingDots() {
  return (
    <div className="flex items-center gap-1 py-2" aria-label="กำลังคิด">
      {[0, 150, 300].map((delay) => (
        <span
          key={delay}
          className="size-1.5 animate-bounce rounded-full bg-muted-foreground/60"
          style={{ animationDelay: `${delay}ms` }}
        />
      ))}
    </div>
  )
}
