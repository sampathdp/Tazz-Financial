/* Picks (or adds) the customer bank account that loan / FD payments are sent to.
   Mandatory on every new Loan and Fixed Deposit. The account is stored encrypted
   server-side; this component only ever sees the masked number. */
import { useEffect, useState } from 'react'
import { Btn, Field, Input, Select, Modal, ErrorBanner } from './ui'
import { banksApi, customerBankDetailsApi, type Bank, type CustomerBankDetail } from '../api'
import { getErrorText } from '../lib/apiError'

const label = (d: CustomerBankDetail) =>
  `${d.bank?.name || 'Bank'}${d.bankBranch ? ` · ${d.bankBranch}` : ''} — ${d.accountNumberMasked} (${d.accountHolder})`

function AddModal({ customerId, open, existing, onClose, onAdded }: {
  customerId: number; open: boolean; existing?: CustomerBankDetail; onClose: () => void; onAdded: (d: CustomerBankDetail) => void
}) {
  const [banks, setBanks] = useState<Bank[]>([])
  const [bankId, setBankId] = useState<number | undefined>()
  const [holder, setHolder] = useState('')
  const [branch, setBranch] = useState('')
  const [accountNo, setAccountNo] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setBankId(existing?.bankId); setHolder(existing?.accountHolder || ''); setBranch(existing?.bankBranch || ''); setAccountNo(''); setErr('')
    banksApi.list().then((r) => setBanks(r.filter((b) => (b.status || 'Active') === 'Active'))).catch(() => setBanks([]))
  }, [open, existing])

  const save = async () => {
    if (!bankId) { setErr('Select the bank.'); return }
    if (!holder.trim()) { setErr('Enter the account holder name.'); return }
    if (!existing && !accountNo.trim()) { setErr('Enter the account number.'); return }
    setBusy(true); setErr('')
    try {
      const body = { bankId, accountHolder: holder.trim(), bankBranch: branch.trim() || undefined, accountNumber: accountNo.trim() || undefined }
      onAdded(existing
        ? await customerBankDetailsApi.update(customerId, existing.id, body)
        : await customerBankDetailsApi.create(customerId, body))
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not save the bank account.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={460} title={existing ? 'Update Customer Bank Account' : 'Add Customer Bank Account'} sub="Stored encrypted. Used to send loan and FD payments."
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : existing ? 'Update Account' : 'Save Account'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Field label="Bank" full><Select value={bankId ?? ''} onChange={(e) => setBankId(Number(e.target.value) || undefined)}>
          <option value="">—</option>
          {banks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </Select></Field>
        <Field label="Bank Branch"><Input value={branch} onChange={(e) => setBranch(e.target.value)} /></Field>
        <Field label="Account Number"><Input value={accountNo} onChange={(e) => setAccountNo(e.target.value)} autoComplete="off" placeholder={existing ? `Leave blank to keep ${existing.accountNumberMasked}` : ''} /></Field>
        <Field label="Account Holder Name" full><Input value={holder} onChange={(e) => setHolder(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

export function CustomerBankDetailPicker({ customerId, value, onChange }: {
  customerId?: number
  value?: number
  onChange: (id: number | undefined) => void
}) {
  const [rows, setRows] = useState<CustomerBankDetail[]>([])
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState(false)
  const selected = rows.find((d) => d.id === value)

  useEffect(() => {
    setRows([])
    if (!customerId) return
    customerBankDetailsApi.list(customerId).then((r) => {
      setRows(r)
      // A customer with a single account needs no extra click.
      if (r.length === 1 && !value) onChange(r[0].id)
    }).catch(() => setRows([]))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerId])

  return (
    <>
      <div className="row gap-2">
        <div style={{ flex: 1 }}>
          <Select value={value ?? ''} disabled={!customerId} onChange={(e) => onChange(Number(e.target.value) || undefined)}>
            <option value="">{customerId ? (rows.length ? 'Select payment bank account…' : 'No bank account yet — add one') : 'Select a customer first'}</option>
            {rows.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}
          </Select>
        </div>
        {selected && <Btn variant="ghost" icon="edit" onClick={() => setEditing(true)}>Update</Btn>}
        <Btn variant="solid" icon="plus" disabled={!customerId} onClick={() => setAdding(true)}>Add Account</Btn>
      </div>
      {customerId && <AddModal customerId={customerId} open={adding} onClose={() => setAdding(false)}
        onAdded={(d) => { setRows((r) => [...r, d]); onChange(d.id); setAdding(false) }} />}
      {customerId && selected && <AddModal customerId={customerId} open={editing} existing={selected} onClose={() => setEditing(false)}
        onAdded={(d) => { setRows((r) => r.map((x) => (x.id === d.id ? d : x))); setEditing(false) }} />}
    </>
  )
}
