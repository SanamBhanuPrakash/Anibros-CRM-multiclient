import { useEffect, useMemo, useState } from 'react';
import {
  Alert, Box, Button, Checkbox, Chip, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, FormControlLabel, IconButton, InputAdornment, MenuItem, Paper, Select, Stack, Switch, Tab, Table, TableBody, TableCell,
  TableContainer, TableHead, TableRow, Tabs, TextField, Tooltip, Typography,
} from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import ArrowUpwardRoundedIcon from '@mui/icons-material/ArrowUpwardRounded';
import ArrowDownwardRoundedIcon from '@mui/icons-material/ArrowDownwardRounded';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import ViewKanbanOutlinedIcon from '@mui/icons-material/ViewKanbanOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { pipelinesApi, type GateField, type LeadStatus, type Pipeline, type Stage, type Transition } from '@/api/crm';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, QueryBoundary } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { FIELD_LABEL, STATUS_LABEL } from '@/features/leads/shared';

const STATUSES = Object.keys(STATUS_LABEL) as LeadStatus[];
const GATES: GateField[] = ['ownerId', 'estimatedValue', 'budgetMax', 'phone', 'email', 'nextFollowupAt'];
const GATE_SHORT: Record<GateField, string> = { ownerId: 'Owner', estimatedValue: 'Deal value', budgetMax: 'Budget', phone: 'Phone', email: 'Email', nextFollowupAt: 'Follow-up date' };
const COLORS = ['#6F86A3', '#8A7F5C', '#6B8F71', '#9A7B6B', '#7C8C8A', '#8F7A9B', '#2E7D4F', '#B3382C'];

/** Plain-words type of a stage for the Basic view. */
const stageType = (s: Stage) => (s.mapsToStatus === 'won' ? 'Won' : s.mapsToStatus === 'lost' ? 'Lost' : s.mapsToStatus === 'disqualified' ? 'Not a fit' : 'In progress');

type Mode = 'basic' | 'advanced';

export function PipelineSettingsPage() {
  const q = useQuery({ queryKey: ['pipelines'], queryFn: pipelinesApi.list });
  const [selected, setSelected] = useState<string | undefined>();
  const [newOpen, setNewOpen] = useState(false);
  const [mode, setMode] = useState<Mode>('basic');
  const pipelines = q.data ?? [];
  const current = pipelines.find((p) => p.id === selected) ?? pipelines.find((p) => p.isDefault) ?? pipelines[0];

  return (
    <>
      <PageHeader
        title="Pipelines"
        description="The steps a lead goes through. Everyday changes are under Basic; what each step means for reports, who can move where and required details are under Advanced."
        actions={<Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setNewOpen(true)}>New pipeline</Button>}
      />
      <QueryBoundary query={q} isEmpty={(d) => d.length === 0} empty={<EmptyState icon={<ViewKanbanOutlinedIcon />} title="No pipelines" description="Create one to start tracking leads." />}>
        {() =>
          current ? (
            <Stack spacing={3}>
              <PipelineHeader pipelines={pipelines} current={current} onSelect={setSelected} />
              <Tabs value={mode} onChange={(_, v: Mode) => setMode(v)} aria-label="Pipeline settings level">
                <Tab value="basic" label="Basic" id="tab-basic" aria-controls="panel-basic" />
                <Tab value="advanced" label="Advanced" id="tab-advanced" aria-controls="panel-advanced" />
              </Tabs>
              {mode === 'basic' ? (
                <Stack spacing={4} role="tabpanel" id="panel-basic" aria-labelledby="tab-basic">
                  <RecommendedCard pipelines={pipelines} onAdded={setSelected} />
                  <StagesEditor pipeline={current} mode="basic" />
                  <MoveSummary pipeline={current} onAdvanced={() => setMode('advanced')} />
                </Stack>
              ) : (
                <Stack spacing={4} role="tabpanel" id="panel-advanced" aria-labelledby="tab-advanced">
                  <StagesEditor pipeline={current} mode="advanced" />
                  <ReasonCodesSetting pipeline={current} />
                  <DealValueSetting pipeline={current} />
                  <RulesEditor pipeline={current} />
                </Stack>
              )}
            </Stack>
          ) : null
        }
      </QueryBoundary>
      <NewPipelineDialog open={newOpen} onClose={() => setNewOpen(false)} pipelines={pipelines} onCreated={(p) => { setSelected(p.id); setNewOpen(false); }} />
    </>
  );
}

function useRefresh() {
  const qc = useQueryClient();
  return () => void Promise.all([qc.invalidateQueries({ queryKey: ['pipelines'] }), qc.invalidateQueries({ queryKey: ['leads'] })]);
}
function useFail() {
  const toast = useToast();
  return (e: unknown) => toast.error(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'That didn’t work. Please try again.');
}

function PipelineHeader({ pipelines, current, onSelect }: { pipelines: Pipeline[]; current: Pipeline; onSelect: (id: string) => void }) {
  const refresh = useRefresh();
  const fail = useFail();
  const toast = useToast();
  const confirm = useConfirm();
  const [name, setName] = useState(current.name);
  useEffect(() => setName(current.name), [current.id, current.name]);
  const update = useMutation({ mutationFn: (patch: { name?: string; isDefault?: boolean }) => pipelinesApi.update(current.id, patch), onSuccess: () => { refresh(); toast.success('Saved'); }, onError: fail });
  const archive = useMutation({ mutationFn: () => pipelinesApi.archive(current.id), onSuccess: () => { refresh(); toast.success('Pipeline archived'); }, onError: fail });
  return (
    <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'center' } }}>
      {pipelines.length > 1 && (
        <TextField select size="small" fullWidth={false} label="Pipeline" value={current.id} onChange={(e) => onSelect(e.target.value)} sx={{ minWidth: 220 }}>
          {pipelines.map((p) => <MenuItem key={p.id} value={p.id}>{p.name}{p.isDefault ? ' (default)' : ''}</MenuItem>)}
        </TextField>
      )}
      <TextField size="small" fullWidth={false} label="Name" value={name} onChange={(e) => setName(e.target.value)} onBlur={() => name.trim() && name.trim() !== current.name && update.mutate({ name: name.trim() })} onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLElement).blur()} sx={{ minWidth: 240 }} slotProps={{ htmlInput: { maxLength: 80 } }} />
      {current.isDefault ? <Chip size="small" color="primary" label="Where new leads start" /> : <Button size="small" onClick={() => update.mutate({ isDefault: true })}>Make this where new leads start</Button>}
      {current.template === 'beginner_v1' && <Chip size="small" variant="outlined" label="Recommended pipeline" />}
      {!current.isDefault && (
        <Button size="small" color="error" onClick={async () => { if (await confirm({ title: `Archive “${current.name}”?`, body: 'Only possible when it has no open leads. Closed leads keep their history.', confirmLabel: 'Archive', destructive: true })) archive.mutate(); }}>Archive</Button>
      )}
    </Stack>
  );
}

/**
 * Existing workspaces keep their pipeline exactly as it is. This offers the recommended one as an ADDITIONAL pipeline, only when asked,
 * and only until it has been added. Nothing is replaced and no lead is moved.
 */
function RecommendedCard({ pipelines, onAdded }: { pipelines: Pipeline[]; onAdded: (id: string) => void }) {
  const refresh = useRefresh();
  const fail = useFail();
  const toast = useToast();
  const confirm = useConfirm();
  const [makeDefault, setMakeDefault] = useState(false);
  const has = pipelines.some((p) => p.template === 'beginner_v1');
  const add = useMutation({
    mutationFn: () => pipelinesApi.applyRecommended(makeDefault),
    onSuccess: (r) => { refresh(); onAdded(r.pipeline.id); toast.success(r.created ? 'Recommended pipeline added. Your existing pipeline and leads are untouched.' : 'You already have the recommended pipeline.'); },
    onError: fail,
  });
  if (has) return null;
  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }} component="section" aria-labelledby="recommended-title">
      <Typography id="recommended-title" variant="h4" component="h2">Want a simpler process?</Typography>
      <Typography color="text.secondary" sx={{ mt: 0.5, mb: 1.5, maxWidth: '70ch' }}>
        We recommend twelve plain steps: New enquiry, Trying to reach, In conversation, Qualified, Site visit booked, Site visit done, Negotiating, Booked, Follow up later, then Won, Lost or Not a fit.
        A lead can go from any step to any other, only Won needs Booked first, and Lost and Not a fit ask for a reason. Adding it creates a <strong>second pipeline</strong>: your current pipeline and every lead stay exactly as they are.
      </Typography>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
        <FormControlLabel control={<Checkbox checked={makeDefault} onChange={(e) => setMakeDefault(e.target.checked)} />} label="Start new leads in it (existing leads stay where they are)" />
        <Button variant="outlined" disabled={add.isPending} onClick={async () => { if (await confirm({ title: 'Add the recommended pipeline?', body: makeDefault ? 'New leads will start in it. Existing leads and your current pipeline are not changed. This is recorded in the audit trail.' : 'It is added next to your current pipeline and does nothing until you use it. This is recorded in the audit trail.', confirmLabel: 'Add pipeline' })) add.mutate(); }}>
          {add.isPending ? 'Adding…' : 'Add the recommended pipeline'}
        </Button>
      </Stack>
    </Paper>
  );
}

function MoveSummary({ pipeline, onAdvanced }: { pipeline: Pipeline; onAdvanced: () => void }) {
  const rules = useQuery({ queryKey: ['pipelines', 'transitions', pipeline.id], queryFn: () => pipelinesApi.transitions(pipeline.id) });
  return (
    <Box component="section" aria-labelledby="moves-title">
      <Typography id="moves-title" variant="h4" component="h2">How leads move</Typography>
      <Typography color="text.secondary" sx={{ mt: 0.5, maxWidth: '70ch' }}>
        {rules.isPending ? 'Loading…' : rules.data?.restricted
          ? `This pipeline only allows some moves between stages (${rules.data.transitions.length} allowed). Closed leads can always be reopened.`
          : 'A lead can move to any stage. Closed leads can always be reopened.'}{' '}
        <Button size="small" onClick={onAdvanced}>Change in Advanced</Button>
      </Typography>
    </Box>
  );
}

function ReasonCodesSetting({ pipeline }: { pipeline: Pipeline }) {
  const refresh = useRefresh();
  const fail = useFail();
  const toast = useToast();
  const update = useMutation({
    mutationFn: (v: boolean) => pipelinesApi.update(pipeline.id, { reasonCodesRequired: v }),
    onSuccess: (p) => { refresh(); toast.success(p.reasonCodesRequired ? 'A reason is now required from a fixed list' : 'A written reason is enough again'); },
    onError: fail,
  });
  return (
    <Box component="section" aria-labelledby="reasons-title">
      <Typography id="reasons-title" variant="h4" component="h2">Why leads are lost</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
        When a lead goes to Lost or Not a fit, people choose a reason from a fixed list so reports can count them. Turn this off to accept any written reason instead. Leads closed earlier keep what was recorded.
      </Typography>
      <FormControlLabel control={<Switch checked={pipeline.reasonCodesRequired} disabled={update.isPending} onChange={(_, v) => update.mutate(v)} />} label="Require a reason from the list" />
    </Box>
  );
}

function DealValueSetting({ pipeline }: { pipeline: Pipeline }) {
  const refresh = useRefresh();
  const fail = useFail();
  const toast = useToast();
  const update = useMutation({
    mutationFn: (v: boolean) => pipelinesApi.update(pipeline.id, { dealValueRequired: v }),
    onSuccess: (p) => { refresh(); toast.success(p.dealValueRequired ? 'Winning a lead now needs a recorded sale' : 'Leads can be won without recording a sale again'); },
    onError: fail,
  });
  return (
    <Box component="section" aria-labelledby="deal-value-title">
      <Typography id="deal-value-title" variant="h4" component="h2">What counts as a sale</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
        When this is on, a lead can only be marked Won by recording which property was sold and its final value. Reports then count revenue from those recorded sales instead of estimates typed on leads. Leads already won keep what they have; they are shown as “won without a recorded sale”.
      </Typography>
      <FormControlLabel control={<Switch checked={pipeline.dealValueRequired} disabled={update.isPending} onChange={(_, v) => update.mutate(v)} />} label="Require a recorded sale to win a lead" />
    </Box>
  );
}

function StagesEditor({ pipeline, mode }: { pipeline: Pipeline; mode: Mode }) {
  const refresh = useRefresh();
  const fail = useFail();
  const advanced = mode === 'advanced';
  const [add, setAdd] = useState(false);
  const [edit, setEdit] = useState<Stage | null>(null);
  const [remove, setRemove] = useState<Stage | null>(null);
  const reorder = useMutation({ mutationFn: (ids: string[]) => pipelinesApi.reorder(pipeline.id, ids), onSuccess: refresh, onError: fail });
  const move = (i: number, d: -1 | 1) => {
    const ids = pipeline.stages.map((s) => s.id);
    [ids[i], ids[i + d]] = [ids[i + d]!, ids[i]!];
    reorder.mutate(ids);
  };
  return (
    <Box component="section" aria-labelledby={`stages-title-${mode}`}>
      <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 1.5 }}>
        <Box>
          <Typography id={`stages-title-${mode}`} variant="h4" component="h2">Stages</Typography>
          <Typography variant="body2" color="text.secondary">
            {advanced
              ? 'The first stage is where new leads start. “Means” drives status in reports; “Chance” feeds the weighted forecast. Renaming a stage never changes what the history of past moves says.'
              : 'The first stage is where new leads start. Rename a stage, change its colour, reorder or remove it. Past history keeps the names it had at the time.'}
          </Typography>
        </Box>
        <Button startIcon={<AddRoundedIcon />} onClick={() => setAdd(true)}>Add stage</Button>
      </Stack>
      <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
        <Table size="small" aria-label="Pipeline stages">
          <TableHead>
            <TableRow>
              <TableCell>Stage</TableCell>
              <TableCell>{advanced ? 'Means' : 'Type'}</TableCell>
              {advanced && <TableCell align="right">Chance</TableCell>}
              <TableCell align="right">Leads</TableCell>
              <TableCell align="right" sx={{ width: 230 }}><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {pipeline.stages.map((s, i) => (
              <TableRow key={s.id} hover>
                <TableCell>
                  <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
                    <Box sx={{ width: 12, height: 12, borderRadius: '50%', bgcolor: s.color }} aria-hidden />
                    <Typography variant="subtitle2">{s.name}</Typography>
                    {i === 0 && <Chip size="small" label="Start" variant="outlined" />}
                  </Stack>
                </TableCell>
                <TableCell>{advanced ? STATUS_LABEL[s.mapsToStatus] : stageType(s)}</TableCell>
                {advanced && <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{s.isClosed ? '—' : `${s.probability}%`}</TableCell>}
                <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums' }}>{s.leadCount}</TableCell>
                <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                  <Tooltip title="Move up"><span><IconButton size="small" aria-label={`Move ${s.name} up`} disabled={i === 0 || reorder.isPending} onClick={() => move(i, -1)}><ArrowUpwardRoundedIcon fontSize="small" /></IconButton></span></Tooltip>
                  <Tooltip title="Move down"><span><IconButton size="small" aria-label={`Move ${s.name} down`} disabled={i === pipeline.stages.length - 1 || reorder.isPending} onClick={() => move(i, 1)}><ArrowDownwardRoundedIcon fontSize="small" /></IconButton></span></Tooltip>
                  <Button size="small" onClick={() => setEdit(s)}>Edit</Button>
                  <Tooltip title="Remove stage"><IconButton size="small" aria-label={`Remove ${s.name}`} onClick={() => setRemove(s)}><DeleteOutlineRoundedIcon fontSize="small" /></IconButton></Tooltip>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <StageDialog open={add} pipeline={pipeline} advanced={advanced} onClose={() => setAdd(false)} />
      <StageDialog open={Boolean(edit)} pipeline={pipeline} advanced={advanced} stage={edit ?? undefined} onClose={() => setEdit(null)} />
      <RemoveStageDialog stage={remove} pipeline={pipeline} onClose={() => setRemove(null)} />
    </Box>
  );
}

function StageDialog({ open, pipeline, stage, advanced, onClose }: { open: boolean; pipeline: Pipeline; stage?: Stage; advanced: boolean; onClose: () => void }) {
  const refresh = useRefresh();
  const toast = useToast();
  const [name, setName] = useState('');
  const [status, setStatus] = useState<LeadStatus>('open');
  const [prob, setProb] = useState('20');
  const [color, setColor] = useState(COLORS[0]!);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setError(null);
      setName(stage?.name ?? '');
      setStatus(stage?.mapsToStatus ?? 'open');
      setProb(String(stage?.probability ?? 20));
      setColor(stage?.color ?? COLORS[pipeline.stages.length % COLORS.length]!);
    }
  }, [open, stage, pipeline.stages.length]);
  const closing = status === 'won' || status === 'lost' || status === 'disqualified';
  const save = useMutation({
    mutationFn: () => {
      // Basic never changes what a stage means or how likely it is to win: only its name and colour.
      const body = advanced ? { name: name.trim(), color, mapsToStatus: status, probability: closing ? undefined : Math.min(99, Math.max(0, Number(prob) || 0)) } : stage ? { name: name.trim(), color } : { name: name.trim(), color, mapsToStatus: 'open' as LeadStatus, probability: 20 };
      return stage ? pipelinesApi.updateStage(pipeline.id, stage.id, body) : pipelinesApi.addStage(pipeline.id, body);
    },
    onSuccess: () => { refresh(); toast.success(stage ? 'Stage updated' : 'Stage added'); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t save the stage'),
  });
  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="stage-title">
      <DialogTitle id="stage-title">{stage ? `Edit “${stage.name}”` : 'Add a stage'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="stage-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (name.trim()) save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <TextField label="Name" autoFocus value={name} onChange={(e) => setName(e.target.value)} slotProps={{ htmlInput: { maxLength: 60 } }} />
          {advanced && (
            <TextField select label="This stage means" value={status} onChange={(e) => setStatus(e.target.value as LeadStatus)} helperText={closing ? 'Leads here are closed. Lost and Not a fit ask for a reason.' : 'Reports and the lead status follow this.'}>
              {STATUSES.map((s) => <MenuItem key={s} value={s}>{STATUS_LABEL[s]}</MenuItem>)}
            </TextField>
          )}
          {advanced && !closing && <TextField label="Chance of winning" type="number" value={prob} onChange={(e) => setProb(e.target.value)} slotProps={{ htmlInput: { min: 0, max: 99 }, input: { endAdornment: <InputAdornment position="end">%</InputAdornment> } }} helperText="Used for the weighted forecast. These are your estimates, not measured results." />}
          {!advanced && !stage && <Typography variant="body2" color="text.secondary">New stages are “In progress” steps. To make one that closes a lead as Won, Lost or Not a fit, use Advanced.</Typography>}
          <Stack direction="row" spacing={1} role="radiogroup" aria-label="Colour">
            {COLORS.map((c) => (
              <Box key={c} component="button" type="button" role="radio" aria-checked={color === c} aria-label={`Colour ${c}`} onClick={() => setColor(c)} sx={{ width: 28, height: 28, borderRadius: '50%', bgcolor: c, border: 2, borderColor: color === c ? 'text.primary' : 'transparent', cursor: 'pointer', p: 0, outlineOffset: 2 }} />
            ))}
          </Stack>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="stage-form" variant="contained" disabled={!name.trim() || save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
      </DialogActions>
    </Dialog>
  );
}

function RemoveStageDialog({ stage, pipeline, onClose }: { stage: Stage | null; pipeline: Pipeline; onClose: () => void }) {
  const refresh = useRefresh();
  const toast = useToast();
  const [target, setTarget] = useState('');
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { setTarget(''); setError(null); }, [stage?.id]);
  const others = pipeline.stages.filter((s) => s.id !== stage?.id);
  const run = useMutation({
    mutationFn: () => pipelinesApi.removeStage(pipeline.id, stage!.id, stage!.leadCount ? target : undefined),
    onSuccess: () => { refresh(); toast.success('Stage removed'); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t remove the stage'),
  });
  return (
    <Dialog open={Boolean(stage)} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="remove-stage-title">
      <DialogTitle id="remove-stage-title">Remove “{stage?.name}”?</DialogTitle>
      <DialogContent>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {stage && stage.leadCount > 0 ? (
          <>
            <DialogContentText sx={{ mb: 2 }}>{stage.leadCount} lead{stage.leadCount === 1 ? ' is' : 's are'} in this stage. Choose where they go; each gets a note in its history.</DialogContentText>
            <TextField select fullWidth label="Move leads to" value={target} onChange={(e) => setTarget(e.target.value)}>
              {others.map((s) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
            </TextField>
          </>
        ) : <DialogContentText>No leads are in this stage. It will be removed from the pipeline.</DialogContentText>}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button color="error" variant="contained" disabled={run.isPending || Boolean(stage?.leadCount && !target)} onClick={() => run.mutate()}>Remove stage</Button>
      </DialogActions>
    </Dialog>
  );
}

function RulesEditor({ pipeline }: { pipeline: Pipeline }) {
  const refresh = useRefresh();
  const fail = useFail();
  const toast = useToast();
  const rules = useQuery({ queryKey: ['pipelines', 'transitions', pipeline.id], queryFn: () => pipelinesApi.transitions(pipeline.id) });
  const [restricted, setRestricted] = useState(false);
  const [allowed, setAllowed] = useState<Set<string>>(new Set());
  const [gates, setGates] = useState<Record<string, GateField[]>>({});
  const [dirty, setDirty] = useState(false);
  const key = (a: string, b: string) => `${a}>${b}`;

  useEffect(() => {
    if (!rules.data) return;
    setRestricted(rules.data.restricted);
    setAllowed(new Set(rules.data.transitions.map((t) => key(t.fromStageId, t.toStageId))));
    const g: Record<string, GateField[]> = {};
    for (const t of rules.data.transitions) if (t.requiredFields.length) g[t.toStageId] = t.requiredFields;
    setGates(g);
    setDirty(false);
  }, [rules.data]);

  const stages = pipeline.stages;
  const payload = useMemo<Transition[]>(
    () => [...allowed].map((k) => { const [fromStageId, toStageId] = k.split('>') as [string, string]; return { fromStageId, toStageId, requiredFields: gates[toStageId] ?? [] }; }),
    [allowed, gates]
  );
  const save = useMutation({
    mutationFn: () => pipelinesApi.setTransitions(pipeline.id, { restricted, transitions: restricted ? payload : [] }),
    onSuccess: () => { refresh(); void rules.refetch(); toast.success(restricted ? 'Move rules saved' : 'Leads can now move between any stages'); },
    onError: fail,
  });
  const toggle = (a: string, b: string) => { const n = new Set(allowed);
    if (n.has(key(a, b))) n.delete(key(a, b));
    else n.add(key(a, b));
    setAllowed(n);
    setDirty(true);
  };

  return (
    <Box component="section" aria-labelledby="rules-title">
      <Typography id="rules-title" variant="h4" component="h2">Move rules</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>By default a lead can jump to any stage. Turn rules on to enforce a process, and optionally require details before a lead enters a stage. Closed leads can always be reopened.</Typography>
      <FormControlLabel control={<Switch checked={restricted} onChange={(_, v) => { setRestricted(v); setDirty(true); if (v && allowed.size === 0) { const n = new Set<string>(); stages.slice(0, -1).forEach((s, i) => n.add(key(s.id, stages[i + 1]!.id))); setAllowed(n); } }} />} label="Only allow the moves I select" />
      {restricted && (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ mt: 2, borderRadius: 2.5, overflowX: 'auto' }}>
            <Table size="small" aria-label="Allowed moves">
              <TableHead>
                <TableRow>
                  <TableCell>From ↓  /  To →</TableCell>
                  {stages.map((s) => <TableCell key={s.id} align="center" sx={{ whiteSpace: 'nowrap' }}>{s.name}</TableCell>)}
                </TableRow>
              </TableHead>
              <TableBody>
                {stages.map((from) => (
                  <TableRow key={from.id} hover>
                    <TableCell sx={{ fontWeight: 500, whiteSpace: 'nowrap' }}>{from.name}</TableCell>
                    {stages.map((to) => (
                      <TableCell key={to.id} align="center" padding="checkbox">
                        {from.id === to.id ? <Typography color="text.disabled" component="span">—</Typography> : <Checkbox size="small" checked={allowed.has(key(from.id, to.id))} onChange={() => toggle(from.id, to.id)} slotProps={{ input: { 'aria-label': `Allow ${from.name} to ${to.name}` } }} />}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
          <Typography variant="h5" component="h3" sx={{ mt: 3, mb: 1 }}>Details required before entering a stage</Typography>
          <Stack spacing={1.5}>
            {stages.filter((s) => stages.some((f) => allowed.has(key(f.id, s.id)))).map((s) => (
              <Stack key={s.id} direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
                <Typography variant="subtitle2" sx={{ width: 180 }}>{s.name}</Typography>
                <Select multiple size="small" displayEmpty value={gates[s.id] ?? []} onChange={(e) => { setGates({ ...gates, [s.id]: e.target.value as GateField[] }); setDirty(true); }} renderValue={(v) => (v.length ? v.map((x) => GATE_SHORT[x]).join(', ') : <Typography color="text.disabled" component="span" variant="body2">Nothing required</Typography>)} sx={{ minWidth: 280 }} inputProps={{ 'aria-label': `Required details for ${s.name}` }}>
                  {GATES.map((g) => <MenuItem key={g} value={g}><Checkbox size="small" checked={(gates[s.id] ?? []).includes(g)} />{GATE_SHORT[g]} <Typography variant="caption" color="text.secondary" sx={{ ml: 1 }}>({FIELD_LABEL[g]})</Typography></MenuItem>)}
                </Select>
              </Stack>
            ))}
          </Stack>
        </>
      )}
      <Stack direction="row" spacing={1} sx={{ mt: 2.5 }}>
        <Button variant="contained" disabled={!dirty || save.isPending || (restricted && allowed.size === 0)} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save move rules'}</Button>
        {dirty && <Button onClick={() => void rules.refetch().then(() => setDirty(false))}>Discard changes</Button>}
      </Stack>
    </Box>
  );
}

function NewPipelineDialog({ open, onClose, pipelines, onCreated }: { open: boolean; onClose: () => void; pipelines: Pipeline[]; onCreated: (p: Pipeline) => void }) {
  const refresh = useRefresh();
  const toast = useToast();
  const [name, setName] = useState('');
  const [copy, setCopy] = useState('');
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { if (open) { setName(''); setCopy(''); setError(null); } }, [open]);
  const create = useMutation({
    mutationFn: () => pipelinesApi.create({ name: name.trim(), copyFromId: copy || undefined }),
    onSuccess: (p) => { refresh(); toast.success(`Created “${p.name}”`); onCreated(p); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t create the pipeline'),
  });
  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="new-pipeline-title">
      <DialogTitle id="new-pipeline-title">New pipeline</DialogTitle>
      <DialogContent>
        <Stack component="form" id="new-pipeline-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (name.trim()) create.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <TextField label="Name" autoFocus value={name} onChange={(e) => setName(e.target.value)} helperText="e.g. Rentals, Resale, Commercial" slotProps={{ htmlInput: { maxLength: 80 } }} />
          <TextField select label="Start from" value={copy} onChange={(e) => setCopy(e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
            <MenuItem value="">Standard stages (New → Won / Lost)</MenuItem>
            {pipelines.map((p) => <MenuItem key={p.id} value={p.id}>A copy of “{p.name}”</MenuItem>)}
          </TextField>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="new-pipeline-form" variant="contained" disabled={!name.trim() || create.isPending}>Create</Button>
      </DialogActions>
    </Dialog>
  );
}
