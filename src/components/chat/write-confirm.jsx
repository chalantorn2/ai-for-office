import { useState } from 'react'
import { motion } from 'motion/react'
import {
  Ban,
  CircleCheck,
  Clock,
  ExternalLink,
  Loader2,
  Pencil,
  Plus,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * A change to ContactRate that Nova has proposed and nobody has agreed to yet.
 *
 * The card is the last thing between the model and the live rate table, so it is
 * built to be read rather than clicked past. Every field that would change is
 * listed with its current value beside the new one — an edit that touches one
 * price shows one row, and the strikethrough on the left is what makes a wrong
 * figure obvious at a glance instead of after it is saved.
 *
 * Nothing here is optimistic. The buttons wait for the server, because the
 * answer is not always the one the click asked for: another tab may have
 * confirmed it already, or the tour may have been edited in ContactRate since,
 * in which case the change is refused and has to be proposed again.
 */
export function WriteConfirm({ card, onDecide }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const decided = card.status !== 'pending'
  const creating = card.action === 'create'

  async function decide(confirm) {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await onDecide(card.id, confirm)
    } catch (err) {
      setError(err.message || 'ทำรายการไม่สำเร็จ')
    } finally {
      setBusy(false)
    }
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.22, ease: 'easeOut' }}
      className={cn(
        'mt-3 overflow-hidden rounded-xl border text-sm',
        card.status === 'pending' && 'border-amber-500/40 bg-amber-500/[0.04]',
      )}
    >
      <div className="flex items-center gap-2 border-b px-3 py-2">
        {creating ? (
          <Plus className="size-3.5 shrink-0 text-muted-foreground" />
        ) : (
          <Pencil className="size-3.5 shrink-0 text-muted-foreground" />
        )}
        <span className="min-w-0 flex-1 truncate font-medium">
          {creating ? 'เพิ่ม' : 'แก้ไข'}
          {card.entity_label}
          {creating ? 'ใหม่' : ''} · {card.record_name || 'ไม่มีชื่อ'}
        </span>
        <StatusBadge status={card.status} />
      </div>

      <dl className="divide-y">
        {card.changes.map((change) => (
          <div key={change.field} className="grid grid-cols-[7rem_1fr] gap-2 px-3 py-2">
            <dt className="text-xs text-muted-foreground">{change.label}</dt>
            <dd className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
              {/* Absent on a create — there is no previous value to strike out,
                  and an em dash there would read as "was blank" rather than
                  "did not exist". */}
              {!creating && (
                <span className="text-muted-foreground line-through break-words">
                  {change.from ?? '—'}
                </span>
              )}
              <span className="font-medium break-words">{change.to}</span>
            </dd>
          </div>
        ))}
      </dl>

      {error && (
        <p className="border-t px-3 py-2 text-xs text-destructive">{error}</p>
      )}

      {!decided && (
        <div className="flex items-center gap-2 border-t px-3 py-2">
          <p className="mr-auto text-xs text-muted-foreground">
            ยังไม่ได้บันทึก — กดยืนยันเพื่อเขียนลง ContactRate
          </p>
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => decide(false)}>
            ยกเลิก
          </Button>
          <Button size="sm" disabled={busy} onClick={() => decide(true)}>
            {busy && <Loader2 className="size-3.5 animate-spin" />}
            ยืนยัน
          </Button>
        </div>
      )}

      {/* Only after it exists. Reading the change back on its own page is the
          usual next step, and on a create there is nowhere to go until then. */}
      {card.link && (
        <div className="border-t px-3 py-2">
          <a
            href={card.link}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
          >
            <ExternalLink className="size-3.5" />
            เปิดใน ContactRate
          </a>
        </div>
      )}
    </motion.div>
  )
}

const STATUS = {
  applied: { label: 'ยืนยันแล้ว', icon: CircleCheck, className: 'text-emerald-600 dark:text-emerald-500' },
  cancelled: { label: 'ยกเลิกแล้ว', icon: Ban, className: 'text-muted-foreground' },
  expired: { label: 'หมดอายุ', icon: Clock, className: 'text-muted-foreground' },
  pending: { label: 'รอยืนยัน', icon: Clock, className: 'text-amber-600 dark:text-amber-500' },
}

function StatusBadge({ status }) {
  const { label, icon: Icon, className } = STATUS[status] ?? STATUS.pending
  return (
    <span className={cn('flex shrink-0 items-center gap-1 text-xs', className)}>
      <Icon className="size-3.5" />
      {label}
    </span>
  )
}
