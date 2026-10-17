import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Box, Button, Card, CardContent, Chip, Stack, Tooltip, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import DomainAddOutlinedIcon from '@mui/icons-material/DomainAddOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { projectsApi, type Project } from '@/api/inventory';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { useConfirm } from '@/ui/confirm';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useToast } from '@/ui/toast';
import { PROJECT_STATUS_LABEL, ProjectDialog } from './ProjectDialog';

/** Stacked bar: available / held / closed. Widths are shares of all units; the legend below carries the exact numbers. */
function Availability({ u }: { u: Project['units'] }) {
  const total = Math.max(u.total, 1);
  const seg = [
    { key: 'available', n: u.available, color: 'success.main', label: 'available' },
    { key: 'held', n: u.held, color: 'warning.main', label: 'on hold' },
    { key: 'closed', n: u.closed, color: 'primary.main', label: 'sold or rented' },
    { key: 'inactive', n: u.inactive, color: 'action.disabled', label: 'inactive' },
  ];
  return (
    <Box>
      <Box role="img" aria-label={seg.map((s) => `${s.n} ${s.label}`).join(', ')} sx={{ display: 'flex', height: 8, borderRadius: 4, overflow: 'hidden', bgcolor: 'surface.sunken', gap: '2px' }}>
        {seg.filter((s) => s.n > 0).map((s) => <Box key={s.key} sx={{ width: `${(s.n / total) * 100}%`, bgcolor: s.color }} />)}
      </Box>
      <Stack direction="row" spacing={2} sx={{ mt: 0.75, flexWrap: 'wrap' }}>
        <Typography variant="caption" color="text.secondary"><b>{u.available}</b> available</Typography>
        <Typography variant="caption" color="text.secondary"><b>{u.held}</b> on hold</Typography>
        <Typography variant="caption" color="text.secondary"><b>{u.closed}</b> closed</Typography>
      </Stack>
    </Box>
  );
}

export function ProjectsPage() {
  const { can } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const q = useQuery({ queryKey: ['projects', 'list'], queryFn: async () => (await projectsApi.list({ sort: 'name' })).data });
  const [dialog, setDialog] = useState<{ project?: Project } | null>(null);
  const archive = useMutation({
    mutationFn: projectsApi.archive,
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['projects'] }); toast.success('Project archived'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t archive the project'),
  });
  const canCreate = can('projects:create');
  const canUpdate = can('projects:update');

  return (
    <>
      <PageHeader
        title="Projects"
        description="Developments and buildings that group your units, with live availability."
        actions={canCreate && <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setDialog({})}>New project</Button>}
      />
      {q.isPending ? <SkeletonRows rows={4} height={140} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : q.data.length === 0 ? (
        <EmptyState icon={<DomainAddOutlinedIcon />} title="No projects yet" description="A project groups units in the same development, so you can see what is left to sell at a glance." action={canCreate ? <Button variant="contained" onClick={() => setDialog({})}>Add your first project</Button> : undefined} />
      ) : (
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))' }}>
          {q.data.map((p) => (
            <Card key={p.id} variant="outlined" sx={{ borderRadius: 3, display: 'flex', flexDirection: 'column' }}>
              <CardContent sx={{ display: 'flex', flexDirection: 'column', gap: 1.75, flex: 1 }}>
                <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'flex-start', gap: 1 }}>
                  <Box sx={{ minWidth: 0 }}>
                    <Typography variant="h4" component="h2" noWrap title={p.name}>{p.name}</Typography>
                    <Typography variant="body2" color="text.secondary" noWrap>{[p.developer, p.locality, p.city].filter(Boolean).join(' · ') || 'No location yet'}</Typography>
                  </Box>
                  <Chip size="small" label={PROJECT_STATUS_LABEL[p.status]} variant={p.status === 'active' ? 'filled' : 'outlined'} color={p.status === 'active' ? 'success' : 'default'} />
                </Stack>
                {p.units.total > 0 ? (
                  <>
                    <Availability u={p.units} />
                    <Typography variant="body2" color="text.secondary" sx={{ fontVariantNumeric: 'tabular-nums' }}>
                      {p.units.minPrice !== null ? <>From <b>{formatMoney(p.units.minPrice)}</b>{p.units.maxPrice !== null && p.units.maxPrice !== p.units.minPrice ? <> to <b>{formatMoney(p.units.maxPrice)}</b></> : null} · </> : null}
                      {formatMoney(p.units.availableValue)} still available
                    </Typography>
                  </>
                ) : (
                  <Typography variant="body2" color="text.secondary">No units yet.</Typography>
                )}
                <Box sx={{ flex: 1 }} />
                <Stack direction="row" spacing={1}>
                  <Button size="small" variant="outlined" component={RouterLink} to={`/properties?project=${p.id}`}>{p.units.total} unit{p.units.total === 1 ? '' : 's'}</Button>
                  {canUpdate && <Tooltip title="Edit"><Button size="small" aria-label={`Edit ${p.name}`} onClick={() => setDialog({ project: p })}><EditOutlinedIcon fontSize="small" /></Button></Tooltip>}
                  {can('projects:delete') && (
                    <Button size="small" color="error" sx={{ ml: 'auto !important' }} onClick={async () => { if (await confirm({ title: `Archive ${p.name}?`, body: p.units.total ? `Its ${p.units.total} unit${p.units.total === 1 ? '' : 's'} stay in your inventory but lose the project label.` : undefined, confirmLabel: 'Archive', destructive: true })) archive.mutate(p.id); }}>Archive</Button>
                  )}
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Box>
      )}
      <ProjectDialog
        open={Boolean(dialog)}
        project={dialog?.project}
        onClose={() => setDialog(null)}
        onSaved={(p) => setDialog(dialog?.project ? null : { project: p })}
      />
    </>
  );
}
