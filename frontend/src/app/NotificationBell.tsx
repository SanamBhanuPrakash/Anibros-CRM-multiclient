import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, Box, Button, Divider, IconButton, Popover, Stack, Tooltip, Typography } from '@mui/material';
import NotificationsNoneRoundedIcon from '@mui/icons-material/NotificationsNoneRounded';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { notificationsApi, type AppNotification } from '@/api/work';
import { RelativeTime } from '@/ui/display';
import { ErrorState, SkeletonRows } from '@/ui/states';

/**
 * Personal inbox in the top bar. The server pushes `notification.created` (ids only); the realtime layer invalidates
 * ['notifications'] and this refetches — the badge is never maintained by hand, so it cannot drift.
 */
export function NotificationBell() {
  const nav = useNavigate();
  const qc = useQueryClient();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const count = useQuery({ queryKey: ['notifications', 'count'], queryFn: notificationsApi.unreadCount, refetchInterval: 120_000 });
  const list = useInfiniteQuery({
    queryKey: ['notifications', 'list'],
    queryFn: ({ pageParam }) => notificationsApi.list(false, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    enabled: Boolean(anchor),
  });
  const refresh = () => void qc.invalidateQueries({ queryKey: ['notifications'] });
  const read = useMutation({ mutationFn: (id: string) => notificationsApi.markRead([id]), onSuccess: refresh });
  const readAll = useMutation({ mutationFn: notificationsApi.markAllRead, onSuccess: refresh });
  const items = list.data?.pages.flatMap((p) => p.data) ?? [];
  const unread = count.data?.count ?? 0;

  const open = (n: AppNotification) => {
    if (!n.isRead) read.mutate(n.id);
    setAnchor(null);
    if (n.link) nav(n.link);
  };

  return (
    <>
      <Tooltip title="Notifications">
        <IconButton aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'} onClick={(e) => setAnchor(e.currentTarget)}>
          <Badge badgeContent={unread} max={99} color="error" invisible={!unread}>
            <NotificationsNoneRoundedIcon />
          </Badge>
        </IconButton>
      </Tooltip>
      <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }} slotProps={{ paper: { sx: { width: { xs: 'calc(100vw - 24px)', sm: 380 }, maxHeight: 520 } } }}>
        <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1.25 }}>
          <Typography variant="h5" component="h2">Notifications</Typography>
          <Button size="small" disabled={!unread || readAll.isPending} onClick={() => readAll.mutate()}>Mark all read</Button>
        </Stack>
        <Divider />
        <Box sx={{ overflowY: 'auto' }}>
          {list.isPending ? <Box sx={{ p: 2 }}><SkeletonRows rows={3} height={48} /></Box> : list.isError ? <Box sx={{ p: 2 }}><ErrorState error={list.error} onRetry={() => void list.refetch()} /></Box> : items.length === 0 ? (
            <Typography color="text.secondary" variant="body2" sx={{ p: 3, textAlign: 'center' }}>Nothing yet. Reminders and assignments will show up here.</Typography>
          ) : (
            <>
              {items.map((n) => (
                <Box key={n.id} component="button" type="button" onClick={() => open(n)} sx={{ display: 'flex', gap: 1.25, width: '100%', textAlign: 'left', p: 1.75, border: 0, borderBottom: 1, borderColor: 'divider', bgcolor: n.isRead ? 'transparent' : 'surface.selected', cursor: 'pointer', color: 'inherit', font: 'inherit', '&:hover': { bgcolor: 'surface.hover' } }}>
                  <Box sx={{ width: 8, height: 8, borderRadius: '50%', mt: 0.9, flexShrink: 0, bgcolor: n.isRead ? 'transparent' : 'primary.main' }} aria-hidden />
                  <Box sx={{ minWidth: 0 }}>
                    <Typography variant="subtitle2">{n.title}{n.isRead ? '' : <span style={{ position: 'absolute', left: -9999 }}> (unread)</span>}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ wordBreak: 'break-word' }}>{n.message}</Typography>
                    <Typography variant="caption" color="text.disabled"><RelativeTime value={n.createdAt} /></Typography>
                  </Box>
                </Box>
              ))}
              {list.hasNextPage && <Button fullWidth onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>Older</Button>}
            </>
          )}
        </Box>
      </Popover>
    </>
  );
}
