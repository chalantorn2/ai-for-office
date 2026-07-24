import { useCallback, useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { Loader2, Mic, SkipForward, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { NovaMark } from '@/components/nova-mark'
import { LANGS, listen, speak, stopSpeaking, stripForSpeech, voiceName } from '@/lib/voice'
import { cn } from '@/lib/utils'

/**
 * Hands-free conversation.
 *
 * One loop, forever until it is closed: listen → ask → speak → listen. The
 * turn it drives is an ordinary turn — same endpoint, same tools, same rate
 * limit, same rows in `ai_messages` — so a conversation held out loud is in the
 * sidebar afterwards and can be scrolled back through like any other. The only
 * difference reaching the model is the note appended to the question
 * (NOVA_VOICE_HINT in api/assistant.php).
 *
 * Nothing here waits on the microphone and the speakers at the same time. They
 * would hear each other: Nova's own answer comes back as the next question and
 * the loop feeds on itself. Each phase owns the audio outright.
 *
 * The chat is still behind this — voice mode is a layer over the thread, not a
 * replacement for it. The table Nova declines to read out is on screen the
 * moment this is closed.
 */

/** What the labels in the composer's tool indicator say, said out loud. */
const WORKING = {
  search_tours: 'กำลังค้นทัวร์',
  get_tour_details: 'กำลังดูรายละเอียดทัวร์',
  search_hotels: 'กำลังค้นโรงแรม',
  get_hotel_rates: 'กำลังดูราคาห้องพัก',
  get_hotel_details: 'กำลังดูรายละเอียดโรงแรม',
  search_suppliers: 'กำลังค้นซัพพลายเออร์',
  get_supplier_files: 'กำลังหาไฟล์ของซัพพลายเออร์',
  web_search: 'กำลังค้นข้อมูลจากเว็บ',
}

/**
 * How fast Nova talks. Thai voices run fast at 1.0 and a price is exactly the
 * thing worth hearing slowly, so the default is a notch below and the control
 * is on screen — how fast is comfortable is not something to decide for anyone.
 */
const RATES = [0.8, 0.9, 1, 1.15]

const PHASES = {
  listening: { label: 'ฟังอยู่ พูดได้เลย', tone: 'bg-primary' },
  thinking: { label: 'กำลังหาข้อมูล', tone: 'bg-amber-500' },
  speaking: { label: 'กำลังตอบ', tone: 'bg-emerald-500' },
  error: { label: 'ใช้ไมค์ไม่ได้', tone: 'bg-destructive' },
}

const MIC_ERRORS = {
  'not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้ไมค์ — กดไอคอนกุญแจข้าง URL แล้วเปิดไมค์',
  'service-not-allowed': 'ระบบเสียงของเครื่องปิดอยู่ — เปิดสิทธิ์ไมค์ให้เบราว์เซอร์ก่อน',
  'audio-capture': 'ไม่พบไมโครโฟน',
  network: 'ต่อบริการรู้จำเสียงไม่ได้ — เช็กอินเทอร์เน็ต',
  unsupported: 'เบราว์เซอร์นี้ไม่รองรับการรู้จำเสียง — ใช้ Chrome หรือ Edge',
}

export function VoiceMode({ open, tool, onSend, onClose }) {
  const [phase, setPhase] = useState('listening')
  const [lang, setLang] = useState('th-TH')
  const [heard, setHeard] = useState('')
  const [said, setSaid] = useState('')
  const [error, setError] = useState('')
  const [rate, setRate] = useState(0.9)
  // Which voice the browser actually gave us. Worth showing: "the speech sounds
  // bad" is unanswerable, "it is using Microsoft Premwadee" is a thing to fix.
  const [using, setUsing] = useState('')

  // Read through a ref by the loop, so changing the speed mid-conversation
  // takes effect on the next answer instead of restarting the microphone.
  const rateRef = useRef(rate)
  useEffect(() => {
    rateRef.current = rate
  }, [rate])

  useEffect(() => {
    if (!open) return
    let live = true
    voiceName(lang).then((name) => {
      if (live) setUsing(name)
    })
    return () => {
      live = false
    }
  }, [open, lang])

  // The loop is one long-lived async function; anything it reads after an
  // await lives in a ref, or it would keep the first render's handler and go on
  // asking questions through a closure that has since gone stale.
  const sendRef = useRef(onSend)
  useEffect(() => {
    sendRef.current = onSend
  }, [onSend])

  // The live recognition session, so the buttons can end a turn early.
  const micRef = useRef(null)

  /** One utterance. Resolves with what was heard, or '' if the turn was silent. */
  const hear = useCallback(
    (code) =>
      new Promise((resolve, reject) => {
        let final = ''
        try {
          micRef.current = listen({
            lang: code,
            onInterim: setHeard,
            onFinal: (text) => {
              final = text
            },
            onEnd: () => {
              micRef.current = null
              resolve(final)
            },
            onError: (reason) => {
              micRef.current = null
              reject(new Error(reason))
            },
          })
        } catch {
          reject(new Error('unsupported'))
        }
      }),
    [],
  )

  useEffect(() => {
    if (!open) return

    let stopped = false

    async function converse() {
      // Reset per opening rather than on close, so the last exchange is still
      // on screen while the overlay animates away.
      setError('')
      setHeard('')
      setSaid('')

      while (!stopped) {
        setPhase('listening')
        setHeard('')

        let question
        try {
          question = await hear(lang)
        } catch (err) {
          if (stopped) return
          setError(MIC_ERRORS[err.message] ?? `ไมค์มีปัญหา (${err.message})`)
          setPhase('error')
          return
        }
        if (stopped) return

        // A turn that passed in silence. Listening again is the whole point of
        // hands-free; the pause keeps Chrome from refusing a restart that
        // arrives in the same tick as the stop.
        if (!question) {
          await new Promise((r) => setTimeout(r, 250))
          continue
        }

        setHeard(question)
        setPhase('thinking')
        setSaid('')

        // Resolves when the stream is finished and the reply is stored, and
        // hands back the text that was streamed — read from the return value
        // rather than from the thread, which this component cannot see.
        let reply
        try {
          reply = (await sendRef.current(question)) ?? ''
        } catch {
          reply = 'ขออภัย ตอบไม่ได้ในรอบนี้'
        }
        if (stopped) return

        const spoken = stripForSpeech(reply)
        setSaid(spoken)
        setPhase('speaking')
        await speak(spoken, { lang, rate: rateRef.current })
        if (stopped) return
      }
    }

    converse()

    return () => {
      stopped = true
      micRef.current?.abort()
      micRef.current = null
      stopSpeaking()
    }
  }, [open, lang, hear])

  // Esc leaves. A hands-free mode that can only be left with the mouse is not
  // hands-free, and this one holds the microphone until it is told otherwise.
  useEffect(() => {
    if (!open) return
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  /** Ends the current phase early — send what has been said, or stop talking. */
  function skip() {
    if (phase === 'listening') micRef.current?.stop()
    else if (phase === 'speaking') stopSpeaking()
  }

  const status = PHASES[phase] ?? PHASES.listening
  const working = phase === 'thinking' && tool ? WORKING[tool] : null

  return (
    <AnimatePresence>
      {open && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.2 }}
          className="fixed inset-0 z-50 flex flex-col bg-background/95 backdrop-blur-md"
          role="dialog"
          aria-label="โหมดเสียง"
        >
          <div className="flex items-center gap-2 p-3">
            <span className="flex items-center gap-1.5 text-sm font-medium">
              <NovaMark className="size-3.5" />
              โหมดเสียง
            </span>

            <div className="ml-auto flex items-center gap-1 rounded-lg border p-0.5">
              {LANGS.map((option) => (
                <button
                  key={option.code}
                  onClick={() => setLang(option.code)}
                  className={cn(
                    'rounded-md px-2 py-1 text-xs transition-colors',
                    lang === option.code
                      ? 'bg-secondary font-medium text-secondary-foreground'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  {option.label}
                </button>
              ))}
            </div>

            {/* Applies to the next answer, not the one being read — stopping
                Nova mid-sentence to change the speed would lose the sentence. */}
            <div className="flex items-center gap-1 rounded-lg border p-0.5">
              {RATES.map((option) => (
                <button
                  key={option}
                  onClick={() => setRate(option)}
                  aria-label={`ความเร็วเสียง ${option} เท่า`}
                  className={cn(
                    'rounded-md px-2 py-1 text-xs transition-colors',
                    rate === option
                      ? 'bg-secondary font-medium text-secondary-foreground'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  {option}×
                </button>
              ))}
            </div>

            <Button
              size="icon"
              variant="ghost"
              className="size-9"
              onClick={onClose}
              aria-label="ออกจากโหมดเสียง"
            >
              <X className="size-5" />
            </Button>
          </div>

          <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-8 px-6 pb-10">
            <Orb phase={phase} tone={status.tone} />

            <div className="flex flex-col items-center gap-2 text-center">
              <p className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                {phase === 'thinking' && <Loader2 className="size-3.5 animate-spin" />}
                {working ?? status.label}
              </p>

              {error && (
                <p className="max-w-sm text-sm text-destructive">{error}</p>
              )}
            </div>

            {/* The exchange, in as much detail as fits. Voice needs a written
                trace beside it: a price heard once and misheard is worse than
                no answer, and this is where someone checks. */}
            <div className="flex w-full max-w-xl flex-col gap-3 overflow-y-auto">
              {heard && (
                <p className="self-end rounded-2xl bg-secondary px-4 py-2 text-[15px] text-secondary-foreground">
                  {heard}
                </p>
              )}
              {said && (
                <p className="self-start text-[15px] leading-relaxed whitespace-pre-line">
                  {said}
                </p>
              )}
            </div>
          </div>

          <div className="flex items-center justify-center gap-3 pb-10">
            <Button
              variant="secondary"
              className="gap-2 rounded-full px-5"
              disabled={phase === 'thinking' || phase === 'error'}
              onClick={skip}
            >
              <SkipForward className="size-4" />
              {phase === 'speaking' ? 'ข้าม' : 'ส่งเลย'}
            </Button>
            <Button variant="outline" className="rounded-full px-5" onClick={onClose}>
              จบการสนทนา
            </Button>
          </div>

          {using && (
            <p className="pb-4 text-center text-xs text-muted-foreground">
              เสียง: {using}
            </p>
          )}
        </motion.div>
      )}
    </AnimatePresence>
  )
}

/** The one thing on screen that says whether anything is happening at all. */
function Orb({ phase, tone }) {
  const listening = phase === 'listening'

  return (
    <div className="relative flex size-40 items-center justify-center">
      {listening && (
        <motion.span
          className={cn('absolute inset-0 rounded-full opacity-20', tone)}
          animate={{ scale: [1, 1.25, 1], opacity: [0.2, 0.05, 0.2] }}
          transition={{ duration: 2, repeat: Infinity, ease: 'easeInOut' }}
        />
      )}
      <motion.div
        className={cn(
          'flex size-28 items-center justify-center rounded-full text-white shadow-lg',
          tone,
        )}
        animate={
          phase === 'speaking'
            ? { scale: [1, 1.06, 0.98, 1.04, 1] }
            : phase === 'thinking'
              ? { scale: [1, 1.03, 1] }
              : { scale: 1 }
        }
        transition={{
          duration: phase === 'speaking' ? 1.1 : 1.6,
          repeat: phase === 'listening' ? 0 : Infinity,
          ease: 'easeInOut',
        }}
      >
        <Mic className="size-10" />
      </motion.div>
    </div>
  )
}
