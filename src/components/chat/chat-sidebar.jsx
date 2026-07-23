import { useEffect, useRef, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import {
  LogOut,
  MessageSquare,
  PanelLeft,
  Pencil,
  Plus,
  Trash2,
  Wallet,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

/** Buckets conversations the way ChatGPT does: today, this week, older. */
function groupByAge(conversations) {
  const now = Date.now();
  const day = 86_400_000;
  const groups = { วันนี้: [], "7 วันก่อน": [], ก่อนหน้านี้: [] };

  for (const c of conversations) {
    // MySQL hands back "2026-07-22 15:47:49" — no zone, and Safari refuses to
    // parse it with a space. Normalise to ISO local time.
    const stamp = String(c.updated_at ?? "").replace(" ", "T");
    const age = now - new Date(stamp).getTime();

    if (!Number.isFinite(age) || age < day) groups["วันนี้"].push(c);
    else if (age < 7 * day) groups["7 วันก่อน"].push(c);
    else groups["ก่อนหน้านี้"].push(c);
  }
  return Object.entries(groups).filter(([, items]) => items.length > 0);
}

/** The panel is narrow; anything past 15 characters is noise. */
const TITLE_LIMIT = 30;

function shortTitle(title) {
  const text = String(title ?? "").trim() || "แชทใหม่";
  return [...text].length > TITLE_LIMIT
    ? `${[...text].slice(0, TITLE_LIMIT).join("")}…`
    : text;
}

/**
 * The history panel. Rendered two ways: fixed alongside the thread on desktop,
 * and inside a Sheet on mobile — where `collapsible` is off, since the Sheet
 * has its own close control.
 */
export function ChatSidebar({
  conversations,
  activeId,
  user,
  onSelect,
  onNew,
  onDelete,
  onRename,
  onToggle,
  onLogout,
  onShowUsage,
  collapsible = true,
  className,
}) {
  const groups = groupByAge(conversations);
  // The row being renamed, if any. Only ever one — renaming is a brief,
  // deliberate act, not a mode the panel sits in.
  const [editingId, setEditingId] = useState(null);

  return (
    <aside
      className={cn(
        "group/sidebar flex h-full w-64 shrink-0 flex-col bg-sidebar text-sidebar-foreground",
        className,
      )}
    >
      <div className="flex items-center gap-1 p-2">
        {collapsible && (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                // Stays out of the way until the pointer enters the panel —
                // the collapse control is not something you reach for often.
                className={cn(
                  "size-8 opacity-0 transition-opacity duration-200",
                  "group-hover/sidebar:opacity-100 focus-visible:opacity-100",
                )}
                onClick={onToggle}
                aria-label="ซ่อนแถบข้าง"
              >
                <PanelLeft className="size-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="right">ซ่อนแถบข้าง</TooltipContent>
          </Tooltip>
        )}

        <Button
          variant="ghost"
          className="flex-1 justify-start gap-2 font-normal"
          onClick={onNew}
        >
          <Plus className="size-4" />
          แชทใหม่
        </Button>
      </div>

      {/* Radix's viewport wraps its children in a `display: table` div, which
          sizes to content instead of to the panel — the rows came out ~25px
          wider than the sidebar, pushing the row controls under its clipped
          edge and defeating `truncate`, which needs a definite width. Forcing
          the wrapper back to block is the documented escape hatch; the
          component itself is shadcn-generated and gets overwritten on update,
          so the override lives here. */}
      <ScrollArea className="flex-1 px-2 [&>[data-slot=scroll-area-viewport]>div]:!block">
        {groups.length === 0 ? (
          <p className="px-2 py-8 text-center text-xs text-muted-foreground">
            ยังไม่มีประวัติแชท
          </p>
        ) : (
          groups.map(([label, items]) => (
            <div key={label} className="mb-4">
              <p className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                {label}
              </p>
              <ul className="space-y-0.5">
                <AnimatePresence initial={false}>
                  {items.map((c) => (
                    <motion.li
                      key={c.id}
                      layout="position"
                      initial={{ opacity: 0, x: -8 }}
                      animate={{ opacity: 1, x: 0 }}
                      exit={{ opacity: 0, height: 0 }}
                      transition={{ duration: 0.18, ease: "easeOut" }}
                    >
                      <div
                        className={cn(
                          "group/item relative rounded-lg",
                          "transition-colors duration-150 hover:bg-sidebar-accent",
                          c.id === activeId &&
                            "bg-sidebar-accent text-sidebar-accent-foreground",
                        )}
                      >
                        {editingId === c.id ? (
                          <RenameField
                            title={c.title}
                            onCancel={() => setEditingId(null)}
                            onSubmit={(title) => {
                              setEditingId(null);
                              if (title && title !== c.title) {
                                onRename?.(c.id, title);
                              }
                            }}
                          />
                        ) : (
                          <>
                            <button
                              onClick={() => onSelect(c.id)}
                              onDoubleClick={() => setEditingId(c.id)}
                              title={c.title}
                              className="flex w-full items-center gap-2 rounded-lg px-2 py-2 pr-14 text-left text-sm"
                            >
                              <MessageSquare className="size-3.5 shrink-0 text-muted-foreground" />
                              <span className="truncate">
                                {shortTitle(c.title)}
                              </span>
                            </button>

                            {/* Kept out of the row button so the click targets
                                stay separate; revealed on hover the way the
                                panel's other secondary controls are. */}
                            <div
                              className={cn(
                                "absolute right-1 top-1/2 flex -translate-y-1/2 items-center",
                                "opacity-0 transition-opacity duration-150",
                                "group-hover/item:opacity-100 focus-within:opacity-100",
                              )}
                            >
                              <button
                                onClick={() => setEditingId(c.id)}
                                aria-label={`เปลี่ยนชื่อแชท ${c.title}`}
                                className="flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-sidebar-border hover:text-foreground"
                              >
                                <Pencil className="size-3.5" />
                              </button>
                              <button
                                onClick={() => onDelete?.(c.id)}
                                aria-label={`ลบแชท ${c.title}`}
                                className="flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-sidebar-border hover:text-destructive"
                              >
                                <Trash2 className="size-3.5" />
                              </button>
                            </div>
                          </>
                        )}
                      </div>
                    </motion.li>
                  ))}
                </AnimatePresence>
              </ul>
            </div>
          ))
        )}
      </ScrollArea>

      <div className="border-t border-sidebar-border p-2">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              className="w-full justify-start gap-2 px-2 font-normal"
            >
              {/* The server composes the display name; the client only has to
                  cope with a token issued before it existed. */}
              <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-medium text-primary-foreground">
                {(user.name ?? user.username).charAt(0).toUpperCase()}
              </span>
              <span className="truncate">
                {user.display_name ?? user.username}
              </span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-56">
            <DropdownMenuLabel className="font-normal">
              <p className="text-sm font-medium">
                {user.display_name ?? user.username}
              </p>
              <p className="text-xs text-muted-foreground">
                {user.role === "admin" ? "ผู้ดูแลระบบ" : "พนักงาน"}
              </p>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={onShowUsage}>
              <Wallet className="size-4" />
              การใช้งานเดือนนี้
            </DropdownMenuItem>
            <DropdownMenuItem onClick={onLogout}>
              <LogOut className="size-4" />
              ออกจากระบบ
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </aside>
  );
}

/**
 * Renames a chat in place. Enter and clicking away both commit; Escape cancels.
 *
 * Blur commits rather than discards: someone who has typed a new name and
 * clicked back into the thread means the rename, and silently throwing it away
 * is the more surprising outcome. Nothing is lost either way — the caller
 * ignores a submission that matches the existing title, and a title is
 * re-editable in two clicks.
 */
function RenameField({ title, onSubmit, onCancel }) {
  const ref = useRef(null);
  // Both endings are followed by a blur — Escape by the one the cancel causes,
  // Enter by the one unmounting the input causes. Without this latch, Escape
  // would commit the edit it was meant to discard and Enter would send it
  // twice.
  const done = useRef(false);

  useEffect(() => {
    ref.current?.select();
  }, []);

  const commit = () => {
    if (done.current) return;
    done.current = true;
    onSubmit(ref.current?.value.trim() ?? "");
  };

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        commit();
      }}
      className="px-1 py-1"
    >
      <input
        ref={ref}
        defaultValue={title}
        maxLength={255}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === "Escape") {
            done.current = true;
            onCancel();
          }
        }}
        aria-label="ชื่อแชท"
        className="w-full rounded-md border bg-background px-2 py-1 text-sm outline-none focus:ring-1 focus:ring-ring"
      />
    </form>
  );
}
