import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { ArrowDown, Loader2, Menu, PanelLeft, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { ChatSidebar } from '@/components/chat/chat-sidebar'
import { ChatComposer } from '@/components/chat/chat-composer'
import { ChatMessage } from '@/components/chat/chat-message'
import { EmptyState } from '@/components/chat/empty-state'
import { UsagePanel } from '@/components/chat/usage-panel'
import { LoginPage } from '@/components/login-page'
import { NovaMark } from '@/components/nova-mark'
import { api, getToken, setToken } from '@/lib/api'

// How long text deltas are allowed to pile up before being rendered. Low enough
// to still read as typing, high enough that a long reply with a table is not
// re-parsed on every token.
const COALESCE_MS = 60

/**
 * The ai_messages id of a message, or null if the server has none yet.
 *
 * Messages loaded from the API arrive with the real id; ones created locally
 * keep a `local-…` key for React and pick up `serverId` when the stream reports
 * it. Only a message the table knows about can be edited, because editing is
 * defined as deleting from that row onward.
 */
function serverIdOf(message) {
  if (message.serverId) return message.serverId
  return String(message.id).startsWith('local-') ? null : message.id
}

export default function App() {
  const [user, setUser] = useState(null)
  // Only worth showing a spinner when there is a token to validate.
  const [checkingSession, setCheckingSession] = useState(() => Boolean(getToken()))

  const [conversations, setConversations] = useState([])
  const [activeId, setActiveId] = useState(null)
  const [messagesById, setMessagesById] = useState({})
  const [draft, setDraft] = useState('')
  const [busy, setBusy] = useState(false)
  const [sidebarOpen, setSidebarOpen] = useState(true) // desktop panel
  const [drawerOpen, setDrawerOpen] = useState(false) // mobile sheet
  const [usageOpen, setUsageOpen] = useState(false)
  // The conversation waiting on a delete confirmation, if any.
  const [deleteTarget, setDeleteTarget] = useState(null)

  const scrollRef = useRef(null)
  const abortRef = useRef(null)
  // Text deltas arrive far faster than the screen can use. Each one used to be
  // its own setState, so the whole reply — markdown, tables and all — was
  // re-parsed dozens of times a second and the text arrived in lurches. They
  // are coalesced into one update per COALESCE_MS instead.
  const textBufferRef = useRef('')
  const flushTimerRef = useRef(0)
  // Auto-scroll only while the reader is already at the bottom; scrolling up to
  // re-read an answer mid-stream should not yank the view back down.
  const atBottomRef = useRef(true)
  const [showJump, setShowJump] = useState(false)
  const messages = useMemo(
    () => (activeId ? (messagesById[activeId] ?? []) : []),
    [activeId, messagesById],
  )

  // Resume the session if a token is already stored.
  useEffect(() => {
    if (!getToken()) return

    api
      .me()
      .then(({ user }) => setUser(user))
      .catch(() => setToken(null))
      .finally(() => setCheckingSession(false))
  }, [])

  useEffect(() => () => clearTimeout(flushTimerRef.current), [])

  const loadConversations = useCallback(() => {
    api
      .listConversations()
      .then(({ conversations }) => setConversations(conversations))
      .catch(() => setConversations([]))
  }, [])

  useEffect(() => {
    if (user) loadConversations()
  }, [user, loadConversations])

  const scrollToBottom = useCallback((behavior = 'smooth') => {
    const el = scrollRef.current
    if (el) el.scrollTo({ top: el.scrollHeight, behavior })
  }, [])

  // A smooth scroll takes longer than the gap between updates, so during a
  // stream each one restarts the last mid-flight — which is what the stutter
  // was. Follow the text instantly and keep the easing for deliberate jumps.
  useEffect(() => {
    if (atBottomRef.current) scrollToBottom(busy ? 'auto' : 'smooth')
  }, [messages, busy, scrollToBottom])

  function handleScroll(e) {
    const el = e.currentTarget
    const near = el.scrollHeight - el.scrollTop - el.clientHeight < 80
    atBottomRef.current = near
    setShowJump(!near)
  }

  function startNew() {
    setActiveId(null)
    setDraft('')
    setDrawerOpen(false)
  }

  async function selectConversation(id) {
    setActiveId(id)
    setDrawerOpen(false)
    atBottomRef.current = true
    setShowJump(false)

    if (messagesById[id]) return
    try {
      const { messages } = await api.getConversation(id)
      setMessagesById((prev) => ({ ...prev, [id]: messages }))
    } catch {
      setMessagesById((prev) => ({ ...prev, [id]: [] }))
    }
  }

  async function deleteConversation(id) {
    setDeleteTarget(null)

    // Drop it locally first — the row animates out immediately, and a failed
    // request puts it back via the reload below.
    setConversations((prev) => prev.filter((c) => c.id !== id))
    if (activeId === id) setActiveId(null)
    setMessagesById((prev) =>
      Object.fromEntries(Object.entries(prev).filter(([key]) => key !== String(id))),
    )

    try {
      await api.deleteConversation(id)
    } catch {
      loadConversations()
    }
  }

  async function renameConversation(id, title) {
    // Optimistic: the new title is on screen before the round trip, and a
    // failure is corrected by the reload rather than by a message nobody reads.
    setConversations((prev) =>
      prev.map((c) => (c.id === id ? { ...c, title } : c)),
    )

    try {
      await api.renameConversation(id, title)
    } catch {
      loadConversations()
    }
  }

  function logout() {
    setToken(null)
    setUser(null)
    setConversations([])
    setMessagesById({})
    setActiveId(null)
    setUsageOpen(false)
  }

  /**
   * Asks a question and streams the reply.
   *
   * `replace` rewrites history instead of appending to it: `{ key, id }` names
   * a message already in the thread — its React key, and its ai_messages id —
   * and it plus everything after it is dropped here and deleted server-side in
   * the same request. That is how editing an earlier question works, and it is
   * one-way: there is no branch kept behind it.
   */
  async function send(text = draft, replace = null) {
    const content = text.trim()
    if (!content || busy) return

    if (!replace) setDraft('')
    setBusy(true)
    // Sending is an explicit "show me the answer" — follow the stream again.
    atBottomRef.current = true
    setShowJump(false)

    // The server creates the conversation on the first message and reports the
    // id back in the `meta` event, so a new chat starts under a temporary key
    // and is re-filed once that arrives.
    let convoId = activeId ?? 'pending'
    const userMsg = { id: `local-${crypto.randomUUID()}`, role: 'user', content }
    const replyId = `local-${crypto.randomUUID()}`

    setMessagesById((prev) => {
      const thread = prev[convoId] ?? []
      const cut = replace ? thread.findIndex((m) => m.id === replace.key) : -1
      // A key that is no longer in the thread means the two sides have drifted;
      // appending is the recoverable outcome, and the reload on `done` puts the
      // thread back in step with the table.
      const kept = cut === -1 ? thread : thread.slice(0, cut)

      return {
        ...prev,
        [convoId]: [...kept, userMsg, { id: replyId, role: 'assistant', content: '' }],
      }
    })
    if (convoId === 'pending') setActiveId('pending')

    const patchMessage = (id, fn) =>
      setMessagesById((prev) => ({
        ...prev,
        [convoId]: (prev[convoId] ?? []).map((m) => (m.id === id ? fn(m) : m)),
      }))
    const patchReply = (fn) => patchMessage(replyId, fn)

    // Write out whatever text has piled up. Called on the timer, and directly
    // before anything that has to land after the text it follows.
    const flushText = () => {
      clearTimeout(flushTimerRef.current)
      flushTimerRef.current = 0

      const chunk = textBufferRef.current
      if (!chunk) return
      textBufferRef.current = ''
      patchReply((m) => ({ ...m, tool: null, content: m.content + chunk }))
    }

    const controller = new AbortController()
    abortRef.current = controller

    try {
      await api.streamAssistant(
        {
          conversationId: activeId ?? null,
          message: content,
          replaceFrom: replace?.id ?? null,
        },
        {
          onMeta: ({ conversation_id, user_message_id }) => {
            if (convoId === 'pending') {
              // Move the in-flight messages under the real id.
              setMessagesById((prev) => {
                const { pending, ...rest } = prev
                return { ...rest, [conversation_id]: pending ?? [] }
              })
              setActiveId(conversation_id)
              convoId = conversation_id
              loadConversations()
            }

            // Carried alongside the local id rather than replacing it: the
            // local one is this bubble's React key, and swapping a key
            // remounts the message and replays its entrance mid-thread.
            if (user_message_id) {
              patchMessage(userMsg.id, (m) => ({ ...m, serverId: user_message_id }))
            }
          },
          onTool: ({ name }) => {
            // Any buffered text belongs before the tool ran, not after.
            flushText()
            patchReply((m) => ({ ...m, tool: name }))
          },
          onText: (delta) => {
            textBufferRef.current += delta
            if (!flushTimerRef.current) {
              flushTimerRef.current = setTimeout(flushText, COALESCE_MS)
            }
          },
          onDone: ({ assistant_message_id }) => {
            flushText()
            // 0 when the reply failed to save. Leaving it without a serverId is
            // right: a row that is not in the table is not one a later edit can
            // truncate from.
            if (assistant_message_id) {
              patchReply((m) => ({ ...m, serverId: assistant_message_id }))
            }
            loadConversations()
          },
          onError: ({ message }) => {
            flushText()
            patchReply((m) => ({
              ...m,
              tool: null,
              content: m.content || `เกิดข้อผิดพลาด: ${message}`,
            }))
          },
        },
        controller.signal,
      )
    } catch (err) {
      // Text that arrived before the failure is still the answer so far.
      flushText()

      if (err.name === 'AbortError') {
        patchReply((m) => ({ ...m, tool: null, content: m.content || '(ยกเลิกแล้ว)' }))
      } else if (err.status === 401) {
        logout()
      } else if (err.status === 429) {
        // A limit is a plain answer, not a connection failure — the server's
        // message already says which cap was hit and what to do about it.
        patchReply((m) => ({ ...m, tool: null, content: err.message }))
      } else {
        patchReply((m) => ({
          ...m,
          tool: null,
          content: m.content || `เชื่อมต่อไม่สำเร็จ: ${err.message}`,
        }))
      }
    } finally {
      flushText()
      abortRef.current = null
      setBusy(false)
    }
  }

  function stop() {
    abortRef.current?.abort()
  }

  // ChatMessage is memoised so a text delta does not re-render the whole
  // thread, and a handler rebuilt on every render would undo that for every
  // message at once. These refs let the callback below keep one identity for
  // the life of the component while still reading current state.
  const liveRef = useRef(null)
  liveRef.current = { messagesById, activeId, busy, send }

  /** Re-asks an earlier question, dropping it and everything after it. */
  const editMessage = useCallback((key, text) => {
    const { messagesById, activeId, busy, send } = liveRef.current
    if (busy) return

    const message = (messagesById[activeId] ?? []).find((m) => m.id === key)
    const id = message && serverIdOf(message)
    if (!id) return

    send(text, { key, id })
  }, [])

  if (checkingSession) {
    return (
      <div className="flex h-full items-center justify-center">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!user) {
    return <LoginPage onSuccess={setUser} />
  }

  const sidebarProps = {
    conversations,
    activeId,
    user,
    onSelect: selectConversation,
    onNew: startNew,
    onDelete: (id) =>
      setDeleteTarget(conversations.find((c) => c.id === id) ?? null),
    onRename: renameConversation,
    onLogout: logout,
    onShowUsage: () => {
      setDrawerOpen(false)
      setUsageOpen(true)
    },
  }

  return (
    <div className="flex h-full">
      {/* Desktop: a panel that shares the layout. Hidden below md.
          The wrapper animates its width while the panel inside keeps a fixed
          w-64, so the contents slide out of view instead of being squeezed. */}
      <AnimatePresence initial={false}>
        {sidebarOpen && (
          <motion.div
            key="sidebar"
            initial={{ width: 0 }}
            animate={{ width: 256 }}
            exit={{ width: 0 }}
            transition={{ duration: 0.28, ease: [0.32, 0.72, 0, 1] }}
            className="hidden shrink-0 overflow-hidden md:block"
          >
            <ChatSidebar
              {...sidebarProps}
              onToggle={() => setSidebarOpen(false)}
              className="border-r border-sidebar-border"
            />
          </motion.div>
        )}
      </AnimatePresence>

      {/* Mobile: the same panel inside a drawer. */}
      <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
        <SheetContent side="left" className="w-64 p-0 md:hidden">
          <SheetTitle className="sr-only">ประวัติแชท</SheetTitle>
          <ChatSidebar {...sidebarProps} collapsible={false} className="w-full" />
        </SheetContent>
      </Sheet>

      {/* Spend. Mounted only while open so the figures are fetched fresh each
          time rather than going stale behind a closed panel. */}
      <Sheet open={usageOpen} onOpenChange={setUsageOpen}>
        <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-sm">
          <SheetTitle className="px-4 pt-4">การใช้งาน</SheetTitle>
          {usageOpen && <UsagePanel user={user} />}
        </SheetContent>
      </Sheet>

      {/* Deleting a chat takes every message in it and there is no undo, so it
          asks first. `window.confirm` did this until now — it blocks the whole
          tab, cannot be styled, and reads as a browser warning rather than as
          part of Nova. */}
      <AlertDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ลบแชทนี้?</AlertDialogTitle>
            <AlertDialogDescription>
              <span className="font-medium text-foreground">
                {deleteTarget?.title?.trim() || 'แชทใหม่'}
              </span>{' '}
              และข้อความทั้งหมดในแชทจะถูกลบถาวร กู้คืนไม่ได้
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-white hover:bg-destructive/90"
              onClick={() => deleteConversation(deleteTarget.id)}
            >
              ลบแชท
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <main className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-12 shrink-0 items-center gap-1 px-2">
          <Button
            variant="ghost"
            size="icon"
            className="size-8 md:hidden"
            onClick={() => setDrawerOpen(true)}
            aria-label="เปิดประวัติแชท"
          >
            <Menu className="size-4" />
          </Button>

          {!sidebarOpen && (
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ duration: 0.2, delay: 0.12 }}
              className="hidden md:flex md:items-center md:gap-1"
            >
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={() => setSidebarOpen(true)}
                    aria-label="แสดงแถบข้าง"
                  >
                    <PanelLeft className="size-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="right">แสดงแถบข้าง</TooltipContent>
              </Tooltip>
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={startNew}
                    aria-label="แชทใหม่"
                  >
                    <Plus className="size-4" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent side="right">แชทใหม่</TooltipContent>
              </Tooltip>
            </motion.div>
          )}

          <span className="flex items-center gap-1.5 px-1 text-sm font-medium">
            <NovaMark className="size-3.5" />
            Nova
          </span>

          <Button
            variant="ghost"
            size="icon"
            className="ml-auto size-8 md:hidden"
            onClick={startNew}
            aria-label="แชทใหม่"
          >
            <Plus className="size-4" />
          </Button>
        </header>

        {messages.length === 0 ? (
          <EmptyState onPick={send} />
        ) : (
          <div className="relative flex min-h-0 flex-1 flex-col">
            <div ref={scrollRef} onScroll={handleScroll} className="flex-1 overflow-y-auto">
              <div className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
                {messages.map((m, i) => (
                  <ChatMessage
                    key={m.id}
                    id={m.id}
                    role={m.role}
                    content={m.content}
                    tool={m.tool}
                    pending={busy && i === messages.length - 1}
                    // Never offered mid-stream: the reply still being written
                    // is one of the messages an edit would delete. Only
                    // primitives are passed, and onEdit holds one identity, so
                    // the memo survives a turn's worth of deltas.
                    editable={m.role === 'user' && !busy && Boolean(serverIdOf(m))}
                    trailing={messages.length - 1 - i}
                    onEdit={editMessage}
                  />
                ))}
              </div>
            </div>

            <AnimatePresence>
              {showJump && (
                <motion.button
                  initial={{ opacity: 0, y: 8, scale: 0.9 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: 8, scale: 0.9 }}
                  transition={{ duration: 0.18 }}
                  onClick={() => scrollToBottom()}
                  aria-label="เลื่อนไปข้อความล่าสุด"
                  className="absolute bottom-4 left-1/2 flex size-9 -translate-x-1/2 items-center justify-center rounded-full border bg-background shadow-md transition-colors hover:bg-accent"
                >
                  <ArrowDown className="size-4" />
                </motion.button>
              )}
            </AnimatePresence>
          </div>
        )}

        <ChatComposer
          value={draft}
          onChange={setDraft}
          onSubmit={() => send()}
          onStop={stop}
          busy={busy}
        />
      </main>
    </div>
  )
}
