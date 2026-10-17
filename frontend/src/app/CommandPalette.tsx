import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Box, Dialog, InputBase, List, ListItemButton, ListItemIcon, ListItemText, ListSubheader, Typography } from '@mui/material';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import PeopleAltOutlinedIcon from '@mui/icons-material/PeopleAltOutlined';
import ContactsOutlinedIcon from '@mui/icons-material/ContactsOutlined';
import ApartmentOutlinedIcon from '@mui/icons-material/ApartmentOutlined';
import HolidayVillageOutlinedIcon from '@mui/icons-material/HolidayVillageOutlined';
import ChecklistRoundedIcon from '@mui/icons-material/ChecklistRounded';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { searchApi, type SearchKind } from '@/api/home';
import { useAuth } from '@/features/auth/AuthProvider';
import { searchablePages, useNavOptions } from './nav';

const KIND: Record<SearchKind, { label: string; icon: ReactNode }> = {
  leads: { label: 'Leads', icon: <PeopleAltOutlinedIcon fontSize="small" /> },
  contacts: { label: 'Contacts', icon: <ContactsOutlinedIcon fontSize="small" /> },
  properties: { label: 'Properties', icon: <ApartmentOutlinedIcon fontSize="small" /> },
  projects: { label: 'Projects', icon: <HolidayVillageOutlinedIcon fontSize="small" /> },
  tasks: { label: 'Tasks', icon: <ChecklistRoundedIcon fontSize="small" /> },
};
const KIND_ORDER: SearchKind[] = ['leads', 'contacts', 'properties', 'projects', 'tasks'];

interface Entry {
  key: string;
  to: string;
  label: string;
  secondary: string | null;
  icon: ReactNode;
  group: string;
}

/**
 * Command palette (⌘/Ctrl + K, or "/" outside inputs). Type two or more letters to find leads, contacts, properties, projects and tasks
 * (only what you may see, at most five of each); pages you can open are listed too. Enter opens the highlighted entry.
 */
export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { can } = useAuth();
  const navOptions = useNavOptions();
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [debounced, setDebounced] = useState('');
  const [active, setActive] = useState(0);
  const listRef = useRef<HTMLUListElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(q.trim()), 200);
    return () => clearTimeout(t);
  }, [q]);

  const found_pages = useMemo(() => searchablePages(navOptions), [navOptions]);
  const pages: Entry[] = useMemo(() => found_pages.map((p) => ({ key: `p-${p.to}`, to: p.to, label: p.label, secondary: p.section, icon: p.icon ?? <SearchRoundedIcon fontSize="small" />, group: 'Pages' })), [found_pages]);
  const keywords = useMemo(() => new Map<string, string>(found_pages.map((p) => [`p-${p.to}`, `${p.label} ${p.section} ${p.keywords ?? ''}`.toLowerCase()] as [string, string])), [found_pages]);

  const searching = open && debounced.length >= 2 && can('search:use');
  const found = useQuery({ queryKey: ['search', debounced], queryFn: ({ signal }) => searchApi.search(debounced, signal), enabled: searching, staleTime: 10_000, placeholderData: (p) => p });

  const entries: Entry[] = useMemo(() => {
    const needle = q.trim().toLowerCase();
    // A page whose own name starts with what was typed outranks a page that merely lists the word as a keyword
    // ("team" must open Team, not the Dashboard that happens to show a team table), and outranks record hits.
    const rank = (e: Entry) => (e.label.toLowerCase().startsWith(needle) ? 0 : e.label.toLowerCase().includes(needle) ? 1 : 2);
    const pageHits = needle ? pages.filter((e) => keywords.get(e.key)?.includes(needle)).sort((a, b) => rank(a) - rank(b)) : pages;
    const records: Entry[] = [];
    if (searching && found.data && found.data.query === debounced) {
      for (const k of KIND_ORDER) for (const h of found.data.results[k] ?? []) records.push({ key: `${k}-${h.id}`, to: h.href, label: h.title, secondary: h.subtitle, icon: KIND[k].icon, group: KIND[k].label });
    }
    if (!needle) return [...records, ...pageHits];
    const strong = pageHits.filter((e) => rank(e) === 0);
    return [...strong, ...records, ...pageHits.filter((e) => rank(e) !== 0)];
  }, [pages, keywords, q, searching, found.data, debounced]);

  useEffect(() => setActive(0), [q, open, found.data]);
  useEffect(() => {
    if (!open) {
      setQ('');
      setDebounced('');
    }
  }, [open]);
  useEffect(() => {
    listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [active]);

  const go = (to: string) => {
    onClose();
    navigate(to);
  };
  const waiting = searching && (found.isFetching || found.data?.query !== debounced) && !found.isError;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth slotProps={{ paper: { sx: { alignSelf: 'flex-start', mt: '12vh', overflow: 'hidden' } } }} aria-label="Search and commands">
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, px: 2, py: 1.25, borderBottom: 1, borderColor: 'divider' }}>
        <SearchRoundedIcon fontSize="small" color="disabled" />
        <InputBase
          autoFocus
          fullWidth
          placeholder="Search leads, contacts, properties, tasks… or jump to a page"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          inputProps={{ 'aria-label': 'Search', role: 'combobox', 'aria-expanded': true, 'aria-controls': 'cmd-list', 'aria-activedescendant': entries[active] ? `cmd-${active}` : undefined }}
          onKeyDown={(e) => {
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              setActive((a) => Math.min(a + 1, entries.length - 1));
            } else if (e.key === 'ArrowUp') {
              e.preventDefault();
              setActive((a) => Math.max(a - 1, 0));
            } else if (e.key === 'Enter' && entries[active]) {
              go(entries[active].to);
            }
          }}
        />
        <Typography variant="caption" color="text.disabled" sx={{ border: 1, borderColor: 'divider', borderRadius: 1, px: 0.75 }}>esc</Typography>
      </Box>
      <List id="cmd-list" ref={listRef} role="listbox" sx={{ maxHeight: 400, overflow: 'auto', p: 0.75 }}>
        {found.isError && <Typography variant="body2" color="error" sx={{ p: 1.5 }}>Search is unavailable right now. You can still jump to a page.</Typography>}
        {waiting && entries.every((e) => e.group === 'Pages') && <Typography variant="body2" color="text.secondary" sx={{ p: 1.5 }} aria-live="polite">Searching…</Typography>}
        {entries.length === 0 && !waiting && <Typography variant="body2" color="text.secondary" sx={{ p: 2, textAlign: 'center' }}>Nothing matches “{q}”.</Typography>}
        {entries.map((r, i) => (
          <Box key={r.key} component="li" sx={{ listStyle: 'none' }}>
            {(i === 0 || entries[i - 1]!.group !== r.group) && <ListSubheader disableSticky component="div" sx={{ lineHeight: '28px', typography: 'caption', color: 'text.secondary', bgcolor: 'transparent' }}>{r.group}</ListSubheader>}
            <ListItemButton id={`cmd-${i}`} role="option" aria-selected={i === active} data-active={i === active} selected={i === active} onMouseMove={() => setActive(i)} onClick={() => go(r.to)} sx={{ borderRadius: 1.5 }}>
              <ListItemIcon sx={{ minWidth: 36, color: 'text.secondary' }}>{r.icon}</ListItemIcon>
              <ListItemText primary={r.label} secondary={r.secondary} slotProps={{ secondary: { variant: 'caption', noWrap: true }, primary: { noWrap: true } }} />
            </ListItemButton>
          </Box>
        ))}
      </List>
    </Dialog>
  );
}
