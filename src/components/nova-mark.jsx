import { cn } from '@/lib/utils'

/**
 * Nova's mark: a four-point star (a nova — a star that brightens sharply).
 * Sized by the `size` class passed in; defaults to a display size.
 */
export function NovaMark({ className }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
      className={cn('size-12', className)}
    >
      <path
        d="M12 1.5c.4 4.6 1.6 6.9 3.7 8.3 1.4 1 3.4 1.7 6.8 2.2-4.6.4-6.9 1.6-8.3 3.7-1 1.4-1.7 3.4-2.2 6.8-.4-4.6-1.6-6.9-3.7-8.3-1.4-1-3.4-1.7-6.8-2.2 4.6-.4 6.9-1.6 8.3-3.7 1-1.4 1.7-3.4 2.2-6.8Z"
        fill="currentColor"
      />
    </svg>
  )
}
