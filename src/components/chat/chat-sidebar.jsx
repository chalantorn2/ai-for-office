import { useEffect, useRef, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import {
  ChevronRight,
  Folder,
  FolderPlus,
  LogOut,
  MessageSquare,
  MoreHorizontal,
  PanelLeft,
  Pencil,
  Plus,
  Search,
  Trash2,
  Wallet,
  X,
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
  DropdownMenuPortal,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
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

function shortTitle(title, limit = TITLE_LIMIT) {
  const text = String(title ?? "").trim() || "แชทใหม่";
  return [...text].length > limit
    ? `${[...text].slice(0, limit).join("")}…`
    : text;
}

/**
 * The history panel. Rendered two ways: fixed alongside the thread on desktop,
 * and inside a Sheet on mobile — where `collapsible` is off, since the Sheet
 * has its own close control.
 *
 * Projects are folders and nothing more: a chat is either inside one or in the
 * flat, date-grouped list, never both. Nothing about a project reaches the
 * model, so filing chats costs nothing per question.
 */
export function ChatSidebar({
  conversations,
  projects = [],
  activeId,
  activeProjectId,
  user,
  onSelect,
  onNew,
  onDelete,
  onRename,
  onMove,
  onNewProject,
  onRenameProject,
  onDeleteProject,
  onToggle,
  onLogout,
  onShowUsage,
  collapsible = true,
  className,
}) {
  // A chat inside a folder is shown under that folder only — listing it under
  // its date as well would put the same row on screen twice.
  const loose = conversations.filter((c) => !c.project_id);
  const groups = groupByAge(loose);

  // The row being renamed, if any. Only ever one of each — renaming is a brief,
  // deliberate act, not a mode the panel sits in.
  const [editingId, setEditingId] = useState(null);
  const [editingProjectId, setEditingProjectId] = useState(null);
  // Which folders are open is derived, not stored: the one holding the chat on
  // screen is open by default, so the panel never points at a closed folder
  // while its contents are being read — and that is true immediately on the
  // mobile drawer, which mounts fresh every time it is pulled open. The two
  // sets hold only what someone has since clicked, either way.
  const [expanded, setExpanded] = useState(() => new Set());
  const [collapsed, setCollapsed] = useState(() => new Set());

  // Search is a mode the panel enters, not a field it carries: an always-on
  // input costs a row of height that the history is better served by.
  const [searchOpen, setSearchOpen] = useState(false);
  const [query, setQuery] = useState("");
  const q = query.trim().toLowerCase();
  const searching = q.length > 0;

  const closeSearch = () => {
    setSearchOpen(false);
    setQuery("");
  };

  // While searching the panel shows one flat list instead of folders and date
  // headings — a hit inside a folder should not need the folder opened first.
  const hits = searching
    ? conversations.filter((c) =>
        String(c.title ?? "")
          .toLowerCase()
          .includes(q),
      )
    : [];
  const projectHits = searching
    ? projects.filter((p) =>
        String(p.name ?? "")
          .toLowerCase()
          .includes(q),
      )
    : [];

  const openProject =
    activeProjectId ??
    conversations.find((c) => c.id === activeId)?.project_id ??
    null;

  const isOpen = (id) =>
    expanded.has(id) || (id === openProject && !collapsed.has(id));

  const setOpen = (id, open) => {
    const drop = (set) => {
      const next = new Set(set);
      next.delete(id);
      return next;
    };
    const add = (set) => new Set(set).add(id);

    setExpanded(open ? add : drop);
    setCollapsed(open ? drop : add);
  };

  /**
   * Makes a folder and puts it straight into rename mode — a project is worth
   * nothing until it is named, and a dialog for one text field is a click more
   * than the sidebar needs. `chatId` files that chat into it on the way.
   */
  async function createProject(chatId = null) {
    const project = await onNewProject?.();
    if (!project) return;

    setOpen(project.id, true);
    if (chatId) onMove?.(chatId, project.id);
    setEditingProjectId(project.id);
  }

  const rowProps = {
    projects,
    activeId,
    editingId,
    setEditingId,
    onSelect,
    onRename,
    onMove,
    onDelete,
    onNewProject: createProject,
  };

  return (
    <aside
      className={cn(
        "group/sidebar flex h-full w-64 shrink-0 flex-col bg-sidebar text-sidebar-foreground",
        className,
      )}
    >
      <div className="flex shrink-0 items-center gap-1 px-2 pt-2">
        {/* Wordmark: fixed height, width follows the 680×132 artwork — a
            square class would squash it. Solid black, so it needs inverting to
            stay visible on the dark sidebar. */}
        <img
          src="/banner.png"
          alt="Nova"
          className="h-3 w-auto shrink-0 px-1 dark:invert"
        />

        <div className="ml-auto flex items-center gap-0.5">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                onClick={() =>
                  searchOpen ? closeSearch() : setSearchOpen(true)
                }
                aria-label="ค้นหาแชท"
              >
                {searchOpen ? (
                  <X className="size-4" />
                ) : (
                  <Search className="size-4" />
                )}
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">ค้นหาแชท</TooltipContent>
          </Tooltip>

          {collapsible && (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-8"
                  onClick={onToggle}
                  aria-label="ซ่อนแถบข้าง"
                >
                  <PanelLeft className="size-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="bottom">ซ่อนแถบข้าง</TooltipContent>
            </Tooltip>
          )}
        </div>
      </div>

      {searchOpen && (
        <div className="relative shrink-0 px-2 pt-2">
          <Search className="pointer-events-none absolute left-4 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => e.key === "Escape" && closeSearch()}
            placeholder="ค้นหาแชท"
            aria-label="ค้นหาแชท"
            className="w-full rounded-lg border bg-background py-1.5 pl-8 pr-2 text-sm outline-none focus:ring-1 focus:ring-ring"
          />
        </div>
      )}

      <div className="shrink-0 p-2">
        <Button
          variant="ghost"
          className="w-full justify-start gap-2 font-normal"
          onClick={() => onNew()}
        >
          <Plus className="size-4" />
          แชทใหม่
        </Button>
      </div>

      {/* `min-h-0` is what makes the panel scroll at all: a flex child's
          default `min-height: auto` refuses to shrink below its content, so a
          long history grew the list instead of scrolling it and pushed the
          account row off the bottom of the sidebar.

          Radix's viewport wraps its children in a `display: table` div, which
          sizes to content instead of to the panel — the rows came out ~25px
          wider than the sidebar, pushing the row controls under its clipped
          edge and defeating `truncate`, which needs a definite width. Forcing
          the wrapper back to block is the documented escape hatch; the
          component itself is shadcn-generated and gets overwritten on update,
          so the override lives here. */}
      <ScrollArea className="min-h-0 flex-1 overflow-hidden px-2 [&>[data-slot=scroll-area-viewport]>div]:!block">
        {searching ? (
          <SearchResults
            hits={hits}
            projectHits={projectHits}
            rowProps={{
              ...rowProps,
              // Picking a result ends the search — the thread it opened is
              // what the panel should be pointing at now.
              onSelect: (id) => {
                closeSearch();
                onSelect(id);
              },
            }}
            onOpenProject={(id) => {
              setOpen(id, true);
              closeSearch();
            }}
          />
        ) : (
          <>
            <div className="mb-4">
              <div className="flex items-center justify-between px-2 py-1.5">
                <p className="text-xs font-medium text-muted-foreground">
                  โปรเจกต์
                </p>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      onClick={() => createProject()}
                      aria-label="สร้างโปรเจกต์"
                      className="flex size-5 items-center justify-center rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-foreground"
                    >
                      <FolderPlus className="size-3.5" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="right">สร้างโปรเจกต์</TooltipContent>
                </Tooltip>
              </div>

              {projects.length === 0 ? (
                <p className="px-2 pb-1 text-xs text-muted-foreground/70">
                  จัดกลุ่มแชทได้ที่นี่
                </p>
              ) : (
                <ul className="space-y-0.5">
                  <AnimatePresence initial={false}>
                    {projects.map((p) => {
                      const chats = conversations.filter(
                        (c) => c.project_id === p.id,
                      );
                      const open = isOpen(p.id);

                      return (
                        <motion.li
                          key={p.id}
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
                              // Highlighted only while composing a new chat into
                              // it; an open chat highlights its own row instead.
                              activeProjectId === p.id &&
                                activeId === null &&
                                "bg-sidebar-accent text-sidebar-accent-foreground",
                            )}
                          >
                            {editingProjectId === p.id ? (
                              <RenameField
                                title={p.name}
                                label="ชื่อโปรเจกต์"
                                maxLength={120}
                                onCancel={() => setEditingProjectId(null)}
                                onSubmit={(name) => {
                                  setEditingProjectId(null);
                                  if (name && name !== p.name) {
                                    onRenameProject?.(p.id, name);
                                  }
                                }}
                              />
                            ) : (
                              <>
                                <button
                                  onClick={() => setOpen(p.id, !open)}
                                  onDoubleClick={() =>
                                    setEditingProjectId(p.id)
                                  }
                                  title={p.name}
                                  aria-expanded={open}
                                  className="flex w-full items-center gap-1.5 rounded-lg px-2 py-2 pr-8 text-left text-sm"
                                >
                                  <ChevronRight
                                    className={cn(
                                      "size-3 shrink-0 text-muted-foreground transition-transform duration-150",
                                      open && "rotate-90",
                                    )}
                                  />
                                  <Folder className="size-3.5 shrink-0 text-muted-foreground" />
                                  <span className="truncate">
                                    {shortTitle(p.name || "โปรเจกต์ใหม่", 22)}
                                  </span>
                                  {chats.length > 0 && (
                                    <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                      {chats.length}
                                    </span>
                                  )}
                                </button>

                                <RowMenu label={`ตัวเลือกโปรเจกต์ ${p.name}`}>
                                  <DropdownMenuItem
                                    onClick={() => {
                                      setOpen(p.id, true);
                                      onNew(p.id);
                                    }}
                                  >
                                    <Plus className="size-4" />
                                    แชทใหม่ในโปรเจกต์
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    onClick={() => setEditingProjectId(p.id)}
                                  >
                                    <Pencil className="size-4" />
                                    เปลี่ยนชื่อ
                                  </DropdownMenuItem>
                                  <DropdownMenuSeparator />
                                  <DropdownMenuItem
                                    variant="destructive"
                                    onClick={() => onDeleteProject?.(p.id)}
                                  >
                                    <Trash2 className="size-4" />
                                    ลบโปรเจกต์
                                  </DropdownMenuItem>
                                </RowMenu>
                              </>
                            )}
                          </div>

                          {open && (
                            <ul className="mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2 ml-4">
                              {chats.length === 0 ? (
                                <li className="px-2 py-1.5 text-xs text-muted-foreground/70">
                                  ยังไม่มีแชท
                                </li>
                              ) : (
                                chats.map((c) => (
                                  <li key={c.id}>
                                    <ChatRow c={c} {...rowProps} />
                                  </li>
                                ))
                              )}
                            </ul>
                          )}
                        </motion.li>
                      );
                    })}
                  </AnimatePresence>
                </ul>
              )}
            </div>

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
                          <ChatRow c={c} {...rowProps} />
                        </motion.li>
                      ))}
                    </AnimatePresence>
                  </ul>
                </div>
              ))
            )}
          </>
        )}
      </ScrollArea>

      <div className="shrink-0 border-t border-sidebar-border p-2">
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
 * What the panel shows while a query is typed: one flat list of matching
 * chats, folders first. Date headings and folder nesting are dropped — they
 * organise browsing, and searching is what you do instead of browsing.
 */
function SearchResults({ hits, projectHits, rowProps, onOpenProject }) {
  if (hits.length === 0 && projectHits.length === 0) {
    return (
      <p className="px-2 py-8 text-center text-xs text-muted-foreground">
        ไม่พบผลลัพธ์
      </p>
    );
  }

  return (
    <div className="pb-2">
      {projectHits.length > 0 && (
        <div className="mb-4">
          <p className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
            โปรเจกต์
          </p>
          <ul className="space-y-0.5">
            {projectHits.map((p) => (
              <li key={p.id}>
                <button
                  onClick={() => onOpenProject(p.id)}
                  title={p.name}
                  className="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm hover:bg-sidebar-accent"
                >
                  <Folder className="size-3.5 shrink-0 text-muted-foreground" />
                  <span className="truncate">
                    {shortTitle(p.name || "โปรเจกต์ใหม่", 22)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}

      {hits.length > 0 && (
        <div className="mb-4">
          <p className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
            แชท
          </p>
          <ul className="space-y-0.5">
            {hits.map((c) => (
              <li key={c.id}>
                <ChatRow c={c} {...rowProps} />
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

/**
 * The hover control shared by chat and project rows.
 *
 * One menu rather than a row of icons: filing a chat needs a list of folders,
 * which no icon can carry, and three targets do not fit beside a title in a
 * 256px panel without eating the width `truncate` needs.
 */
function RowMenu({ label, children }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          aria-label={label}
          onClick={(e) => e.stopPropagation()}
          className={cn(
            "absolute right-1 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md",
            "text-muted-foreground opacity-0 transition-opacity duration-150",
            "hover:bg-sidebar-border hover:text-foreground",
            "group-hover/item:opacity-100 focus-visible:opacity-100",
            // Without this the menu closes and its own trigger vanishes from
            // under the pointer the moment the pointer leaves the row.
            "data-[state=open]:opacity-100",
          )}
        >
          <MoreHorizontal className="size-3.5" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" side="right" className="w-48">
        {children}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** One conversation. Identical inside a folder and under a date heading. */
function ChatRow({
  c,
  projects,
  activeId,
  editingId,
  setEditingId,
  onSelect,
  onRename,
  onMove,
  onDelete,
  onNewProject,
}) {
  return (
    <div
      className={cn(
        "group/item relative rounded-lg",
        "transition-colors duration-150 hover:bg-sidebar-accent",
        c.id === activeId && "bg-sidebar-accent text-sidebar-accent-foreground",
      )}
    >
      {editingId === c.id ? (
        <RenameField
          title={c.title}
          label="ชื่อแชท"
          onCancel={() => setEditingId(null)}
          onSubmit={(title) => {
            setEditingId(null);
            if (title && title !== c.title) onRename?.(c.id, title);
          }}
        />
      ) : (
        <>
          <button
            onClick={() => onSelect(c.id)}
            onDoubleClick={() => setEditingId(c.id)}
            title={c.title}
            className="flex w-full items-center gap-2 rounded-lg px-2 py-2 pr-8 text-left text-sm"
          >
            <MessageSquare className="size-3.5 shrink-0 text-muted-foreground" />
            <span className="truncate">{shortTitle(c.title)}</span>
          </button>

          <RowMenu label={`ตัวเลือกแชท ${c.title}`}>
            <DropdownMenuItem onClick={() => setEditingId(c.id)}>
              <Pencil className="size-4" />
              เปลี่ยนชื่อ
            </DropdownMenuItem>

            <DropdownMenuSub>
              <DropdownMenuSubTrigger>
                <Folder className="size-4" />
                ย้ายไปโปรเจกต์
              </DropdownMenuSubTrigger>
              <DropdownMenuPortal>
                <DropdownMenuSubContent className="max-h-72 w-52 overflow-y-auto">
                  {projects.map((p) => (
                    <DropdownMenuItem
                      key={p.id}
                      disabled={p.id === c.project_id}
                      onClick={() => onMove?.(c.id, p.id)}
                    >
                      <Folder className="size-4" />
                      <span className="truncate">
                        {p.name || "โปรเจกต์ใหม่"}
                      </span>
                    </DropdownMenuItem>
                  ))}
                  {projects.length > 0 && <DropdownMenuSeparator />}
                  <DropdownMenuItem onClick={() => onNewProject?.(c.id)}>
                    <FolderPlus className="size-4" />
                    โปรเจกต์ใหม่…
                  </DropdownMenuItem>
                  {c.project_id && (
                    <DropdownMenuItem onClick={() => onMove?.(c.id, null)}>
                      เอาออกจากโปรเจกต์
                    </DropdownMenuItem>
                  )}
                </DropdownMenuSubContent>
              </DropdownMenuPortal>
            </DropdownMenuSub>

            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              onClick={() => onDelete?.(c.id)}
            >
              <Trash2 className="size-4" />
              ลบแชท
            </DropdownMenuItem>
          </RowMenu>
        </>
      )}
    </div>
  );
}

/**
 * Renames a chat or a project in place. Enter and clicking away both commit;
 * Escape cancels.
 *
 * Blur commits rather than discards: someone who has typed a new name and
 * clicked back into the thread means the rename, and silently throwing it away
 * is the more surprising outcome. Nothing is lost either way — the caller
 * ignores a submission that matches the existing title, and a title is
 * re-editable in two clicks.
 */
function RenameField({
  title,
  onSubmit,
  onCancel,
  label = "ชื่อแชท",
  maxLength = 255,
}) {
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
        maxLength={maxLength}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === "Escape") {
            done.current = true;
            onCancel();
          }
        }}
        aria-label={label}
        className="w-full rounded-md border bg-background px-2 py-1 text-sm outline-none focus:ring-1 focus:ring-ring"
      />
    </form>
  );
}
