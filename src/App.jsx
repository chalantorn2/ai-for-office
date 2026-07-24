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
import { VoiceMode } from '@/components/chat/voice-mode'
import { EmptyState } from '@/components/chat/empty-state'
import { UsagePanel } from '@/components/chat/usage-panel'
import { LoginPage } from '@/components/login-page'
import { NovaMark } from '@/components/nova-mark'
import { api, getToken, setToken } from '@/lib/api'
import { MAX_IMAGES, MAX_TOTAL_BYTES, prepareImage } from '@/lib/image-resize'
import { VOICE_SUPPORTED } from '@/lib/voice'

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

/**
 * The ai_message_images ids attached to a message.
 *
 * Same split as serverIdOf: a message loaded from the API carries real ids on
 * its images, while one just sent holds local keys until the stream reports
 * what the rows became. Rewriting a question sends these back so its pictures
 * follow it instead of being uploaded — and paid for — again.
 */
function imageIdsOf(message) {
  if (message.imageIds) return message.imageIds
  return (message.images ?? []).map((img) => img.id).filter(Number.isInteger)
}

/**
 * Files each proposed tour change under the reply that proposed it.
 *
 * The cards are rows in their own table rather than part of the transcript, so
 * reopening a chat has to put them back by hand. A proposal whose turn died
 * before the reply was stored has no message to sit under; those go at the end
 * of the thread, which is where they were on screen when it happened.
 */
function withWrites(messages, writes) {
  if (!writes?.length) return messages

  const byMessage = new Map()
  const orphans = []
  for (const card of writes) {
    if (card.message_id === null) {
      orphans.push(card)
      continue
    }
    const key = String(card.message_id)
    byMessage.set(key, [...(byMessage.get(key) ?? []), card])
  }

  const last = messages.length - 1
  return messages.map((message, i) => {
    const own = byMessage.get(String(message.id)) ?? []
    const cards = i === last ? [...own, ...orphans] : own
    return cards.length ? { ...message, writes: cards } : message
  })
}

export default function App() {
  const [user, setUser] = useState(null)
  // Only worth showing a spinner when there is a token to validate.
  const [checkingSession, setCheckingSession] = useState(() => Boolean(getToken()))

  const [conversations, setConversations] = useState([])
  const [projects, setProjects] = useState([])
  const [activeId, setActiveId] = useState(null)
  // The folder a new chat will be filed into. Set by starting a chat from
  // inside a project, and only meaningful until that chat exists — after that
  // the conversation's own `project_id` is what says where it lives.
  const [composingIn, setComposingIn] = useState(null)
  const [messagesById, setMessagesById] = useState({})
  const [draft, setDraft] = useState('')
  // Images staged for the next question, already resized. Each holds its own
  // object URL for the thumbnail, so they are released rather than dropped.
  const [attachments, setAttachments] = useState([])
  const [attachError, setAttachError] = useState('')
  const [busy, setBusy] = useState(false)
  const [sidebarOpen, setSidebarOpen] = useState(true) // desktop panel
  const [drawerOpen, setDrawerOpen] = useState(false) // mobile sheet
  const [usageOpen, setUsageOpen] = useState(false)
  // Hands-free. The thread underneath carries on as normal — voice mode is a
  // layer over this chat, not a separate kind of conversation.
  const [voiceOpen, setVoiceOpen] = useState(false)
  // The conversation waiting on a delete confirmation, if any.
  const [deleteTarget, setDeleteTarget] = useState(null)
  // Likewise for a project. Deleting one keeps its chats, so the wording and
  // the consequences differ enough to be its own dialog.
  const [deleteProjectTarget, setDeleteProjectTarget] = useState(null)

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

  const loadProjects = useCallback(() => {
    api
      .listProjects()
      .then(({ projects }) => setProjects(projects))
      .catch(() => setProjects([]))
  }, [])

  useEffect(() => {
    if (!user) return
    loadConversations()
    loadProjects()
  }, [user, loadConversations, loadProjects])

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

  /**
   * Stages images for the next question, resizing each before it is held.
   *
   * Resizing happens here rather than on send so the cost is paid while someone
   * is still typing, and so a file the browser cannot read (an iPhone HEIC, say)
   * is reported at the moment it is dropped instead of when the question goes.
   */
  async function attach(files) {
    setAttachError('')

    const room = MAX_IMAGES - attachments.length
    if (room <= 0) {
      setAttachError(`แนบได้สูงสุด ${MAX_IMAGES} รูปต่อข้อความ`)
      return
    }
    if (files.length > room) {
      setAttachError(`แนบได้อีก ${room} รูป — ส่วนที่เหลือไม่ได้แนบ`)
    }

    // Tracked here rather than read back out of state: the loop awaits between
    // files, and each update is one render behind the next check.
    let used = attachments.reduce((sum, a) => sum + (a.bytes ?? 0), 0)

    for (const file of files.slice(0, room)) {
      // Held before the work starts, so the thumbnail row appears on drop
      // rather than after a pause that looks like nothing happened.
      const key = `img-${crypto.randomUUID()}`
      setAttachments((prev) => [...prev, { id: key, name: file.name }])

      try {
        const image = await prepareImage(file)

        // Caught here rather than at the server, where going over the request
        // limit loses the whole question and reports itself as an empty one.
        if (used + image.bytes > MAX_TOTAL_BYTES) {
          URL.revokeObjectURL(image.previewUrl)
          setAttachments((prev) => prev.filter((a) => a.id !== key))
          setAttachError(`${file.name} ทำให้รูปรวมกันใหญ่เกินไป — ส่งทีละรูป`)
          // Anything after this one is over the line too.
          break
        }

        used += image.bytes
        setAttachments((prev) =>
          prev.map((a) => (a.id === key ? { ...a, ...image, id: key } : a)),
        )
      } catch (err) {
        setAttachments((prev) => prev.filter((a) => a.id !== key))
        setAttachError(err.message)
      }
    }
  }

  function removeAttachment(key) {
    setAttachError('')
    setAttachments((prev) => {
      const going = prev.find((a) => a.id === key)
      if (going?.previewUrl) URL.revokeObjectURL(going.previewUrl)
      return prev.filter((a) => a.id !== key)
    })
  }

  /**
   * Drops anything staged but unsent. Only safe where the images are not on
   * screen: once a question has been sent, its thumbnails are the message's,
   * and revoking those URLs would blank the bubble.
   */
  function discardAttachments() {
    setAttachError('')
    setAttachments((prev) => {
      for (const a of prev) if (a.previewUrl) URL.revokeObjectURL(a.previewUrl)
      return []
    })
  }

  function startNew(projectId = null) {
    setActiveId(null)
    setComposingIn(projectId)
    setDraft('')
    discardAttachments()
    setDrawerOpen(false)
  }

  async function selectConversation(id) {
    setActiveId(id)
    setComposingIn(null)
    discardAttachments()
    setDrawerOpen(false)
    atBottomRef.current = true
    setShowJump(false)

    if (messagesById[id]) return
    try {
      const { messages, writes } = await api.getConversation(id)
      setMessagesById((prev) => ({ ...prev, [id]: withWrites(messages, writes) }))
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

  /** Files a chat into a project, or out of every project with `null`. */
  async function moveConversation(id, projectId) {
    setConversations((prev) =>
      prev.map((c) => (c.id === id ? { ...c, project_id: projectId } : c)),
    )

    try {
      await api.moveConversation(id, projectId)
      // The counts on the folders are the server's, so they have to come back
      // from it — the sidebar's own list is capped at 200 conversations.
      loadProjects()
    } catch {
      loadConversations()
    }
  }

  /**
   * Creates a folder and hands it back, because the sidebar puts it straight
   * into rename mode and may have a chat to drop into it.
   */
  async function createProject() {
    try {
      const { project } = await api.createProject('')
      setProjects((prev) => [...prev, project])
      return project
    } catch {
      return null
    }
  }

  async function renameProject(id, name) {
    setProjects((prev) => prev.map((p) => (p.id === id ? { ...p, name } : p)))

    try {
      await api.renameProject(id, name)
    } catch {
      loadProjects()
    }
  }

  async function deleteProject(id) {
    setDeleteProjectTarget(null)
    setProjects((prev) => prev.filter((p) => p.id !== id))
    // The chats survive — the foreign key releases them rather than deleting
    // them, and they belong back in the date-grouped list immediately.
    setConversations((prev) =>
      prev.map((c) => (c.project_id === id ? { ...c, project_id: null } : c)),
    )
    if (composingIn === id) setComposingIn(null)

    try {
      await api.deleteProject(id)
    } catch {
      loadProjects()
      loadConversations()
    }
  }

  function logout() {
    setToken(null)
    setUser(null)
    setConversations([])
    setProjects([])
    setMessagesById({})
    setActiveId(null)
    setComposingIn(null)
    setUsageOpen(false)
    // Leaving it open would keep the microphone live on the login page.
    setVoiceOpen(false)
  }

  /**
   * Asks a question and streams the reply.
   *
   * `replace` rewrites history instead of appending to it: `{ key, id }` names
   * a message already in the thread — its React key, and its ai_messages id —
   * and it plus everything after it is dropped here and deleted server-side in
   * the same request. That is how editing an earlier question works, and it is
   * one-way: there is no branch kept behind it. It also carries that question's
   * pictures (`imageIds`, `images`) so a rewrite keeps them.
   *
   * Resolves with the reply's text. Voice mode is the caller that needs it: it
   * has to read the answer out once the turn is over, and it cannot see the
   * thread to find it. Everything else ignores the return value.
   */
  async function send(text = draft, replace = null, { voice = false } = {}) {
    const content = text.trim()

    // An edit re-uses what is already stored; only a fresh question uploads.
    const images = replace ? [] : attachments
    const keepImageIds = replace?.imageIds ?? []

    if (busy) return ''
    if (!content && !images.length && !keepImageIds.length) return ''

    // Resizing is quick but not instant, and sending half a question's
    // attachments would be worse than making someone wait a beat for them.
    if (images.some((image) => !image.data)) {
      setAttachError('กำลังย่อรูป รอสักครู่')
      return ''
    }

    if (!replace) {
      setDraft('')
      // Not discarded: these object URLs are the thumbnails in the bubble now.
      setAttachments([])
      setAttachError('')
    }
    setBusy(true)
    // Sending is an explicit "show me the answer" — follow the stream again.
    atBottomRef.current = true
    setShowJump(false)

    // The server creates the conversation on the first message and reports the
    // id back in the `meta` event, so a new chat starts under a temporary key
    // and is re-filed once that arrives.
    let convoId = activeId ?? 'pending'
    const userMsg = {
      id: `local-${crypto.randomUUID()}`,
      role: 'user',
      content,
      // Freshly attached images render from the object URLs they already carry;
      // a rewrite re-shows the ones the original question had.
      images: replace ? (replace.images ?? []) : images,
      imageIds: keepImageIds.length ? keepImageIds : undefined,
    }
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

    // The reply, accumulated independently of the thread. Reading it back out
    // of state afterwards would mean chasing the right message through an
    // update that has not necessarily landed yet.
    let full = ''

    try {
      await api.streamAssistant(
        {
          conversationId: activeId ?? null,
          message: content,
          replaceFrom: replace?.id ?? null,
          images,
          keepImageIds,
          voice,
          // Only read when this request creates the conversation; on an
          // existing chat the server ignores it.
          projectId: activeId ? null : composingIn,
        },
        {
          onMeta: ({ conversation_id, user_message_id, image_ids }) => {
            if (convoId === 'pending') {
              // Move the in-flight messages under the real id.
              setMessagesById((prev) => {
                const { pending, ...rest } = prev
                return { ...rest, [conversation_id]: pending ?? [] }
              })
              setActiveId(conversation_id)
              convoId = conversation_id
              loadConversations()

              // The chat now exists and carries its own project_id, so the
              // pending folder has done its job; the folder's count changed.
              if (composingIn) {
                setComposingIn(null)
                loadProjects()
              }
            }

            // Carried alongside the local id rather than replacing it: the
            // local one is this bubble's React key, and swapping a key
            // remounts the message and replays its entrance mid-thread.
            if (user_message_id) {
              patchMessage(userMsg.id, (m) => ({ ...m, serverId: user_message_id }))
            }

            // The rows the uploads became. Kept so that editing this question
            // re-links them rather than sending the bytes up a second time.
            if (image_ids?.length) {
              patchMessage(userMsg.id, (m) => ({ ...m, imageIds: image_ids }))
            }
          },
          onTool: ({ name }) => {
            // Any buffered text belongs before the tool ran, not after.
            flushText()
            patchReply((m) => ({ ...m, tool: name }))
          },
          onWrite: (card) => {
            // A change waiting on a button press. It arrives before the sentence
            // that explains it — the tool runs first — and renders under the
            // reply either way, so nothing has to be held back for it.
            patchReply((m) => ({ ...m, writes: [...(m.writes ?? []), card] }))
          },
          onText: (delta) => {
            full += delta
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
            full ||= `เกิดข้อผิดพลาด: ${message}`
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
        full ||= '(ยกเลิกแล้ว)'
        patchReply((m) => ({ ...m, tool: null, content: m.content || '(ยกเลิกแล้ว)' }))
      } else if (err.status === 401) {
        logout()
      } else if (err.status === 429) {
        // A limit is a plain answer, not a connection failure — the server's
        // message already says which cap was hit and what to do about it.
        full = err.message
        patchReply((m) => ({ ...m, tool: null, content: err.message }))
      } else {
        full ||= `เชื่อมต่อไม่สำเร็จ: ${err.message}`
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

    return full
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

  /**
   * One spoken question. Held stable so the voice loop is not restarted by a
   * text delta — it runs across the whole turn, and remounting it mid-answer
   * would drop the microphone.
   */
  const askByVoice = useCallback((text) => liveRef.current.send(text, null, { voice: true }), [])

  const closeVoice = useCallback(() => setVoiceOpen(false), [])

  /** Re-asks an earlier question, dropping it and everything after it. */
  const editMessage = useCallback((key, text) => {
    const { messagesById, activeId, busy, send } = liveRef.current
    if (busy) return

    const message = (messagesById[activeId] ?? []).find((m) => m.id === key)
    const id = message && serverIdOf(message)
    if (!id) return

    // The pictures come along: they are already stored, so the rewritten
    // question re-links them instead of re-uploading, and the thumbnails on
    // screen stay the ones that were there.
    send(text, {
      key,
      id,
      imageIds: imageIdsOf(message),
      images: message.images ?? [],
    })
  }, [])

  /** Puts one card back in the thread once the server has ruled on it. */
  const patchWrite = useCallback((conversationId, card) => {
    setMessagesById((prev) => ({
      ...prev,
      [conversationId]: (prev[conversationId] ?? []).map((m) =>
        m.writes?.some((w) => w.id === card.id)
          ? { ...m, writes: m.writes.map((w) => (w.id === card.id ? card : w)) }
          : m,
      ),
    }))
  }, [])

  /**
   * Confirms or cancels a change Nova proposed. Deliberately not optimistic:
   * this is the one action in the app that writes to ContactRate, and the server
   * can refuse it — the tour may have been edited elsewhere since, or another
   * tab may have answered the same card already. The error is rethrown so the
   * card itself can say what happened.
   */
  const decideWrite = useCallback(
    async (writeId, confirm) => {
      const { activeId } = liveRef.current
      try {
        const { write } = await api.decideWrite(writeId, confirm)
        patchWrite(activeId, write)
      } catch (err) {
        // 409 means the card on screen no longer describes reality. Fetching the
        // real state back is the only way it stops offering a button that cannot
        // work.
        if (err.status === 409) {
          api
            .listWrites(activeId)
            .then(({ writes }) => writes.forEach((card) => patchWrite(activeId, card)))
            .catch(() => {})
        }
        throw err
      }
    },
    [patchWrite],
  )

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
    projects,
    activeId,
    activeProjectId: composingIn,
    user,
    onSelect: selectConversation,
    onNew: startNew,
    onDelete: (id) =>
      setDeleteTarget(conversations.find((c) => c.id === id) ?? null),
    onRename: renameConversation,
    onMove: moveConversation,
    onNewProject: createProject,
    onRenameProject: renameProject,
    onDeleteProject: (id) =>
      setDeleteProjectTarget(projects.find((p) => p.id === id) ?? null),
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

      {/* Deleting a folder is not deleting what is in it, and staff have every
          reason to assume otherwise — so the dialog says where the chats go
          rather than warning about a loss that does not happen. */}
      <AlertDialog
        open={deleteProjectTarget !== null}
        onOpenChange={(open) => !open && setDeleteProjectTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ลบโปรเจกต์นี้?</AlertDialogTitle>
            <AlertDialogDescription>
              <span className="font-medium text-foreground">
                {deleteProjectTarget?.name?.trim() || 'โปรเจกต์ใหม่'}
              </span>{' '}
              {deleteProjectTarget?.chat_count
                ? `จะถูกลบ แชท ${deleteProjectTarget.chat_count} รายการข้างในไม่ถูกลบ — ย้ายกลับไปที่ประวัติแชท`
                : 'จะถูกลบ'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-white hover:bg-destructive/90"
              onClick={() => deleteProject(deleteProjectTarget.id)}
            >
              ลบโปรเจกต์
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
                    onClick={() => startNew()}
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
            onClick={() => startNew()}
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
                    images={m.images}
                    tool={m.tool}
                    writes={m.writes}
                    pending={busy && i === messages.length - 1}
                    // Never offered mid-stream: the reply still being written
                    // is one of the messages an edit would delete. Only
                    // primitives are passed, and onEdit holds one identity, so
                    // the memo survives a turn's worth of deltas.
                    editable={m.role === 'user' && !busy && Boolean(serverIdOf(m))}
                    trailing={messages.length - 1 - i}
                    onEdit={editMessage}
                    onDecideWrite={decideWrite}
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
          attachments={attachments}
          onAttach={attach}
          onRemoveAttachment={removeAttachment}
          attachError={attachError}
          onVoice={VOICE_SUPPORTED ? () => setVoiceOpen(true) : null}
        />
      </main>

      {/* Which tool is running is the only honest thing to show during the
          pause between a spoken question and its answer — the reply has not
          started and the silence otherwise reads as a dropped connection. */}
      <VoiceMode
        open={voiceOpen}
        tool={busy ? (messages.at(-1)?.tool ?? null) : null}
        onSend={askByVoice}
        onClose={closeVoice}
      />
    </div>
  )
}
