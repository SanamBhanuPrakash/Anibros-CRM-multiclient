import { useState } from 'react';
import { Link as RouterLink, useNavigate, useParams } from 'react-router-dom';
import { Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, Divider, ListItemIcon, ListItemText, Menu, MenuItem, Paper, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import MoreHorizRoundedIcon from '@mui/icons-material/MoreHorizRounded';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { fileUrl, propertiesApi, type PropertyDetail, type PropertyStatus } from '@/api/inventory';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { RelativeTime } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { PropertyDialog } from './PropertyDialog';
import { PropertyDeals } from '@/features/deals/PropertyDeals';
import { BookVisitDialog } from '@/features/viewings/BookVisitDialog';
import EventAvailableOutlinedIcon from '@mui/icons-material/EventAvailableOutlined';
import { FURNISHING_LABEL, FilesPanel, PropertyImage, STATUS_LABEL, StatusChip, TYPE_LABEL, formatArea } from './shared';

const ACTION_LABEL: Record<PropertyStatus, string> = { available: 'Make available', under_offer: 'Mark under offer', reserved: 'Reserve', sold: 'Mark sold', rented: 'Mark rented', inactive: 'Take off the market' };

export function PropertyDetailPage() {
  const { id = '' } = useParams();
  const nav = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const { can } = useAuth();
  const q = useQuery({ queryKey: ['properties', 'detail', id], queryFn: () => propertiesApi.get(id) });
  const [editOpen, setEditOpen] = useState(false);
  const [bookOpen, setBookOpen] = useState(false);
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const [reasonFor, setReasonFor] = useState<PropertyStatus | null>(null);
  const [reason, setReason] = useState('');
  const [tab, setTab] = useState<'status' | 'price'>('status');
  const [hero, setHero] = useState<string | null>(null);
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['properties'] }); void qc.invalidateQueries({ queryKey: ['projects'] }); };

  const status = useMutation({
    mutationFn: (v: { status: PropertyStatus; reason?: string }) => propertiesApi.changeStatus(id, v.status, v.reason),
    onSuccess: (p) => { refresh(); setReasonFor(null); setReason(''); toast.success(`Marked ${STATUS_LABEL[p.status].toLowerCase()}`); },
    onError: (e) => toast.error(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t change the status'),
  });
  const archive = useMutation({
    mutationFn: () => propertiesApi.archive(id),
    onSuccess: () => { refresh(); nav('/properties'); toast.success('Property archived', { label: 'Undo', onClick: () => void propertiesApi.restore(id).then(refresh).catch((e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t restore')) }); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t archive'),
  });

  if (q.isPending) return <><PageHeader title="Property" /><SkeletonRows rows={6} /></>;
  if (q.isError) {
    const gone = q.error instanceof ApiProblem && q.error.status === 404;
    return <><PageHeader title={gone ? 'Property not found' : 'Property'} crumbs={[{ label: 'Properties', to: '/properties' }, { label: 'Property' }]} /><ErrorState error={q.error} onRetry={() => void q.refetch()} title={gone ? 'This property doesn’t exist, or it was archived' : 'We couldn’t load this property'} /></>;
  }
  const p: PropertyDetail = q.data;
  const canEdit = can('properties:update');
  const heroId = hero ?? p.coverFileId ?? p.images[0]?.id ?? null;
  const needsReason = (s: PropertyStatus) => p.status === 'sold' || p.status === 'rented' ? s === 'available' : false;

  return (
    <>
      <PageHeader
        crumbs={[{ label: 'Properties', to: '/properties' }, { label: p.title }]}
        title={p.title}
        description={
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.75, mt: 0.5 }}>
            <StatusChip status={p.status} />
            <Chip size="small" variant="outlined" label={TYPE_LABEL[p.propertyType]} />
            <Chip size="small" variant="outlined" label={p.listingType === 'sale' ? 'For sale' : p.listingType === 'rent' ? 'For rent' : 'For lease'} />
            {p.projectId && <Button size="small" component={RouterLink} to={`/properties?project=${p.projectId}`}>{p.projectName}</Button>}
          </Stack>
        }
        actions={
          <>
            {can('viewings:create') && (p.status === 'available' || p.status === 'under_offer') && <Button startIcon={<EventAvailableOutlinedIcon />} onClick={() => setBookOpen(true)}>Book a visit</Button>}
            {canEdit && <Button startIcon={<EditOutlinedIcon />} onClick={() => setEditOpen(true)}>Edit</Button>}
            {can('properties:delete') && (
              <>
                <Button aria-label="More actions" onClick={(e) => setMenu(e.currentTarget)}><MoreHorizRoundedIcon /></Button>
                <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
                  <MenuItem sx={{ color: 'error.main' }} onClick={async () => { setMenu(null); if (await confirm({ title: 'Archive this property?', body: 'It disappears from lists and matching. You can undo this right after.', confirmLabel: 'Archive', destructive: true })) archive.mutate(); }}>
                    <ListItemIcon><ArchiveOutlinedIcon fontSize="small" color="error" /></ListItemIcon><ListItemText>Archive property</ListItemText>
                  </MenuItem>
                </Menu>
              </>
            )}
          </>
        }
      />

      <BookVisitDialog open={bookOpen} onClose={() => setBookOpen(false)} property={{ id: p.id, title: p.title }} />

      <Box sx={{ display: 'grid', gap: 4, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1fr) 380px' } }}>
        <Stack spacing={3} sx={{ minWidth: 0 }}>
          <Box>
            <PropertyImage fileId={heroId} alt={`${p.title} — main picture`} ratio="16 / 9" radius={3} />
            {p.images.length > 1 && (
              <Stack direction="row" spacing={1} sx={{ mt: 1, overflowX: 'auto', pb: 0.5 }} aria-label="Pictures">
                {p.images.map((f) => (
                  <Box key={f.id} component="button" type="button" onClick={() => setHero(f.id)} aria-label={`Show ${f.name}`} aria-pressed={heroId === f.id} sx={{ p: 0, border: 2, borderColor: heroId === f.id ? 'primary.main' : 'transparent', borderRadius: 2, bgcolor: 'transparent', cursor: 'pointer', width: 88, flexShrink: 0, overflow: 'hidden' }}>
                    <Box component="img" src={fileUrl(f.id)} alt="" loading="lazy" sx={{ width: '100%', aspectRatio: '4 / 3', objectFit: 'cover', display: 'block' }} />
                  </Box>
                ))}
              </Stack>
            )}
          </Box>

          {p.description && <Typography sx={{ whiteSpace: 'pre-wrap', maxWidth: '70ch' }}>{p.description}</Typography>}
          {p.amenities.length > 0 && (
            <Box><Typography variant="h5" component="h2" sx={{ mb: 1 }}>Amenities</Typography><Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75 }}>{p.amenities.map((a) => <Chip key={a} size="small" variant="outlined" label={a} />)}</Stack></Box>
          )}
          <Box component="section" aria-labelledby="files-title">
            <Typography id="files-title" variant="h5" component="h2" sx={{ mb: 1 }}>Pictures & documents</Typography>
            <FilesPanel
              files={[...p.images, ...p.documents]}
              canEdit={canEdit}
              coverId={p.coverFileId}
              upload={(f) => propertiesApi.uploadFile(id, f)}
              onSetCover={(fid) => propertiesApi.setCover(id, fid)}
              invalidate={() => void qc.invalidateQueries({ queryKey: ['properties', 'detail', id] })}
            />
          </Box>
        </Stack>

        <Stack spacing={3}>
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
            <Typography variant="display" component="p" sx={{ fontSize: 34, lineHeight: 1.1, fontVariantNumeric: 'tabular-nums' }}>{formatMoney(p.price, p.currency)}</Typography>
            <Typography variant="body2" color="text.secondary">{[p.listingType !== 'sale' ? (p.listingType === 'rent' ? 'per month' : 'lease') : null, p.pricePerSqft ? `₹${Math.round(p.pricePerSqft).toLocaleString('en-IN')} / sq ft` : null, p.negotiable ? 'Negotiable' : 'Fixed price'].filter(Boolean).join(' · ')}</Typography>
            {canEdit && p.statusLock && (
              <>
                <Divider sx={{ my: 2 }} />
                <Typography variant="subtitle2" sx={{ mb: 0.5 }}>Availability</Typography>
                <Typography variant="body2" color="text.secondary">{p.statusLock}</Typography>
              </>
            )}
            {canEdit && p.allowedStatuses.length > 0 && (
              <>
                <Divider sx={{ my: 2 }} />
                <Typography variant="subtitle2" sx={{ mb: 1 }}>Availability</Typography>
                <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 1 }}>
                  {p.allowedStatuses.filter((s) => (s === 'sold' ? p.listingType === 'sale' : s === 'rented' ? p.listingType !== 'sale' : true)).map((s) => (
                    <Button key={s} size="small" variant="outlined" color={s === 'sold' || s === 'rented' ? 'primary' : 'inherit'} disabled={status.isPending} onClick={() => (needsReason(s) ? setReasonFor(s) : status.mutate({ status: s }))}>{ACTION_LABEL[s]}</Button>
                  ))}
                </Stack>
              </>
            )}
          </Paper>

          <PropertyDeals property={{ id: p.id, title: p.title, price: p.price, status: p.status }} />

          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
            <Typography variant="h5" component="h2" sx={{ mb: 1.5 }}>Details</Typography>
            <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', columnGap: 2.5, rowGap: 1, '& dt': { color: 'text.secondary', typography: 'body2' }, '& dd': { m: 0, typography: 'body2', textAlign: 'right', wordBreak: 'break-word' } }}>
              <dt>Bedrooms</dt><dd>{p.bedrooms ?? '—'}</dd>
              <dt>Bathrooms</dt><dd>{p.bathrooms ?? '—'}</dd>
              <dt>Built-up area</dt><dd>{formatArea(p.areaSqft) ?? '—'}</dd>
              <dt>Carpet area</dt><dd>{formatArea(p.carpetAreaSqft) ?? '—'}</dd>
              <dt>Unit</dt><dd>{[p.tower && `Tower ${p.tower}`, p.unitNumber && `Unit ${p.unitNumber}`, p.floor !== null && `Floor ${p.floor}`].filter(Boolean).join(' · ') || '—'}</dd>
              <dt>Facing</dt><dd>{p.facing ?? '—'}</dd>
              <dt>Furnishing</dt><dd>{p.furnishing ? FURNISHING_LABEL[p.furnishing] : '—'}</dd>
              <dt>Location</dt><dd>{[p.address, p.location].filter(Boolean).join(', ') || '—'}</dd>
              <dt>Possession</dt><dd>{p.possessionDate ?? '—'}</dd>
              <dt>RERA</dt><dd>{p.reraId ?? '—'}</dd>
              <dt>Listing agent</dt><dd>{p.ownerName ?? 'Unassigned'}</dd>
              <dt>Listed</dt><dd><RelativeTime value={p.createdAt} /></dd>
            </Box>
            {p.tags.length > 0 && <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75, mt: 2 }}>{p.tags.map((t) => <Chip key={t} size="small" variant="outlined" label={t} />)}</Stack>}
          </Paper>

          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
            <Tabs value={tab} onChange={(_, v) => setTab(v)} aria-label="History" sx={{ mb: 1.5, minHeight: 36 }}>
              <Tab value="status" label="Availability" sx={{ minHeight: 36 }} />
              <Tab value="price" label="Price" sx={{ minHeight: 36 }} />
            </Tabs>
            <Stack spacing={1.25} divider={<Divider flexItem />}>
              {tab === 'status' ? p.statusHistory.map((h, i) => (
                <Box key={i}>
                  <Typography variant="body2">{h.from ? <>{STATUS_LABEL[h.from]} → <b>{STATUS_LABEL[h.to]}</b></> : <>Listed as <b>{STATUS_LABEL[h.to]}</b></>}</Typography>
                  <Typography variant="caption" color="text.secondary">{h.changedBy ?? 'System'} · <RelativeTime value={h.at} />{h.reason && h.reason !== 'Listed' ? ` · ${h.reason}` : ''}</Typography>
                </Box>
              )) : p.priceHistory.map((h, i) => (
                <Box key={i}>
                  <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}><b>{formatMoney(h.price, h.currency)}</b>{p.priceHistory[i + 1] ? <Typography component="span" variant="caption" color={h.price < p.priceHistory[i + 1]!.price ? 'success.main' : 'warning.main'}> {h.price < p.priceHistory[i + 1]!.price ? '▼' : '▲'} from {formatMoney(p.priceHistory[i + 1]!.price, h.currency)}</Typography> : null}</Typography>
                  <Typography variant="caption" color="text.secondary">{h.changedBy ?? 'System'} · <RelativeTime value={h.at} /></Typography>
                </Box>
              ))}
            </Stack>
          </Paper>
        </Stack>
      </Box>

      <PropertyDialog open={editOpen} property={p} onClose={() => setEditOpen(false)} onSaved={() => { setEditOpen(false); refresh(); }} />
      <Dialog open={Boolean(reasonFor)} onClose={() => setReasonFor(null)} fullWidth maxWidth="xs" aria-labelledby="reason-title">
        <DialogTitle id="reason-title">{reasonFor ? ACTION_LABEL[reasonFor] : ''}</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>This {p.status === 'sold' ? 'sale' : 'rental'} is being reversed. Say why, so the history makes sense later.</DialogContentText>
          <TextField autoFocus fullWidth label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} slotProps={{ htmlInput: { maxLength: 300 } }} />
          {status.isError && <Alert severity="error" sx={{ mt: 2 }}>{status.error instanceof ApiProblem ? status.error.detail : 'Couldn’t change the status'}</Alert>}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setReasonFor(null)}>Cancel</Button>
          <Button variant="contained" disabled={reason.trim().length < 2 || status.isPending} onClick={() => reasonFor && status.mutate({ status: reasonFor, reason: reason.trim() })}>Confirm</Button>
        </DialogActions>
      </Dialog>
    </>
  );
}
