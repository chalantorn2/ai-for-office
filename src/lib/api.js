/**
 * API client. Holds the JWT in localStorage and attaches it to every request.
 *
 * localStorage is readable by any script on the origin, so this is only as safe
 * as the page's XSS posture — acceptable for an internal tool on its own
 * subdomain, and the token expires after 12 hours. Revisit if Nova ever renders
 * untrusted HTML.
 */

const TOKEN_KEY = 'nova.token'
const BASE = import.meta.env.VITE_API_BASE_URL ?? '/api'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else {
    localStorage.removeItem(TOKEN_KEY)
    // Object URLs outlive the session that was allowed to fetch them. Dropping
    // them on the way out means the next person to sign in on this machine
    // cannot see the last one's screenshots still on screen.
    forgetImages()
  }
}

/**
 * Attached images, keyed by ai_message_images id.
 *
 * `<img src>` cannot carry an Authorization header and the uploads directory is
 * closed to direct requests, so each image is fetched with the token and shown
 * as a blob. One promise per id is cached: a thread can mention the same image
 * in several renders, and a fetch per render would re-download it every time.
 *
 * @type {Map<number, Promise<string>>}
 */
const imageUrls = new Map()

/** Resolves to an object URL for one attachment. */
export function loadImage(id) {
  let url = imageUrls.get(id)
  if (url) return url

  url = (async () => {
    const res = await fetch(`${BASE}/image.php?id=${id}`, {
      headers: authHeaders(getToken()),
    })
    if (!res.ok) {
      // Not cached as a rejection the caller keeps hitting — dropping it lets a
      // remount try again, which is the right behaviour for a blip.
      imageUrls.delete(id)
      if (res.status === 401) setToken(null)
      throw new ApiError(res.status, 'image_failed')
    }
    return URL.createObjectURL(await res.blob())
  })()

  imageUrls.set(id, url)
  return url
}

/** Releases every cached image. */
function forgetImages() {
  for (const pending of imageUrls.values()) {
    pending.then(URL.revokeObjectURL, () => {})
  }
  imageUrls.clear()
}

/**
 * The production host runs nginx in front of PHP-FPM and drops `Authorization`
 * before PHP ever sees it — measured, not assumed: a request carrying both
 * `Authorization` and `X-Nova-Token` arrives with only the second one. Every
 * authenticated call therefore looked unauthenticated, and the UI, doing as it
 * was told, logged the person straight back out.
 *
 * Both headers are sent. `Authorization` is the correct one and works anywhere
 * sane, including local dev; the custom header is what actually survives this
 * host. The server accepts either.
 */
function authHeaders(token) {
  if (!token) return {}
  return {
    Authorization: `Bearer ${token}`,
    'X-Nova-Token': token,
  }
}

export class ApiError extends Error {
  constructor(status, code, message) {
    super(message || code)
    this.status = status
    this.code = code
  }
}

async function request(path, { method = 'GET', body, auth = true } = {}) {
  const headers = {}
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const token = getToken()
  if (auth) Object.assign(headers, authHeaders(token))

  const res = await fetch(`${BASE}/${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  if (res.status === 204) return null

  let data = null
  try {
    data = await res.json()
  } catch {
    // Fall through — a non-JSON body is itself the error worth reporting.
  }

  if (!res.ok) {
    // An expired or forged token should drop the session rather than leave the
    // UI in a half-authenticated state.
    if (res.status === 401 && auth) setToken(null)
    throw new ApiError(res.status, data?.error ?? 'request_failed', data?.message)
  }

  return data
}

export const api = {
  login: (username, password) =>
    request('auth.php', {
      method: 'POST',
      body: { username, password },
      auth: false,
    }),

  me: () => request('auth.php'),

  listConversations: () => request('conversations.php'),

  getConversation: (id) => request(`conversations.php?id=${id}`),

  createConversation: (title, projectId = null) =>
    request('conversations.php', {
      method: 'POST',
      body: { title, project_id: projectId },
    }),

  renameConversation: (id, title) =>
    request(`conversations.php?id=${id}`, { method: 'PATCH', body: { title } }),

  /** Files a chat into a project, or out of one with `null`. */
  moveConversation: (id, projectId) =>
    request(`conversations.php?id=${id}`, {
      method: 'PATCH',
      body: { project_id: projectId },
    }),

  deleteConversation: (id) =>
    request(`conversations.php?id=${id}`, { method: 'DELETE' }),

  listProjects: () => request('projects.php'),

  createProject: (name) =>
    request('projects.php', { method: 'POST', body: { name } }),

  renameProject: (id, name) =>
    request(`projects.php?id=${id}`, { method: 'PATCH', body: { name } }),

  /** Deletes the folder only — the chats inside are released, not removed. */
  deleteProject: (id) => request(`projects.php?id=${id}`, { method: 'DELETE' }),

  /**
   * Tour changes Nova proposed in one chat, in whatever state they ended up.
   * Only needed on reload — during a turn the cards arrive on the stream.
   */
  listWrites: (conversationId) =>
    request(`writes.php?conversation_id=${conversationId}`),

  /**
   * Applies a proposed change to ContactRate, or turns it down.
   *
   * The only call in the app that writes to the main system's tables, and it
   * only ever runs from a button. `confirm` is sent explicitly rather than
   * implied by the endpoint, so neither outcome can be reached by a request
   * that lost a field on the way.
   */
  decideWrite: (writeId, confirm) =>
    request('writes.php', { method: 'POST', body: { write_id: writeId, confirm } }),

  /**
   * Spend for the current calendar month. `scope: 'all'` adds the office total
   * and a per-user breakdown, and is rejected with 403 for non-admins.
   */
  usage: (scope) => request(`usage.php${scope ? `?scope=${scope}` : ''}`),

  /**
   * Streams a reply from the assistant.
   *
   * EventSource can't POST or send auth headers, so this reads the SSE body off
   * fetch directly and parses the frames.
   *
   * `replaceFrom` is an ai_messages id. Passing one rewrites history: that
   * message and everything after it is deleted server-side before the new
   * question is stored, which is how editing an earlier question works.
   *
   * `projectId` only applies to the first question of a new chat — that is the
   * request that creates the conversation, and it files it into that folder.
   *
   * @param {object} handlers - { onMeta, onTool, onWrite, onText, onDone, onError }
   * @param {AbortSignal} [signal] - aborts the request when the user hits stop
   */
  async streamAssistant(
    { conversationId, message, replaceFrom, projectId, images, keepImageIds, voice },
    handlers,
    signal,
  ) {
    const token = getToken()
    const res = await fetch(`${BASE}/assistant.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...authHeaders(token),
      },
      body: JSON.stringify({
        conversation_id: conversationId,
        message,
        replace_from: replaceFrom ?? null,
        project_id: projectId ?? null,
        // Only the payload the API needs. `previewUrl` is an object URL that
        // means nothing off this tab, and the rest is for the thumbnail.
        images: (images ?? []).map(({ media_type, data }) => ({ media_type, data })),
        // Attachments of the question being rewritten, kept rather than
        // re-uploaded (see api/lib/images.php).
        keep_image_ids: keepImageIds ?? [],
        // Asked out loud. Changes the shape of the answer, not the rules it
        // answers under — see NOVA_VOICE_HINT in api/assistant.php.
        voice: Boolean(voice),
      }),
      signal,
    })

    if (!res.ok) {
      if (res.status === 401) setToken(null)
      let data = null
      try {
        data = await res.json()
      } catch {
        // Non-JSON error body — the status alone is what we have.
      }
      throw new ApiError(res.status, data?.error ?? 'request_failed', data?.message)
    }

    const reader = res.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''

    for (;;) {
      const { done, value } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })

      // SSE frames are separated by a blank line; a frame can straddle chunks.
      let split
      while ((split = buffer.indexOf('\n\n')) !== -1) {
        const frame = buffer.slice(0, split)
        buffer = buffer.slice(split + 2)

        let event = 'message'
        let data = ''
        for (const line of frame.split('\n')) {
          if (line.startsWith('event:')) event = line.slice(6).trim()
          else if (line.startsWith('data:')) data += line.slice(5).trim()
        }
        if (!data) continue

        let payload
        try {
          payload = JSON.parse(data)
        } catch {
          continue
        }

        switch (event) {
          case 'meta':
            handlers.onMeta?.(payload)
            break
          case 'tool':
            handlers.onTool?.(payload)
            break
          case 'write':
            handlers.onWrite?.(payload.card)
            break
          case 'text':
            handlers.onText?.(payload.delta)
            break
          case 'done':
            handlers.onDone?.(payload)
            break
          case 'error':
            handlers.onError?.(payload)
            break
        }
      }
    }
  },
}
