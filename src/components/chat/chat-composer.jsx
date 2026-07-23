import { useEffect, useRef } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { ArrowUp, Square } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

const MAX_HEIGHT = 200

/**
 * The message box. Grows with its content up to MAX_HEIGHT, then scrolls.
 * Enter sends, Shift+Enter adds a newline.
 */
export function ChatComposer({ value, onChange, onSubmit, onStop, busy }) {
  const ref = useRef(null)

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

  const canSend = value.trim().length > 0 && !busy

  function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      if (canSend) onSubmit()
    }
  }

  return (
    <div className="mx-auto w-full max-w-3xl px-4 pb-4">
      <div
        className={cn(
          'relative flex items-end gap-2 rounded-2xl border bg-card p-2 shadow-sm',
          'transition-colors focus-within:border-ring/60',
        )}
      >
        <textarea
          ref={ref}
          rows={1}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="ถามเกี่ยวกับทัวร์ โรงแรม ซัพพลายเออร์..."
          className={cn(
            'flex-1 resize-none bg-transparent px-2 py-2 text-[15px] leading-6',
            'placeholder:text-muted-foreground focus-visible:outline-none',
          )}
        />
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
      <p className="mt-2 text-center text-xs text-muted-foreground">
        ตอบจากข้อมูลในระบบ ContactRate — ตรวจสอบก่อนใช้อ้างอิงกับลูกค้า
      </p>
    </div>
  )
}
