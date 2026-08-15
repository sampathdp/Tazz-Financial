/* Shared Export Excel / Export PDF button pair for report screens — same
   visual language everywhere (PageHead actions slot). See lib/export.ts for
   what "Export Excel" actually builds and the *ReportController::exportPdf
   methods (via App\Support\ReportExport) for what "Export PDF" renders. */
import { useState } from 'react'
import { Btn } from './ui'
import { useNotify } from './Notify'
import { getErrorText } from '../lib/apiError'

export function ExportButtons({ onExcel, onPdf }: { onExcel?: () => void; onPdf?: () => Promise<void> }) {
  const notify = useNotify()
  const [busy, setBusy] = useState(false)

  const doPdf = async () => {
    if (!onPdf) return
    setBusy(true)
    try {
      await onPdf()
    } catch (ex: any) {
      notify.error('Export failed', getErrorText(ex, 'Could not generate PDF'))
    } finally { setBusy(false) }
  }

  return (
    <div className="row gap-2">
      {onExcel && <Btn variant="solid" size="sm" icon="download" onClick={onExcel}>Export Excel</Btn>}
      {onPdf && <Btn variant="solid" size="sm" icon="download" onClick={doPdf} disabled={busy}>{busy ? 'Exporting…' : 'Export PDF'}</Btn>}
    </div>
  )
}
