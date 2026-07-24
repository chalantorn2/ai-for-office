/**
 * Prepares an attached image for upload.
 *
 * The model is billed by an image's pixels, not its bytes — roughly
 * (width × height) / 750 tokens — so shrinking is the only thing here that
 * saves money, and recompressing a file that is already small enough saves
 * nothing at all. That is why an image that needs neither is passed through
 * untouched: re-encoding it would cost quality on exactly the small text this
 * app exists to read, and the bill would not move.
 *
 * The cap is Sonnet's own limit. Anything larger is downscaled by Anthropic
 * before it is looked at, so those extra pixels are only ever paid for in
 * upload time — and on the shared host, in the request-size ceiling.
 */

/** Sonnet 5 reads no more than this on the long edge and shrinks the rest itself. */
export const MAX_EDGE = 2576

/** Kept in step with NOVA_IMAGE_MAX_COUNT / NOVA_IMAGE_MAX_BYTES in api/lib/images.php. */
export const MAX_IMAGES = 4
export const MAX_BYTES = 5 * 1024 * 1024

/**
 * Across one question, before base64 inflates it by a third.
 *
 * Four images at the per-image limit would be 20 MB, and the shared host's
 * post_max_size is commonly 8 MB — over which PHP throws the body away and the
 * question arrives looking empty. Resized screenshots are around a megabyte
 * each, so this is generous in practice and only bites the case that would
 * otherwise fail confusingly at the server.
 */
export const MAX_TOTAL_BYTES = 5 * 1024 * 1024

/**
 * High for a lossy format, deliberately. These are screenshots of rate tables,
 * and JPEG artefacts land hardest on small text against a flat background —
 * which is the entire subject. The file is still a fraction of the PNG, and
 * quality has no effect on the token bill either way.
 */
const QUALITY = 0.9

const ACCEPTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

export function isSupportedImage(file) {
  return ACCEPTED.includes(file.type)
}

/**
 * @typedef {object} PreparedImage
 * @property {string} name        original filename, for the tooltip
 * @property {string} media_type
 * @property {string} data        base64, no data: prefix
 * @property {number} width
 * @property {number} height
 * @property {number} bytes
 * @property {string} previewUrl  object URL — the caller revokes it
 */

/**
 * @param {File} file
 * @returns {Promise<PreparedImage>}
 * @throws {Error} with a message meant to be shown as-is
 */
export async function prepareImage(file) {
  const bitmap = await decode(file)

  try {
    const { width, height } = bitmap
    const scale = Math.min(1, MAX_EDGE / Math.max(width, height))

    // Nothing to gain: already within the model's resolution, already a format
    // the API takes, already small enough to post. Re-encoding would only lose
    // detail.
    if (scale === 1 && isSupportedImage(file) && file.size <= MAX_BYTES) {
      return {
        name: file.name,
        media_type: file.type,
        data: await toBase64(file),
        width,
        height,
        bytes: file.size,
        previewUrl: URL.createObjectURL(file),
      }
    }

    const blob = await render(bitmap, scale)
    if (blob.size > MAX_BYTES) {
      throw new Error(`${file.name} ใหญ่เกินไปแม้ย่อแล้ว`)
    }

    return {
      name: file.name,
      media_type: 'image/jpeg',
      data: await toBase64(blob),
      width: Math.round(width * scale),
      height: Math.round(height * scale),
      bytes: blob.size,
      previewUrl: URL.createObjectURL(blob),
    }
  } finally {
    bitmap.close?.()
  }
}

/**
 * Decodes to a bitmap, honouring the EXIF orientation phones write instead of
 * rotating the pixels. Without `from-image`, a photo taken in portrait arrives
 * on its side — and a rate sheet the model has to read sideways is a rate sheet
 * it reads wrong.
 */
async function decode(file) {
  try {
    return await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    // Chrome cannot decode HEIC, which is what an iPhone produces unless the
    // camera is set to "Most Compatible". Nothing to work around — say which
    // file and what to do.
    throw new Error(`เปิดไฟล์ ${file.name} ไม่ได้ (รองรับ JPEG, PNG, GIF, WebP)`)
  }
}

/** Draws the bitmap at `scale` and encodes it as JPEG. */
function render(bitmap, scale) {
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(bitmap.width * scale))
  canvas.height = Math.max(1, Math.round(bitmap.height * scale))

  const ctx = canvas.getContext('2d')
  // JPEG has no alpha, and an unpainted canvas is transparent black — a
  // screenshot saved as a transparent PNG would come out as black text on
  // black. Paint the sheet white first.
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('ย่อรูปไม่สำเร็จ'))),
      'image/jpeg',
      QUALITY,
    )
  })
}

/** Base64 payload without the `data:...;base64,` prefix the API does not take. */
function toBase64(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result).split(',')[1] ?? '')
    reader.onerror = () => reject(new Error('อ่านไฟล์ไม่สำเร็จ'))
    reader.readAsDataURL(blob)
  })
}
