/* Client-side image downscale + WebP re-encode, run before uploading contract
   images / KYC scans. Keeps stored files small and in one format. PDFs and
   anything that isn't a raster image are returned untouched — callers can pass
   any File through blindly. If the browser can't produce a smaller WebP (or
   decoding fails), the original File is returned so an upload never breaks. */

export interface CompressOptions {
  /** Longest edge, in px. Larger images are scaled down to fit. */
  maxDim?: number
  /** WebP quality, 0–1. */
  quality?: number
  /** Skip work for files already at or below this size (bytes). */
  skipUnderBytes?: number
}

const DEFAULTS: Required<CompressOptions> = {
  maxDim: 2000,
  quality: 0.8,
  skipUnderBytes: 200 * 1024,
}

export function isRasterImage(file: File): boolean {
  return /^image\/(png|jpe?g|webp|bmp)$/i.test(file.type)
}

function loadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('decode failed'))
    img.src = url
  })
}

export async function compressImageToWebp(file: File, opts: CompressOptions = {}): Promise<File> {
  const { maxDim, quality, skipUnderBytes } = { ...DEFAULTS, ...opts }

  if (!isRasterImage(file)) return file
  if (file.type === 'image/webp' && file.size <= skipUnderBytes) return file

  const url = URL.createObjectURL(file)
  try {
    const img = await loadImage(url)
    const { naturalWidth: w, naturalHeight: h } = img
    if (!w || !h) return file

    const scale = Math.min(1, maxDim / Math.max(w, h))
    const outW = Math.max(1, Math.round(w * scale))
    const outH = Math.max(1, Math.round(h * scale))

    const canvas = document.createElement('canvas')
    canvas.width = outW
    canvas.height = outH
    const ctx = canvas.getContext('2d')
    if (!ctx) return file
    ctx.drawImage(img, 0, 0, outW, outH)

    const blob = await new Promise<Blob | null>((res) => canvas.toBlob(res, 'image/webp', quality))
    if (!blob || blob.size >= file.size) return file

    const name = file.name.replace(/\.[^.]+$/, '') + '.webp'
    return new File([blob], name, { type: 'image/webp', lastModified: Date.now() })
  } catch {
    return file
  } finally {
    URL.revokeObjectURL(url)
  }
}
