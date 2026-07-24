import { useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { ArrowUp, AudioLines, ImagePlus, Square, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { MAX_IMAGES } from '@/lib/image-resize'
import { cn } from '@/lib/utils'

const MAX_HEIGHT = 200

const ACCEPT = 'image/jpeg,image/png,image/gif,image/webp'

/**
 * The message box. Grows with its content up to MAX_HEIGHT, then scrolls.
 * Enter sends, Shift+Enter adds a newline.
 *
 * Images can arrive three ways — the button, a paste, or a drop — because all
 * three are how a screenshot actually reaches a chat box. Paste is the one that
 * matters most here: the common case is Win+Shift+S over a rate table, and
 * saving that to a file first is a step nobody should have to take.
 */
export function ChatComposer({
  value,
  onChange,
  onSubmit,
  onStop,
  busy,
  attachments = [],
  onAttach,
  onRemoveAttachment,
  attachError,
  onVoice,
}) {
  const ref = useRef(null)
  const fileRef = useRef(null)
  const [dragging, setDragging] = useState(false)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, MAX_HEIGHT)}px`
  }, [value])

  // Hand the caret back once the reply finishes, so the next question can be
  // typed without reaching for the mouse.
  useEffect(() => {
    if (!busy) ref.current?.focus()
  }, [busy])

  const full = attachments.length >= MAX_IMAGES
  // A screenshot on its own is a question — "อ่านตารางนี้ให้หน่อย" is often the
  // whole of what someone means, and typing it adds nothing.
  const canSend = (value.trim().length > 0 || attachments.length > 0) && !busy

  function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      if (canSend) onSubmit()
    }
  }

  function handlePaste(e) {
    const files = [...e.clipboardData.files].filter((f) => f.type.startsWith('image/'))
    if (!files.length) return
    // Only when there is an image on the clipboard — pasting text has to keep
    // working exactly as it did.
    e.preventDefault()
    onAttach?.(files)
  }

  function handleDrop(e) {
    e.preventDefault()
    setDragging(false)
    const files = [...e.dataTransfer.files].filter((f) => f.type.startsWith('image/'))
    if (files.length) onAttach?.(files)
  }

  function pick(e) {
    const files = [...e.target.files]
    // Reset first, so choosing the same file twice in a row still fires.
    e.target.value = ''
    if (files.length) onAttach?.(files)
  }

  return (
    <div className="mx-auto w-full max-w-3xl px-4 pb-4">
      <div
        onDragOver={(e) => {
          if (e.dataTransfer.types.includes('Files')) {
            e.preventDefault()
            setDragging(true)
          }
        }}
        onDragLeave={(e) => {
          // Moving between children fires dragleave on the parent; only the
          // pointer actually leaving the box should clear the highlight.
          if (!e.currentTarget.contains(e.relatedTarget)) setDragging(false)
        }}
        onDrop={handleDrop}
        className={cn(
          'relative rounded-2xl border bg-card p-2 shadow-sm',
          'transition-colors focus-within:border-ring/60',
          dragging && 'border-ring bg-accent/40',
        )}
      >
        {attachments.length > 0 && (
          <div className="mb-1 flex flex-wrap gap-2 px-1 pt-1">
            {attachments.map((image) => (
              <Thumbnail
                key={image.id}
                image={image}
                onRemove={() => onRemoveAttachment?.(image.id)}
              />
            ))}
          </div>
        )}

        <div className="flex items-end gap-2">
          <input
            ref={fileRef}
            type="file"
            accept={ACCEPT}
            multiple
            onChange={pick}
            className="hidden"
          />
          <Button
            size="icon"
            variant="ghost"
            className="size-9 shrink-0 rounded-xl text-muted-foreground"
            disabled={busy || full}
            onClick={() => fileRef.current?.click()}
            aria-label={full ? `แนบได้สูงสุด ${MAX_IMAGES} รูป` : 'แนบรูป'}
            title={full ? `แนบได้สูงสุด ${MAX_IMAGES} รูป` : 'แนบรูป'}
          >
            <ImagePlus className="size-5" />
          </Button>

          <textarea
            ref={ref}
            rows={1}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            onKeyDown={handleKeyDown}
            onPaste={handlePaste}
            placeholder="ถามเกี่ยวกับทัวร์ โรงแรม ซัพพลายเออร์..."
            className={cn(
              'flex-1 resize-none bg-transparent px-1 py-2 text-[15px] leading-6',
              'placeholder:text-muted-foreground focus-visible:outline-none',
            )}
          />

          {/* Only rendered where the browser can both hear and speak — half of
              a conversation is not a feature worth a button. */}
          {onVoice && (
            <Button
              size="icon"
              variant="ghost"
              className="size-9 shrink-0 rounded-xl text-muted-foreground"
              disabled={busy}
              onClick={onVoice}
              aria-label="คุยด้วยเสียง"
              title="คุยด้วยเสียง"
            >
              <AudioLines className="size-5" />
            </Button>
          )}

          <AnimatePresence mode="wait" initial={false}>
            <motion.div
              key={busy ? 'stop' : 'send'}
              initial={{ opacity: 0, scale: 0.7 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.7 }}
              transition={{ duration: 0.12 }}
              className="shrink-0"
            >
              {busy ? (
                <Button
                  size="icon"
                  variant="secondary"
                  className="size-9 rounded-xl"
                  onClick={onStop}
                  aria-label="หยุด"
                >
                  <Square className="size-4 fill-current" />
                </Button>
              ) : (
                <Button
                  size="icon"
                  className="size-9 rounded-xl transition-transform active:scale-95"
                  disabled={!canSend}
                  onClick={onSubmit}
                  aria-label="ส่ง"
                >
                  <ArrowUp className="size-5" />
                </Button>
              )}
            </motion.div>
          </AnimatePresence>
        </div>
      </div>

      {attachError && (
        <p className="mt-2 text-center text-xs text-amber-600 dark:text-amber-500">
          {attachError}
        </p>
      )}

      <p className="mt-2 text-center text-xs text-muted-foreground">
        ตอบจากข้อมูลในระบบ ContactRate — ตรวจสอบก่อนใช้อ้างอิงกับลูกค้า
      </p>
    </div>
  )
}

/**
 * One pending attachment. Shows the picture rather than a filename: what tells
 * you whether you grabbed the right screenshot is the screenshot.
 */
function Thumbnail({ image, onRemove }) {
  return (
    <motion.div
      initial={{ opacity: 0, scale: 0.9 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ duration: 0.14 }}
      className="group/thumb relative size-16 overflow-hidden rounded-lg border bg-muted"
    >
      {image.previewUrl ? (
        <img
          src={image.previewUrl}
          alt={image.name ?? 'รูปที่แนบ'}
          title={image.name}
          className="size-full object-cover"
        />
      ) : (
        <div className="size-full animate-pulse bg-muted-foreground/15" />
      )}

      <button
        onClick={onRemove}
        aria-label={`เอา ${image.name ?? 'รูป'} ออก`}
        className={cn(
          'absolute top-0.5 right-0.5 flex size-5 items-center justify-center rounded-full',
          'bg-background/85 text-foreground shadow-sm backdrop-blur-sm transition-opacity',
          // Always visible on touch, where there is no hover to reveal it.
          'opacity-0 group-hover/thumb:opacity-100 focus-visible:opacity-100',
          'max-[768px]:opacity-100',
        )}
      >
        <X className="size-3" />
      </button>
    </motion.div>
  )
}
