/* Branch filter for report screens. Server-side, non-admin (role without
   canViewAllBranches) users are always forced to their own branch — see
   BelongsToBranch / CurrentBranch on the backend, which reads this exact
   `branchId` param (as ?branch_id=) for the users who ARE allowed to pick.
   This component is UI only: hiding the dropdown from branch-locked users is
   a convenience, not the security boundary — that boundary is server-side. */
import { useEffect, useState } from 'react'
import { Field, Select } from './ui'
import { branchesApi, type Branch, type AuthUser } from '../api'

export function useBranchFilter(user?: AuthUser | null) {
  const canFilter = !!user?.canViewAllBranches
  const [branches, setBranches] = useState<Branch[]>([])
  const [branchId, setBranchId] = useState<number | undefined>(undefined)

  useEffect(() => {
    if (canFilter) branchesApi.list().then(setBranches).catch(() => setBranches([]))
  }, [canFilter])

  return { canFilter, branches, branchId, setBranchId }
}

export function BranchFilter({ user, branches, branchId, onChange }: {
  user?: AuthUser | null; branches: Branch[]; branchId: number | undefined; onChange: (id: number | undefined) => void
}) {
  if (!user?.canViewAllBranches) {
    return user?.branch ? (
      <Field label="Branch"><div style={{ padding: '9px 0', fontSize: 13.5, color: 'var(--tx-1)', fontWeight: 600 }}>{user.branch.name}</div></Field>
    ) : null
  }
  return (
    <Field label="Branch">
      <Select value={branchId ?? ''} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : undefined)} style={{ minWidth: 190 }}>
        <option value="">All Branches</option>
        {branches.map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
      </Select>
    </Field>
  )
}
