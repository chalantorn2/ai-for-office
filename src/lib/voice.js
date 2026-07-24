/**
 * Speech in and speech out, both from the browser.
 *
 * Anthropic's API has no speech of its own, so the alternative was a second
 * vendor for transcription and a third for the voice — each one billed per
 * minute, and each one another place the office's rates would have to travel
 * to. The browser does both for nothing. The price is that quality is whatever
 * the machine happens to ship, and that Chrome sends the audio to Google for
 * recognition — see the note in PROJECT_NOTES.md before turning this on for a
 * question that names a supplier.
 *
 * Recognition and synthesis must never run at once. The microphone hears the
 * speakers, Nova's own reply comes back as the next question, and the loop eats
 * itself. Everything here assumes one at a time, and `voice-mode.jsx` is what
 * enforces it.
 */

const Recognition =
  typeof window !== 'undefined'
    ? (window.SpeechRecognition ?? window.webkitSpeechRecognition)
    : null

export const CAN_LISTEN = Boolean(Recognition)
export const CAN_SPEAK =
  typeof window !== 'undefined' && 'speechSynthesis' in window

/** Both halves are needed for a conversation; one alone is not worth offering. */
export const VOICE_SUPPORTED = CAN_LISTEN && CAN_SPEAK

export const LANGS = [
  { code: 'th-TH', label: 'ไทย' },
  { code: 'en-US', label: 'EN' },
]

/**
 * Starts one utterance of recognition.
 *
 * Deliberately not `continuous`: in continuous mode Chrome keeps the microphone
 * open and delivers a running transcript, which means deciding in application
 * code when someone has finished a sentence. One utterance per start hands that
 * judgement to the browser's own endpointing, and the caller restarts when it
 * wants the next one.
 *
 * @returns {{ stop: () => void, abort: () => void }}
 */
export function listen({ lang = 'th-TH', onInterim, onFinal, onEnd, onError }) {
  if (!Recognition) throw new Error('speech recognition unavailable')

  const rec = new Recognition()
  rec.lang = lang
  rec.continuous = false
  // Interim results are what make the overlay feel alive — without them the
  // screen shows nothing at all until the sentence is over, which reads as a
  // microphone that is not working.
  rec.interimResults = true
  rec.maxAlternatives = 1

  let settled = ''

  rec.onresult = (event) => {
    let interim = ''
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const result = event.results[i]
      if (result.isFinal) settled += result[0].transcript
      else interim += result[0].transcript
    }
    if (interim) onInterim?.(interim)
  }

  rec.onerror = (event) => {
    // Fired whenever a turn passes in silence. That is an ordinary event in a
    // hands-free loop, not a failure, and reporting it would put an error on
    // screen every time somebody paused to think.
    if (event.error === 'no-speech' || event.error === 'aborted') return
    onError?.(event.error)
  }

  rec.onend = () => {
    const text = settled.trim()
    if (text) onFinal?.(text)
    onEnd?.(Boolean(text))
  }

  rec.start()

  return {
    // Ends the utterance and keeps whatever has been recognised so far.
    stop: () => {
      try {
        rec.stop()
      } catch {
        // Already stopped — nothing to do.
      }
    },
    // Drops it, including any text in flight. Used when leaving voice mode.
    abort: () => {
      rec.onresult = null
      rec.onerror = null
      rec.onend = null
      try {
        rec.abort()
      } catch {
        // Already gone.
      }
    },
  }
}

/**
 * The installed voices, once the browser has them.
 *
 * Chrome populates the list asynchronously and returns an empty array on the
 * first call, so a voice picked at module load is no voice at all.
 */
function voicesReady() {
  return new Promise((resolve) => {
    const have = speechSynthesis.getVoices()
    if (have.length) return resolve(have)

    const done = () => {
      speechSynthesis.removeEventListener('voiceschanged', done)
      resolve(speechSynthesis.getVoices())
    }
    speechSynthesis.addEventListener('voiceschanged', done)
    // Some builds never fire the event when the list is genuinely empty.
    setTimeout(done, 1000)
  })
}

/**
 * Best available voice for a language tag, or null to let the browser choose.
 *
 * `localService === false` marks a voice synthesised on Google's servers rather
 * than by the operating system, and for Thai the difference is not subtle — the
 * bundled Windows voice is the flat, clipped one people mean when they say the
 * speech sounds bad. Picking the first match for the language, which is what
 * this did at first, lands on whichever the browser happens to list first.
 */
async function pickVoice(lang) {
  const voices = await voicesReady()
  const tag = lang.toLowerCase()
  const base = tag.slice(0, 2)

  const exact = voices.filter((v) => v.lang?.toLowerCase().replace('_', '-') === tag)
  const related = voices.filter((v) => v.lang?.toLowerCase().startsWith(base))

  return (
    exact.find((v) => v.localService === false) ??
    related.find((v) => v.localService === false) ??
    exact[0] ??
    related[0] ??
    null
  )
}

/** Name of the voice that would be used, for the overlay to show. */
export async function voiceName(lang) {
  if (!CAN_SPEAK) return ''
  const voice = await pickVoice(lang)
  return voice ? voice.name : ''
}

/**
 * Reads text aloud. Resolves when it finishes, is cancelled, or fails —
 * the caller's loop has to move on either way.
 */
export async function speak(text, { lang = 'th-TH', rate = 1 } = {}) {
  if (!CAN_SPEAK || !text.trim()) return

  // A previous utterance still in the queue would be read first.
  speechSynthesis.cancel()

  const voice = await pickVoice(lang)

  return new Promise((resolve) => {
    const utterance = new SpeechSynthesisUtterance(text)
    utterance.lang = lang
    utterance.rate = rate
    if (voice) utterance.voice = voice

    let done = false
    const finish = () => {
      if (done) return
      done = true
      clearInterval(keepAlive)
      resolve()
    }

    utterance.onend = finish
    utterance.onerror = finish

    // Chrome stops a long utterance after ~15 seconds with no event of any
    // kind — the answer simply goes quiet mid-sentence and the loop waits
    // forever for an `onend` that never comes. Pausing and resuming on a timer
    // keeps it running; it is the standard workaround and there is no better
    // one.
    const keepAlive = setInterval(() => {
      if (!speechSynthesis.speaking) return finish()
      speechSynthesis.pause()
      speechSynthesis.resume()
    }, 10000)

    speechSynthesis.speak(utterance)
  })
}

export function stopSpeaking() {
  if (CAN_SPEAK) speechSynthesis.cancel()
}

/**
 * Turns a reply into something worth hearing.
 *
 * Nova writes markdown for a screen: tables of rates, names wrapped in links
 * back to ContactRate, bold on the figures. Read literally, that comes out as
 * "open square bracket Patong Beach Hotel close square bracket open paren
 * https colon slash slash…" — so the markup is removed and the one thing that
 * genuinely cannot be spoken, a table, is replaced by a sentence pointing at
 * the screen. The table is still on screen; voice mode never hides the chat.
 */
export function stripForSpeech(markdown) {
  if (!markdown) return ''

  let text = markdown

  // Fenced code, and the sources block web search appends — a list of URLs is
  // the worst possible thing to read out loud.
  text = text.replace(/```[\s\S]*?```/g, ' ')
  text = text.replace(/\*\*แหล่งอ้างอิงจากเว็บ\*\*[\s\S]*$/g, ' ')

  // Tables, as whole blocks. Counting the body rows — everything but the header
  // and the |---|---| separator — is what makes the sentence useful.
  text = text.replace(/(?:^\|.*\|[ \t]*$\n?)+/gm, (block) => {
    const rows = block.trim().split('\n')
    const body = rows.filter((row) => !/^\|[\s:|-]+\|$/.test(row.trim())).length - 1
    return body > 0 ? ` มีตาราง ${body} รายการอยู่บนหน้าจอ ` : ' มีตารางอยู่บนหน้าจอ '
  })

  text = text
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    // Links keep their label and lose the URL.
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/^\s*>\s?/gm, '')
    .replace(/^\s*[-*+]\s+/gm, '')
    .replace(/^\s*\d+\.\s+/gm, '')
    .replace(/^\s*([-*_])\s*(?:\1\s*){2,}$/gm, ' ')
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/__([^_]+)__/g, '$1')
    .replace(/(?<!\w)[*_]([^*_\n]+)[*_](?!\w)/g, '$1')
    // Anything still carrying a scheme is a bare URL.
    .replace(/https?:\/\/\S+/g, ' ')

  return text
    .replace(/[ \t]+/g, ' ')
    .replace(/\n{2,}/g, '\n')
    .trim()
}
