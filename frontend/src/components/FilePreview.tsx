/* Shared inline preview for attachments (contract images, KYC scans, collateral
   docs). Every "view" / eye button in the app funnels a downloaded Blob through
   this hook instead of window.open() — images render as <img>, PDFs in an
   <iframe>, anything else falls back to a download link.

   Usage:
     const preview = useFilePreview()
     const view = async (d) => { try { preview.open(await someApi.download(d.id), d.filePath) } catch { notify.error(...) } }
     return <>...{preview.node}</>
*/
import { useEffect, useState } from 'react'
import { Modal, Btn } from './ui'

type Kind = 'image' | 'pdf' | 'other'

function basename(path?: string): string {
  return (path || '').split(/[\\/]/).pop() || 'Attachment'
}

function kindOf(blob: Blob, hintPath?: string): Kind {
  const ext = (hintPath || '').split('.').pop()?.toLowerCase() || ''
  if (blob.type === 'application/pdf' || ext === 'pdf') return 'pdf'
  if (blob.type.startsWith('image/') || /^(png|jpe?g|webp|gif|bmp|avif|svg)$/.test(ext)) return 'image'
  return 'other'
}

export function useFilePreview() {
  const [state, setState] = useState<{ url: string; kind: Kind; name: string } | null>(null)

  // Object URL for the open preview lives until the modal closes or swaps.
  useEffect(() => () => { if (state) URL.revokeObjectURL(state.url) }, [state])

  const open = (blob: Blob, hintPath?: string, name?: string) => {
    setState({ url: URL.createObjectURL(blob), kind: kindOf(blob, hintPath), name: name || basename(hintPath) })
  }
  const close = () => setState(null)

  const node = (
    <Modal open={!!state} onClose={close} title={state?.name} sub="Preview" width={900}
      footer={<Btn variant="solid" onClick={close}>Close</Btn>}>
      {state?.kind === 'image' && (
        <img src={state.url} alt={state.name} style={{ display: 'block', maxWidth: '100%', margin: '0 auto', borderRadius: 'var(--r-s)' }} />
      )}
      {state?.kind === 'pdf' && (
        <iframe src={state.url} title={state.name} style={{ width: '100%', height: '75vh', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }} />
      )}
      {state?.kind === 'other' && (
        <div className="col gap-2" style={{ alignItems: 'center', padding: 24 }}>
          <div className="t-2" style={{ fontSize: 12.5 }}>This file type can't be previewed inline.</div>
          <a href={state.url} download={state.name}><Btn variant="solid" icon="download" size="sm">Download</Btn></a>
        </div>
      )}
    </Modal>
  )

  return { open, close, node }
}
