import { motion } from 'motion/react'
import { Hotel, Map, Truck, Wallet } from 'lucide-react'
import { NovaMark } from '@/components/nova-mark'
import { cn } from '@/lib/utils'

// Grounded in what the database actually holds: 280 tours, 494 hotel rates,
// 37 suppliers. Nothing here should suggest data the system does not have.
const SUGGESTIONS = [
  {
    icon: Map,
    title: 'ทัวร์กระบี่',
    prompt: 'ทัวร์กระบี่มีอะไรบ้าง ราคาผู้ใหญ่ไม่เกิน 1500',
  },
  {
    icon: Hotel,
    title: 'ราคาโรงแรม',
    prompt: 'โรงแรมป่าตอง ภูเก็ต ขอราคาเดือนตุลาคม',
  },
  {
    icon: Wallet,
    title: 'เทียบราคา',
    prompt: 'ทัวร์เกาะพีพี ราคาผู้ใหญ่ เรียงจากถูกไปแพง',
  },
  {
    icon: Truck,
    title: 'ซัพพลายเออร์',
    prompt: 'ซัพพลายเออร์ที่มีทัวร์ภูเก็ตมากที่สุด 5 อันดับแรก',
  },
]

// The greeting and the cards arrive one after another rather than all at once,
// which makes an empty screen feel deliberate instead of unfinished.
const container = {
  animate: { transition: { staggerChildren: 0.06, delayChildren: 0.04 } },
}
const item = {
  initial: { opacity: 0, y: 10 },
  animate: { opacity: 1, y: 0, transition: { duration: 0.3, ease: 'easeOut' } },
}

export function EmptyState({ onPick }) {
  return (
    <motion.div
      variants={container}
      initial="initial"
      animate="animate"
      className="mx-auto flex w-full max-w-3xl flex-1 flex-col items-center justify-center px-4 py-10"
    >
      <motion.div variants={item}>
        <NovaMark />
      </motion.div>
      <motion.h1
        variants={item}
        className="mt-5 text-2xl font-semibold tracking-tight sm:text-3xl"
      >
        สวัสดี ฉันชื่อ Nova
      </motion.h1>
      <motion.p variants={item} className="mt-2 text-sm text-muted-foreground">
        ถามเกี่ยวกับทัวร์ โรงแรม และซัพพลายเออร์ในระบบได้เลย
      </motion.p>

      <div className="mt-8 grid w-full grid-cols-1 gap-3 sm:grid-cols-2">
        {SUGGESTIONS.map(({ icon: Icon, title, prompt }) => (
          <motion.button
            key={title}
            variants={item}
            whileHover={{ y: -2 }}
            whileTap={{ scale: 0.985 }}
            transition={{ duration: 0.15 }}
            onClick={() => onPick(prompt)}
            className={cn(
              'group flex flex-col gap-1.5 rounded-xl border bg-card p-4 text-left',
              'transition-colors hover:bg-accent hover:shadow-sm focus-visible:outline-none',
              'focus-visible:ring-2 focus-visible:ring-ring/50',
            )}
          >
            <span className="flex items-center gap-2 text-sm font-medium">
              <Icon className="size-4 text-muted-foreground transition-colors group-hover:text-foreground" />
              {title}
            </span>
            <span className="text-sm text-muted-foreground">{prompt}</span>
          </motion.button>
        ))}
      </div>
    </motion.div>
  )
}
