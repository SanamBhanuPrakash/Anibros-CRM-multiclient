import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Divider, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { projectsApi, type Project, type ProjectInput, type ProjectStatus } from '@/api/inventory';
import { useToast } from '@/ui/toast';
import { FilesPanel } from './shared';

export const PROJECT_STATUS_LABEL: Record<ProjectStatus, string> = { upcoming: 'Upcoming', active: 'Selling', on_hold: 'On hold', completed: 'Completed' };

interface Form { name: string; developer: string; status: ProjectStatus; city: string; locality: string; address: string; possession: string; rera: string; description: string; amenities: string[] }
const empty: Form = { name: '', developer: '', status: 'active', city: '', locality: '', address: '', possession: '', rera: '', description: '', amenities: [] };
const fromProject = (p: Project): Form => ({ name: p.name, developer: p.developer ?? '', status: p.status, city: p.city ?? '', locality: p.locality ?? '', address: p.address ?? '', possession: p.possessionDate ?? '', rera: p.reraId ?? '', description: p.description ?? '', amenities: p.amenities });

/** Create or edit a project. Brochures and site pictures can be added once the project exists. */
export function ProjectDialog({ open, project, onClose, onSaved }: { open: boolean; project?: Project; onClose: () => void; onSaved: (p: Project) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [f, setF] = useState<Form>(empty);
  const [error, setError] = useState<string | null>(null);
  const [fe, setFe] = useState<Record<string, string>>({});
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => ({ ...x, [k]: v }));
  useEffect(() => {
    if (!open) return;
    setError(null);
    setFe({});
    setF(project ? fromProject(project) : empty);
  }, [open, project]);

  const files = useQuery({ queryKey: ['projects', 'files', project?.id], queryFn: () => projectsApi.files(project!.id), enabled: open && Boolean(project) });

  const save = useMutation({
    mutationFn: () => {
      const body: ProjectInput = {
        name: f.name.trim(), developer: f.developer.trim() || null, status: f.status, city: f.city.trim() || null, locality: f.locality.trim() || null,
        address: f.address.trim() || null, possessionDate: f.possession || null, reraId: f.rera.trim() || null, description: f.description.trim() || null, amenities: f.amenities,
      };
      return project ? projectsApi.update(project.id, project.version, body) : projectsApi.create(body);
    },
    onSuccess: (p) => {
      void qc.invalidateQueries({ queryKey: ['projects'] });
      toast.success(project ? 'Project updated' : `${p.name} added`);
      onSaved(p);
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('Couldn’t save the project. Please try again.');
      if (e.status === 412) return setError('Someone else changed this project while you were editing. Close this and reopen it to see their changes.');
      const fields = e.fieldErrors();
      setFe(fields);
      if (!Object.keys(fields).length) setError(e.status === 409 ? 'A project with this name already exists.' : e.detail);
    },
  });

  const field = (k: keyof Form, label: string, extra: Record<string, unknown> = {}) => (
    <TextField label={label} fullWidth value={f[k] as string} onChange={(e) => set(k, e.target.value as never)} error={Boolean(fe[k])} helperText={fe[k]} {...extra} />
  );

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" scroll="paper" aria-labelledby="project-dialog-title">
      <DialogTitle id="project-dialog-title">{project ? 'Edit project' : 'New project'}</DialogTitle>
      <DialogContent dividers>
        <Stack component="form" id="project-form" spacing={2} noValidate onSubmit={(e) => { e.preventDefault(); setError(null); setFe({}); save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {field('name', 'Project name', { required: true, autoFocus: true, slotProps: { htmlInput: { maxLength: 200 } } })}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            {field('developer', 'Developer')}
            <TextField select label="Status" fullWidth value={f.status} onChange={(e) => set('status', e.target.value as ProjectStatus)}>
              {(Object.keys(PROJECT_STATUS_LABEL) as ProjectStatus[]).map((s) => <MenuItem key={s} value={s}>{PROJECT_STATUS_LABEL[s]}</MenuItem>)}
            </TextField>
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>{field('city', 'City')}{field('locality', 'Locality')}</Stack>
          {field('address', 'Address')}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>{field('possession', 'Possession date', { type: 'date', slotProps: { inputLabel: { shrink: true } } })}{field('rera', 'RERA registration')}</Stack>
          {field('description', 'Description', { multiline: true, minRows: 3, maxRows: 8, slotProps: { htmlInput: { maxLength: 5000 } } })}
          <Autocomplete multiple freeSolo options={[] as string[]} value={f.amenities} onChange={(_, v) => set('amenities', v)} renderValue={(v, getItemProps) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />)} renderInput={(p) => <TextField {...p} label="Amenities" helperText="Type and press Enter" />} />
        </Stack>
        {project && (
          <>
            <Divider sx={{ my: 3 }} />
            <Typography variant="eyebrow" component="p" sx={{ mb: 1.5 }}>Brochures & pictures</Typography>
            <FilesPanel
              files={files.data ?? []}
              canEdit
              upload={(file) => projectsApi.uploadFile(project.id, file)}
              invalidate={() => void qc.invalidateQueries({ queryKey: ['projects', 'files', project.id] })}
            />
          </>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>{project ? 'Close' : 'Cancel'}</Button>
        <Button type="submit" form="project-form" variant="contained" disabled={!f.name.trim() || save.isPending}>{save.isPending ? 'Saving…' : project ? 'Save changes' : 'Add project'}</Button>
      </DialogActions>
    </Dialog>
  );
}
